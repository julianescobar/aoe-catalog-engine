<?php
/**
 * Diagnostic: measure each step of pack_catalog() to find the bottleneck.
 *
 * Usage:
 *   php tools/diag-pack-speed.php samtec
 */
if ( ! in_array( PHP_SAPI, [ 'cli', 'cgi-fcgi' ], true ) ) {
	die( 'CLI only' );
}

$manufacturer_slug = $argv[1] ?? '';
if ( empty( $manufacturer_slug ) ) {
	die( "Usage: php tools/diag-pack-speed.php <manufacturer_slug>\n" );
}

$wp_load = __DIR__ . '/../../../../../wp-load.php';
if ( ! file_exists( $wp_load ) ) { $wp_load = __DIR__ . '/../../../../wp-load.php'; }
if ( ! file_exists( $wp_load ) ) { $wp_load = __DIR__ . '/../../../wp-load.php'; }
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
$table_cat = $wpdb->prefix . 'aoe_catalog_categories';
$table_prod = $wpdb->prefix . 'aoe_catalog_products';
$table_page = $wpdb->prefix . 'aoe_catalog_pregenerated_pages';
$table_seg = $wpdb->prefix . 'aoe_catalog_page_segments';

echo "=== Diagnostic: {$mfr->name} (ID=$mfr_id) ===\n\n";

// Helper
function timed( $label, $callback ) {
	$start = microtime( true );
	$result = $callback();
	$elapsed = round( ( microtime( true ) - $start ) * 1000 );
	echo "  $label: {$elapsed}ms\n";
	return $result;
}

timed( 'Bootstrap', function() {} );

$cat_count = timed( 'COUNT categories', function() use ( $wpdb, $table_cat, $mfr_id ) {
	return $wpdb->get_var( "SELECT COUNT(1) FROM $table_cat WHERE manufacturer_id = $mfr_id" );
} );
echo "    -> $cat_count categories\n";

$prod_count = timed( 'COUNT products', function() use ( $wpdb, $table_prod, $mfr_id ) {
	return $wpdb->get_var( "SELECT COUNT(1) FROM $table_prod WHERE manufacturer_id = $mfr_id" );
} );
echo "    -> $prod_count products\n";

$l4_count = timed( 'COUNT level-4', function() use ( $wpdb, $table_cat, $mfr_id ) {
	return $wpdb->get_var( "SELECT COUNT(1) FROM $table_cat WHERE manufacturer_id = $mfr_id AND level = 4" );
} );
echo "    -> $l4_count level-4 categories\n";

// Step 1: products_count UPDATE (correlated subquery)
timed( 'UPDATE products_count (subquery)', function() use ( $wpdb, $table_cat, $table_prod, $mfr_id ) {
	$wpdb->query( "UPDATE $table_cat c SET c.products_count = (
		SELECT COUNT(*) FROM $table_prod p WHERE p.category_id = c.id AND p.manufacturer_id = $mfr_id
	) WHERE c.manufacturer_id = $mfr_id" );
} );

// Step 2: products_count with JOIN instead
timed( 'UPDATE products_count (JOIN)', function() use ( $wpdb, $table_cat, $table_prod, $mfr_id ) {
	$wpdb->query( "UPDATE $table_cat c
		LEFT JOIN (SELECT category_id, COUNT(*) as cnt FROM $table_prod WHERE manufacturer_id = $mfr_id GROUP BY category_id) p ON p.category_id = c.id
		SET c.products_count = COALESCE(p.cnt, 0)
		WHERE c.manufacturer_id = $mfr_id" );
} );

// Step 3: fetch categories
$cats = timed( 'SELECT categories with content', function() use ( $wpdb, $table_cat, $mfr_id ) {
	return $wpdb->get_results( "SELECT id, name, slug, level, products_count, description, metadata_json, image FROM $table_cat WHERE manufacturer_id = $mfr_id AND (products_count > 0 OR (description IS NOT NULL AND description != '') OR (metadata_json IS NOT NULL AND metadata_json != '[]' AND metadata_json != '{}') OR (image IS NOT NULL AND image != '')) ORDER BY id ASC" );
} );
echo "    -> " . count( $cats ) . " categories returned\n";

// Step 4: fetch all categories for tree
$all_names = timed( 'SELECT all categories for tree', function() use ( $wpdb, $table_cat, $mfr_id ) {
	return $wpdb->get_results( "SELECT id, name, slug, parent_id, level, products_count FROM $table_cat WHERE manufacturer_id = $mfr_id ORDER BY COALESCE(parent_id, 0) ASC, level ASC, id ASC" );
} );
echo "    -> " . count( $all_names ) . " categories returned\n";

// Step 5: clear old pages
timed( 'DELETE old pages', function() use ( $wpdb, $table_page, $table_seg, $mfr_id ) {
	$wpdb->delete( $table_page, [ 'manufacturer_id' => $mfr_id ], [ '%d' ] );
	$wpdb->delete( $table_seg, [ 'manufacturer_id' => $mfr_id ], [ '%d' ] );
} );

// Step 6: test single INSERT page
timed( 'INSERT 1 page', function() use ( $wpdb, $table_page, $mfr_id ) {
	$wpdb->insert( $table_page, [
		'manufacturer_id' => $mfr_id, 'type' => 'test', 'slug' => 'test-speed-1',
		'page_number' => 1, 'link_count' => 0,
	], [ '%d', '%s', '%s', '%d', '%d' ] );
} );

$test_page_id = (int) $wpdb->insert_id;

timed( 'INSERT 1 segment', function() use ( $wpdb, $table_seg, $mfr_id, $test_page_id ) {
	$wpdb->insert( $table_seg, [
		'page_id' => $test_page_id, 'manufacturer_id' => $mfr_id, 'category_id' => 1,
		'segment_type' => 'test', 'products_from' => 0, 'products_to' => 0, 'sort_order' => 1,
	], [ '%d', '%d', '%d', '%s', '%d', '%d', '%d' ] );
} );

// Cleanup test
$wpdb->delete( $table_page, [ 'id' => $test_page_id ], [ '%d' ] );
$wpdb->delete( $table_seg, [ 'page_id' => $test_page_id ], [ '%d' ] );

// Step 7: test batch INSERT 100 segments
timed( 'INSERT 100 segments (batch)', function() use ( $wpdb, $table_seg, $mfr_id, $test_page_id ) {
	$values = [];
	$placeholders = [];
	for ( $i = 0; $i < 100; $i++ ) {
		$placeholders[] = '(%d, %d, %d, %s, %d, %d, %d)';
		$values = array_merge( $values, [ $test_page_id, $mfr_id, 1, 'test', 0, 0, $i ] );
	}
	$sql = "INSERT INTO $table_seg (page_id, manufacturer_id, category_id, segment_type, products_from, products_to, sort_order) VALUES " . implode( ', ', $placeholders );
	$wpdb->query( $wpdb->prepare( $sql, $values ) );
} );

echo "\nDone.\n";
