<?php

namespace AOE\CatalogEngine\PublicFacing;

class PublicManager {

	public function __construct() {
		add_action( 'init', [ $this, 'register_rewrite_rules' ] );
		add_filter( 'query_vars', [ $this, 'register_query_vars' ] );
		add_action( 'pre_get_posts', [ $this, 'override_catalog_query' ] );
		add_filter( 'template_include', [ $this, 'load_catalog_templates' ] );
		add_action( 'template_redirect', [ $this, 'intercept_catalog_request' ], 1 );
		add_filter( 'redirect_canonical', [ $this, 'disable_redirect_for_catalog' ], 10, 2 );
		add_filter( 'pre_get_document_title', [ $this, 'set_catalog_title' ], 20 );
		add_action( 'wp_head', [ $this, 'output_catalog_meta' ], 1 );
		add_action( 'wp_head', [ $this, 'start_og_buffer' ], -1 );
		add_action( 'wp_head', [ $this, 'flush_og_buffer' ], PHP_INT_MAX );

		add_filter( 'rank_math/opengraph/url', [ $this, 'override_og_url' ], 20 );
		add_filter( 'rank_math/opengraph/title', [ $this, 'override_og_title' ], 20 );
		add_filter( 'rank_math/opengraph/description', [ $this, 'override_og_description' ], 20 );
		add_filter( 'rank_math/opengraph/image', [ $this, 'override_og_image' ], 9999 );
		add_filter( 'rank_math/opengraph/facebook/og_image', [ $this, 'override_og_image' ], 9999 );
		add_filter( 'rank_math/opengraph/facebook/og_image_secure_url', [ $this, 'override_og_image_secure_url' ], 9999 );
		add_filter( 'rank_math/opengraph/type', [ $this, 'override_og_type' ], 20 );
		add_filter( 'rank_math/json_ld', [ $this, 'override_json_ld' ], 20, 2 );
		// Sitemap provider desactivado durante pruebas. Activar al final:
		// add_filter( 'rank_math/sitemap/providers', [ $this, 'register_catalog_sitemap_provider' ] );
	}

	public function register_rewrite_rules() {
		// Root catalog page: /catalogo/
		add_rewrite_rule( '^catalogo/?$', 'index.php?aoe_catalog=root', 'top' );

		// Test preview
		add_rewrite_rule( '^catalogo/(test-[^/]+)/([^/]+)-([0-9]+)/?', 'index.php?aoe_catalog_preview=$matches[1]&aoe_catalog_category=$matches[2]&aoe_catalog_page=$matches[3]', 'top' );
		add_rewrite_rule( '^catalogo/(test-[^/]+)/([^/]+)/?', 'index.php?aoe_catalog_preview=$matches[1]&aoe_catalog_category=$matches[2]', 'top' );

		// Production: grouped pages  /samtec/productos/ and /samtec/productos-2/
		add_rewrite_rule( '^catalogo/([^/]+)/productos(?:-([0-9]+))?/?', 'index.php?aoe_catalog_manufacturer=$matches[1]&aoe_catalog_page=$matches[2]&aoe_catalog_type=grouped', 'top' );

		// Production: category paginated  /samtec/erf8-2/
		add_rewrite_rule( '^catalogo/([^/]+)/([^/]+)-([0-9]+)/?', 'index.php?aoe_catalog_manufacturer=$matches[1]&aoe_catalog_category=$matches[2]&aoe_catalog_page=$matches[3]', 'top' );

		// Production: category single  /samtec/erf8/
		add_rewrite_rule( '^catalogo/([^/]+)/([^/]+)/?', 'index.php?aoe_catalog_manufacturer=$matches[1]&aoe_catalog_category=$matches[2]', 'top' );

		// Tree paginated: /camdenboss-2/, /camdenboss-3/, etc.
		add_rewrite_rule( '^catalogo/([^/]+)-([0-9]+)/?', 'index.php?aoe_catalog_manufacturer=$matches[1]&aoe_catalog_page=$matches[2]', 'top' );

		// Tree index: /samtec/
		add_rewrite_rule( '^catalogo/([^/]+)/?', 'index.php?aoe_catalog_manufacturer=$matches[1]', 'top' );
	}

