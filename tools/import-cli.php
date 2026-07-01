<?php
/**
 * CLI script: import catalog data bypassing AJAX (no timeout).
 *
 * Usage: wp eval-file tools/import-cli.php -- camdenboss --mode=replace
 *        wp eval-file tools/import-cli.php -- samtec --mode=incremental
 */

namespace AOE\CatalogEngine\Tools;

if ( 'cli' !== php_sapi_name() ) {
	die( 'CLI only' );
}

$args = $_SERVER['argv'] ?? [];
$manufacturer_slug = $args[4] ?? '';
$mode = 'replace';

// Parse --mode=
foreach ( $args as $a ) {
	if ( str_starts_with( $a, '--mode=' ) ) {
		$mode = substr( $a, 7 );
	}
}

// Parse positional (after --)
$dash_idx = array_search( '--', $args );
if ( $dash_idx !== false && isset( $args[ $dash_idx + 1 ] ) ) {
	$manufacturer_slug = $args[ $dash_idx + 1 ];
}

if ( empty( $manufacturer_slug ) ) {
	die( "Uso: wp eval-file tools/import-cli.php -- <fabricante> [--mode=replace|incremental]\n" );
}

echo "Importando: {$manufacturer_slug} | Modo: {$mode}\n\n";

// Load WordPress
if ( ! defined( 'ABSPATH' ) ) {
	require_once dirname( __DIR__, 5 ) . '/wp-load.php';
}

global $wpdb;

$manager      = new \AOE\CatalogEngine\Import\ProcessorManager();
$processor    = $manager->get_processor( $manufacturer_slug );

if ( ! $processor ) {
	die( "Procesador no encontrado para {$manufacturer_slug}\n" );
}

$table = $wpdb->prefix . 'aoe_import_' . $manufacturer_slug;
$rows = $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A );

if ( empty( $rows ) ) {
	die( "No hay datos en la tabla {$table}\n" );
}

echo "Total filas: " . count( $rows ) . "\n";

$manufacturer = $wpdb->get_row( $wpdb->prepare(
	"SELECT * FROM {$wpdb->prefix}aoe_catalog_manufacturers WHERE slug = %s",
	$manufacturer_slug
) );

if ( ! $manufacturer ) {
	die( "Fabricante no encontrado en DB\n" );
}

// Replace mode: clear existing data
if ( 'replace' === $mode ) {
	echo "Limpiando datos existentes...\n";
	\AOE\CatalogEngine\Database\ProductRepository::clear_by_manufacturer( $manufacturer->id );
	\AOE\CatalogEngine\Database\CategoryRepository::clear_by_manufacturer( $manufacturer->id );
}

// Process rows
$processed = 0;
foreach ( $rows as $row ) {
	$normalized = $processor->process_row( $row );
	if ( empty( $normalized['sku'] ) ) {
		continue;
	}

	$category_path = $normalized['category_path'] ?? [];
	if ( ! empty( $category_path ) ) {
		$parent_cat_id = null;
		foreach ( $category_path as $path_name ) {
			$parent_cat_id = \AOE\CatalogEngine\Database\CategoryRepository::find_or_create(
				$manufacturer->id, $path_name, 'category', $parent_cat_id
			);
		}
		$category_id = $parent_cat_id;
	} else {
		$category_name = ! empty( $normalized['category'] ) ? $normalized['category'] : 'Uncategorized';
		$category_id   = \AOE\CatalogEngine\Database\CategoryRepository::find_or_create( $manufacturer->id, $category_name );
	}

	$product_data = array_merge( $normalized, [
		'manufacturer_id' => $manufacturer->id,
		'category_id'     => $category_id,
	] );

	$product_id = \AOE\CatalogEngine\Database\ProductRepository::save( $product_data );
	if ( $product_id ) {
		$processed++;
	}

	if ( $processed % 500 === 0 ) {
		echo "  Procesados {$processed} productos...\n";
	}
}

echo "\nProductos procesados: {$processed}\n";

// Run pack_catalog
echo "Ejecutando pack_catalog...\n";
$batch = new \AOE\CatalogEngine\Import\BatchProcessor( $manager );
$batch->pack_catalog( (int) $manufacturer->id, $manufacturer_slug, $processor );
echo "pack_catalog completado.\n";
echo "\nImportación finalizada.\n";
