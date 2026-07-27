<?php

namespace AOE\CatalogEngine\Admin;

use AOE\CatalogEngine\Import\ProcessorManager;

class AdminManager {

	private $processor_manager;

	public function __construct( ProcessorManager $processor_manager ) {
		$this->processor_manager = $processor_manager;
		add_action( 'admin_menu', [ $this, 'add_plugin_admin_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_styles_scripts' ] );
		add_action( 'admin_init', [ $this, 'handle_manufacturer_crud' ] );
		add_action( 'admin_post_aoe_export_media_txt', [ $this, 'handle_export_media_txt' ] );
		add_action( 'wp_ajax_aoe_clear_cache', [ $this, 'ajax_clear_cache' ] );
		add_action( 'wp_ajax_aoe_regenerate_pages', [ $this, 'ajax_regenerate_pages' ] );
		add_action( 'wp_ajax_aoe_generate_template_cache', [ $this, 'ajax_generate_template_cache' ] );
		add_action( 'wp_ajax_aoe_clear_all_cache', [ $this, 'ajax_clear_all_cache' ] );
		add_action( 'wp_ajax_aoe_import_structure', [ $this, 'ajax_import_structure' ] );
		add_action( 'wp_ajax_aoe_import_samtec_categories', [ $this, 'ajax_import_samtec_categories' ] );
		add_action( 'wp_ajax_aoe_import_samtec_specs', [ $this, 'ajax_import_samtec_specs' ] );
		add_action( 'wp_ajax_aoe_import_bivar_categories', [ $this, 'ajax_import_bivar_categories' ] );
		add_action( 'save_post', [ $this, 'invalidate_cache_on_template_save' ], 10, 2 );
	}

	public function add_plugin_admin_menu() {
		// Main Menu: Catálogo
		add_menu_page(
			'Catálogo AOE',
			'Catálogo AOE',
			'manage_options',
			'aoe-catalog-engine',
			[ $this, 'display_catalog_pages' ],
			'dashicons-database',
			30
		);

		// Submenu 1: Fabricantes (re-pointing or registered separately)
		add_submenu_page(
			'aoe-catalog-engine',
			'Fabricantes',
			'Fabricantes',
			'manage_options',
			'aoe-catalog-manufacturers',
			[ $this, 'display_manufacturers_page' ]
		);

		// Submenu 2: Logs
		add_submenu_page(
			'aoe-catalog-engine',
			'Logs',
			'Logs',
			'manage_options',
			'aoe-catalog-logs',
			[ $this, 'display_logs_page' ]
		);

		// Hidden page: Media Validator (accessible via URL, not shown in menu)
		add_submenu_page(
			null,
			'Validar Media',
			'Validar Media',
			'manage_options',
			'aoe-catalog-media-validator',
			[ $this, 'display_media_validator_page' ]
		);
	}

	/**
	 * Display Catalog generated pages
	 */
	public function display_catalog_pages() {
		if ( isset( $_POST['save_aoe_root_template'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'aoe_root_template' ) ) {
			update_option( 'aoe_catalog_root_template_post_id', intval( $_POST['root_template_post_id'] ?? 0 ) );
			echo '<div class="notice notice-success"><p>Plantilla raíz guardada.</p></div>';
		}
		require_once __DIR__ . '/Views/catalog-list.php';
	}

	/**
	 * Display Manufacturers list page or CRUD Form
	 */
	public function display_manufacturers_page() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'aoe_catalog_manufacturers';

		$action = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : '';

		if ( 'add' === $action || 'edit' === $action ) {
			if ( 'edit' === $action ) {
				$id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;
				$manufacturer = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE id = %d", $id ) );
			}
			require_once __DIR__ . '/Views/manufacturer-form.php';
			return;
		}

		if ( 'import' === $action ) {
			$id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;
			$manufacturer = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE id = %d", $id ) );
			if ( $manufacturer ) {
				$processor = $this->processor_manager->get_processor( $manufacturer->slug );
				$supported_columns = [];
				if ( $processor ) {
					$supported_columns = $processor->get_supported_columns();
				}
				require_once __DIR__ . '/Views/import-page.php';
				return;
			}
		}

		// Default view: list manufacturers
		$manufacturers = $wpdb->get_results( "SELECT * FROM $table_name ORDER BY name ASC" );
		require_once __DIR__ . '/Views/manufacturers-list.php';
	}

	public function display_logs_page() {
		$logs = get_option( 'aoe_catalog_import_logs', [] );
		require_once __DIR__ . '/Views/logs-list.php';
	}

