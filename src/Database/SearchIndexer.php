<?php

namespace AOE\CatalogEngine\Database;

/**
 * Reusable search index builder for wp_aoe_catalog_search_products.
 *
 * Single source of truth for the indexing logic. Used by:
 * - tools/build-search-index.php (CLI, synchronous loop)
 * - AdminManager AJAX (batch driven by frontend polling)
 */
class SearchIndexer {

	const TRANSIENT_PREFIX = 'aoe_index_progress_';
	const JOB_TTL            = 900;
	const STALE_AFTER        = 600;

	public static function ensure_table() {
		global $wpdb;
		$table_search = $wpdb->prefix . 'aoe_catalog_search_products';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "CREATE TABLE IF NOT EXISTS $table_search (
			id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			manufacturer_normalized VARCHAR(64)  NOT NULL,
			manufacturer_name       VARCHAR(255) NOT NULL,
			sku_normalized          VARCHAR(255) NOT NULL,
			sku                     VARCHAR(255) NOT NULL,
			search_text             TEXT NOT NULL,
			payload_json            JSON NOT NULL,
			created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at              DATETIME NOT NULL,
			UNIQUE KEY uq_mfr_sku (manufacturer_normalized, sku),
			KEY k_sku (sku_normalized),
			FULLTEXT KEY ft_search (search_text)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
	}

	public static function normalize_search( string $text ): string {
		$text = iconv( 'UTF-8', 'UTF-8//IGNORE', $text );
		$text = preg_replace( '/[áàâã]/u', 'A', $text );
		$text = preg_replace( '/[éèêë]/u', 'E', $text );
		$text = preg_replace( '/[íìîï]/u', 'I', $text );
		$text = preg_replace( '/[óòôõ]/u', 'O', $text );
		$text = preg_replace( '/[úùûü]/u', 'U', $text );
		$text = preg_replace( '/[ñ]/u', 'N', $text );
		$text = preg_replace( '/[ç]/u', 'C', $text );
		$text = strtoupper( $text );
		$text = preg_replace( '/[^A-Z0-9]/', '', $text );
		return $text;
	}

	public static function build_category_path( int $category_id, array $cat_map ): array {
		$path      = [];
		$leaf_slug = '';
		$current   = $category_id;
		$safety     = 0;
		while ( $current && $safety < 10 ) {
			if ( ! isset( $cat_map[ $current ] ) ) {
				break;
			}
			$cat = $cat_map[ $current ];
			array_unshift( $path, $cat->name );
			if ( '' === $leaf_slug ) {
				$leaf_slug = $cat->slug ?? '';
			}
			$current = (int) ( $cat->parent_id ?? 0 );
			$safety++;
		}
		return [ $path, $leaf_slug ];
	}

