<?php
/**
 * CLI: Import Samtec specs CSV into additional_data.specs.
 *
 * Usage:
 *   php tools/import-samtec-specs.php samtec fichatecnica.csv
 *
 * CSV format: row 1 = headers (SKU, Serie, attr1, attr2, ...)
 *             row 2+ = data (sku, series, value1, value2, ...)
 * Columns 3+ (index 2+) are technical specs.
 */

if ( ! in_array( PHP_SAPI, [ 'cli', 'cgi-fcgi' ], true ) ) {
	die( 'CLI only' . "\n" );
}

// Disable output buffering for real-time console output
if ( ob_get_level() ) {
	ob_end_flush();
}
ob_implicit_flush( true );

$manufacturer_slug = $argv[1] ?? '';
$csv_path          = $argv[2] ?? '';
$is_test           = in_array( '--test', $argv );

if ( empty( $manufacturer_slug ) || empty( $csv_path ) ) {
	die( "Usage: php tools/import-samtec-specs.php <manufacturer_slug> <path/to/specs.csv> [--test]\n" );
}

echo ( $is_test ? "TEST MODE — only 10 rows\n" : "Starting...\n" );

if ( ! file_exists( $csv_path ) ) {
	die( "File not found: $csv_path\n" );
}

echo "Loading WordPress...\n";

// Bootstrap WordPress
$wp_load = __DIR__ . '/../../../../../wp-load.php';
if ( ! file_exists( $wp_load ) ) {
	$wp_load = __DIR__ . '/../../../../wp-load.php';
}
if ( ! file_exists( $wp_load ) ) {
	$wp_load = __DIR__ . '/../../../wp-load.php';
}
if ( ! file_exists( $wp_load ) ) {
	die( "wp-load.php not found\n" );
}
require_once $wp_load;

global $wpdb;
$wpdb->show_errors();

$manufacturer = $wpdb->get_row( $wpdb->prepare(
	"SELECT id, name FROM {$wpdb->prefix}aoe_catalog_manufacturers WHERE slug = %s",
	$manufacturer_slug
) );
if ( ! $manufacturer ) {
	die( "Manufacturer not found: $manufacturer_slug\n" );
}

$mfr_id   = (int) $manufacturer->id;
$table_p  = $wpdb->prefix . 'aoe_catalog_products';

// Detect encoding (UTF-16 LE BOM or content)
$raw_header = file_get_contents( $csv_path, false, null, 0, 100 );
$is_utf16   = false;
$encoding   = 'UTF-8';

if ( strpos( $raw_header, "\xFF\xFE" ) === 0 ) {
	$is_utf16 = true;
	$encoding = 'UTF-16 LE';
} elseif ( strpos( $raw_header, "\xFE\xFF" ) === 0 ) {
	$is_utf16 = true;
	$encoding = 'UTF-16 BE';
} elseif ( preg_match( '/[\x00]/', $raw_header ) ) {
	// Null bytes in first 100 chars strongly indicate UTF-16
	$is_utf16 = true;
	$encoding = 'UTF-16 (detected by null bytes)';
}

echo "File encoding: {$encoding}\n";

// Detect separator (read first line in raw bytes, decode to UTF-8 first)
if ( $is_utf16 ) {
	$handle_detect = fopen( $csv_path, 'r' );
	$raw_line      = fgets( $handle_detect );
	fclose( $handle_detect );
	$first_line    = mb_convert_encoding( $raw_line, 'UTF-8', 'UTF-16' );
} else {
	$handle_detect = fopen( $csv_path, 'r' );
	$first_line    = fgets( $handle_detect );
	fclose( $handle_detect );
}

if ( strpos( $first_line, "\t" ) !== false ) {
	$sep = "\t";
} elseif ( strpos( $first_line, ';' ) !== false ) {
	$sep = ';';
} else {
	$sep = ',';
}
echo "Separator detected: " . ( $sep === "\t" ? "TAB" : $sep ) . "\n";