	/**
	 * Handle CRUD save and delete requests for manufacturers
	 */
	public function handle_export_media_txt() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Acceso no autorizado' );
		}
		if ( ! isset( $_GET['manufacturer'] ) || empty( $_GET['manufacturer'] ) ) {
			wp_die( 'Fabricante no especificado' );
		}

		global $wpdb;
		$mfr_slug = sanitize_text_field( $_GET['manufacturer'] );
		$table_m  = $wpdb->prefix . 'aoe_catalog_manufacturers';
		$table_p  = $wpdb->prefix . 'aoe_catalog_products';
		$table_c  = $wpdb->prefix . 'aoe_catalog_categories';

		$mfr = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_m WHERE slug = %s", $mfr_slug ) );
		if ( ! $mfr ) {
			wp_die( 'Fabricante no encontrado' );
		}

		$products = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.*, c.name AS category_name FROM $table_p p LEFT JOIN $table_c c ON p.category_id = c.id WHERE p.manufacturer_id = %d ORDER BY p.sku ASC",
			$mfr->id
		) );

		$upload_dir = wp_upload_dir();
		$base_dir   = $upload_dir['basedir'] . '/catalogo/' . $mfr_slug;

		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="media-faltantes-' . $mfr_slug . '.txt"' );

		echo "SKU\tNombre\tCategoria\tImagenes faltantes\tPDFs faltantes\r\n";

		foreach ( $products as $prod ) {
			$images = (array) ( json_decode( $prod->urls_images ?? '[]', true ) ?: [] );
			$pdfs   = (array) ( json_decode( $prod->url_pdf ?? '[]', true ) ?: [] );

			$missing_img = [];
			foreach ( $images as $img ) {
				if ( ! preg_match( '#^https?://#i', $img ) && ! file_exists( $base_dir . '/images/' . $img ) ) {
					$missing_img[] = $img;
				}
			}
			$missing_pdf = [];
			foreach ( $pdfs as $key => $url ) {
				if ( ! preg_match( '#^https?://#i', $url ) && ! file_exists( $base_dir . '/pdfs/' . $url ) ) {
					$missing_pdf[] = $url;
				}
			}

			if ( empty( $missing_img ) && empty( $missing_pdf ) ) {
				continue;
			}

			echo $prod->sku . "\t" . $prod->name . "\t" . ( $prod->category_name ?? '-' ) . "\t" . implode( ', ', $missing_img ) . "\t" . implode( ', ', $missing_pdf ) . "\r\n";
		}

		exit;
	}

	public function handle_manufacturer_crud() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'aoe_catalog_manufacturers';

		// Handle Delete Action
		if ( isset( $_GET['page'] ) && 'aoe-catalog-manufacturers' === $_GET['page'] && isset( $_GET['action'] ) && 'delete' === $_GET['action'] ) {
			$id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;
			if ( $id && wp_verify_nonce( $_GET['_wpnonce'], 'delete_manufacturer_' . $id ) ) {
				$wpdb->delete( $table_name, [ 'id' => $id ], [ '%d' ] );
				wp_safe_redirect( admin_url( 'admin.php?page=aoe-catalog-manufacturers&message=deleted' ) );
				exit;
			}
		}

		// Handle Save Action
		if ( isset( $_POST['save_manufacturer'] ) ) {
			if ( ! isset( $_POST['aoe_manufacturer_nonce'] ) || ! wp_verify_nonce( $_POST['aoe_manufacturer_nonce'], 'save_manufacturer_action' ) ) {
				wp_die( 'Security check failed' );
			}

			$id = isset( $_POST['manufacturer_id'] ) ? intval( $_POST['manufacturer_id'] ) : 0;
			$name = sanitize_text_field( $_POST['name'] );
			$slug = sanitize_title( $_POST['slug'] );
			$wp_post_id = intval( $_POST['wp_post_id'] );

			// Merge SEO templates into config_json
			if ( $id ) {
				$existing = $wpdb->get_var( $wpdb->prepare( "SELECT config_json FROM $table_name WHERE id = %d", $id ) );
				$config = json_decode( $existing ?? '', true ) ?: [];
			} else {
				$config = [];
			}
			unset( $config['seo_title_template'], $config['seo_description_template'] );
			$config['tree_layout'] = in_array( $_POST['tree_layout'] ?? '', [ 'normal', 'columns', 'table_desc' ] ) ? $_POST['tree_layout'] : 'normal';
			$config['tree_columns'] = min( 8, max( 2, intval( $_POST['tree_columns'] ?? 4 ) ) );
			$config['media_source'] = in_array( $_POST['media_source'] ?? '', [ 'remote', 'local' ] ) ? $_POST['media_source'] : 'local';

			$data = [
				'name'        => $name,
				'slug'        => $slug,
				'wp_post_id'  => $wp_post_id,
				'config_json' => json_encode( $config, JSON_UNESCAPED_UNICODE ),
			];

			if ( $id ) {
				$wpdb->update( $table_name, $data, [ 'id' => $id ], [ '%s', '%s', '%d', '%s' ], [ '%d' ] );
			} else {
				$wpdb->insert( $table_name, $data, [ '%s', '%s', '%d', '%s' ] );
			}

			wp_safe_redirect( admin_url( 'admin.php?page=aoe-catalog-manufacturers' ) );
			exit;
		}
	}

	public function ajax_import_structure() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Acceso no autorizado' );
		}

		global $wpdb;
		$manufacturer_slug = sanitize_text_field( $_POST['manufacturer'] ?? '' );
		$rows_json         = isset( $_POST['rows_json'] ) ? json_decode( wp_unslash( $_POST['rows_json'] ), true ) : [];

		if ( empty( $manufacturer_slug ) || empty( $rows_json ) ) {
			wp_send_json_error( 'Datos incompletos' );
		}

		$table_m = $wpdb->prefix . 'aoe_catalog_manufacturers';
		$table_c = $wpdb->prefix . 'aoe_catalog_categories';

		$manufacturer = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_m WHERE slug = %s", $manufacturer_slug ) );
		if ( ! $manufacturer ) {
			wp_send_json_error( 'Fabricante no encontrado' );
		}

		$mfr_id = (int) $manufacturer->id;

		// Clear existing categories for this manufacturer to allow re-import
		$wpdb->delete( $table_c, [ 'manufacturer_id' => $mfr_id ], [ '%d' ] );

		$cat_node_map   = []; // node_key → category id
		$subcat_node_map = []; // node_key → subcategory id
		$created_series  = 0;

		// First pass: categories (Level 1)
		foreach ( $rows_json as $row ) {
			$type = trim( $row['type'] ?? '' );
			if ( 'category' !== $type ) {
				continue;
			}
			$node_key = trim( $row['node_key'] ?? '' );
			$name     = trim( $row['name'] ?? '' );
			if ( empty( $node_key ) || empty( $name ) ) {
				continue;
			}

			$slug = sanitize_title( $name );
			$wpdb->insert( $table_c, [
				'manufacturer_id' => $mfr_id,
				'parent_id'       => null,
				'name'            => $name,
				'slug'            => $slug,
				'type'            => 'category',
				'description'     => trim( $row['description'] ?? '' ),
				'image'           => trim( $row['image_url'] ?? '' ),
				'level'           => 1,
				'products_count'  => 0,
				'metadata_json'   => json_encode( [] ),
			], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ] );
			$cat_node_map[ $node_key ] = (int) $wpdb->insert_id;
		}

		// Second pass: subcategories (Level 2)
		foreach ( $rows_json as $row ) {
			$type = trim( $row['type'] ?? '' );
			if ( 'subcategory' !== $type ) {
				continue;
			}
			$node_key   = trim( $row['node_key'] ?? '' );
			$parent_key = trim( $row['parent_key'] ?? '' );
			$name       = trim( $row['name'] ?? '' );
			if ( empty( $node_key ) || empty( $name ) || ! isset( $cat_node_map[ $parent_key ] ) ) {
				continue;
			}

			$slug = sanitize_title( $name );
			$wpdb->insert( $table_c, [
				'manufacturer_id' => $mfr_id,
				'parent_id'       => $cat_node_map[ $parent_key ],
				'name'            => $name,
				'slug'            => $slug,
				'type'            => 'category',
				'description'     => trim( $row['description'] ?? '' ),
				'image'           => trim( $row['image_url'] ?? '' ),
				'level'           => 2,
				'products_count'  => 0,
				'metadata_json'   => json_encode( [] ),
			], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ] );
			$subcat_node_map[ $node_key ] = (int) $wpdb->insert_id;
		}

		// Third pass: series (Level 3)
		foreach ( $rows_json as $row ) {
			$type = trim( $row['type'] ?? '' );
			if ( 'series' !== $type ) {
				continue;
			}
			$node_key   = trim( $row['node_key'] ?? '' );
			$parent_key = trim( $row['parent_key'] ?? '' );
			$series_id  = trim( $row['series_id'] ?? '' );
			$name       = trim( $row['name'] ?? '' );
			if ( empty( $node_key ) || empty( $name ) ) {
				continue;
			}

			$parent_id = isset( $subcat_node_map[ $parent_key ] ) ? $subcat_node_map[ $parent_key ] : null;
			if ( null === $parent_id ) {
				// Fall back to category-level parent
				$parent_id = isset( $cat_node_map[ $parent_key ] ) ? $cat_node_map[ $parent_key ] : null;
			}

			$slug = sanitize_title( $name );
			$metadata = [
				'series_id'     => $series_id,
				'series_url'    => trim( $row['series_url'] ?? '' ),
				'image_url'     => trim( $row['image_url'] ?? '' ),
				'image_large'   => trim( $row['image_large_url'] ?? '' ),
				'highlights'    => trim( $row['highlights'] ?? '' ),
				'features'      => trim( $row['features'] ?? '' ),
				'specifications' => trim( $row['specifications'] ?? '' ),
			];

			$wpdb->insert( $table_c, [
				'manufacturer_id' => $mfr_id,
				'parent_id'       => $parent_id,
				'name'            => $name,
				'slug'            => $slug,
				'type'            => 'series',
				'description'     => trim( $row['description'] ?? '' ),
				'image'           => trim( $row['image_url'] ?? '' ),
				'level'           => 3,
				'products_count'  => 0,
				'metadata_json'   => json_encode( $metadata ),
			], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ] );

			if ( $wpdb->insert_id ) {
				$created_series++;
			}
		}

		// Save structure data in transient so Replace mode can re-import it
		set_transient( 'aoe_structure_' . $mfr_id, $_POST['rows_json'], WEEK_IN_SECONDS );

		$total_categories = count( $cat_node_map );
		$total_subcategories = count( $subcat_node_map );

		wp_send_json_success( [
			'message' => sprintf(
				'Estructura importada: %d categorías, %d subcategorías, %d series.',
				$total_categories,
				$total_subcategories,
				$created_series
			),
		] );
	}

	public function ajax_import_samtec_categories() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Acceso no autorizado' );
		}

		global $wpdb;
		$manufacturer_slug = sanitize_text_field( $_POST['manufacturer'] ?? '' );
		$rows_json         = isset( $_POST['rows_json'] ) ? json_decode( wp_unslash( $_POST['rows_json'] ), true ) : [];

		if ( empty( $manufacturer_slug ) || empty( $rows_json ) ) {
			wp_send_json_error( 'Datos incompletos' );
		}

		$table_m  = $wpdb->prefix . 'aoe_catalog_manufacturers';
		$table_c  = $wpdb->prefix . 'aoe_catalog_categories';
		$table_p  = $wpdb->prefix . 'aoe_catalog_products';

		$manufacturer = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_m WHERE slug = %s", $manufacturer_slug ) );
		if ( ! $manufacturer ) {
			wp_send_json_error( 'Fabricante no encontrado' );
		}

		$mfr_id = (int) $manufacturer->id;

		$cat_map    = [];
		$subcat_map = [];
		$serie_map  = [];
		$stats      = [ 'categorias' => 0, 'subcategorias' => 0, 'series' => 0, 'productos' => 0 ];

		// Clean up previous CSV-imported categories before re-importing
		$wpdb->delete( $table_c, [ 'manufacturer_id' => $mfr_id, 'level' => 1 ], [ '%d', '%d' ] );
		$wpdb->delete( $table_c, [ 'manufacturer_id' => $mfr_id, 'level' => 2 ], [ '%d', '%d' ] );
		$wpdb->delete( $table_c, [ 'manufacturer_id' => $mfr_id, 'level' => 3 ], [ '%d', '%d' ] );
		$wpdb->delete( $table_c, [ 'manufacturer_id' => $mfr_id, 'slug' => 'sin-clasificar' ], [ '%d', '%s' ] );
		// Reset any previously-updated level 4 categories back to level 0
		$wpdb->query( $wpdb->prepare(
			"UPDATE $table_c SET level = 0, parent_id = NULL, type = 'category' WHERE manufacturer_id = %d AND level = 4",
			$mfr_id
		) );

		// Pass 1: categorias (level 1)
		foreach ( $rows_json as $row ) {
			if ( trim( $row['tipo'] ?? '' ) !== 'categoria' ) {
				continue;
			}
			$slug = sanitize_title( trim( $row['categoria'] ?? '' ) );
			if ( empty( $slug ) ) {
				continue;
			}
			$name   = trim( $row['nombre'] ?? '' ) ?: $slug;
			$titulo = trim( $row['titulo'] ?? '' );
			$desc   = trim( $row['descripcion'] ?? '' );
			$feats  = trim( $row['caracteristicas'] ?? '' );
			$img    = trim( $row['imagen'] ?? '' );

			$existing = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM $table_c WHERE manufacturer_id = %d AND slug = %s AND parent_id IS NULL AND type = 'category'",
				$mfr_id, $slug
			) );

			if ( $existing ) {
				$wpdb->update( $table_c, [
					'name'          => $name,
					'description'   => $desc,
					'image'         => $img,
					'level'         => 1,
					'metadata_json' => json_encode( [
						'titulo'   => $titulo,
						'features' => $feats,
					] ),
				], [ 'id' => $existing ] );
				$cat_map[ $slug ] = (int) $existing;
			} else {
				$wpdb->insert( $table_c, [
					'manufacturer_id' => $mfr_id,
					'parent_id'       => null,
					'name'            => $name,
					'slug'            => $slug,
					'type'            => 'category',
					'description'     => $desc,
					'image'           => $img,
					'level'           => 1,
					'products_count'  => 0,
					'metadata_json'   => json_encode( [
						'titulo'   => $titulo,
						'features' => $feats,
					] ),
				], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ] );
				$cat_map[ $slug ] = (int) $wpdb->insert_id;
			}
			$stats['categorias']++;
		}

		// Pass 2: subcategorias (level 2)
		foreach ( $rows_json as $row ) {
			if ( trim( $row['tipo'] ?? '' ) !== 'subcategoria' ) {
				continue;
			}
			$cat_slug = sanitize_title( trim( $row['categoria'] ?? '' ) );
			$sub_slug = sanitize_title( trim( $row['subcategoria'] ?? '' ) );
			if ( empty( $sub_slug ) || ! isset( $cat_map[ $cat_slug ] ) ) {
				continue;
			}
			$parent_id = $cat_map[ $cat_slug ];
			$name      = trim( $row['nombre'] ?? '' ) ?: $sub_slug;
			$desc      = trim( $row['descripcion'] ?? '' );
			$feats     = trim( $row['caracteristicas'] ?? '' );
			$img       = trim( $row['imagen'] ?? '' );

			$existing = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM $table_c WHERE manufacturer_id = %d AND slug = %s AND parent_id = %d",
				$mfr_id, $sub_slug, $parent_id
			) );

			if ( $existing ) {
				$wpdb->update( $table_c, [
					'name'          => $name,
					'description'   => $desc,
					'image'         => $img,
					'level'         => 2,
					'metadata_json' => json_encode( [
						'features' => $feats,
					] ),
				], [ 'id' => $existing ] );
				$subcat_map[ $cat_slug . '/' . $sub_slug ] = (int) $existing;
			} else {
				$wpdb->insert( $table_c, [
					'manufacturer_id' => $mfr_id,
					'parent_id'       => $parent_id,
					'name'            => $name,
					'slug'            => $sub_slug,
					'type'            => 'category',
					'description'     => $desc,
					'image'           => $img,
					'level'           => 2,
					'products_count'  => 0,
					'metadata_json'   => json_encode( [
						'features' => $feats,
					] ),
				], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ] );
				$subcat_map[ $cat_slug . '/' . $sub_slug ] = (int) $wpdb->insert_id;
			}
			$stats['subcategorias']++;
		}

		// Pass 3: series (level 3)
		foreach ( $rows_json as $row ) {
			if ( trim( $row['tipo'] ?? '' ) !== 'serie' ) {
				continue;
			}
			$cat_slug  = sanitize_title( trim( $row['categoria'] ?? '' ) );
			$sub_slug  = sanitize_title( trim( $row['subcategoria'] ?? '' ) );
			$ser_slug  = sanitize_title( trim( $row['serie'] ?? '' ) );
			$path      = $cat_slug . '/' . $sub_slug;
			if ( empty( $ser_slug ) || ! isset( $subcat_map[ $path ] ) ) {
				continue;
			}
			$parent_id = $subcat_map[ $path ];
			$name      = trim( $row['nombre'] ?? '' ) ?: ( trim( $row['serie'] ?? '' ) ?: $ser_slug );
			$desc      = trim( $row['descripcion'] ?? '' );
			$feats     = trim( $row['caracteristicas'] ?? '' );
			$img       = trim( $row['imagen'] ?? '' );
			$titulo    = trim( $row['titulo'] ?? '' );

			$existing = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM $table_c WHERE manufacturer_id = %d AND slug = %s AND parent_id = %d",
				$mfr_id, $ser_slug, $parent_id
			) );

			if ( $existing ) {
				$wpdb->update( $table_c, [
					'name'          => $name,
					'description'   => $desc,
					'image'         => $img,
					'level'         => 3,
					'metadata_json' => json_encode( [
						'titulo'   => $titulo,
						'features' => $feats,
					] ),
				], [ 'id' => $existing ] );
				$serie_map[ $path . '/' . $ser_slug ] = (int) $existing;
			} else {
				$wpdb->insert( $table_c, [
					'manufacturer_id' => $mfr_id,
					'parent_id'       => $parent_id,
					'name'            => $name,
					'slug'            => $ser_slug,
					'type'            => 'series',
					'description'     => $desc,
					'image'           => $img,
					'level'           => 3,
					'products_count'  => 0,
					'metadata_json'   => json_encode( [
						'titulo'   => $titulo,
						'features' => $feats,
					] ),
				], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ] );
				$serie_map[ $path . '/' . $ser_slug ] = (int) $wpdb->insert_id;
			}
			$stats['series']++;
		}

		// Pass 4: productos (level 4) — reassign products to parent serie
		foreach ( $rows_json as $row ) {
			if ( trim( $row['tipo'] ?? '' ) !== 'producto' ) {
				continue;
			}
			$cat_slug  = sanitize_title( trim( $row['categoria'] ?? '' ) );
			$sub_slug  = sanitize_title( trim( $row['subcategoria'] ?? '' ) );
			$ser_slug  = sanitize_title( trim( $row['serie'] ?? '' ) );
			$prod_slug = sanitize_title( trim( $row['producto'] ?? '' ) );
			$cod_serie = sanitize_title( trim( $row['codigo_serie'] ?? '' ) );

			if ( empty( $prod_slug ) ) {
				continue;
			}

			$serie_path   = $cat_slug . '/' . $sub_slug . '/' . $ser_slug;
			$parent_serie = isset( $serie_map[ $serie_path ] ) ? $serie_map[ $serie_path ] : null;
			if ( ! $parent_serie && ! empty( $sub_slug ) ) {
				$subcat_path = $cat_slug . '/' . $sub_slug;
				$parent_serie = isset( $subcat_map[ $subcat_path ] ) ? $subcat_map[ $subcat_path ] : null;
			}
			if ( ! $parent_serie && isset( $cat_map[ $cat_slug ] ) ) {
				$parent_serie = $cat_map[ $cat_slug ];
			}

			$existing = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM $table_c WHERE manufacturer_id = %d AND slug = %s",
				$mfr_id, $prod_slug
			) );
			if ( ! $existing && ! empty( $cod_serie ) && $cod_serie !== $prod_slug ) {
				$existing = $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM $table_c WHERE manufacturer_id = %d AND slug = %s",
					$mfr_id, $cod_serie
				) );
			}

			$name   = trim( $row['nombre'] ?? '' ) ?: ( trim( $row['producto'] ?? '' ) ?: $prod_slug );
			$desc   = trim( $row['descripcion'] ?? '' );
			$feats  = trim( $row['caracteristicas'] ?? '' );
			$img    = trim( $row['imagen'] ?? '' );
			$titulo = trim( $row['titulo'] ?? '' );

			if ( $existing ) {
				$wpdb->update( $table_c, [
					'parent_id'     => $parent_serie,
					'name'          => $name,
					'description'   => $desc,
					'image'         => $img,
					'level'         => 4,
					'type'          => 'series',
					'metadata_json' => json_encode( [
						'titulo'   => $titulo,
						'features' => $feats,
					] ),
				], [ 'id' => $existing ] );
				$level4_id = (int) $existing;
			} else {
				$wpdb->insert( $table_c, [
					'manufacturer_id' => $mfr_id,
					'parent_id'       => $parent_serie,
					'name'            => $name,
					'slug'            => $prod_slug,
					'type'            => 'series',
					'description'     => $desc,
					'image'           => $img,
					'level'           => 4,
					'products_count'  => 0,
					'metadata_json'   => json_encode( [
						'titulo'   => $titulo,
						'features' => $feats,
					] ),
				], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ] );
				$level4_id = (int) $wpdb->insert_id;
			}

			$stats['productos']++;
		}

		// Pass 5: move leftover level-0 categories under "Sin clasificar"
		$orphans = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, name, slug FROM $table_c WHERE manufacturer_id = %d AND level = 0 AND products_count > 0",
			$mfr_id
		) );
		if ( ! empty( $orphans ) ) {
			$uncat_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM $table_c WHERE manufacturer_id = %d AND slug = 'sin-clasificar' AND level = 1",
				$mfr_id
			) );
			if ( ! $uncat_id ) {
				$wpdb->insert( $table_c, [
					'manufacturer_id' => $mfr_id,
					'parent_id'       => null,
					'name'            => 'Sin clasificar',
					'slug'            => 'sin-clasificar',
					'type'            => 'category',
					'description'     => '',
					'image'           => '',
					'level'           => 1,
					'products_count'  => 0,
					'metadata_json'   => '[]',
				], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ] );
				$uncat_id = (int) $wpdb->insert_id;
				$stats['categorias']++;
			}
			$uncat_id = (int) $uncat_id;
			foreach ( $orphans as $orphan ) {
				$wpdb->update( $table_c, [
					'parent_id' => $uncat_id,
					'level'     => 2,
				], [ 'id' => $orphan->id ] );
				$stats['huérfanos'] = ( $stats['huérfanos'] ?? 0 ) + 1;
			}
			// Update sin-clasificar products_count to reflect its new children
			$uncat_count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT SUM(products_count) FROM $table_c WHERE parent_id = %d",
				$uncat_id
			) );
			$wpdb->update( $table_c, [ 'products_count' => $uncat_count ], [ 'id' => $uncat_id ] );
		}

		$msg = sprintf(
			'Categorías Samtec importadas: %d categorías, %d subcategorías, %d series, %d productos.',
			$stats['categorias'],
			$stats['subcategorias'],
			$stats['series'],
			$stats['productos']
		);
		if ( ! empty( $stats['huérfanos'] ) ) {
			$msg .= sprintf( ' %d huérfanos movidos a Sin clasificar.', $stats['huérfanos'] );
		}

		// Regenerate pages so tree reflects new hierarchy
		$processor_mgr = new \AOE\CatalogEngine\Import\ProcessorManager();
		$bp = new \AOE\CatalogEngine\Import\BatchProcessor( $processor_mgr );
		$bp->pack_catalog( (int) $mfr_id, $manufacturer_slug, $processor_mgr->get_processor( $manufacturer_slug ) );

		$msg .= ' Páginas regeneradas.';
		wp_send_json_success( [ 'message' => $msg ] );
	}

	public function ajax_regenerate_pages() {
		@set_time_limit( 0 );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Acceso no autorizado' );
		}

		$slug = sanitize_text_field( $_POST['slug'] ?? '' );
		if ( empty( $slug ) ) {
			wp_send_json_error( 'Slug no proporcionado' );
		}

		while ( ob_get_level() ) { ob_end_clean(); }

		try {
			global $wpdb;
			$table_m = $wpdb->prefix . 'aoe_catalog_manufacturers';
			$manufacturer = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM $table_m WHERE slug = %s", $slug
			) );
			if ( ! $manufacturer ) {
				wp_send_json_error( 'Fabricante no encontrado' );
			}

			$processor_mgr = new \AOE\CatalogEngine\Import\ProcessorManager();
			$processor     = $processor_mgr->get_processor( $slug );
			$batch         = new \AOE\CatalogEngine\Import\BatchProcessor( $processor_mgr );

			$batch->pack_catalog( (int) $manufacturer->id, $slug, $processor );
			$this->update_last_modified( $slug );

			while ( ob_get_level() ) { ob_end_clean(); }

			wp_send_json_success( [ 'message' => 'Páginas regeneradas para ' . $slug ] );
		} catch ( \Throwable $e ) {
			while ( ob_get_level() ) { ob_end_clean(); }
			wp_send_json_error( $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
		}
	}

	public function ajax_clear_cache() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Acceso no autorizado' );
		}

		$slug = sanitize_text_field( $_POST['slug'] ?? '' );
		if ( empty( $slug ) ) {
			wp_send_json_error( 'Slug no proporcionado' );
		}

		\AOE\CatalogEngine\PublicFacing\CacheCatalog::invalidate( $slug );
		$this->update_last_modified( $slug );
		wp_send_json_success( [ 'message' => 'Cache limpiado para ' . $slug ] );
	}

	public function ajax_generate_template_cache() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Acceso no autorizado' );
		}

		$slug = sanitize_text_field( $_POST['slug'] ?? '' );
		if ( empty( $slug ) ) {
			wp_send_json_error( 'Slug no proporcionado' );
		}

		if ( ! empty( $_POST['clear_cache'] ) ) {
			\AOE\CatalogEngine\PublicFacing\CacheCatalog::invalidate( $slug );
			$this->update_last_modified( $slug );
		}

		$frontend_url = home_url( '/__gen-template/' . $slug . '/' );
		wp_send_json_success( [
			'message' => 'Generando template cache...',
			'url'     => $frontend_url,
		] );
	}

	public function ajax_clear_all_cache() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Acceso no autorizado' );
		}

		$logs = [];
		$logs[] = 'Iniciando limpieza de caché...';

		// Root template cache
		if ( \AOE\CatalogEngine\PublicFacing\TemplateCache::exists( 'root' ) ) {
			\AOE\CatalogEngine\PublicFacing\TemplateCache::delete( 'root' );
			$logs[] = 'Template cache root eliminado.';
		} else {
			$logs[] = 'Template cache root no existe.';
		}

		// Manufacturers template + page caches
		global $wpdb;
		$table = $wpdb->prefix . 'aoe_catalog_manufacturers';
		$manufacturers = $wpdb->get_results( "SELECT slug, name FROM $table ORDER BY name ASC" );

		foreach ( $manufacturers as $m ) {
			if ( \AOE\CatalogEngine\PublicFacing\TemplateCache::exists( $m->slug ) ) {
				\AOE\CatalogEngine\PublicFacing\TemplateCache::delete( $m->slug );
				$logs[] = "Template cache {$m->name} ({$m->slug}) eliminado.";
			} else {
				$logs[] = "Template cache {$m->name} ({$m->slug}) no existe.";
			}
			\AOE\CatalogEngine\PublicFacing\CacheCatalog::invalidate( $m->slug );
			$this->update_last_modified( $m->slug );
			$logs[] = "Page cache {$m->name} ({$m->slug}) invalidado.";
		}

		$logs[] = 'Limpieza completada.';
		wp_send_json_success( [ 'logs' => $logs ] );
	}

	public function invalidate_cache_on_template_save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Invalidate root cache if the root template was saved
		if ( (int) get_option( 'aoe_catalog_root_template_post_id', 0 ) === $post_id ) {
			\AOE\CatalogEngine\PublicFacing\TemplateCache::delete( 'root' );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'aoe_catalog_manufacturers';
		$manufacturer = $wpdb->get_row( $wpdb->prepare(
			"SELECT slug FROM $table WHERE wp_post_id = %d LIMIT 1",
			$post_id
		) );
		if ( $manufacturer ) {
			\AOE\CatalogEngine\PublicFacing\CacheCatalog::invalidate( $manufacturer->slug );
			$this->update_last_modified( $manufacturer->slug );
		}
	}

	public function ajax_import_samtec_specs() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Acceso no autorizado' );
		}

		global $wpdb;
		$manufacturer_slug = sanitize_text_field( $_POST['manufacturer'] ?? '' );
		$rows              = isset( $_POST['rows_json'] ) ? json_decode( wp_unslash( $_POST['rows_json'] ), true ) : [];

		if ( empty( $manufacturer_slug ) || empty( $rows ) ) {
			wp_send_json_error( 'Datos incompletos' );
		}

		$table_p = $wpdb->prefix . 'aoe_catalog_products';

		$manufacturer = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}aoe_catalog_manufacturers WHERE slug = %s",
			$manufacturer_slug
		) );
		if ( ! $manufacturer ) {
			wp_send_json_error( 'Fabricante no encontrado' );
		}

		$mfr_id      = (int) $manufacturer->id;
		$processed   = 0;
		$errors      = 0;
		$headers     = [];
		$first       = true;

		foreach ( $rows as $row ) {
			if ( $first ) {
				$headers = $row;
				$first   = false;
				continue;
			}

			$sku = trim( $row[0] ?? '' );
			if ( empty( $sku ) ) {
				continue;
			}

			$specs = [];
			for ( $i = 2; $i < count( $row ) && $i < count( $headers ); $i++ ) {
				$key   = trim( $headers[ $i ] ?? '' );
				$value = trim( $row[ $i ] ?? '' );
				if ( $key !== '' && $value !== '' ) {
					$specs[ $key ] = $value;
				}
			}

			if ( empty( $specs ) ) {
				continue;
			}

			$product = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM $table_p WHERE manufacturer_id = %d AND sku = %s",
				$mfr_id, $sku
			) );

			if ( ! $product ) {
				$errors++;
				continue;
			}

			$existing = $wpdb->get_var( $wpdb->prepare(
				"SELECT additional_data FROM $table_p WHERE id = %d", $product
			) );
			$additional = $existing ? (array) json_decode( $existing, true ) : [];
			$additional['specs'] = $specs;

			$wpdb->update( $table_p,
				[ 'additional_data' => json_encode( $additional, JSON_UNESCAPED_UNICODE ) ],
				[ 'id' => $product ],
				[ '%s' ],
				[ '%d' ]
			);
			$processed++;
		}

		wp_send_json_success( [
			'processed' => $processed,
			'errors'    => $errors,
		] );
	}

	public function ajax_import_bivar_categories() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Acceso no autorizado' );
		}

		global $wpdb;
		$manufacturer_slug = sanitize_text_field( $_POST['manufacturer'] ?? '' );
		$csv_content       = isset( $_POST['csv_content'] ) ? wp_unslash( $_POST['csv_content'] ) : '';
		$update_only       = ! empty( $_POST['update_only'] );

		if ( empty( $manufacturer_slug ) || empty( $csv_content ) ) {
			wp_send_json_error( 'Datos incompletos' );
		}

		$table_m = $wpdb->prefix . 'aoe_catalog_manufacturers';
		$table_c = $wpdb->prefix . 'aoe_catalog_categories';

		$manufacturer = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM $table_m WHERE slug = %s",
			$manufacturer_slug
		) );
		if ( ! $manufacturer ) {
			wp_send_json_error( 'Fabricante no encontrado' );
		}
		$mfr_id = (int) $manufacturer->id;

		// Parse CSV
		$lines = explode( "\n", $csv_content );
		if ( count( $lines ) < 2 ) {
			wp_send_json_error( 'CSV vacío o sin datos' );
		}

		$header = str_getcsv( trim( $lines[0] ), ',' );
		$header = array_map( 'trim', $header );

		$col_map = array_flip( $header );
		foreach ( [ 'name', 'level' ] as $col ) {
			if ( ! isset( $col_map[ $col ] ) ) {
				wp_send_json_error( "Columna requerida '$col' no encontrada en CSV" );
			}
		}

		$has_breadcrumb = isset( $col_map['breadcrumb_names'] );

		// Parse rows, skip level <= 1 (All Categories)
		$rows = [];
		for ( $i = 1; $i < count( $lines ); $i++ ) {
			$line = trim( $lines[ $i ] );
			if ( $line === '' ) continue;
			$cols = str_getcsv( $line, ',' );
			if ( count( $cols ) < count( $header ) ) continue;
			$data = [];
			foreach ( $col_map as $col_name => $col_idx ) {
				$data[ $col_name ] = isset( $cols[ $col_idx ] ) ? trim( $cols[ $col_idx ] ) : '';
			}
			$level = (int) $data['level'];
			if ( $level <= 1 ) continue;
			$data['_normalized_level'] = $level >= 5 ? 3 : ( $level - 1 );
			$rows[] = $data;
		}

		if ( empty( $rows ) ) {
			wp_send_json_error( 'No se encontraron filas válidas en el CSV' );
		}

		// Sort by level so parents come before children
		usort( $rows, function ( $a, $b ) {
			return $a['_normalized_level'] - $b['_normalized_level'];
		} );

		if ( ! $update_only ) {
			// Clean import: delete existing categories
			$wpdb->delete( $table_c, [ 'manufacturer_id' => $mfr_id ], [ '%d' ] );
		}

		/**
		 * Find a category by name + parent_id within this manufacturer.
		 */
		$find_cat = function ( $name, $parent_id ) use ( $wpdb, $table_c, $mfr_id ) {
			if ( $parent_id === null ) {
				return $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM $table_c WHERE manufacturer_id = %d AND name = %s AND parent_id IS NULL LIMIT 1",
					$mfr_id, $name
				) );
			}
			return $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM $table_c WHERE manufacturer_id = %d AND name = %s AND parent_id = %d LIMIT 1",
				$mfr_id, $name, $parent_id
			) );
		};

		$is_null_literal = function ( $v ) {
			return strtolower( trim( (string) $v ) ) === 'null';
		};

		$created = 0;
		$updated = 0;
		foreach ( $rows as $row ) {
			$name  = $row['name'] ?? '';
			$level = $row['_normalized_level'] ?? 1;

			if ( empty( $name ) || $is_null_literal( $name ) ) continue;

			// Resolve parent
			$parent_id = null;
			if ( $level > 1 && $has_breadcrumb ) {
				$bc_raw = $row['breadcrumb_names'] ?? '';
				$bc_parts = array_values( array_filter(
					array_map( 'trim', explode( '>', $bc_raw ) ),
					function ( $p ) use ( $is_null_literal ) {
						return strtolower( $p ) !== 'all categories'
							&& strtolower( $p ) !== 'view items'
							&& ! $is_null_literal( $p );
					}
				) );
				if ( count( $bc_parts ) >= 2 ) {
					$parent_name    = $bc_parts[ count( $bc_parts ) - 2 ];
					$grandparent_id = null;
					if ( count( $bc_parts ) > 2 ) {
						$gp_name = $bc_parts[ count( $bc_parts ) - 3 ] ?? '';
						if ( ! empty( $gp_name ) && ! $is_null_literal( $gp_name ) ) {
							$grandparent_id = $find_cat( $gp_name, null );
						}
					}
					if ( ! $is_null_literal( $parent_name ) ) {
						$parent_id = $find_cat( $parent_name, $grandparent_id );
						if ( ! $parent_id && $grandparent_id !== null ) {
							$parent_id = $find_cat( $parent_name, null );
						}
					}
				}
			}

			$desc = ( $row['description'] ?? '' );
			$img  = ( $row['image_url'] ?? '' );
			if ( $is_null_literal( $desc ) ) $desc = '';
			if ( $is_null_literal( $img ) ) $img = '';

			$existing_id = $find_cat( $name, $parent_id );
			if ( $existing_id ) {
				if ( $update_only ) {
					$wpdb->update( $table_c,
						[ 'description' => $desc, 'image' => $img ],
						[ 'id' => $existing_id ],
						[ '%s', '%s' ],
						[ '%d' ]
					);
				}
				$updated++;
			} else {
				$slug = sanitize_title( $name );
				$wpdb->insert( $table_c, [
					'manufacturer_id' => $mfr_id,
					'parent_id'       => $parent_id,
					'name'            => $name,
					'slug'            => $slug,
					'type'            => 'category',
					'description'     => $desc,
					'image'           => $img,
					'level'           => $level,
					'products_count'  => 0,
					'metadata_json'   => json_encode( [] ),
				], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ] );
				$created++;
			}
		}

		wp_send_json_success( [
			'message' => sprintf(
				'Categorías: %d creadas, %d actualizadas.',
				$created, $updated
			),
		] );
	}

	private function update_last_modified( string $slug ) {
		update_option( 'aoe_catalog_last_modified_' . $slug, time() );
	}

	/**
	 * Display Media Validator page
	 */
	public function display_media_validator_page() {
		require_once __DIR__ . '/Views/media-validator.php';
	}

	/**
	 * Enqueue admin scripts & styles
	 */
	public function enqueue_styles_scripts( $hook ) {
		if ( strpos( $hook, 'aoe-catalog' ) === false ) {
			return;
		}

		$css_path = dirname( dirname( __DIR__ ) ) . '/assets/css/admin.css';
		$js_path  = dirname( dirname( __DIR__ ) ) . '/assets/js/admin.js';

		wp_enqueue_style(
			'aoe-catalog-admin-style',
			plugin_dir_url( dirname( __DIR__ ) ) . 'assets/css/admin.css',
			[],
			file_exists( $css_path ) ? filemtime( $css_path ) : '1.0.0'
		);
		wp_enqueue_script(
			'aoe-catalog-admin-js',
			plugin_dir_url( dirname( __DIR__ ) ) . 'assets/js/admin.js',
			[ 'jquery' ],
			file_exists( $js_path ) ? filemtime( $js_path ) : '1.0.0',
			true
		);
		wp_localize_script( 'aoe-catalog-admin-js', 'aoe_catalog', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
		] );
	}
}
