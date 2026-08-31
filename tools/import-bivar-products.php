<?php
/**
 * CLI: Import Bivar products CSV.
 * Las categorías deben importarse primero con import-bivar-categories.php
 *
 * Uso:
 *   php tools/import-bivar-products.php <csv_path> [--test]
 */
if ( ! in_array( PHP_SAPI, [ 'cli', 'cgi-fcgi' ], true ) ) {
	die( 'CLI only' . "\n" );
}

ini_set( 'max_execution_time', '0' );
ini_set( 'memory_limit', '1024M' );

$args    = array_slice( $argv, 1 );
$is_test = false;
$csv_path = '';
foreach ( $args as $arg ) {
	if ( $arg === '--test' ) { $is_test = true; continue; }
	$csv_path = $arg;
}
if ( empty( $csv_path ) ) {
	die( "Uso: php tools/import-bivar-products.php <csv_path> [--test]\n" );
}
if ( ! file_exists( $csv_path ) ) {
	die( "Archivo no encontrado: $csv_path\n" );
}

$w = __DIR__ . '/../../../../../wp-load.php';
if ( ! file_exists( $w ) ) { $w = __DIR__ . '/../../../../wp-load.php'; }
if ( ! file_exists( $w ) ) { $w = __DIR__ . '/../../../wp-load.php'; }
require_once $w;

global $wpdb;
$manufacturer_slug = 'bivar';
$mfr = $wpdb->get_row( $wpdb->prepare(
	"SELECT * FROM {$wpdb->prefix}aoe_catalog_manufacturers WHERE slug = %s", $manufacturer_slug
) );
if ( ! $mfr ) {
	die( "Fabricante 'bivar' no encontrado. Imprta primero las categorías.\n" );
}
$mfr_id = (int) $mfr->id;

$processor = ( new \AOE\CatalogEngine\Import\ProcessorManager() )->get_processor( $manufacturer_slug );
if ( ! $processor ) {
	die( "Procesador Bivar no encontrado.\n" );
}

// Read CSV
echo "Leyendo CSV...\n";
$handle = fopen( $csv_path, 'r' );
$header = fgetcsv( $handle );
if ( ! $header ) {
	die( "CSV vacío o sin cabecera.\n" );
}
$header = array_map( 'trim', $header );

$rows = [];
while ( ( $cols = fgetcsv( $handle ) ) !== false ) {
	$row = [];
	foreach ( $header as $i => $col ) {
		$row[ $col ] = isset( $cols[ $i ] ) ? trim( $cols[ $i ] ) : '';
	}
	$rows[] = $row;
}
fclose( $handle );
echo "Total filas: " . count( $rows ) . "\n";

if ( $is_test ) {
	echo "\n=== MODO TEST ===\n";
	$sample = $processor->process_row( $rows[0] ?? [] );
	echo "Ejemplo primera fila:\n";
	foreach ( $sample as $k => $v ) {
		if ( is_array( $v ) ) $v = json_encode( $v, JSON_UNESCAPED_UNICODE );
		echo "  $k: $v\n";
	}
	echo "\nSin test (--test) para importar realmente.\n";
	exit;
}

// Process
$table_prod = $wpdb->prefix . 'aoe_catalog_products';
$table_cat  = $wpdb->prefix . 'aoe_catalog_categories';

$total   = count( $rows );
$created = 0;
$updated = 0;
$skipped = 0;

echo "Importando productos...\n";
foreach ( $rows as $i => $row ) {
	$data = $processor->process_row( $row );
	if ( empty( $data['sku'] ) ) {
		$skipped++;
		continue;
	}

	// Resolve category
	$category_id = null;
	$path = $data['category_path'] ?? [];
	if ( ! empty( $path ) ) {
		$parent_cat_id = null;
		foreach ( $path as $path_name ) {
			$parent_cat_id = \AOE\CatalogEngine\Database\CategoryRepository::find_or_create(
				$mfr_id, $path_name, 'category', $parent_cat_id
			);
		}
		$category_id = $parent_cat_id;
	}

	// Upsert product
	$existing = $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM $table_prod WHERE manufacturer_id = %d AND sku = %s",
		$mfr_id, $data['sku']
	) );

	$product_data = [
		'manufacturer_id' => $mfr_id,
		'sku'            => $data['sku'],
		'name'           => $data['name'],
		'description'    => $data['description'],
		'category_id'    => $category_id,
		'urls_images'    => ! empty( $data['images'] ) ? json_encode( $data['images'] ) : '',
		'url_pdf'        => ! empty( $data['pdf'] ) ? json_encode( $data['pdf'] ) : '',
		'additional_data'=> ! empty( $data['additional_data'] ) ? json_encode( $data['additional_data'] ) : '',
	];

	if ( $existing ) {
		$wpdb->update( $table_prod, $product_data, [ 'id' => $existing ] );
		$updated++;
	} else {
		$wpdb->insert( $table_prod, $product_data );
		$created++;
	}

	if ( ( $i + 1 ) % 1000 === 0 ) {
		echo "  Procesados " . ( $i + 1 ) . " / $total\n";
	}
}

echo "\n=== Importación completada ===\n";
echo "Creados: $created | Actualizados: $updated | Saltados: $skipped\n";
echo "Luego corre: php tools/pack-catalog.php bivar\n";
