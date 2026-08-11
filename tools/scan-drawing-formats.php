<?php
/**
 * CLI scan: analiza la columna 'documents' de un CSV (Panduit) y lista
 * TODOS los formatos de archivo (extensión de URL) y sus tipos de documento.
 * No escribe en BD.
 *
 * Usage:
 *   php tools/scan-drawing-formats.php --csv=/ruta/catalogo-panduit.csv [--sep=;]
 */

if ( PHP_SAPI !== 'cli' ) {
	die( "CLI only.\n" );
}

ob_implicit_flush( true );
while ( ob_get_level() > 0 ) {
	ob_end_flush();
}

$longopts = [ 'csv:', 'sep::' ];
$args     = getopt( '', $longopts );
$csv_path = $args['csv'] ?? '';
$sep      = $args['sep'] ?? '';

if ( empty( $csv_path ) || ! file_exists( $csv_path ) ) {
	echo "Uso: php tools/scan-drawing-formats.php --csv=/ruta/archivo.csv [--sep=,|;|\\t]\n";
	exit( 1 );
}

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

$headers = fgetcsv( $handle, 0, $sep );
if ( empty( $headers ) ) {
	die( "CSV vacío o sin cabeceras.\n" );
}

// Buscar columna documents (case-insensitive, con/sin BOM)
$docs_idx = null;
foreach ( $headers as $i => $h ) {
	$clean = ltrim( trim( $h ), "\xEF\xBB\xBF" );
	if ( strtolower( $clean ) === 'documents' ) {
		$docs_idx = $i;
		break;
	}
}
if ( null === $docs_idx ) {
	echo "No se encontró columna 'documents'. Columnas: " . implode( ', ', $headers ) . "\n";
	exit( 1 );
}
echo "Columna documents: índice {$docs_idx}.\n\n";

$ext_counts    = []; // ext -> count
$type_by_ext   = []; // ext -> [type => count]
$rows          = 0;
$rows_with_doc = 0;
$total_files   = 0;

while ( ( $row = fgetcsv( $handle, 0, $sep ) ) !== false ) {
	$rows++;
	if ( $rows % 20000 === 0 ) {
		echo "  ...{$rows} filas\n";
	}

	if ( ! isset( $row[ $docs_idx ] ) ) {
		continue;
	}
	$cell = trim( (string) $row[ $docs_idx ] );
	if ( '' === $cell ) {
		continue;
	}
	$rows_with_doc++;

	$entries = explode( '||', $cell );
	foreach ( $entries as $entry ) {
		$parts = explode( '|', trim( $entry ) );
		if ( count( $parts ) < 5 ) {
			continue;
		}
		$url  = trim( $parts[4] ?? '' );
		$type = trim( $parts[0] ?? '' );
		if ( '' === $url ) {
			continue;
		}
		$total_files++;

		$url_no_query = preg_replace( '/[?#].*$/', '', $url );
		$ext          = strtolower( pathinfo( parse_url( $url_no_query, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		if ( '' === $ext ) {
			$ext = '(sin extension)';
		}
		$ext_counts[ $ext ] = ( $ext_counts[ $ext ] ?? 0 ) + 1;
		$type_by_ext[ $ext ][ $type ] = ( $type_by_ext[ $ext ][ $type ] ?? 0 ) + 1;
	}
}

echo "\n========== RESUMEN ==========\n";
echo "Filas totales:      {$rows}\n";
echo "Filas con documents: {$rows_with_doc}\n";
echo "Archivos totales:   {$total_files}\n\n";

echo "Formatos por extensión:\n";
uksort( $ext_counts, function( $a, $b ) use ( $ext_counts ) {
	return $ext_counts[ $b ] <=> $ext_counts[ $a ];
} );
foreach ( $ext_counts as $ext => $count ) {
	$types = $type_by_ext[ $ext ] ?? [];
	$type_str = '';
	foreach ( $types as $t => $tc ) {
		$type_str .= "    - {$t}: {$tc}\n";
	}
	$pct = $total_files > 0 ? round( 100 * $count / $total_files, 1 ) : 0;
	echo "  .{$ext}: {$count} ({$pct}%)" . ( $type_str !== '' ? "\n{$type_str}" : '' ) . "\n";
}

echo "\nListo.\n";
