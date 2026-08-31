<?php
/**
 * CLI: Build search index table for chatbot (Nina).
 *
 * Creates and populates wp_aoe_catalog_search_products with normalized SKU,
 * manufacturer, full-text search_text, and a payload_json containing the
 * full product data (name, description, category_path, images, docs, specs, page_urls).
 *
 * Usage:
 *   php tools/build-search-index.php --manufacturer=panduit
 *   php tools/build-search-index.php --all
 *   php tools/build-search-index.php                    (shows help)
 *
 * The script is independent of the import pipeline. Run it manually or
 * chain it after imports as needed.
 */

if ( PHP_SAPI !== 'cli' ) {
	die( "CLI only.\n" );
}

// ---- Parse args ----
$args = [];
foreach ( $argv as $arg ) {
	if ( 0 === strpos( $arg, '--' ) ) {
		$parts = explode( '=', substr( $arg, 2 ), 2 );
		$args[ $parts[0] ] = $parts[1] ?? true;
	}
}

ini_set( 'memory_limit', '1G' );
ob_implicit_flush( true );

if ( ! isset( $args['manufacturer'] ) && ! isset( $args['all'] ) && ! isset( $args['stats'] ) ) {
	echo <<<HELP
Usage:
  php tools/build-search-index.php --manufacturer=slug   Index one manufacturer
  php tools/build-search-index.php --all                 Index all manufacturers
  php tools/build-search-index.php --stats               Show table stats

HELP;
	exit( 0 );
}

// ---- Bootstrap WordPress ----
if ( getenv( 'AOE_DB_HOST' ) && ! defined( 'DB_HOST' ) ) {
	define( 'DB_HOST', getenv( 'AOE_DB_HOST' ) );
}
$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
if ( ! file_exists( $wp_load ) ) {
	$wp_load = dirname( __DIR__, 5 ) . '/wp-load.php';
}
if ( ! file_exists( $wp_load ) ) {
	die( "wp-load.php not found.\n" );
}
require_once $wp_load;

global $wpdb;

$table_search = $wpdb->prefix . 'aoe_catalog_search_products';
$table_p      = $wpdb->prefix . 'aoe_catalog_products';
$table_c      = $wpdb->prefix . 'aoe_catalog_categories';
$table_m      = $wpdb->prefix . 'aoe_catalog_manufacturers';
$table_pages  = $wpdb->prefix . 'aoe_catalog_pregenerated_pages';
$table_segs   = $wpdb->prefix . 'aoe_catalog_page_segments';

// ---- Create table if not exists ----
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

echo "Table $table_search ready.\n\n";

// Processor manager to reuse each manufacturer's field knowledge (PDF/3D CAD location).
$processor_manager = null;
if ( class_exists( '\AOE\CatalogEngine\Import\ProcessorManager' ) ) {
	$processor_manager = new \AOE\CatalogEngine\Import\ProcessorManager();
}

// ---- Stats mode ----
if ( isset( $args['stats'] ) ) {
	$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_search" );
	echo "Total indexed products: $total\n\n";
	$rows = $wpdb->get_results( "SELECT manufacturer_name, manufacturer_normalized, COUNT(*) AS cnt FROM $table_search GROUP BY manufacturer_normalized ORDER BY cnt DESC" );
	if ( $rows ) {
		echo sprintf( "%-30s %-20s %s\n", 'Manufacturer', 'Normalized', 'Count' );
		echo str_repeat( '-', 60 ) . "\n";
		foreach ( $rows as $r ) {
			echo sprintf( "%-30s %-20s %d\n", $r->manufacturer_name, $r->manufacturer_normalized, $r->cnt );
		}
	}
	exit( 0 );
}

// ---- Determine manufacturers to index ----
if ( isset( $args['all'] ) ) {
	$manufacturers = $wpdb->get_results( "SELECT id, slug, name FROM $table_m ORDER BY name ASC" );
} else {
	$slug = $args['manufacturer'];
	$manufacturers = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, slug, name FROM $table_m WHERE slug = %s", $slug
	) );
	if ( empty( $manufacturers ) ) {
		die( "Manufacturer not found: $slug\n" );
	}
}

