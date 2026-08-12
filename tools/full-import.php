<?php
/**
 * Full import CLI: process CSV + pack_catalog in one shot, no timeouts.
 *
 * Usage:
 *   php tools/full-import.php --manufacturer=camdenboss --csv=/ruta/al/archivo.csv --mode=replace
 *   php tools/full-import.php --manufacturer=camdenboss --csv=./camdenboss.csv --mode=incremental
 *
 * Flags:
 *   --manufacturer=slug     (obligatorio)
 *   --csv=/path/to/file.csv (obligatorio: ruta al CSV)
 *   --mode=replace|incremental  (default: replace)
 *   --limit=500             (opcional: procesar solo N filas)
 */

namespace AOE\CatalogEngine\Tools;

if ( PHP_SAPI !== 'cli' ) {
	die( "CLI only.\n" );
}

// Write to the log immediately even when stdout is redirected (nohup/file).
ob_implicit_flush( true );
while ( ob_get_level() > 0 ) {
	ob_end_flush();
}

// Parse args
$longopts = [
	'manufacturer:',
	'csv:',
	'mode::',
	'limit::',
	'sep::',
];
$args = getopt( '', $longopts );

$manufacturer_slug = $args['manufacturer'] ?? '';
$csv_path          = $args['csv'] ?? '';
$mode              = $args['mode'] ?? 'replace';
$limit             = intval( $args['limit'] ?? 0 );
$sep               = $args['sep'] ?? '';

if ( empty( $manufacturer_slug ) || empty( $csv_path ) ) {
	echo "Uso: php tools/full-import.php --manufacturer=slug --csv=/ruta/archivo.csv [--mode=replace|incremental] [--limit=N] [--sep=,|;|\\t]\n";
	exit( 1 );
}

if ( ! file_exists( $csv_path ) ) {
	die( "Archivo no encontrado: {$csv_path}\n" );
}

// Bootstrap WordPress
// Optional local override: AOE_DB_HOST=127.0.0.1:10006 to bypass Local's socket-only host.
if ( getenv( 'AOE_DB_HOST' ) && ! defined( 'DB_HOST' ) ) {
	define( 'DB_HOST', getenv( 'AOE_DB_HOST' ) );
}
$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
if ( ! file_exists( $wp_load ) ) {
	$wp_load = dirname( __DIR__, 5 ) . '/wp-load.php';
}
if ( ! file_exists( $wp_load ) ) {
	die( "wp-load.php no encontrado. Ajustá la ruta.\n" );
}

echo "=== Full Import CLI ===\n";
echo "Fabricante: {$manufacturer_slug}\n";
echo "CSV:        {$csv_path}\n";
echo "Modo:       {$mode}\n";
echo "Límite:     " . ( $limit ?: 'sin límite' ) . "\n";
echo "Cargando WordPress...\n\n";

require_once $wp_load;

global $wpdb;

// --- Leer CSV ---
$handle = fopen( $csv_path, 'r' );
if ( ! $handle ) {
	die( "No se pudo abrir el CSV.\n" );
}

// Detect separator from the first line (default: auto, as admin.js does)
if ( '' === $sep ) {
	$first_line = fgets( $handle );
	rewind( $handle );
	$counts = [
		';'  => substr_count( $first_line, ';' ),
		','  => substr_count( $first_line, ',' ),
		"\t" => substr_count( $first_line, "\t" ),
	];
	arsort( $counts );
	$sep = (int) max( $counts ) > 0 ? key( $counts ) : ',';
}
echo "Delimitador: " . var_export( $sep, true ) . "\n";

$headers = fgetcsv( $handle, 0, $sep );
if ( empty( $headers ) ) {
	die( "CSV vacío o sin cabeceras.\n" );
}

// --- Cargar procesador ---
$manager   = new \AOE\CatalogEngine\Import\ProcessorManager();
$processor = $manager->get_processor( $manufacturer_slug );
if ( ! $processor ) {
	die( "Procesador no encontrado para {$manufacturer_slug}\n" );
}

$manufacturer = $wpdb->get_row( $wpdb->prepare(
	"SELECT * FROM {$wpdb->prefix}aoe_catalog_manufacturers WHERE slug = %s",
	$manufacturer_slug
) );
if ( ! $manufacturer ) {
	die( "Fabricante no registrado en DB.\n" );
}

// --- Replace: limpiar datos existentes ---
if ( 'replace' === $mode ) {
	echo "Limpiando datos existentes...\n";
	\AOE\CatalogEngine\Database\ProductRepository::clear_by_manufacturer( $manufacturer->id );
	\AOE\CatalogEngine\Database\CategoryRepository::clear_by_manufacturer( $manufacturer->id );
}

// --- Procesar filas (streaming: una fila a la vez, sin acumular en memoria) ---
@ini_set( 'memory_limit', '1G' );

$processed = 0;
$rows_read = 0;
$skipped   = 0;
$start     = microtime( true );

while ( ( $cols = fgetcsv( $handle, 0, $sep ) ) !== false ) {
	$rows_read++;
	if ( $limit && $rows_read > $limit ) break;

	$row = [];
	foreach ( $headers as $i => $h ) {
		$row[ $h ] = $cols[ $i ] ?? '';
	}

	$normalized = $processor->process_row( $row );
	if ( empty( $normalized['sku'] ) ) {
		$skipped++;
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

	if ( \AOE\CatalogEngine\Database\ProductRepository::save( $product_data ) ) {
		$processed++;
	}

	if ( $processed % 500 === 0 ) {
		$elapsed = round( microtime( true ) - $start, 1 );
		echo sprintf( "  %s | %s | %s\n",
			str_pad( "Procesados: {$processed}", 20 ),
			str_pad( "Filas: {$rows_read}", 16 ),
			"Tiempo: {$elapsed}s"
		);
		flush();
	}
}
fclose( $handle );

$elapsed = round( microtime( true ) - $start, 1 );
echo "\nFilas leídas: {$rows_read} | Procesados: {$processed} | Sin SKU: {$skipped} | Tiempo: {$elapsed}s\n";

// --- pack_catalog ---
echo "\nEjecutando pack_catalog...\n";
$pack_start = microtime( true );
$batch      = new \AOE\CatalogEngine\Import\BatchProcessor( $manager );
$batch->pack_catalog( (int) $manufacturer->id, $manufacturer_slug, $processor );
$pack_elapsed = round( microtime( true ) - $pack_start, 1 );
echo "pack_catalog completado en {$pack_elapsed}s\n";

$total = round( microtime( true ) - $start, 1 );
echo "\n=== Importación finalizada en {$total}s ===\n";
