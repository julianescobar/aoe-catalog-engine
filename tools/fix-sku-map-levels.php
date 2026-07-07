<?php
/**
 * Fix existing sku_map categories: update level from 0 to 4 and find parent_id.
 *
 * Usage:
 *   php tools/fix-sku-map-levels.php <manufacturer_slug> [--dry-run]
 *
 * This script:
 *   1. Finds all categories with level=0 and parent_id=null (created by apply-sku-map.php)
 *   2. For each, tries to find a parent level-3 category by slug prefix match
 *   3. Updates level to 4 and sets parent_id
 */

if ( ! in_array( PHP_SAPI, [ 'cli', 'cgi-fcgi' ], true ) ) {
	die( 'CLI only' );
}

$manufacturer_slug = $argv[1] ?? '';
$dry_run = in_array( '--dry-run', $argv );
if ( empty( $manufacturer_slug ) ) {
	die( "Usage: php tools/fix-sku-map-levels.php <manufacturer_slug> [--dry-run]\n" );
}
if ( $dry_run ) {
	echo "*** DRY RUN — no changes will be made ***\n";
}

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

$mfr = $wpdb->get_row( $wpdb->prepare(
	"SELECT * FROM {$wpdb->prefix}aoe_catalog_manufacturers WHERE slug = %s",
	$manufacturer_slug
) );
if ( ! $mfr ) {
	die( "Manufacturer not found: $manufacturer_slug\n" );
}
$mfr_id = (int) $mfr->id;
$cat_table = $wpdb->prefix . 'aoe_catalog_categories';

echo "Manufacturer: {$mfr->name} (ID: $mfr_id)\n";

// Find level-0 categories created by sku_map
$orphans = $wpdb->get_results( $wpdb->prepare(
	"SELECT id, name, slug, parent_id, level, products_count
	 FROM $cat_table
	 WHERE manufacturer_id = %d AND level = 0 AND parent_id IS NULL
	 ORDER BY products_count DESC",
	$mfr_id
) );

echo "Found " . count( $orphans ) . " level-0 orphan categories.\n\n";

$fixed = 0;
$with_parent = 0;
foreach ( $orphans as $cat ) {
	// Try to find parent from existing level-3 categories
	$parent_id = null;
	$prefix = strtolower( preg_replace( '/[^a-zA-Z0-9]+.*$/', '', $cat->slug ) );
	if ( ! empty( $prefix ) ) {
		$found = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM $cat_table
			 WHERE manufacturer_id = %d AND level = 3 AND (slug = %s OR slug LIKE %s)
			 LIMIT 1",
			$mfr_id, $prefix, $prefix . '-%'
		) );
		if ( $found ) {
			$parent_id = (int) $found;
		}
	}

	if ( $parent_id ) {
		$with_parent++;
	}

	if ( ! $dry_run ) {
		$wpdb->update(
			$cat_table,
			[ 'level' => 4, 'parent_id' => $parent_id ],
			[ 'id' => $cat->id ],
			[ '%d', '%d' ],
			[ '%d' ]
		);
	}

	$parent_info = $parent_id ? "parent_id=$parent_id" : "no parent";
	echo "  {$cat->slug} (products: {$cat->products_count}) → level=4 {$parent_info}\n";
	$fixed++;
}

echo "\nDone. Fixed $fixed categories ($with_parent with parent found).\n";

if ( ! $dry_run ) {
	echo "\nRegenerating pages...\n";
	require_once __DIR__ . '/../vendor/autoload.php';
	$processor_mgr = new \AOE\CatalogEngine\Import\ProcessorManager();
	$batch = new \AOE\CatalogEngine\Import\BatchProcessor( $processor_mgr );
	$batch->pack_catalog( $mfr_id, $manufacturer_slug, $processor_mgr->get_processor( $manufacturer_slug ) );
	echo "Pages regenerated.\n";
}