// ---- Helpers ----
function normalize_search( string $text ): string {
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

function build_category_path( int $category_id, array $cat_map ): array {
	$path   = [];
	$leaf_slug = '';
	$current = $category_id;
	$safety = 0;
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

function classify_docs( array $raw_docs ): array {
	$CAD_EXT = [ 'dxf', 'dwg', 'stp', 'step', 'igs', 'iges', 'jt', 'stl', 'x_t', 'x_b' ];
	$CAD_KEYWORDS = '/\b(dxf|dwg|stp|step|iges?|jt|3d\s*model|3d\s*cad|cad\s*file|solidworks|autocad)\b/i';
	$result = [ 'pdfs' => [], '3dcad' => [] ];

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
			$ext = strtolower( pathinfo( parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
			$is_cad_ext   = in_array( $ext, $CAD_EXT, true );
			$is_cad_name  = (bool) preg_match( $CAD_KEYWORDS, $name );

			if ( $is_cad_ext || $is_cad_name ) {
				$result['3dcad'][] = [ 'url' => $url, 'name' => $name, 'ext' => $ext ];
			} else {
				$result['pdfs'][] = [ 'url' => $url, 'name' => $name ];
			}
		}
	}
	return $result;
}

// ---- Index each manufacturer ----
$total_indexed = 0;
foreach ( $manufacturers as $mfr ) {
	$mfr_id   = (int) $mfr->id;
	$mfr_slug = $mfr->slug;
	$mfr_name = $mfr->name;
	$mfr_norm = normalize_search( $mfr_name );

	// Processor instance (if available) to reuse per-manufacturer docs logic.
	$processor = null;
	if ( $processor_manager ) {
		$processor = $processor_manager->get_processor( $mfr_slug );
	}

	echo "=== $mfr_name ($mfr_slug) ===\n";

	// 1. [UPSERT] No DELETE: on each build we INSERT ... ON DUPLICATE KEY UPDATE.
	//    This preserves created_at (first index date) for existing rows and
	//    only refreshes updated_at + current fields.

	// 2. Load categories into memory (id => stdClass)
	$cat_rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, name, slug, parent_id FROM $table_c WHERE manufacturer_id = %d", $mfr_id
	) );
	$cat_map = [];
	foreach ( $cat_rows as $cr ) {
		$cat_map[ (int) $cr->id ] = $cr;
	}
	echo "  Categories: " . count( $cat_map ) . "\n";

	// 3. Load pages + segments to compute page URLs per product
	$page_rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, slug, page_number, type FROM $table_pages WHERE manufacturer_id = %d", $mfr_id
	) );
	$seg_rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT page_id, category_id, products_from, products_to FROM $table_segs WHERE page_id IN (
			SELECT id FROM $table_pages WHERE manufacturer_id = %d
		)", $mfr_id
	) );

	// Map: product_id => [page_slug, page_number, page_type, category_slug]
	$product_page_map = [];
	$page_info = [];
	foreach ( $page_rows as $pr ) {
		$page_info[ (int) $pr->id ] = [
			'page_slug'   => $pr->slug,
			'page_number' => (int) $pr->page_number,
			'page_type'   => $pr->type,
		];
	}
	// Map: category_id => its deepest leaf slug (for #cat- anchors)
	$cat_slug_map = [];
	foreach ( $cat_rows as $cr ) {
		$cat_slug_map[ (int) $cr->id ] = $cr->slug;
	}
	foreach ( $seg_rows as $sr ) {
		$pi = $page_info[ (int) $sr->page_id ] ?? null;
		if ( ! $pi ) continue;
		$cat_id = (int) $sr->category_id;
		$from   = (int) $sr->products_from;
		$to     = (int) $sr->products_to;
		$limit  = $to - $from;
		if ( $limit <= 0 || $cat_id <= 0 ) continue;
		$seg_info = $pi + [ 'category_slug' => $cat_slug_map[ $cat_id ] ?? '' ];
		$prod_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id FROM $table_p WHERE category_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
			$cat_id, $limit, $from
		) );
		foreach ( $prod_rows as $pr2 ) {
			$pid      = (int) $pr2->id;
			$existing = $product_page_map[ $pid ] ?? null;
			$new_type = $pi['page_type'];
			// Category-page mapping takes priority over tree-page.
			if ( $existing && 'category' === $existing['page_type'] ) {
				continue; // already on a category page — keep it
			}
			if ( $existing && 'tree' === $new_type ) {
				continue; // both tree — nothing to gain
			}
			$product_page_map[ $pid ] = $seg_info;
		}
	}
	echo "  Pages: " . count( $page_rows ) . " | Segments mapped products: " . count( $product_page_map ) . "\n";

	// 4. Load products in batches
	$offset   = 0;
	$batch    = 1000;
	$count    = 0;
	$page_url_base = home_url( '/catalogo/' . $mfr_slug . '/' );

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$total_products = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM $table_p WHERE manufacturer_id = %d", $mfr_id
	) );
	echo "  Products: $total_products\n";

	while ( $offset < $total_products ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$products = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, sku, name, description, category_id, urls_images, url_pdf, additional_data
			 FROM $table_p WHERE manufacturer_id = %d ORDER BY id ASC LIMIT $batch OFFSET $offset",
			$mfr_id
		) );

		if ( empty( $products ) ) {
			break;
		}

		$insert_rows = [];

		foreach ( $products as $p ) {
			$sku      = trim( $p->sku ?? '' );
			$name     = trim( $p->name ?? '' );
			$desc     = trim( $p->description ?? '' );
			$sku_norm = normalize_search( $sku );

			if ( '' === $sku_norm ) {
				continue;
			}

			// Category path + slug
			$cat_id   = (int) ( $p->category_id ?? 0 );
			if ( $cat_id ) {
				[ $cat_path, $cat_slug ] = build_category_path( $cat_id, $cat_map );
			} else {
				$cat_path = [];
				$cat_slug = '';
			}
			$cat_str  = implode( ' > ', $cat_path );

			// Images
			$images = [];
			$images_raw = json_decode( $p->urls_images ?? '[]', true );
			if ( is_array( $images_raw ) ) {
				$images = array_values( array_filter( $images_raw ) );
			}

			// PDFs / CAD (standardized: pdfs + 3dcad)
			$raw_docs = json_decode( $p->url_pdf ?? '{}', true );
			if ( ! is_array( $raw_docs ) ) {
				$raw_docs = [];
			}

			// Specs + additional data
			$additional = json_decode( $p->additional_data ?? '{}', true );
			if ( ! is_array( $additional ) ) {
				$additional = [];
			}
			$specs = $additional['specs'] ?? [];

			// Delegate docs extraction to the manufacturer's processor when possible
			if ( $processor ) {
				$docs = $processor->get_search_docs( $raw_docs, $additional );
			} else {
				$docs = classify_docs( $raw_docs );
			}

			// URLs — build the category URL from the page where the product
			// actually renders (category page URL, or tree page + #cat- anchor),
			// and the product URL as the same page anchored to the product's SKU row.
			$urls = [ 'catalog' => $page_url_base ];
			$pi = $product_page_map[ (int) $p->id ] ?? null;
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
			// No mapped page: fall back to base tree page + anchor if we have a slug.
			$urls['category'] = home_url( '/catalogo/' . $mfr_slug . '/#cat-' . $cat_slug );
			$urls['product'] = home_url( '/catalogo/' . $mfr_slug . '/#producto-' . $sku_norm );
		}

			// Build search_text
			$search_text = implode( ' ', array_filter( [ $mfr_name, $sku, $name, $desc ] ) );

			// Build payload_json
			$payload = [
				'name'            => $name,
				'description'     => $desc,
				'category_path'   => $cat_str,
				'image_url'       => $images,
				'docs'            => $docs,
				'specs'           => $specs,
				'urls'            => $urls,
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

		// Batch insert (raw SQL for speed) — UPSERT to preserve created_at
		if ( ! empty( $insert_rows ) ) {
			$chunks = array_chunk( $insert_rows, 500 );
			foreach ( $chunks as $chunk ) {
				$insert_cols = "INSERT INTO $table_search (manufacturer_normalized, manufacturer_name, sku_normalized, sku, search_text, payload_json, created_at, updated_at) VALUES ";
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
				$sql = $insert_cols . implode( ',', $values ) . ' ON DUPLICATE KEY UPDATE
					manufacturer_name = VALUES(manufacturer_name),
					sku_normalized     = VALUES(sku_normalized),
					sku                = VALUES(sku),
					search_text        = VALUES(search_text),
					payload_json       = VALUES(payload_json),
					updated_at         = VALUES(updated_at)';
				$result = $wpdb->query( $sql );
				if ( false === $result ) {
					echo "\n  SQL ERROR: " . $wpdb->last_error . "\n";
				}
			}
			$count += count( $insert_rows );
		}

		$offset += $batch;
		echo "  Indexed: $count / $total_products (batch OK, mem: " . round( memory_get_peak_usage( true ) / 1048576, 1 ) . "MB)\n";
		flush();
	}

	echo "\n  Done: $count products indexed.\n\n";
	$total_indexed += $count;
}

echo "=== Total indexed: $total_indexed products ===\n";