// Create temp table to hold the JSON updates
$temp_table = $table_p . '_specs_tmp';
$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$temp_table}" );
$wpdb->query( "
	CREATE TEMPORARY TABLE {$temp_table} (
		sku varchar(255) NOT NULL,
		specs_json longtext NOT NULL,
		PRIMARY KEY (sku)
	)
" );

// Stream CSV: read line by line, insert in batches (no full in-memory storage)
$handle = fopen( $csv_path, 'r' );

// If UTF-16, skip BOM and attach iconv stream filter to convert to UTF-8
if ( $is_utf16 ) {
	$utf16_variant = ( strpos( $encoding, 'BE' ) !== false ) ? 'UTF-16BE' : 'UTF-16LE';
	// Consume the 2-byte BOM so iconv doesn't produce a UTF-8 BOM
	$bom = fread( $handle, 2 );
	// Stream filter: convert UTF-16 LE/BE → UTF-8 on the fly
	stream_filter_append( $handle, "convert.iconv.{$utf16_variant}/UTF-8//IGNORE" );
}

$headers     = [];
$batch       = [];
$batch_limit = 5000;
$chunk_count = 0;
$skipped     = 0;
$line        = 0;

echo "Importing specs in batches of {$batch_limit}...\n";

while ( ( $row = fgetcsv( $handle, 0, $sep ) ) !== false ) {
	if ( $line === 0 ) {
		$headers = array_map( 'trim', $row );
		echo "Headers: " . count( $headers ) . " columns\n\n";
		$line++;
		continue;
	}
	$line++;
	$row = array_map( 'trim', $row );

	if ( $is_test && $line > 10 ) {
		break;
	}

	$sku = $row[0] ?? '';
	if ( empty( $sku ) ) {
		$skipped++;
		continue;
	}

	$specs = [];
	$col_count = count( $row );
	$header_count = count( $headers );
	for ( $i = 2; $i < $col_count && $i < $header_count; $i++ ) {
		$key   = $headers[ $i ];
		$value = $row[ $i ] ?? '';
		if ( $key !== '' && $value !== '' ) {
			$specs[ $key ] = $value;
		}
	}

	if ( empty( $specs ) ) {
		$skipped++;
		continue;
	}

	$batch[] = $sku;
	$batch[] = json_encode( $specs, JSON_UNESCAPED_UNICODE );

	if ( count( $batch ) >= $batch_limit * 2 ) {
		$batch_size = count( $batch ) / 2;
		insert_batch_temp( $wpdb, $temp_table, $batch );
		$chunk_count += $batch_size;
		echo "  {$chunk_count} rows written to temp table...\n";
		$batch = [];
	}
}

fclose( $handle );

// Last batch
if ( ! empty( $batch ) ) {
	$last_count = count( $batch ) / 2;
	insert_batch_temp( $wpdb, $temp_table, $batch );
	$chunk_count += $last_count;
	echo "  {$chunk_count} rows total written to temp table.\n";
}

echo "\nMerging specs into products table...\n";

// Debug: compare first 10 SKUs from CSV vs DB
$sample_csv = $wpdb->get_results( "SELECT sku FROM {$temp_table} LIMIT 10", ARRAY_A );
$sample_db  = $wpdb->get_results( $wpdb->prepare(
	"SELECT DISTINCT sku FROM {$table_p} WHERE manufacturer_id = %d LIMIT 10",
	$mfr_id
), ARRAY_A );

echo "First 10 SKUs from CSV:\n";
foreach ( $sample_csv as $i => $r ) {
	printf( "  [%d] '%s' (len=%d, hex=%s)\n", $i, $r['sku'], strlen( $r['sku'] ), bin2hex( $r['sku'] ) );
}
echo "First 10 SKUs from DB:\n";
foreach ( $sample_db as $i => $r ) {
	printf( "  [%d] '%s' (len=%d, hex=%s)\n", $i, $r['sku'], strlen( $r['sku'] ), bin2hex( $r['sku'] ) );
}

// Try exact match with first CSV SKU
if ( ! empty( $sample_csv ) ) {
	$test_sku = $sample_csv[0]['sku'];
	$test_db  = $wpdb->get_var( $wpdb->prepare(
		"SELECT sku FROM {$table_p} WHERE manufacturer_id = %d AND sku = %s",
		$mfr_id, $test_sku
	) );
	echo "Exact match for '{$test_sku}': " . ( $test_db ? "FOUND" : "NOT FOUND" ) . "\n";

	// Try searching without the first char (in case of BOM)
	$test_sku_trimmed = preg_replace( '/^[^A-Za-z0-9]/', '', $test_sku );
	if ( $test_sku_trimmed !== $test_sku ) {
		$test_db2 = $wpdb->get_var( $wpdb->prepare(
			"SELECT sku FROM {$table_p} WHERE manufacturer_id = %d AND sku = %s",
			$mfr_id, $test_sku_trimmed
		) );
		echo "Match without first char '{$test_sku_trimmed}': " . ( $test_db2 ? "FOUND" : "NOT FOUND" ) . "\n";
	}
}

// Verify total products count for samtec
$total_db_products = $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$table_p} WHERE manufacturer_id = %d",
	$mfr_id
) );
echo "Total products in DB for samtec: {$total_db_products}\n\n";