	public static function classify_docs( array $raw_docs ): array {
		$CAD_EXT      = [ 'dxf', 'dwg', 'stp', 'step', 'igs', 'iges', 'jt', 'stl', 'x_t', 'x_b' ];
		$CAD_KEYWORDS = '/\b(dxf|dwg|stp|step|iges?|jt|3d\s*model|3d\s*cad|cad\s*file|solidworks|autocad)\b/i';
		$result       = [ 'pdfs' => [], '3dcad' => [] ];

		foreach ( $raw_docs as $label => $items ) {
			if ( ! is_array( $items ) ) {
				continue;
			}
			foreach ( $items as $item ) {
				$url  = $item['url'] ?? '';
				$name = $item['name'] ?? $label;
				if ( '' === $url ) {
					continue;
				}
				$ext         = strtolower( pathinfo( parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
				$is_cad_ext  = in_array( $ext, $CAD_EXT, true );
				$is_cad_name = (bool) preg_match( $CAD_KEYWORDS, $name );

				if ( $is_cad_ext || $is_cad_name ) {
					$result['3dcad'][] = [ 'url' => $url, 'name' => $name, 'ext' => $ext ];
				} else {
					$result['pdfs'][] = [ 'url' => $url, 'name' => $name ];
				}
			}
		}
		return $result;
	}

	/**
	 * Start (or resume) an indexing job for a manufacturer slug.
	 *
	 * @return array ['progress_key' => string] or ['error' => string]
	 */
	public static function start_job( string $slug, int $batch = 2000 ): array {
		global $wpdb;
		$table_m = $wpdb->prefix . 'aoe_catalog_manufacturers';
		$table_p = $wpdb->prefix . 'aoe_catalog_products';

		$mfr = $wpdb->get_row( $wpdb->prepare( "SELECT id, name, slug FROM $table_m WHERE slug = %s", $slug ) );
		if ( ! $mfr ) {
			// Fallback: the frontend passes manufacturer_normalized (e.g. "teconnectivity")
			// which matches the NORMALIZED name, not the slug column. Compare normalized.
			$needle = self::normalize_search( $slug );
			if ( '' !== $needle ) {
				$candidates = $wpdb->get_results( "SELECT id, name, slug FROM $table_m" );
				foreach ( $candidates as $c ) {
					if ( self::normalize_search( $c->name ) === $needle ) {
						$mfr = $c;
						break;
					}
				}
			}
		}
		if ( ! $mfr ) {
			return [ 'error' => 'Manufacturer not found' ];
		}

		self::ensure_table();

		// Resume a recent still-active job for this slug, if any.
		$like = '%aoe_index_progress_' . $wpdb->esc_like( $mfr->slug ) . '_%';
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT 5",
			$like
		) );
		foreach ( $rows as $row ) {
			$val = maybe_unserialize( $row->option_value );
			if ( ! is_array( $val ) || empty( $val['status'] ) ) {
				continue;
			}
			if ( in_array( $val['status'], [ 'starting', 'running' ], true )
				&& isset( $val['started_at'] )
				&& ( time() - (int) $val['started_at'] ) < self::STALE_AFTER ) {
				$prefix_len = strlen( '_transient_' . self::TRANSIENT_PREFIX );
				return [ 'progress_key' => substr( $row->option_name, $prefix_len ) ];
			}
		}
		// Stale or completed leftovers: clean up.
		if ( $rows ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
		}

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table_p WHERE manufacturer_id = %d", $mfr->id ) );

		$progress_key = $mfr->slug . '_' . time();
		$state        = [
			'status'     => 'starting',
			'mfr_id'     => (int) $mfr->id,
			'mfr_slug'   => $mfr->slug,
			'mfr_name'   => $mfr->name,
			'mfr_norm'   => self::normalize_search( $mfr->name ),
			'offset'     => 0,
			'count'      => 0,
			'total'      => $total,
			'batch'      => $batch,
			'errors'     => 0,
			'started_at' => time(),
		];
		set_transient( self::TRANSIENT_PREFIX . $progress_key, $state, self::JOB_TTL );

		return [ 'progress_key' => $progress_key ];
	}

	/**
	 * Process ONE batch of the job identified by $progress_key.
	 * The frontend polling drives the work forward: each call advances one batch.
	 *
	 * @return array The current job state.
	 */
	public static function process_batch( string $progress_key ): array {
		$state = get_transient( self::TRANSIENT_PREFIX . $progress_key );
		if ( ! is_array( $state ) ) {
			return [ 'status' => 'idle', 'current' => 0, 'total' => 0 ];
		}
		if ( ! in_array( $state['status'], [ 'starting', 'running' ], true ) ) {
			return $state;
		}

		if ( 0 === (int) $state['total'] || (int) $state['offset'] >= (int) $state['total'] ) {
			$state['status'] = 'completed';
			set_transient( self::TRANSIENT_PREFIX . $progress_key, $state, self::JOB_TTL );
			return $state;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 );
		}

		$state['status'] = 'running';
		$ctx  = self::load_context( (int) $state['mfr_id'], $state['mfr_slug'] );
		$done = self::run_single_batch( $ctx, $state );

