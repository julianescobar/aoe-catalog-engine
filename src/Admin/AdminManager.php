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
		add_action( 'wp_ajax_aoe_import_structure', [ $this, 'ajax_import_structure' ] );
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

		// Submenu 2: SEO
		add_submenu_page(
			'aoe-catalog-engine',
			'SEO Catálogo',
			'SEO',
			'manage_options',
			'aoe-catalog-seo',
			[ $this, 'display_seo_settings_page' ]
		);

		// Submenu 3: Logs
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

	/**
	 * Display System Logs
	 */
	public function display_seo_settings_page() {
		if ( isset( $_POST['save_aoe_seo'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'aoe_seo_settings' ) ) {
			update_option( 'aoe_catalog_seo_title_template', sanitize_text_field( $_POST['seo_title_template'] ?? '' ) );
			update_option( 'aoe_catalog_seo_description_template', sanitize_textarea_field( $_POST['seo_description_template'] ?? '' ) );
			echo '<div class="notice notice-success"><p>Ajustes SEO guardados.</p></div>';
		}

		$title_template = get_option( 'aoe_catalog_seo_title_template', 'Catálogo de productos de {manufacturer}: TC Componentes' );
		$desc_template  = get_option( 'aoe_catalog_seo_description_template', 'TC Componentes es distribuidor de {manufacturer} en España. Catálogo completo de productos, documentación técnica y soporte técnico especializado.' );
		require_once __DIR__ . '/Views/seo-settings.php';
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
			$config['seo_title_template'] = sanitize_text_field( $_POST['seo_title_template'] ?? '' );
			$config['seo_description_template'] = sanitize_textarea_field( $_POST['seo_description_template'] ?? '' );
			$config['tree_layout'] = in_array( $_POST['tree_layout'] ?? '', [ 'normal', 'columns' ] ) ? $_POST['tree_layout'] : 'normal';
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

	public function ajax_regenerate_pages() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Acceso no autorizado' );
		}

		$slug = sanitize_text_field( $_POST['slug'] ?? '' );
		if ( empty( $slug ) ) {
			wp_send_json_error( 'Slug no proporcionado' );
		}

		// Ensure clean output buffer
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

			// Clean again in case pack_catalog output something
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
		}

		$frontend_url = home_url( '/__gen-template/' . $slug . '/' );
		wp_send_json_success( [
			'message' => 'Generando template cache...',
			'url'     => $frontend_url,
		] );
	}

	public function invalidate_cache_on_template_save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'aoe_catalog_manufacturers';
		$manufacturer = $wpdb->get_row( $wpdb->prepare(
			"SELECT slug FROM $table WHERE wp_post_id = %d LIMIT 1",
			$post_id
		) );
		if ( $manufacturer ) {
			\AOE\CatalogEngine\PublicFacing\CacheCatalog::invalidate( $manufacturer->slug );
		}
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