$updated      = 0;
$not_found    = 0;
$chunk_size   = 5000;
$last_sku     = '';
$merge_round  = 0;

while ( true ) {
	$spec_rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT sku, specs_json FROM {$temp_table} WHERE sku > %s ORDER BY sku LIMIT %d",
		$last_sku, $chunk_size
	), ARRAY_A );

	if ( empty( $spec_rows ) ) {
		break;
	}
	$merge_round++;

	$skus = array_column( $spec_rows, 'sku' );
	$placeholders = implode( ',', array_fill( 0, count( $skus ), '%s' ) );

	// Bulk-fetch existing additional_data for this chunk
	$existing_rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT sku, additional_data FROM {$table_p} WHERE manufacturer_id = %d AND sku IN ({$placeholders})",
		array_merge( [ $mfr_id ], $skus )
	), ARRAY_A );

	$existing_map = [];
	foreach ( $existing_rows as $r ) {
		$existing_map[ $r['sku'] ] = $r['additional_data'];
	}

	// Build CASE WHEN updates
	$when_clauses = [];
	$params       = [];
	$matched_skus = [];
	foreach ( $spec_rows as $spec ) {
		$sku = $spec['sku'];
		if ( ! isset( $existing_map[ $sku ] ) ) {
			$not_found++;
			continue;
		}
		$meta = $existing_map[ $sku ] ? (array) json_decode( $existing_map[ $sku ], true ) : [];
		$meta['specs'] = json_decode( $spec['specs_json'], true );
		$when_clauses[] = "WHEN %s THEN %s";
		$params[] = $sku;
		$params[] = json_encode( $meta, JSON_UNESCAPED_UNICODE );
		$matched_skus[] = $sku;
		$updated++;
	}

	if ( $is_test ) {
		echo "\nTEST MODE SUMMARY: {$updated} would be updated, {$not_found} not found\n";
		break;
	}

	if ( ! empty( $when_clauses ) ) {
		$in_placeholders = implode( ',', array_fill( 0, count( $matched_skus ), '%s' ) );
		$sql = "UPDATE {$table_p} SET additional_data = CASE sku "
			. implode( ' ', $when_clauses )
			. " END WHERE manufacturer_id = %d AND sku IN ({$in_placeholders})";
		$all_params = array_merge( $params, [ $mfr_id ], $matched_skus );
		$result = $wpdb->query( $wpdb->prepare( $sql, $all_params ) );
		if ( $result === false ) {
			echo "  DB Error on round {$merge_round}: {$wpdb->last_error}\n";
		}
	}

	$last_sku = end( $spec_rows )['sku'];
	echo "  R{$merge_round}: up to {$last_sku} (updated {$updated} so far)\n";
}

$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$temp_table}" );

echo "\nDone. Updated: {$updated}, SKUs not found in DB: {$not_found}, Skipped (no specs): {$skipped}\n";

function insert_batch_temp( $wpdb, $table, array $batch ) {
	$placeholders = [];
	$values = [];
	$chunks = array_chunk( $batch, 2 );
	foreach ( $chunks as $pair ) {
		$placeholders[] = '(%s, %s)';
		$values[] = $pair[0];
		$values[] = $pair[1];
	}
	$sql = "INSERT IGNORE INTO {$table} (sku, specs_json) VALUES "
		. implode( ', ', $placeholders );
	$wpdb->query( $wpdb->prepare( $sql, $values ) );
}

// Invalidate cache
$cache = new \AOE\CatalogEngine\PublicFacing\CacheCatalog();
$cache->invalidate( $manufacturer_slug );
echo "Cache invalidated for {$manufacturer_slug}.\n";