		set_transient( self::TRANSIENT_PREFIX . $progress_key, $state, self::JOB_TTL );
		return $state;
	}

	/**
	 * Index a whole manufacturer synchronously (CLI usage).
	 *
	 * @param string   $mfr_slug   Manufacturer slug.
	 * @param string   $mfr_name   Manufacturer display name.
	 * @param int      $batch      Batch size.
	 * @param string   $progress_key Optional transient key for progress updates.
	 * @param callable|null $on_batch  Callback( array $state ) after each batch.
	 * @param callable|null $on_start  Callback( int $total, int $categories, int $hidden ) before first batch.
	 * @return int Indexed product count.
	 */
	public static function index_manufacturer( int $mfr_id, string $mfr_slug, string $mfr_name, int $batch = 1000, string $progress_key = '', $on_batch = null, $on_start = null ): int {
		global $wpdb;
		$table_p = $wpdb->prefix . 'aoe_catalog_products';

		self::ensure_table();

		$ctx = self::load_context( $mfr_id, $mfr_slug );

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table_p WHERE manufacturer_id = %d", $mfr_id ) );

		if ( is_callable( $on_start ) ) {
			call_user_func( $on_start, $total, $ctx['categories_count'], $ctx['hidden_count'] );
		}

		$state = [
			'status'   => 'running',
			'mfr_id'   => $mfr_id,
			'mfr_slug' => $mfr_slug,
			'mfr_name' => $mfr_name,
			'mfr_norm' => self::normalize_search( $mfr_name ),
			'offset'   => 0,
			'count'    => 0,
			'total'    => $total,
			'batch'    => $batch,
			'errors'   => 0,
		];

		if ( $progress_key ) {
			$state['started_at'] = time();
			set_transient( self::TRANSIENT_PREFIX . $progress_key, $state, self::JOB_TTL );
		}

		while ( 'running' === $state['status'] ) {
			self::run_single_batch( $ctx, $state );

			if ( $progress_key ) {
				set_transient( self::TRANSIENT_PREFIX . $progress_key, $state, self::JOB_TTL );
			}
			if ( is_callable( $on_batch ) ) {
				call_user_func( $on_batch, $state );
			}
		}

		return (int) $state['count'];
	}

	/**
	 * Load per-manufacturer context: category map, hidden ids, segments by category, processor.
	 */
	private static function load_context( int $mfr_id, string $mfr_slug ): array {
		global $wpdb;
		$table_c     = $wpdb->prefix . 'aoe_catalog_categories';
		$table_pages = $wpdb->prefix . 'aoe_catalog_pregenerated_pages';
		$table_segs  = $wpdb->prefix . 'aoe_catalog_page_segments';

		$cat_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, name, slug, parent_id, is_hidden FROM $table_c WHERE manufacturer_id = %d", $mfr_id
		) );
		$cat_map       = [];
		$hidden        = [];
		$cat_slug_map  = [];
		foreach ( $cat_rows as $cr ) {
			$cat_map[ (int) $cr->id ] = $cr;
			$cat_slug_map[ (int) $cr->id ] = $cr->slug;
			if ( (int) $cr->is_hidden ) {
				$hidden[] = (int) $cr->id;
			}
		}

		$page_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, slug, type FROM $table_pages WHERE manufacturer_id = %d", $mfr_id
		) );
		$page_info = [];
		foreach ( $page_rows as $pr ) {
			$page_info[ (int) $pr->id ] = [
				'page_slug' => $pr->slug,
				'page_type' => $pr->type,
			];
		}

		$seg_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT page_id, category_id, products_from, products_to FROM $table_segs WHERE page_id IN (
				SELECT id FROM $table_pages WHERE manufacturer_id = %d
			)", $mfr_id
		) );

		$segments_by_cat = [];
		foreach ( $seg_rows as $sr ) {
			$pi = $page_info[ (int) $sr->page_id ] ?? null;
			if ( ! $pi ) {
				continue;
			}
			$cat_id = (int) $sr->category_id;
			$from   = (int) $sr->products_from;
			$to     = (int) $sr->products_to;
			if ( $to - $from <= 0 || $cat_id <= 0 ) {
				continue;
			}
			if ( in_array( $cat_id, $hidden, true ) ) {
				continue;
			}
			$segments_by_cat[ $cat_id ][] = [
				'from'           => $from,
				'to'             => $to,
				'page_slug'      => $pi['page_slug'],
				'page_type'      => $pi['page_type'],
				'category_slug'  => $cat_slug_map[ $cat_id ] ?? '',
			];
		}

		$processor = null;
		if ( class_exists( '\AOE\CatalogEngine\Import\ProcessorManager' ) ) {
			$processor = ( new \AOE\CatalogEngine\Import\ProcessorManager() )->get_processor( $mfr_slug );
		}

		return [
			'cat_map'          => $cat_map,
			'hidden_cat_ids'   => $hidden,
			'cat_slug_map'     => $cat_slug_map,
			'segments_by_cat'  => $segments_by_cat,
			'processor'        => $processor,
			'categories_count' => count( $cat_map ),
			'hidden_count'     => count( $hidden ),
		];
	}

	/**
	 * Lazily resolve the page where each batch product renders.
	 * Faithful replica of the original full product_page_map: a product belongs
	 * to the segment (of its category) whose [products_from, products_to) range
	 * contains its rank (position by id ASC) within the category; category pages
	 * take priority over tree pages.
	 *
	 * @return array product_id => ['page_slug','page_type','category_slug']
	 */
	private static function resolve_batch_page_map( array $ctx, array $products ): array {
		global $wpdb;
		$table_p = $wpdb->prefix . 'aoe_catalog_products';

		$needed_cats = [];
		foreach ( $products as $p ) {
			$cid = (int) ( $p->category_id ?? 0 );
			if ( $cid && ! empty( $ctx['segments_by_cat'][ $cid ] ) ) {
				$needed_cats[ $cid ] = true;
			}
		}

		$id_ranks = [];
		foreach ( array_keys( $needed_cats ) as $cid ) {
			$ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT id FROM $table_p WHERE category_id = %d ORDER BY id ASC", $cid
			) );
			$id_ranks[ $cid ] = array_flip( array_map( 'intval', $ids ) );
		}

		$map = [];
		foreach ( $products as $p ) {
			$pid = (int) $p->id;
			$cid = (int) ( $p->category_id ?? 0 );
			if ( ! $cid || empty( $ctx['segments_by_cat'][ $cid ] ) ) {
				continue;
			}
			$rank = $id_ranks[ $cid ][ $pid ] ?? null;
			if ( null === $rank ) {
				continue;
			}
			$best = null;
			foreach ( $ctx['segments_by_cat'][ $cid ] as $seg ) {
				if ( $seg['from'] <= $rank && $rank < $seg['to'] ) {
					if ( 'category' === $seg['page_type'] ) {
						$best = $seg;
						break;
					}
					if ( null === $best ) {
						$best = $seg;
					}
				}
			}
			if ( $best ) {
				$map[ $pid ] = $best;
			}
		}
		return $map;
	}

	/**
	 * Fetch + process + upsert a single batch of products. Mutates $state.
	 */
	private static function run_single_batch( array $ctx, array &$state ): bool {
		global $wpdb;
		$table_p = $wpdb->prefix . 'aoe_catalog_products';

		$offset = (int) $state['offset'];
		$batch  = (int) $state['batch'];

		$products = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, sku, name, description, category_id, urls_images, url_pdf, additional_data
			 FROM $table_p WHERE manufacturer_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
			(int) $state['mfr_id'], $batch, $offset
		) );

		if ( empty( $products ) ) {
			$state['status'] = 'completed';
			return true;
		}

		$page_map = self::resolve_batch_page_map( $ctx, $products );

		$mfr_slug     = $state['mfr_slug'];
		$mfr_name     = $state['mfr_name'];
		$mfr_norm     = $state['mfr_norm'];
		$cat_map      = $ctx['cat_map'];
		$processor    = $ctx['processor'];
		$page_url_base = home_url( '/catalogo/' . $mfr_slug . '/' );

		$insert_rows = [];
		foreach ( $products as $p ) {
			$sku      = trim( $p->sku ?? '' );
			$name     = trim( $p->name ?? '' );
			$desc     = trim( $p->description ?? '' );
			$sku_norm = self::normalize_search( $sku );

			if ( '' === $sku_norm ) {
				continue;
			}

			$cat_id = (int) ( $p->category_id ?? 0 );
			if ( $cat_id ) {
				[ $cat_path, $cat_slug ] = self::build_category_path( $cat_id, $cat_map );
			} else {
				$cat_path = [];
				$cat_slug = '';
			}
			$cat_str = implode( ' > ', $cat_path );

			$images = [];
			$images_raw = json_decode( $p->urls_images ?? '[]', true );
			if ( is_array( $images_raw ) ) {
				$images = array_values( array_filter( $images_raw ) );
			}

			$raw_docs = json_decode( $p->url_pdf ?? '{}', true );
			if ( ! is_array( $raw_docs ) ) {
				$raw_docs = [];
			}

			$additional = json_decode( $p->additional_data ?? '{}', true );
			if ( ! is_array( $additional ) ) {
				$additional = [];
			}
			$specs = $additional['specs'] ?? [];

			if ( $processor ) {
				$docs = $processor->get_search_docs( $raw_docs, $additional );
			} else {
				$docs = self::classify_docs( $raw_docs );
			}

			$urls = [ 'catalog' => $page_url_base ];
			$pi   = $page_map[ (int) $p->id ] ?? null;
			if ( $pi ) {
				$page_href = home_url( '/catalogo/' . $pi['page_slug'] . '/' );
				if ( 'tree' === $pi['page_type'] ) {
					$anchor = '' !== ( $pi['category_slug'] ?? '' ) ? '#cat-' . $pi['category_slug'] : '';
					$urls['category'] = $page_href . $anchor;
				} else {
					$urls['category'] = $page_href;
				}
				$urls['product'] = $page_href . '#producto-' . $sku_norm;
			} elseif ( '' !== $cat_slug ) {
				$urls['category'] = home_url( '/catalogo/' . $mfr_slug . '/#cat-' . $cat_slug );
				$urls['product']  = home_url( '/catalogo/' . $mfr_slug . '/#producto-' . $sku_norm );
			}

			$search_text = implode( ' ', array_filter( [ $mfr_name, $sku, $name, $desc ] ) );

			$payload = [
				'name'          => $name,
				'description'   => $desc,
				'category_path' => $cat_str,
				'image_url'      => $images,
				'docs'          => $docs,
				'specs'         => $specs,
				'urls'          => $urls,
			];

			$insert_rows[] = [
				'manufacturer_normalized' => $mfr_norm,
				'manufacturer_name'       => $mfr_name,
				'sku_normalized'          => $sku_norm,
				'sku'                     => $sku,
				'search_text'             => $search_text,
				'payload_json'            => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'created_at'              => current_time( 'mysql' ),
				'updated_at'              => current_time( 'mysql' ),
			];
		}

		$errors = self::upsert_rows( $insert_rows );

		$state['count']  += count( $insert_rows );
		$state['offset'] += $batch;
		$state['errors'] = (int) ( $state['errors'] ?? 0 ) + $errors;
		if ( $state['offset'] >= (int) $state['total'] ) {
			$state['status'] = 'completed';
		}
		return true;
	}

	/**
	 * Chunked INSERT ... ON DUPLICATE KEY UPDATE. Returns the number of failed statements.
	 */
	private static function upsert_rows( array $rows ): int {
		global $wpdb;
		$table_search = $wpdb->prefix . 'aoe_catalog_search_products';

		if ( empty( $rows ) ) {
			return 0;
		}

		$errors = 0;
		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			$values = [];
			foreach ( $chunk as $row ) {
				$values[] = '(' .
					$wpdb->prepare( '%s', $row['manufacturer_normalized'] ) . ',' .
					$wpdb->prepare( '%s', $row['manufacturer_name'] ) . ',' .
					$wpdb->prepare( '%s', $row['sku_normalized'] ) . ',' .
					$wpdb->prepare( '%s', $row['sku'] ) . ',' .
					$wpdb->prepare( '%s', $row['search_text'] ) . ',' .
					$wpdb->prepare( '%s', $row['payload_json'] ) . ',' .
					$wpdb->prepare( '%s', $row['created_at'] ) . ',' .
					$wpdb->prepare( '%s', $row['updated_at'] ) .
				')';
			}
			$sql = "INSERT INTO $table_search (manufacturer_normalized, manufacturer_name, sku_normalized, sku, search_text, payload_json, created_at, updated_at) VALUES "
				. implode( ',', $values )
				. ' ON DUPLICATE KEY UPDATE
					manufacturer_name = VALUES(manufacturer_name),
					sku_normalized    = VALUES(sku_normalized),
					sku               = VALUES(sku),
					search_text       = VALUES(search_text),
					payload_json      = VALUES(payload_json),
					updated_at        = VALUES(updated_at)';
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$result = $wpdb->query( $sql );
			if ( false === $result ) {
				$errors++;
			}
		}
		return $errors;
	}
}