	public function register_query_vars( $vars ) {
		$vars[] = 'aoe_catalog';
		$vars[] = 'aoe_catalog_preview';
		$vars[] = 'aoe_catalog_manufacturer';
		$vars[] = 'aoe_catalog_category';
		$vars[] = 'aoe_catalog_page';
		$vars[] = 'aoe_catalog_type';
		return $vars;
	}

	public function override_catalog_query( $query ) {
		if ( ! is_admin() && $query->is_main_query() && (
			get_query_var( 'aoe_catalog' ) ||
			get_query_var( 'aoe_catalog_preview' ) ||
			get_query_var( 'aoe_catalog_manufacturer' )
		) ) {
			$query->is_404 = false;
			$query->is_home = false;
			status_header( 200 );
		}
	}

	public function load_catalog_templates( $template ) {
		// Root catalog page: /catalogo/
		if ( get_query_var( 'aoe_catalog' ) === 'root' ) {
			$view_path = __DIR__ . '/Views/catalog-index.php';
			if ( file_exists( $view_path ) ) {
				return $view_path;
			}
		}

		$manufacturer_slug = get_query_var( 'aoe_catalog_manufacturer' );
		if ( ! empty( $manufacturer_slug ) ) {
			$view_path = __DIR__ . '/Views/single-catalog.php';
			if ( file_exists( $view_path ) ) {
				return $view_path;
			}
		}

		$preview_slug = get_query_var( 'aoe_catalog_preview' );
		if ( ! empty( $preview_slug ) ) {
			$view_path = __DIR__ . '/Views/preview-catalog.php';
			if ( file_exists( $view_path ) ) {
				return $view_path;
			}
		}

		$catalog_slug = get_query_var( 'aoe_catalog' );
		if ( ! empty( $catalog_slug ) ) {
			$view_path = __DIR__ . '/Views/single-catalog.php';
			if ( file_exists( $view_path ) ) {
				return $view_path;
			}
		}
		return $template;
	}

	public function intercept_catalog_request() {
		if ( get_query_var( 'aoe_catalog' ) === 'root' || get_query_var( 'aoe_catalog_manufacturer' ) || get_query_var( 'aoe_catalog_preview' ) || get_query_var( 'aoe_catalog' ) ) {
			status_header( 200 );
			nocache_headers();
		}
	}

	public function disable_redirect_for_catalog( $redirect_url, $requested_url ) {
		if ( get_query_var( 'aoe_catalog_manufacturer' ) || get_query_var( 'aoe_catalog_preview' ) || get_query_var( 'aoe_catalog' ) ) {
			return false;
		}

		// Also check the request path directly, in case rewrite rules didn't match
		$path = trim( parse_url( $requested_url, PHP_URL_PATH ), '/' );
		if ( preg_match( '#^catalogo(?:/|/(test-[^/]+|[^/]+))#', $path ) ) {
			return false;
		}

		return $redirect_url;
	}

