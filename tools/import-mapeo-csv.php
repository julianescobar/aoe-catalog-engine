<?php
/**
 * CLI: Import mapeo.csv into wp_aoe_catalog_sku_map.
 *
 * Usage:
 *   php tools/import-mapeo-csv.php samtec mapeo.csv
 *
 * CSV format: sku,codigo_serie (one per line, no header or with header)
 * Example:
 *   QMSS-048-01-H-D-DP-EM2,QMSS-DP
 *   135-J-P-VP-ST-CM-1,135
 */

if ( ! in_array( PHP_SAPI, [ 'cli', 'cgi-fcgi' ], true ) ) {
	die( 'CLI only' );
}

$manufacturer_slug = $argv[1] ?? '';
$csv_path          = $argv[2] ?? '';

if ( empty( $manufacturer_slug ) || empty( $csv_path ) ) {
	die( "Usage: php tools/import-mapeo-csv.php <manufacturer_slug> <path/to/mapeo.csv>\n" );
}

if ( ! file_exists( $csv_path ) ) {
	die( "File not found: $csv_path\n" );
}

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

$manufacturer = $wpdb->get_row( $wpdb->prepare(
	"SELECT * FROM {$wpdb->prefix}aoe_catalog_manufacturers WHERE slug = %s",
	$manufacturer_slug
) );
if ( ! $manufacturer ) {
	die( "Manufacturer not found: $manufacturer_slug\n" );
}

$mfr_id    = (int) $manufacturer->id;
$map_table = $wpdb->prefix . 'aoe_catalog_sku_map';

// Create table if it doesn't exist yet
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
$charset_collate = $wpdb->get_charset_collate();
$sql = "CREATE TABLE IF NOT EXISTS $map_table (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	manufacturer_id bigint(20) unsigned NOT NULL,
	sku varchar(255) NOT NULL,
	codigo_serie varchar(255) NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY manufacturer_sku (manufacturer_id, sku),
	KEY codigo_serie (codigo_serie)
) $charset_collate;";
dbDelta( $sql );

// Clear existing mapping for this manufacturer
$wpdb->delete( $map_table, [ 'manufacturer_id' => $mfr_id ], [ '%d' ] );
echo "Cleared existing sku_map for {$manufacturer->name}.\n";

$handle = fopen( $csv_path, 'r' );
if ( ! $handle ) {
	die( "Cannot open file: $csv_path\n" );
}

$batch      = [];
$batch_size = 1000;
$total      = 0;
$skipped    = 0;
$line       = 0;

echo "Importing...\n";

while ( ( $row = fgetcsv( $handle ) ) !== false ) {
	$line++;

	// Skip header if present (first line with "sku")
	if ( $line === 1 && isset( $row[0] ) && strtolower( trim( $row[0] ) ) === 'sku' ) {
		continue;
	}

	$sku        = trim( $row[0] ?? '' );
	$codigo_serie = trim( $row[1] ?? '' );

	if ( empty( $sku ) || empty( $codigo_serie ) ) {
		$skipped++;
		continue;
	}

	$batch[] = [ $mfr_id, $sku, $codigo_serie ];

	if ( count( $batch ) >= $batch_size ) {
		$total += insert_batch( $wpdb, $map_table, $batch );
		$batch = [];
		echo "  $total rows imported...\n";
	}
}

// Last batch
if ( ! empty( $batch ) ) {
	$total += insert_batch( $wpdb, $map_table, $batch );
}

fclose( $handle );

echo "\nDone. Imported $total rows ($skipped skipped).\n";

function insert_batch( $wpdb, $table, array $rows ) {
	$values = [];
	$placeholders = [];

	foreach ( $rows as $row ) {
		$placeholders[] = '(%d, %s, %s)';
		$values[] = $row[0];
		$values[] = $row[1];
		$values[] = $row[2];
	}

	$sql = "INSERT IGNORE INTO $table (manufacturer_id, sku, codigo_serie) VALUES "
		. implode( ', ', $placeholders );

	$wpdb->query( $wpdb->prepare( $sql, $values ) );

	return count( $rows );
}
