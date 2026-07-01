<?php
/**
 * Add missing database indexes for performance.
 *
 * Usage: php tools/add-indexes.php
 */

if ( PHP_SAPI !== 'cli' ) {
	die( 'This script must be run via CLI.' );
}

// Bootstrap WordPress
$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
if ( ! file_exists( $wp_load ) ) {
	$wp_load = dirname( __DIR__, 5 ) . '/wp-load.php';
}
if ( ! file_exists( $wp_load ) ) {
	die( "Could not find wp-load.php. Run from plugin root or adjust path.\n" );
}
require_once $wp_load;

global $wpdb;

$prefix = $wpdb->prefix;
$indexes = [
	[
		'table'  => $prefix . 'aoe_catalog_categories',
		'name'   => 'slug',
		'column' => 'slug',
		'sql'    => "ALTER TABLE {$prefix}aoe_catalog_categories ADD INDEX slug (slug)",
	],
	[
		'table'  => $prefix . 'aoe_catalog_categories',
		'name'   => 'idx_manufacturer_slug',
		'column' => 'manufacturer_id, slug',
		'sql'    => "ALTER TABLE {$prefix}aoe_catalog_categories ADD INDEX idx_manufacturer_slug (manufacturer_id, slug)",
	],
	[
		'table'  => $prefix . 'aoe_catalog_pregenerated_pages',
		'name'   => 'slug',
		'column' => 'slug',
		'sql'    => "ALTER TABLE {$prefix}aoe_catalog_pregenerated_pages ADD INDEX slug (slug)",
	],
];

$added = 0;
$skipped = 0;

foreach ( $indexes as $idx ) {
	// Check if index already exists
	$existing = $wpdb->get_results( "SHOW INDEX FROM {$idx['table']} WHERE Key_name = '{$idx['name']}'" );
	if ( ! empty( $existing ) ) {
		echo "SKIP: index '{$idx['name']}' already exists on {$idx['table']}\n";
		$skipped++;
		continue;
	}

	$result = $wpdb->query( $idx['sql'] );
	if ( false === $result ) {
		echo "ERROR: could not add index '{$idx['name']}' on {$idx['table']}: {$wpdb->last_error}\n";
	} else {
		echo "OK: added index '{$idx['name']}' on {$idx['table']} ({$idx['column']})\n";
		$added++;
	}
}

echo "\nDone. Added: $added, Skipped: $skipped\n";
