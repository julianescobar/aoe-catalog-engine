<?php
if ( PHP_SAPI !== 'cli' ) {
	die( 'CLI only' );
}

if ( getenv( 'DB_HOST' ) ) {
	define( 'DB_HOST', getenv( 'DB_HOST' ) );
}

$wp_load = getenv( 'WP_LOAD' ) ?: __DIR__ . '/../../../../wp-load.php';
if ( ! file_exists( $wp_load ) ) {
	echo "WordPress not found at $wp_load. Set WP_LOAD env var.\n";
	exit( 1 );
}
require_once $wp_load;

global $wpdb;
$prefix = $wpdb->prefix;

$tables = [
	'aoe_catalog_manufacturers',
	'aoe_catalog_categories',
	'aoe_catalog_products',
	'aoe_catalog_pregenerated_pages',
	'aoe_catalog_page_segments',
];

$output = "-- AOE Catalog Engine data export\n";
$output .= "-- Generated: " . date( 'Y-m-d H:i:s' ) . "\n";
$output .= "-- Prefix: {$prefix}\n\n";
$output .= "SET NAMES utf8mb4;\n\n";

foreach ( $tables as $table ) {
	$full_table = $prefix . $table;
	$rows = $wpdb->get_results( "SELECT * FROM `{$full_table}`", ARRAY_A );
	if ( empty( $rows ) ) {
		$output .= "-- Table {$full_table} is empty\n\n";
		continue;
	}

	$columns = array_keys( $rows[0] );
	$col_list = '`' . implode( '`, `', $columns ) . '`';

	foreach ( $rows as $row ) {
		$values = [];
		foreach ( $columns as $col ) {
			$val = $row[ $col ];
			if ( $val === null ) {
				$values[] = 'NULL';
			} else {
				$values[] = "'" . esc_sql( $val ) . "'";
			}
		}
		$vals = implode( ', ', $values );
		$output .= "INSERT INTO `{$full_table}` ({$col_list}) VALUES ({$vals});\n";
	}
	$output .= "\n";
}

$file = getenv( 'OUTPUT' ) ?: __DIR__ . '/aoe-catalog-export-' . date( 'Ymd_His' ) . '.sql';
file_put_contents( $file, $output );
echo "Export saved: $file\n";
echo "Size: " . round( filesize( $file ) / 1024 / 1024, 2 ) . " MB\n";
