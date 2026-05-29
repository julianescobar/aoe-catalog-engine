<?php

namespace AOE\CatalogEngine\Import;

use AOE\CatalogEngine\Database\CategoryRepository;
use AOE\CatalogEngine\Database\ProductRepository;

class BatchProcessor {

	private $processor_manager;

	public function __construct( ProcessorManager $processor_manager ) {
		$this->processor_manager = $processor_manager;
		add_action( 'wp_ajax_aoe_process_batch', [ $this, 'ajax_process_batch' ] );
	}

	public function ajax_process_batch() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Acceso no autorizado' );
		}

		global $wpdb;

		$manufacturer_slug = sanitize_text_field( $_POST['manufacturer'] ?? '' );
		$import_mode       = sanitize_text_field( $_POST['import_mode'] ?? 'incremental' );
		$is_test           = intval( $_POST['is_test'] ?? 0 );
		$rows              = isset( $_POST['rows'] ) && is_array( $_POST['rows'] ) ? $_POST['rows'] : [];

		$processor = $this->processor_manager->get_processor( $manufacturer_slug );
		if ( ! $processor ) {
			wp_send_json_error( 'Procesador no encontrado para el fabricante ' . $manufacturer_slug );
		}

		$table_manufacturers = $wpdb->prefix . 'aoe_catalog_manufacturers';
		$manufacturer = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM $table_manufacturers WHERE slug = %s",
			$manufacturer_slug
		) );

		if ( ! $manufacturer ) {
			wp_send_json_error( 'El fabricante no esta registrado en la base de datos.' );
		}

		if ( $is_test ) {
			$this->process_test_preview( $processor, $manufacturer, $manufacturer_slug, $rows );
			return;
		}

		$offset         = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;
		$total_rows     = isset( $_POST['total_rows'] ) ? intval( $_POST['total_rows'] ) : 0;
		$is_first_chunk = ( 0 === $offset );
		$is_last_chunk  = ( $offset + count( $rows ) >= $total_rows );

		if ( 'replace' === $import_mode && $is_first_chunk ) {
			ProductRepository::clear_by_manufacturer( $manufacturer->id );
			CategoryRepository::clear_by_manufacturer( $manufacturer->id );
		}

		$processed_count = 0;

		foreach ( $rows as $row ) {
			$normalized = $processor->process_row( $row );

			if ( empty( $normalized['sku'] ) ) {
				continue;
			}

			$category_name = ! empty( $normalized['category'] ) ? $normalized['category'] : 'Uncategorized';
			$category_id   = CategoryRepository::find_or_create( $manufacturer->id, $category_name );

			$product_data = array_merge( $normalized, [
				'manufacturer_id' => $manufacturer->id,
				'category_id'     => $category_id,
			] );

			$product_id = ProductRepository::save( $product_data );

			if ( $product_id ) {
				CategoryRepository::increment_count( $category_id, 1 );
				$processed_count++;
			}
		}

		if ( $is_last_chunk && $processed_count > 0 ) {
			$pages     = get_option( 'aoe_catalog_generated_pages', [] );
			$prod_slug = $manufacturer_slug;
			$prod_url  = home_url( '/catalog/' . $prod_slug );

			$total_products = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}aoe_catalog_products WHERE manufacturer_id = %d",
				$manufacturer->id
			) );

			$pages[ $prod_slug ] = [
				'url'            => $prod_url,
				'type'           => 'catalogo principal',
				'manufacturer'   => $manufacturer->name,
				'products_count' => $total_products,
			];
			update_option( 'aoe_catalog_generated_pages', $pages );

			$this->add_log( 'Importacion Catalogo', $manufacturer->name, "Importacion completada. Modo: $import_mode. Total catalogo: $total_products." );
		}

		wp_send_json_success( [
			'processed' => $processed_count,
			'message'   => "Se procesaron $processed_count filas.",
		] );
	}

	private function process_test_preview( $processor, $manufacturer, string $manufacturer_slug, array $rows ) {
		$products = [];
		$category = '';

		foreach ( $rows as $row ) {
			$normalized = $processor->process_row( $row );

			if ( empty( $normalized['sku'] ) ) {
				continue;
			}

			if ( empty( $category ) ) {
				$category = ! empty( $normalized['category'] ) ? $normalized['category'] : 'uncategorized';
			}

			if ( $normalized['category'] !== $category ) {
				continue;
			}

			$products[] = [
				'sku'         => $normalized['sku'],
				'name'        => $normalized['name'],
				'category'    => $normalized['category'],
				'description' => $normalized['description'],
				'images'      => $normalized['images'],
				'pdf'         => $normalized['pdf'],
			];

			if ( count( $products ) >= 200 ) {
				break;
			}
		}

		if ( empty( $products ) ) {
			wp_send_json_error( 'No se encontraron productos validos para generar la prueba.' );
		}

		$previous_slug = get_option( 'aoe_preview_current_' . $manufacturer_slug );
		if ( ! empty( $previous_slug ) ) {
			delete_transient( 'aoe_preview_' . $previous_slug );
		}

		$test_slug = 'test-' . $manufacturer_slug . '-' . gmdate( 'YmdHis' );
		$payload   = [
			'manufacturer_slug' => $manufacturer_slug,
			'manufacturer_name' => $manufacturer->name,
			'test_slug'         => $test_slug,
			'category'          => $category,
			'template_post_id'  => intval( $manufacturer->wp_post_id ),
			'products'          => $products,
			'created_at'        => current_time( 'mysql' ),
		];

		set_transient( 'aoe_preview_' . $test_slug, $payload, 12 * HOUR_IN_SECONDS );
		update_option( 'aoe_preview_current_' . $manufacturer_slug, $test_slug, false );

		$test_url   = home_url( '/catalogo/' . $test_slug . '/' . sanitize_title( $category ) . '/' );
		$test_pages = get_option( 'aoe_catalog_generated_pages', [] );

		if ( ! empty( $previous_slug ) && isset( $test_pages[ $previous_slug ] ) ) {
			unset( $test_pages[ $previous_slug ] );
		}

		$test_pages[ $test_slug ] = [
			'url'            => $test_url,
			'type'           => 'prueba temporal',
			'manufacturer'   => $manufacturer->name,
			'products_count' => count( $products ),
		];
		update_option( 'aoe_catalog_generated_pages', $test_pages );
		$this->refresh_preview_rewrite_rules();

		$this->add_log( 'Generacion de Prueba', $manufacturer->name, "Se genero una prueba temporal en: $test_url" );

		wp_send_json_success( [
			'processed' => count( $products ),
			'message'   => 'Prueba generada con ' . count( $products ) . ' productos del modelo ' . $category . '.',
			'test_url'  => $test_url,
		] );
	}

	private function refresh_preview_rewrite_rules() {
		add_rewrite_rule( '^catalogo/(test-[^/]+)/([^/]+)/?', 'index.php?aoe_catalog_preview=$matches[1]&aoe_catalog_category=$matches[2]', 'top' );
		flush_rewrite_rules( false );
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
