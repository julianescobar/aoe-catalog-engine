<?php
/**
 * CLI preview: check separator, column mapping and sample rows BEFORE importing.
 * No writes to the DB.
 *
 * Usage:
 *   php tools/preview-import.php --csv=/ruta/archivo.csv [--sep=;] [--rows=10] [--manufacturer=bulgin]
 */

namespace AOE\CatalogEngine\Tools;

if ( PHP_SAPI !== 'cli' ) {
	die( "CLI only.\n" );
}

ob_implicit_flush( true );
while ( ob_get_level() > 0 ) {
	ob_end_flush();
}

$longopts = [
	'csv:',
	'sep::',
	'rows::',
	'manufacturer::',
];
$args          = getopt( '', $longopts );
$csv_path      = $args['csv'] ?? '';
$sep           = $args['sep'] ?? '';
$rows_to_show  = intval( $args['rows'] ?? 10 );
$mfr           = $args['manufacturer'] ?? '';

if ( empty( $csv_path ) || ! file_exists( $csv_path ) ) {
	echo "Uso: php tools/preview-import.php --csv=/ruta/archivo.csv [--sep=,|;|\\t] [--rows=10] [--manufacturer=slug]\n";
	exit( 1 );
}

// --- Separador ---
$handle = fopen( $csv_path, 'r' );
if ( ! $handle ) {
	die( "No se pudo abrir el CSV.\n" );
}

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

// --- Cabeceras ---
$headers = fgetcsv( $handle, 0, $sep );
if ( empty( $headers ) ) {
	die( "CSV vacío o sin cabeceras.\n" );
}
echo "Columnas detectadas: " . count( $headers ) . "\n";
foreach ( $headers as $i => $h ) {
	echo "  [{$i}] " . var_export( $h, true ) . "\n";
}

// --- WordPress + procesador ---
if ( ! defined( 'ABSPATH' ) ) {
	$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
	if ( ! file_exists( $wp_load ) ) {
		$wp_load = dirname( __DIR__, 5 ) . '/wp-load.php';
	}
	if ( ! file_exists( $wp_load ) ) {
		die( "wp-load.php no encontrado.\n" );
	}
	require_once $wp_load;
}

$processor = null;
if ( $mfr ) {
	$manager   = new \AOE\CatalogEngine\Import\ProcessorManager();
	$processor = $manager->get_processor( $mfr );
	if ( ! $processor ) {
		die( "Procesador no encontrado para {$mfr}\n" );
	}

	$expected = $processor->get_supported_columns();
	echo "\nColumnas esperadas por {$mfr}: " . implode( ', ', $expected ) . "\n";

	$headers_lower = array_map(
		function ( $h ) { return strtolower( ltrim( $h, "\xEF\xBB\xBF" ) ); },
		$headers
	);
	foreach ( $expected as $col ) {
		$found = in_array( strtolower( $col ), $headers_lower, true );
		echo "  " . ( $found ? "OK   " : "FALTA" ) . " {$col}\n";
	}
}

// --- Previsualización de filas ---
echo "\n=== Previsualización (primeras {$rows_to_show} filas) ===\n";
$n = 0;
while ( $n < $rows_to_show && ( $cols = fgetcsv( $handle, 0, $sep ) ) !== false ) {
	$n++;
	$row = [];
	foreach ( $headers as $i => $h ) {
		$row[ $h ] = $cols[ $i ] ?? '';
	}
	$row = array_combine(
		array_map( function ( $k ) { return ltrim( $k, "\xEF\xBB\xBF" ); }, array_keys( $row ) ),
		$row
	);

	echo "\n--- Fila {$n} ---\n";

	if ( $processor ) {
		$norm = $processor->process_row( $row );
		echo "  sku:          " . var_export( $norm['sku'], true ) . "\n";
		echo "  name:         " . var_export( $norm['name'], true ) . "\n";
		echo "  category:     " . var_export( $norm['category'], true ) . "\n";
		echo "  category_path:" . var_export( $norm['category_path'], true ) . "\n";
		echo "  desc len:     " . mb_strlen( (string) $norm['description'] ) . " chars\n";
		echo "  images:       " . count( $norm['images'] ) . "\n";
		echo "  pdf:          " . count( $norm['pdf'], COUNT_RECURSIVE ) . "\n";
		$specs = $norm['additional_data']['specs'] ?? [];
		echo "  specs:        " . count( $specs ) . "\n";
	} else {
		foreach ( $row as $k => $v ) {
			$v     = is_scalar( $v ) ? (string) $v : '';
			$short = mb_substr( $v, 0, 60 );
			if ( mb_strlen( $v ) > 60 ) {
				$short .= '...';
			}
			echo "  {$k}: {$short}\n";
		}
	}
}
fclose( $handle );

echo "\n=== Fin de previsualización ===\n";
if ( $processor && $n > 0 ) {
	echo "Aviso: si 'sku' aparece como '' en todas las filas, el mapeo de columnas está mal.\n";
}
