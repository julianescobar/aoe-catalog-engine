<?php

namespace AOE\CatalogEngine\Import;

use AOE\CatalogEngine\Database\CategoryRepository;
use AOE\CatalogEngine\Database\ProductRepository;
use AOE\CatalogEngine\Database\PageRepository;
use AOE\CatalogEngine\Database\PageSegmentRepository;

class BatchProcessor {

	private $processor_manager;

	public function __construct( ProcessorManager $processor_manager ) {
		$this->processor_manager = $processor_manager;
		add_action( 'wp_ajax_aoe_process_batch', [ $this, 'ajax_process_batch' ] );
	}

	public function ajax_process_batch() {
		@set_time_limit( 0 );
		@ini_set( 'display_errors', '0' );
		if ( ob_get_length() ) {
			ob_clean();
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			$this->send_json_error( 'Acceso no autorizado' );
		}

		global $wpdb;

		$manufacturer_slug = sanitize_text_field( $_POST['manufacturer'] ?? '' );
		$import_mode       = sanitize_text_field( $_POST['import_mode'] ?? 'incremental' );
		$is_test           = intval( $_POST['is_test'] ?? 0 );
		$rows              = isset( $_POST['rows_json'] ) ? json_decode( wp_unslash( $_POST['rows_json'] ), true ) : [];

		$processor = $this->processor_manager->get_processor( $manufacturer_slug );
		if ( ! $processor ) {
			$this->send_json_error( 'Procesador no encontrado para el fabricante ' . $manufacturer_slug );
		}

		$table_manufacturers = $wpdb->prefix . 'aoe_catalog_manufacturers';
		$manufacturer = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM $table_manufacturers WHERE slug = %s",
			$manufacturer_slug
		) );

		if ( ! $manufacturer ) {
			$this->send_json_error( 'El fabricante no esta registrado en la base de datos.' );
		}

		if ( $is_test ) {
			$this->process_test_preview( $processor, $manufacturer, $manufacturer_slug, $rows );
			return;
		}

		$offset         = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;
		$is_first_chunk = ( 0 === $offset );
		$is_last_chunk  = ! empty( $_POST['is_last_chunk'] );

		if ( 'replace' === $import_mode && $is_first_chunk ) {
			$has_structure = (bool) get_transient( 'aoe_structure_' . $manufacturer->id );
			if ( $has_structure ) {
				$this->reimport_structure( $manufacturer );
			}
			ProductRepository::clear_by_manufacturer( $manufacturer->id );
			if ( ! $has_structure ) {
				CategoryRepository::clear_by_manufacturer( $manufacturer->id );
			}
		}

		$processed_count = 0;

		foreach ( $rows as $row ) {
			$normalized = $processor->process_row( $row );

			if ( empty( $normalized['sku'] ) ) {
				continue;
			}

			// Override category from sku_map if available (producto → codigo_serie mapping)
			$sku_map_table = $wpdb->prefix . 'aoe_catalog_sku_map';
			$mapped_codigo = $wpdb->get_var( $wpdb->prepare(
				"SELECT codigo_serie FROM $sku_map_table WHERE manufacturer_id = %d AND sku = %s",
				$manufacturer->id,
				$normalized['sku']
			) );
			if ( $mapped_codigo ) {
				$normalized['category'] = $mapped_codigo;
			}

			$category_path = ! empty( $normalized['category_path'] ) ? $normalized['category_path'] : [];
			if ( ! empty( $category_path ) ) {
				$parent_cat_id = null;
				foreach ( $category_path as $path_name ) {
					$parent_cat_id = CategoryRepository::find_or_create( $manufacturer->id, $path_name, 'category', $parent_cat_id );
				}
				$category_id = $parent_cat_id;
			} else {
				$category_name = ! empty( $normalized['category'] ) ? $normalized['category'] : 'Uncategorized';
				$category_id   = CategoryRepository::find_or_create( $manufacturer->id, $category_name );
			}

			$product_data = array_merge( $normalized, [
				'manufacturer_id' => $manufacturer->id,
				'category_id'     => $category_id,
			] );

			$product_id = ProductRepository::save( $product_data );

			if ( $product_id ) {
				if ( $product_id > 0 ) {
					CategoryRepository::increment_count( $category_id, 1 );
				}
				$processed_count++;
			}
		}

		if ( $is_last_chunk && $processed_count > 0 ) {
			$total_products = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}aoe_catalog_products WHERE manufacturer_id = %d",
				$manufacturer->id
			) );

			$this->pack_catalog( (int) $manufacturer->id, $manufacturer_slug, $processor );
			update_option( 'aoe_catalog_last_modified_' . $manufacturer_slug, time() );

			$this->add_log( 'Importacion Catalogo', $manufacturer->name, "Importacion completada. Modo: $import_mode. Total catalogo: $total_products." );
		}

		$this->send_json_success( [
			'processed' => $processed_count,
			'message'   => "Se procesaron $processed_count filas.",
		] );
	}

	private function process_test_preview( $processor, $manufacturer, string $manufacturer_slug, array $rows ) {
		$offset   = intval( $_POST['offset'] ?? 0 );
		$is_first = ( 0 === $offset );
		$is_last  = ! empty( $_POST['is_last_chunk'] );
		$per_page = 200;

		// First batch: set up slug and clean previous preview
		if ( $is_first ) {
			$previous_slug = get_option( 'aoe_preview_current_' . $manufacturer_slug );
			if ( ! empty( $previous_slug ) ) {
				delete_transient( 'aoe_preview_' . $previous_slug );
			}
			$test_slug      = 'test-' . $manufacturer_slug . '-' . gmdate( 'YmdHis' );
			$products       = [];
			$first_category = null;
		} else {
			$test_slug = get_option( 'aoe_preview_current_' . $manufacturer_slug );
			if ( ! $test_slug ) {
				$this->send_json_error( 'Error de sesion de prueba. Regenera la prueba desde cero.' );
			}
			$existing       = get_transient( 'aoe_preview_' . $test_slug );
			$products       = is_array( $existing['products'] ?? null ) ? $existing['products'] : [];
			$first_category = $existing['first_category'] ?? null;
		}

		// Process current batch — keep all products from all categories
		foreach ( $rows as $row ) {
			$normalized = $processor->process_row( $row );
			if ( empty( $normalized['sku'] ) ) {
				continue;
			}
			$cat = ! empty( $normalized['category'] ) ? $normalized['category'] : 'uncategorized';

			if ( $first_category === null ) {
				$first_category = $cat;
			}

			$products[] = [
				'sku'         => $normalized['sku'],
				'name'        => $normalized['name'],
				'category'    => $cat,
				'description' => $normalized['description'],
				'images'      => $normalized['images'],
				'pdf'         => $normalized['pdf'],
			];
		}

		$display_category = $first_category;

		// Store accumulated state
		$payload = [
			'manufacturer_slug' => $manufacturer_slug,
			'manufacturer_name' => $manufacturer->name,
			'test_slug'         => $test_slug,
			'first_category'    => $display_category,
			'template_post_id'  => intval( $manufacturer->wp_post_id ),
			'products'          => $products,
			'created_at'        => current_time( 'mysql' ),
		];
		set_transient( 'aoe_preview_' . $test_slug, $payload, 12 * HOUR_IN_SECONDS );
		update_option( 'aoe_preview_current_' . $manufacturer_slug, $test_slug, false );

		// Last batch: finalize and generate URL
		if ( $is_last ) {
			if ( empty( $products ) ) {
				delete_transient( 'aoe_preview_' . $test_slug );
				delete_option( 'aoe_preview_current_' . $manufacturer_slug );
				$this->send_json_error( 'No se encontraron productos validos para generar la prueba.' );
			}

			// Pick the category with the most products for the preview
			$cat_counts = [];
			foreach ( $products as $p ) {
				$cat_name = $p['category'] ?? 'uncategorized';
				if ( ! isset( $cat_counts[ $cat_name ] ) ) {
					$cat_counts[ $cat_name ] = 0;
				}
				$cat_counts[ $cat_name ]++;
			}
			arsort( $cat_counts );
			$display_category = key( $cat_counts );
			$products = array_values( array_filter( $products, function( $p ) use ( $display_category ) {
				return ( $p['category'] ?? '' ) === $display_category;
			} ) );

			// Update transient with filtered products
			$payload['products']       = $products;
			$payload['first_category'] = $display_category;
			set_transient( 'aoe_preview_' . $test_slug, $payload, 12 * HOUR_IN_SECONDS );

			$first_cat_slug   = sanitize_title( $display_category );
			$test_url         = home_url( '/catalogo/' . $test_slug . '/' . $first_cat_slug . '/' );
			$total            = count( $products );
			$total_pages      = max( 1, ceil( $total / $per_page ) );

			$test_pages = get_option( 'aoe_catalog_generated_pages', [] );
			foreach ( $test_pages as $slug => $page ) {
				if ( strpos( $slug, 'test-' . $manufacturer_slug ) === 0 ) {
					unset( $test_pages[ $slug ] );
				}
			}
			$test_pages[ $test_slug ] = [
				'url'            => $test_url,
				'type'           => 'prueba temporal',
				'manufacturer'   => $manufacturer->name,
				'products_count' => $total,
				'total_pages'    => $total_pages,
			];
			update_option( 'aoe_catalog_generated_pages', $test_pages );
			$this->refresh_preview_rewrite_rules();

			$this->add_log( 'Generacion de Prueba', $manufacturer->name, "Se genero una prueba temporal en: $test_url ($total_pages paginas)" );

			$this->send_json_success( [
				'processed'   => $total,
				'message'     => "Prueba generada con $total productos de la categoria '$display_category' en $total_pages paginas.",
				'test_url'    => $test_url,
				'total_pages' => $total_pages,
			] );
		} else {
			$this->send_json_success( [
				'processed' => count( $rows ),
				'message'   => 'Lote procesado (' . count( $rows ) . ' filas). Acumulados: ' . count( $products ) . ' productos de "' . $display_category . '".',
			] );
		}
	}

	private function send_json_success( array $data ) {
		if ( ob_get_length() ) {
			ob_clean();
		}

		wp_send_json_success( $data );
	}

	public function pack_catalog( int $manufacturer_id, string $manufacturer_slug, $processor = null ) {
		global $wpdb;

		// Clear previous cache and pages for this manufacturer
		\AOE\CatalogEngine\PublicFacing\CacheCatalog::invalidate( $manufacturer_slug );
		PageRepository::clear_by_manufacturer( $manufacturer_id );
		PageSegmentRepository::clear_by_manufacturer( $manufacturer_id );

		// Recalculate products_count from actual products
		$table_cat   = $wpdb->prefix . 'aoe_catalog_categories';
		$table_prod  = $wpdb->prefix . 'aoe_catalog_products';
		$wpdb->query( $wpdb->prepare(
			"UPDATE $table_cat c SET c.products_count = (
				SELECT COUNT(*) FROM $table_prod p WHERE p.category_id = c.id AND p.manufacturer_id = %d
			) WHERE c.manufacturer_id = %d",
			$manufacturer_id,
			$manufacturer_id
		) );

		$threshold = null !== $processor ? $processor->get_page_threshold() : 190;
		$per_page  = 200;

		// Get categories with products
		$categories = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, name, slug, products_count, description, metadata_json, image FROM $table_cat WHERE manufacturer_id = %d AND (products_count > 0 OR (description IS NOT NULL AND description != '') OR (metadata_json IS NOT NULL AND metadata_json != '[]' AND metadata_json != '{}') OR (image IS NOT NULL AND image != '')) ORDER BY id ASC",
			$manufacturer_id
		) );

		if ( empty( $categories ) ) {
			return;
		}

		// Separate large/categories-with-content and small categories
		$large = [];
		$small = [];
		foreach ( $categories as $cat ) {
			$meta_has_content = false;
			if ( ! empty( $cat->metadata_json ) && $cat->metadata_json !== '[]' && $cat->metadata_json !== '{}' ) {
				$meta = json_decode( $cat->metadata_json, true );
				if ( is_array( $meta ) ) {
					$meta_has_content = ! empty( $meta['features'] ) || ! empty( $meta['highlights'] ) || ! empty( $meta['specifications'] ) || ! empty( $meta['series_url'] ) || ! empty( $meta['image_url'] ) || ! empty( $meta['image_large'] );
				}
			}
			$has_content = ! empty( $cat->description ) || ! empty( $cat->image ) || $meta_has_content;
			if ( (int) $cat->products_count >= $threshold || $has_content ) {
				$large[] = $cat;
			} else {
				$small[] = $cat;
			}
		}

		// Large categories: one or more dedicated pages (type=category)
		foreach ( $large as $cat ) {
			$total_prods  = (int) $cat->products_count;
			$total_pages  = max( 1, ceil( $total_prods / $per_page ) );
			for ( $p = 1; $p <= $total_pages; $p++ ) {
				$page_slug = $manufacturer_slug . '/' . $cat->slug . ( $p > 1 ? '-' . $p : '' );
				$from      = ( $p - 1 ) * $per_page;
				$to        = min( $p * $per_page, $total_prods );
				$page_id   = PageRepository::insert( [
					'manufacturer_id' => $manufacturer_id,
					'type'            => 'category',
					'slug'            => $page_slug,
					'page_number'     => $p,
					'link_count'      => $to - $from,
				] );
				PageSegmentRepository::insert( [
					'page_id'        => $page_id,
					'manufacturer_id' => $manufacturer_id,
					'category_id'    => $cat->id,
					'segment_type'   => 'category',
					'products_from'  => $from,
					'products_to'    => $to,
					'sort_order'     => 1,
				] );
			}
		}

		// Tree pages: category index for /samtec/, /samtec-2/, etc.
		$all_names = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, name, slug, parent_id, level, products_count FROM $table_cat WHERE manufacturer_id = %d ORDER BY COALESCE(parent_id, 0) ASC, level ASC, id ASC",
			$manufacturer_id
		) );
		if ( ! empty( $all_names ) ) {
			// Build parent lookup
			$parent_lookup = [];
			foreach ( $all_names as $cat ) {
				$parent_lookup[ (int) $cat->id ] = (int) $cat->parent_id;
			}

			// Build cat_by_id lookup
			$cat_by_id = [];
			foreach ( $all_names as $cat ) {
				$cat_by_id[ (int) $cat->id ] = $cat;
			}

			// Separate level-4 items for Samtec-style pagination
			$level4_items = [];
			foreach ( $all_names as $cat ) {
				if ( (int) $cat->level === 4 ) {
					$level4_items[] = $cat;
				}
			}

			if ( ! empty( $level4_items ) ) {
				// New approach: batch level-4 items by 200, include all ancestors
				$level4_batches = array_chunk( $level4_items, 200 );

				$tree_page = 1;
				foreach ( $level4_batches as $batch ) {
					$unique_ancestor_ids = [];
					foreach ( $batch as $item ) {
						$cur = (int) $item->parent_id;
						while ( $cur ) {
							$unique_ancestor_ids[ $cur ] = true;
							$cur = $parent_lookup[ $cur ] ?? 0;
						}
					}

					$ancestors_ordered = [];
					foreach ( array_keys( $unique_ancestor_ids ) as $aid ) {
						if ( isset( $cat_by_id[ $aid ] ) ) {
							$ancestors_ordered[] = $cat_by_id[ $aid ];
						}
					}
					usort( $ancestors_ordered, function( $a, $b ) {
						$cmp = (int) $a->level - (int) $b->level;
						if ( $cmp !== 0 ) return $cmp;
						$pa = (int) ( $a->parent_id ?: 0 );
						$pb = (int) ( $b->parent_id ?: 0 );
						$cmp = $pa - $pb;
						if ( $cmp !== 0 ) return $cmp;
						return (int) $a->id - (int) $b->id;
					} );

					$tree_segments = [];

					foreach ( $ancestors_ordered as $acat ) {
						$tree_segments[] = [
							'manufacturer_id' => $manufacturer_id,
							'category_id'    => $acat->id,
							'segment_type'   => 'category',
							'products_from'  => 0,
							'products_to'    => (int) $acat->products_count,
							'sort_order'     => count( $tree_segments ) + 1,
						];
					}

					foreach ( $batch as $item ) {
						$tree_segments[] = [
							'manufacturer_id' => $manufacturer_id,
							'category_id'    => $item->id,
							'segment_type'   => 'category',
							'products_from'  => 0,
							'products_to'    => (int) $item->products_count,
							'sort_order'     => count( $tree_segments ) + 1,
						];
					}

					$link_count = count( $batch );
					$tree_slug  = $manufacturer_slug . ( $tree_page > 1 ? '-' . $tree_page : '' );
					$page_id    = PageRepository::insert( [
						'manufacturer_id' => $manufacturer_id,
						'type'            => 'tree',
						'slug'            => $tree_slug,
						'page_number'     => $tree_page,
						'link_count'      => $link_count,
					] );
					foreach ( $tree_segments as $seg ) {
						$seg['page_id'] = $page_id;
						PageSegmentRepository::insert( $seg );
					}

					$tree_page++;
				}
			} else {
				// Fallback: iterate all items sequentially (no level-4 categories)
				$tree_page     = 1;
				$tree_accum    = 0;
				$tree_segments = [];

				foreach ( $all_names as $cat ) {
					if ( $tree_accum >= 200 ) {
						$tree_slug = $manufacturer_slug . ( $tree_page > 1 ? '-' . $tree_page : '' );
						$page_id   = PageRepository::insert( [
							'manufacturer_id' => $manufacturer_id,
							'type'            => 'tree',
							'slug'            => $tree_slug,
							'page_number'     => $tree_page,
							'link_count'      => $tree_accum,
						] );
						foreach ( $tree_segments as $seg ) {
							$seg['page_id'] = $page_id;
							PageSegmentRepository::insert( $seg );
						}
						$tree_page++;
						$tree_accum    = 0;
						$tree_segments = [];
					}

					$tree_segments[] = [
						'manufacturer_id' => $manufacturer_id,
						'category_id'    => $cat->id,
						'segment_type'   => 'category',
						'products_from'  => 0,
						'products_to'    => (int) $cat->products_count,
						'sort_order'     => count( $tree_segments ) + 1,
					];
					$tree_accum++;
				}

				if ( ! empty( $tree_segments ) ) {
					$tree_slug = $manufacturer_slug . ( $tree_page > 1 ? '-' . $tree_page : '' );
					$page_id   = PageRepository::insert( [
						'manufacturer_id' => $manufacturer_id,
						'type'            => 'tree',
						'slug'            => $tree_slug,
						'page_number'     => $tree_page,
						'link_count'      => $tree_accum,
					] );
					foreach ( $tree_segments as $seg ) {
						$seg['page_id'] = $page_id;
						PageSegmentRepository::insert( $seg );
					}
				}
			}
		}

		// Small categories: pack into grouped pages
		if ( ! empty( $small ) ) {
			$group_page    = 1;
			$group_accum   = 0;
			$group_segments = [];
			$page_id        = null;

			foreach ( $small as $cat ) {
				$count = (int) $cat->products_count;
				if ( $group_accum + $count > $per_page && $group_accum > 0 ) {
					// Finalize current grouped page
					$page_slug = $manufacturer_slug . '/productos' . ( $group_page > 1 ? '-' . $group_page : '' );
					$page_id = PageRepository::insert( [
						'manufacturer_id' => $manufacturer_id,
						'type'            => 'grouped',
						'slug'            => $page_slug,
						'page_number'     => $group_page,
						'link_count'      => $group_accum,
					] );
					foreach ( $group_segments as $seg ) {
						$seg['page_id'] = $page_id;
						PageSegmentRepository::insert( $seg );
					}
					$group_page++;
					$group_accum    = 0;
					$group_segments = [];
				}
				$group_segments[] = [
					'manufacturer_id' => $manufacturer_id,
					'category_id'    => $cat->id,
					'segment_type'   => 'category',
					'products_from'  => 0,
					'products_to'    => $count,
					'sort_order'     => count( $group_segments ) + 1,
				];
				$group_accum += $count;
			}

			// Finalize last grouped page
			if ( ! empty( $group_segments ) ) {
				$page_slug = $manufacturer_slug . '/productos' . ( $group_page > 1 ? '-' . $group_page : '' );
				$page_id = PageRepository::insert( [
					'manufacturer_id' => $manufacturer_id,
					'type'            => 'grouped',
					'slug'            => $page_slug,
					'page_number'     => $group_page,
					'link_count'      => $group_accum,
				] );
				foreach ( $group_segments as $seg ) {
					$seg['page_id'] = $page_id;
					PageSegmentRepository::insert( $seg );
				}
			}
		}

		// Invalidate Rank Math sitemap cache after import
		$this->invalidate_rankmath_sitemap( $manufacturer_slug );
	}

	private function invalidate_rankmath_sitemap( $manufacturer_slug ) {
		$sitemap_type = 'catalogo-' . $manufacturer_slug;
		if ( class_exists( '\RankMath\Sitemap\Cache' ) && is_callable( [ '\RankMath\Sitemap\Cache', 'invalidate_storage' ] ) ) {
			\RankMath\Sitemap\Cache::invalidate_storage( $sitemap_type );
			\RankMath\Sitemap\Cache::invalidate_storage( '1' );
		} else {
			$upload_dir = wp_upload_dir();
			$cache_dir  = $upload_dir['basedir'] . '/rank-math';
			if ( is_dir( $cache_dir ) ) {
				array_map( 'unlink', glob( $cache_dir . '/*.xml' ) ?: [] );
				array_map( 'unlink', glob( $cache_dir . '/*.html' ) ?: [] );
			}
			delete_option( 'rank_math_sitemap_cache' );
		}
	}

	private function send_json_error( $data ) {
		if ( ob_get_length() ) {
			ob_clean();
		}

		wp_send_json_error( $data );
	}

	private function refresh_preview_rewrite_rules() {
		add_rewrite_rule( '^catalogo/(test-[^/]+)/([^/]+)-([0-9]+)/?', 'index.php?aoe_catalog_preview=$matches[1]&aoe_catalog_category=$matches[2]&aoe_catalog_page=$matches[3]', 'top' );
		add_rewrite_rule( '^catalogo/(test-[^/]+)/([^/]+)/?', 'index.php?aoe_catalog_preview=$matches[1]&aoe_catalog_category=$matches[2]', 'top' );
		flush_rewrite_rules( false );
	}

	private function reimport_structure( $manufacturer ) {
		global $wpdb;
		$table_c = $wpdb->prefix . 'aoe_catalog_categories';
		$mfr_id  = (int) $manufacturer->id;

		$saved = get_transient( 'aoe_structure_' . $mfr_id );
		if ( ! $saved ) {
			return;
		}

		$rows_json = json_decode( $saved, true );
		if ( empty( $rows_json ) ) {
			return;
		}

		// Clear existing categories
		$wpdb->delete( $table_c, [ 'manufacturer_id' => $mfr_id ], [ '%d' ] );

		$cat_node_map    = [];
		$subcat_node_map = [];

		// Pass 1: categories (Level 1)
		foreach ( $rows_json as $row ) {
			$type = trim( $row['type'] ?? '' );
			if ( 'category' !== $type ) { continue; }
			$node_key = trim( $row['node_key'] ?? '' );
			$name     = trim( $row['name'] ?? '' );
			if ( empty( $node_key ) || empty( $name ) ) { continue; }
			$wpdb->insert( $table_c, [
				'manufacturer_id' => $mfr_id, 'parent_id' => null,
				'name' => $name, 'slug' => sanitize_title( $name ),
				'type' => 'category', 'description' => trim( $row['description'] ?? '' ),
				'image' => trim( $row['image_url'] ?? '' ), 'level' => 1,
				'products_count' => 0, 'metadata_json' => json_encode( [] ),
			], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ] );
			$cat_node_map[ $node_key ] = (int) $wpdb->insert_id;
		}

		// Pass 2: subcategories (Level 2)
		foreach ( $rows_json as $row ) {
			$type = trim( $row['type'] ?? '' );
			if ( 'subcategory' !== $type ) { continue; }
			$node_key   = trim( $row['node_key'] ?? '' );
			$parent_key = trim( $row['parent_key'] ?? '' );
			$name       = trim( $row['name'] ?? '' );
			if ( empty( $node_key ) || empty( $name ) || ! isset( $cat_node_map[ $parent_key ] ) ) { continue; }
			$wpdb->insert( $table_c, [
				'manufacturer_id' => $mfr_id, 'parent_id' => $cat_node_map[ $parent_key ],
				'name' => $name, 'slug' => sanitize_title( $name ),
				'type' => 'category', 'description' => trim( $row['description'] ?? '' ),
				'image' => trim( $row['image_url'] ?? '' ), 'level' => 2,
				'products_count' => 0, 'metadata_json' => json_encode( [] ),
			], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ] );
			$subcat_node_map[ $node_key ] = (int) $wpdb->insert_id;
		}

		// Pass 3: series (Level 3)
		foreach ( $rows_json as $row ) {
			$type = trim( $row['type'] ?? '' );
			if ( 'series' !== $type ) { continue; }
			$node_key   = trim( $row['node_key'] ?? '' );
			$parent_key = trim( $row['parent_key'] ?? '' );
			$series_id  = trim( $row['series_id'] ?? '' );
			$name       = trim( $row['name'] ?? '' );
			if ( empty( $node_key ) || empty( $name ) ) { continue; }
			$parent_id = isset( $subcat_node_map[ $parent_key ] ) ? $subcat_node_map[ $parent_key ] : null;
			if ( null === $parent_id ) {
				$parent_id = isset( $cat_node_map[ $parent_key ] ) ? $cat_node_map[ $parent_key ] : null;
			}
			$slug     = sanitize_title( $name );
			$metadata = [
				'series_id' => $series_id, 'series_url' => trim( $row['series_url'] ?? '' ),
				'image_url' => trim( $row['image_url'] ?? '' ), 'image_large' => trim( $row['image_large_url'] ?? '' ),
				'highlights' => trim( $row['highlights'] ?? '' ), 'features' => trim( $row['features'] ?? '' ),
				'specifications' => trim( $row['specifications'] ?? '' ),
			];
			$wpdb->insert( $table_c, [
				'manufacturer_id' => $mfr_id, 'parent_id' => $parent_id,
				'name' => $name, 'slug' => $slug,
				'description' => trim( $row['description'] ?? '' ), 'type' => 'series',
				'image' => trim( $row['image_url'] ?? '' ), 'level' => 3,
				'products_count' => 0, 'metadata_json' => json_encode( $metadata ),
			], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ] );
		}
	}

	private function add_log( string $event, string $manufacturer, string $details ) {
		$logs = get_option( 'aoe_catalog_import_logs', [] );
		array_unshift( $logs, [
			'date'         => current_time( 'mysql' ),
			'event'        => $event,
			'manufacturer' => $manufacturer,
			'details'      => $details,
		] );

		$logs = array_slice( $logs, 0, 100 );
		update_option( 'aoe_catalog_import_logs', $logs );
	}
}
