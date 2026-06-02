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
	}

	/**
	 * Display Catalog generated pages
	 */
	public function display_catalog_pages() {
		// Query generated pages mock data for MVP/Test
		// Later this queries database categories/products URLs.
		$generated_pages = get_option( 'aoe_catalog_generated_pages', [] );
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
	public function display_logs_page() {
		$logs = get_option( 'aoe_catalog_import_logs', [] );
		require_once __DIR__ . '/Views/logs-list.php';
	}

	/**
	 * Handle CRUD save and delete requests for manufacturers
	 */
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

			$data = [
				'name'       => $name,
				'slug'       => $slug,
				'wp_post_id' => $wp_post_id,
			];

			if ( $id ) {
				$wpdb->update( $table_name, $data, [ 'id' => $id ], [ '%s', '%s', '%d' ], [ '%d' ] );
			} else {
				$wpdb->insert( $table_name, $data, [ '%s', '%s', '%d' ] );
			}

			wp_safe_redirect( admin_url( 'admin.php?page=aoe-catalog-manufacturers' ) );
			exit;
		}
	}

	/**
	 * Enqueue admin scripts & styles
	 */
	public function enqueue_styles_scripts( $hook ) {
		// Only load assets on our plugin pages
		if ( strpos( $hook, 'aoe-catalog' ) === false ) {
			return;
		}

		wp_enqueue_style( 'aoe-catalog-admin-style', plugin_dir_url( dirname( __DIR__ ) ) . 'assets/css/admin.css', [], '1.0.0' );
		wp_enqueue_script( 'aoe-catalog-admin-js', plugin_dir_url( dirname( __DIR__ ) ) . 'assets/js/admin.js', [ 'jquery' ], '1.0.0', true );
	}
}