	/**
	 * Resolve SEO data for current catalog page.
	 * @return array{title: string, description: string}|null
	 */
	private function get_catalog_seo(): ?array {
		if ( get_query_var( 'aoe_catalog' ) === 'root' ) {
			return [
				'title'       => 'Catálogo de productos | TC Componentes',
				'description' => 'Catálogo completo de conectores y componentes electrónicos de Samtec, Amphenol, CamdenBoss y EDAC. Documentación técnica, especificaciones y soporte especializado.',
			];
		}

		$manufacturer_slug = get_query_var( 'aoe_catalog_manufacturer' );
		if ( empty( $manufacturer_slug ) ) {
			$preview = get_query_var( 'aoe_catalog_preview' );
			if ( ! empty( $preview ) ) {
				$manufacturer_slug = preg_replace( '/^test-/', '', $preview );
				$ts_pos = strrpos( $manufacturer_slug, '-' );
				if ( $ts_pos !== false ) {
					$manufacturer_slug = substr( $manufacturer_slug, 0, $ts_pos );
				}
			}
		}
		if ( empty( $manufacturer_slug ) ) {
			return null;
		}

		global $wpdb;
		$table_m = $wpdb->prefix . 'aoe_catalog_manufacturers';
		$mfr = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, name, wp_post_id, config_json FROM $table_m WHERE slug = %s",
			$manufacturer_slug
		) );
		if ( ! $mfr ) {
			return null;
		}

		$config = json_decode( $mfr->config_json ?? '', true ) ?: [];

		// Get template: per-manufacturer > global > hardcoded default
		$title_template = $config['seo_title_template'] ?? '';
		$desc_template  = $config['seo_description_template'] ?? '';

		if ( empty( $title_template ) ) {
			$title_template = get_option( 'aoe_catalog_seo_title_template', 'Catálogo de productos de {manufacturer}: TC Componentes' );
		}
		if ( empty( $desc_template ) ) {
			$desc_template = get_option( 'aoe_catalog_seo_description_template', 'TC Componentes es distribuidor de {manufacturer} en España. Catálogo completo de productos, documentación técnica y soporte técnico especializado.' );
		}

		$category_slug = get_query_var( 'aoe_catalog_category' );
		$page         = get_query_var( 'aoe_catalog_page' );
		$page_num     = ! empty( $page ) ? (int) $page : 0;
		$type         = get_query_var( 'aoe_catalog_type' );

		// Resolve category name from DB or slug
		$category_name = '';
		if ( ! empty( $category_slug ) ) {
			$table_c = $wpdb->prefix . 'aoe_catalog_categories';
			$cat_row = $wpdb->get_row( $wpdb->prepare(
				"SELECT name FROM $table_c WHERE slug = %s AND manufacturer_id = %d LIMIT 1",
				$category_slug, $mfr->id
			) );
			$category_name = $cat_row ? $cat_row->name : str_replace( '-', ' ', ucwords( $category_slug, '-' ) );
		}

		$category_label = '';
		if ( ! empty( $category_name ) && 'grouped' !== $type ) {
			$category_label = $category_name;
		}

		$replacements = [
			'{manufacturer}' => $mfr->name,
			'{category}'     => $category_label,
			'{page}'         => $page_num > 0 ? (string) $page_num : '',
		];

		$title = str_replace( array_keys( $replacements ), array_values( $replacements ), $title_template );
		$description = str_replace( array_keys( $replacements ), array_values( $replacements ), $desc_template );

		// Append category to title if not already in template via {category}
		if ( ! empty( $category_label ) && strpos( $title_template, '{category}' ) === false ) {
			$title .= ' | ' . $category_label;
		}

		if ( $page_num > 1 ) {
			$title .= ' - Página ' . $page_num;
		}

		return [ 'title' => $title, 'description' => $description ];
	}

	public function set_catalog_title( $title ) {
		$seo = $this->get_catalog_seo();
		if ( $seo ) {
			return $seo['title'];
		}
		return $title;
	}

	public function output_catalog_meta() {
		$seo = $this->get_catalog_seo();
		if ( ! $seo ) {
			return;
		}
		if ( ! empty( $seo['description'] ) ) {
			echo '<meta name="description" content="' . esc_attr( $seo['description'] ) . '" />' . "\n";
		}
	}

	public function start_og_buffer() {
		if ( ! $this->get_catalog_seo() ) {
			return;
		}
		ob_start();
	}

	public function flush_og_buffer() {
		if ( ! $this->get_catalog_seo() ) {
			return;
		}
		$html = ob_get_clean();
		if ( $html === false ) {
			return;
		}

		$seo  = $this->get_catalog_seo();
		$slug = get_query_var( 'aoe_catalog_manufacturer' );

		// Strip all OG tags — Rank Math and Avada both output them
		$html = preg_replace(
			'/<meta\s[^>]*property=["\']og:[a-z_:]+["\'][^>]*\/?>\s*\n?/i',
			'',
			$html
		);

		echo $html;

		// Output ONLY our OG tags
		echo "\n" . '<meta property="og:locale" content="es_ES" />';
		echo "\n" . '<meta property="og:type" content="website" />';
		if ( ! empty( $slug ) ) {
			$og_url = home_url( '/' . $slug . '/' );
		} else {
			$og_url = home_url( '/catalogo/' );
		}
		echo "\n" . '<meta property="og:url" content="' . esc_attr( $og_url ) . '" />';
		echo "\n" . '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . '" />';
		if ( ! empty( $seo['title'] ) ) {
			echo "\n" . '<meta property="og:title" content="' . esc_attr( $seo['title'] ) . '" />';
		}
		if ( ! empty( $seo['description'] ) ) {
			echo "\n" . '<meta property="og:description" content="' . esc_attr( $seo['description'] ) . '" />';
		}
		$img = content_url( 'uploads/tc-componentes-vr.webp' );
		echo "\n" . '<meta property="og:image" content="' . esc_attr( $img ) . '" />';
		echo "\n" . '<meta property="og:image:secure_url" content="' . esc_attr( $img ) . '" />';
		echo "\n" . '<meta property="og:image:width" content="428" />';
		echo "\n" . '<meta property="og:image:height" content="367" />';
		echo "\n";
	}

	public function override_og_url( $url ) {
		$manufacturer_slug = get_query_var( 'aoe_catalog_manufacturer' );
		if ( ! empty( $manufacturer_slug ) ) {
			return home_url( '/' . $manufacturer_slug . '/' );
		}
		if ( get_query_var( 'aoe_catalog' ) === 'root' ) {
			return home_url( '/catalogo/' );
		}
		return $url;
	}

	public function override_og_title( $title ) {
		$seo = $this->get_catalog_seo();
		if ( $seo ) {
			return $seo['title'];
		}
		return $title;
	}

	public function override_og_description( $description ) {
		$seo = $this->get_catalog_seo();
		if ( $seo && ! empty( $seo['description'] ) ) {
			return $seo['description'];
		}
		return $description;
	}

	public function override_og_image( $image ) {
		if ( $this->get_catalog_seo() ) {
			return content_url( 'uploads/tc-componentes-vr.webp' );
		}
		return $image;
	}

	public function override_og_image_secure_url( $url ) {
		if ( $this->get_catalog_seo() ) {
			return content_url( 'uploads/tc-componentes-vr.webp' );
		}
		return $url;
	}

	public function override_og_type( $type ) {
		if ( $this->get_catalog_seo() ) {
			return 'website';
		}
		return $type;
	}

	public function override_json_ld( $data, $context ) {
		if ( ! $this->is_catalog_page() ) {
			return $data;
		}

		global $wp, $wpdb;
		$current_url = home_url( $wp->request );

		// Resolve manufacturer data early so it's available everywhere
		$manufacturer_slug = get_query_var( 'aoe_catalog_manufacturer' );
		$mfr       = null;
		$mfr_name  = '';
		$tree_url  = '';
		if ( ! empty( $manufacturer_slug ) ) {
			$table_m  = $wpdb->prefix . 'aoe_catalog_manufacturers';
			$mfr      = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, name FROM $table_m WHERE slug = %s",
				$manufacturer_slug
			) );
			$mfr_name = $mfr ? $mfr->name : ucwords( str_replace( '-', ' ', $manufacturer_slug ) );
			$tree_url = home_url( '/catalogo/' . $manufacturer_slug . '/' );
		}

		$category_slug = get_query_var( 'aoe_catalog_category' );
		$type          = get_query_var( 'aoe_catalog_type' );

		// Fix WebPage URL
		if ( isset( $data['WebPage'] ) && empty( $data['WebPage']['url'] ) ) {
			$data['WebPage']['url'] = $current_url;
		}

		// Build breadcrumb items
		$items = [];
		$pos   = 1;

		$items[] = [
			'@type'    => 'ListItem',
			'position' => $pos++,
			'item'     => [
				'@id'  => home_url( '/' ),
				'name' => 'Inicio',
			],
		];

		if ( get_query_var( 'aoe_catalog' ) === 'root' ) {
			$items[] = [
				'@type'    => 'ListItem',
				'position' => $pos++,
				'item'     => [
					'@id'  => home_url( '/catalogo/' ),
					'name' => 'Catálogo',
				],
			];
		} elseif ( ! empty( $manufacturer_slug ) ) {
			$items[] = [
				'@type'    => 'ListItem',
				'position' => $pos++,
				'item'     => [
					'@id'  => home_url( '/catalogo/' ),
					'name' => 'Catálogo',
				],
			];

			$items[] = [
				'@type'    => 'ListItem',
				'position' => $pos++,
				'item'     => [
					'@id'  => $tree_url,
					'name' => $mfr_name,
				],
			];

			if ( ! empty( $category_slug ) && $mfr ) {
				$table_c = $wpdb->prefix . 'aoe_catalog_categories';
				$cat_row = $wpdb->get_row( $wpdb->prepare(
					"SELECT name, parent_id FROM $table_c WHERE slug = %s AND manufacturer_id = %d",
					$category_slug, $mfr->id
				) );
				$cat_name = $cat_row ? $cat_row->name : str_replace( '-', ' ', ucwords( $category_slug, '-' ) );

				if ( $cat_row && $cat_row->parent_id ) {
					$ancestor_names = [];
					$cur_parent     = (int) $cat_row->parent_id;
					while ( $cur_parent ) {
						$p_row = $wpdb->get_row( $wpdb->prepare(
							"SELECT name, parent_id FROM $table_c WHERE id = %d",
							$cur_parent
						) );
						if ( $p_row ) {
							array_unshift( $ancestor_names, $p_row->name );
							$cur_parent = (int) $p_row->parent_id;
						} else {
							break;
						}
					}
					foreach ( $ancestor_names as $a_name ) {
						$items[] = [
							'@type'    => 'ListItem',
							'position' => $pos++,
							'item'     => [
								'name' => $a_name,
							],
						];
					}
				}

				$items[] = [
					'@type'    => 'ListItem',
					'position' => $pos++,
					'item'     => [
						'@id'  => $current_url,
						'name' => $cat_name,
					],
				];
			} elseif ( 'grouped' === $type ) {
				$items[] = [
					'@type'    => 'ListItem',
					'position' => $pos++,
					'item'     => [
						'@id'  => $current_url,
						'name' => 'Productos',
					],
				];
			}
		}

		// Replace BreadcrumbList items
		if ( isset( $data['BreadcrumbList'] ) ) {
			$data['BreadcrumbList']['itemListElement'] = $items;
		}

		// Add manufacturer Organization for catalog pages
		if ( ! empty( $manufacturer_slug ) && $mfr ) {
			$data['manufacturer'] = [
				'@type' => 'Organization',
				'@id'   => $tree_url . '#manufacturer',
				'name'  => $mfr_name,
				'url'   => $tree_url,
			];

			if ( isset( $data['WebPage'] ) ) {
				$is_tree_page = empty( $category_slug ) && 'grouped' !== $type && get_query_var( 'aoe_catalog' ) !== 'root';

				if ( $is_tree_page ) {
					// Tree page – add CollectionPage + ItemList with visible categories
					$page_num = (int) get_query_var( 'aoe_catalog_page', 1 );
					$page_slug = $manufacturer_slug . ( $page_num > 1 ? '-' . $page_num : '' );
					$table_pages = $wpdb->prefix . 'aoe_catalog_pregenerated_pages';
					$table_seg   = $wpdb->prefix . 'aoe_catalog_page_segments';
					$table_cat   = $wpdb->prefix . 'aoe_catalog_categories';

					$tree_page = $wpdb->get_row( $wpdb->prepare(
						"SELECT id FROM $table_pages WHERE slug = %s", $page_slug
					) );

					if ( $tree_page ) {
						// Build cat_page_map first: for each category on this tree page, find its target page slug
						$cat_page_map = [];
						$cat_map_rows = $wpdb->get_results( $wpdb->prepare(
							"SELECT DISTINCT s2.category_id, p2.slug AS page_slug
							 FROM $table_seg s2
							 JOIN $table_pages p2 ON s2.page_id = p2.id
							 WHERE s2.manufacturer_id = %d AND p2.type IN ('category','grouped')",
							$mfr->id
						) );
						foreach ( $cat_map_rows as $cmr ) {
							$cat_page_map[ (int) $cmr->category_id ] = $cmr->page_slug;
						}

						$tree_items = $wpdb->get_results( $wpdb->prepare(
							"SELECT s.category_id, s.products_to, c.name AS category_name, c.slug AS category_slug, c.metadata_json
							 FROM $table_seg s
							 JOIN $table_cat c ON s.category_id = c.id
							 WHERE s.page_id = %d
							 ORDER BY s.sort_order ASC",
							$tree_page->id
						) );

						$list_items = [];
						$list_pos   = 1;
						foreach ( $tree_items as $ti ) {
							if ( (int) $ti->products_to === 0 ) continue;

							$meta = json_decode( $ti->metadata_json ?? '', true ) ?: [];
							$wp_post_id = ! empty( $meta['wp_post_id'] ) ? (int) $meta['wp_post_id'] : 0;

							if ( $wp_post_id ) {
								$item_url = get_permalink( $wp_post_id );
							} elseif ( isset( $cat_page_map[ (int) $ti->category_id ] ) ) {
								$item_url = home_url( '/catalogo/' . $cat_page_map[ (int) $ti->category_id ] . '/' );
							} else {
								$item_url = '';
							}

							$list_item = [
								'@type'    => 'ListItem',
								'position' => $list_pos++,
								'name'     => $ti->category_name,
							];
							if ( $item_url ) {
								$list_item['url'] = $item_url;
							}
							$list_items[] = $list_item;
						}

						if ( ! empty( $list_items ) ) {
							$current_types = isset( $data['WebPage']['@type'] ) ? (array) $data['WebPage']['@type'] : [ 'WebPage' ];
							if ( ! in_array( 'CollectionPage', $current_types, true ) ) {
								$current_types[] = 'CollectionPage';
							}
							$data['WebPage']['@type'] = $current_types;

							$itemlist_id = $current_url . '#itemlist';
							$data['ItemList'] = [
								'@type'           => 'ItemList',
								'@id'             => $itemlist_id,
								'url'             => $current_url,
								'name'            => $data['WebPage']['name'] ?? ( 'Catálogo de ' . $mfr_name ),
								'numberOfItems'   => (int) count( $list_items ),
								'itemListElement' => $list_items,
							];
							$data['WebPage']['mainEntity'] = [ '@id' => $itemlist_id ];
						}
					}
				} elseif ( ! empty( $category_slug ) || 'grouped' === $type ) {
					// Category or grouped page – add ItemList for products
					$itemlist_id = $current_url . '#itemlist';

					$page_num = (int) get_query_var( 'aoe_catalog_page', 1 );
					$page_slug = $manufacturer_slug . '/' . ( 'grouped' === $type ? 'productos' : $category_slug ) . ( $page_num > 1 ? '-' . $page_num : '' );
					$item_count = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT link_count FROM {$wpdb->prefix}aoe_catalog_pregenerated_pages WHERE slug = %s",
						$page_slug
					) );

					$data['ItemList'] = [
						'@type'         => 'ItemList',
						'@id'           => $itemlist_id,
						'url'           => $current_url,
						'name'          => $data['WebPage']['name'] ?? '',
						'numberOfItems' => $item_count,
					];

					$data['WebPage']['mainEntity'] = [ '@id' => $itemlist_id ];
				}
			}
		}

		return $data;
	}

	public function is_catalog_page(): bool {
		return get_query_var( 'aoe_catalog' ) === 'root'
			|| ! empty( get_query_var( 'aoe_catalog_manufacturer' ) )
			|| ! empty( get_query_var( 'aoe_catalog_preview' ) );
	}

	public function register_catalog_sitemap_provider( $providers ) {
		$providers[] = new CatalogSitemapProvider();
		return $providers;
	}
}
