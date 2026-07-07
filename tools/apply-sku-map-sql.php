<?php
/**
 * CLI: Apply sku_map to products via direct SQL (no per-product loop).
 *
 * Usage: php tools/apply-sku-map-sql.php samtec [--dry-run]
 */

if ( ! in_array( PHP_SAPI, [ 'cli', 'cgi-fcgi' ], true ) ) die( 'CLI only' );

$manufacturer_slug = $argv[1] ?? '';
$dry_run = in_array( '--dry-run', $argv );
if ( empty( $manufacturer_slug ) ) die( "Usage: php tools/apply-sku-map-sql.php <slug> [--dry-run]\n" );

require_once __DIR__ . '/../../../../wp-load.php';

global $wpdb;
$mfr = $wpdb->get_row( $wpdb->prepare(
	"SELECT * FROM {$wpdb->prefix}aoe_catalog_manufacturers WHERE slug = %s", $manufacturer_slug
) );
if ( ! $mfr ) die( "Manufacturer not found\n" );
$mfr_id = (int) $mfr->id;

$map_table = $wpdb->prefix . 'aoe_catalog_sku_map';
$cat_table = $wpdb->prefix . 'aoe_catalog_categories';
$prod_table = $wpdb->prefix . 'aoe_catalog_products';

echo "Manufacturer: {$mfr->name} (ID: $mfr_id)\n\n";

// Step 1: Create missing level-4 categories from unique codigo_serie
echo "Step 1: Creating missing level-4 categories...\n";
$unique_series = $wpdb->get_col( $wpdb->prepare(
	"SELECT DISTINCT codigo_serie FROM $map_table WHERE manufacturer_id = %d", $mfr_id
) );
echo "  Unique codigo_serie: " . count( $unique_series ) . "\n";

$created = 0;
foreach ( $unique_series as $cs ) {
	$slug = sanitize_title( $cs );
	if ( empty( $slug ) ) continue;
	$exists = $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM $cat_table WHERE manufacturer_id = %d AND slug = %s", $mfr_id, $slug
	) );
	if ( ! $exists ) {
		$prefix = strtolower( preg_replace( '/[^a-zA-Z0-9]+.*$/', '', $slug ) );
		$parent_id = null;
		if ( ! empty( $prefix ) ) {
			$parent_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM $cat_table WHERE manufacturer_id = %d AND level = 3 AND (slug = %s OR slug LIKE %s) LIMIT 1",
				$mfr_id, $prefix, $prefix . '-%'
			) );
		}
		$wpdb->insert( $cat_table, [
			'manufacturer_id' => $mfr_id,
			'parent_id'       => $parent_id ?: null,
			'name'            => $cs,
			'slug'            => $slug,
			'type'            => 'category',
			'level'           => 4,
			'products_count'  => 0,
			'metadata_json'   => '[]',
		], [ '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s' ] );
		$created++;
	}
}
echo "  Created: $created\n";

// Step 2: Build temp table: codigo_serie → correct category_id
echo "\nStep 2: Building codigo_serie → category_id map...\n";
$tmp_table = $wpdb->prefix . 'aoe_temp_cs_map';
$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS $tmp_table" );
$wpdb->query( "CREATE TEMPORARY TABLE $tmp_table (
	codigo_serie varchar(255) NOT NULL,
	category_id bigint(20) unsigned NOT NULL,
	PRIMARY KEY (codigo_serie)
) ENGINE=InnoDB" );

$batch = [];
foreach ( $unique_series as $cs ) {
	$slug = sanitize_title( $cs );
	if ( empty( $slug ) ) continue;
	$cat_id = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM $cat_table WHERE manufacturer_id = %d AND slug = %s", $mfr_id, $slug
	) );
	if ( ! $cat_id ) continue;
	$batch[] = $wpdb->prepare( "(%s, %d)", $cs, $cat_id );
	if ( count( $batch ) >= 100 ) {
		$wpdb->query( "INSERT IGNORE INTO $tmp_table VALUES " . implode( ',', $batch ) );
		$batch = [];
	}
}
if ( $batch ) {
	$wpdb->query( "INSERT IGNORE INTO $tmp_table VALUES " . implode( ',', $batch ) );
}
echo "  Temp table populated.\n";

// Step 3: Count what needs updating
echo "\nStep 3: Counting affected products...\n";
$to_update = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM $prod_table p
	 JOIN $map_table m ON p.sku = m.sku AND m.manufacturer_id = p.manufacturer_id
	 JOIN $tmp_table t ON m.codigo_serie = t.codigo_serie
	 WHERE p.manufacturer_id = %d AND p.category_id != t.category_id",
	$mfr_id
) );
echo "  Products to update: $to_update\n";

// Step 4: Single UPDATE JOIN
echo "\nStep 4: Updating products...\n";
if ( $to_update > 0 && ! $dry_run ) {
	$affected = $wpdb->query( $wpdb->prepare(
		"UPDATE $prod_table p
		 JOIN $map_table m ON p.sku = m.sku AND m.manufacturer_id = p.manufacturer_id
		 JOIN $tmp_table t ON m.codigo_serie = t.codigo_serie
		 SET p.category_id = t.category_id
		 WHERE p.manufacturer_id = %d AND p.category_id != t.category_id",
		$mfr_id
	) );
	echo "  Updated: $affected\n";
} elseif ( $to_update > 0 ) {
	echo "  Skipped (dry run).\n";
} else {
	echo "  Nothing to update.\n";
}

// Step 5: Recalculate products_count
echo "\nStep 5: Recalculating products_count...\n";
if ( ! $dry_run ) {
	$wpdb->query( $wpdb->prepare(
		"UPDATE $cat_table c
		 SET c.products_count = (SELECT COUNT(*) FROM $prod_table p WHERE p.category_id = c.id)
		 WHERE c.manufacturer_id = %d",
		$mfr_id
	) );
	echo "  Done.\n";
} else {
	echo "  Skipped (dry run).\n";
}

$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS $tmp_table" );
echo "\nFinished.\n";
