<?php
/**
 * CLI: Import Wieland category structure + re-attribute products.
 *
 * Reads tools/wieland_categorias_v2.csv (categories with hierarchy) and
 * tools/wieland_productos_v2.csv (products with category_id) to rebuild
 * aoe_catalog_categories for Wieland:
 *   - L1 families + L2 subcategories from categories CSV
 *   - Products re-attributed by SKU -> category_id -> category name
 *   - uncategorized fallback
 *   - regenerates pages via pack_catalog
 *
 * Usage:
 *   php tools/import-wieland-structure.php
 */

if ( PHP_SAPI !== 'cli' ) {
	die( "CLI only.\n" );
}

if ( getenv( 'AOE_DB_HOST' ) && ! defined( 'DB_HOST' ) ) {
	define( 'DB_HOST', getenv( 'AOE_DB_HOST' ) );
}
$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
if ( ! file_exists( $wp_load ) ) {
	$wp_load = dirname( __DIR__, 5 ) . '/wp-load.php';
}
if ( ! file_exists( $wp_load ) ) {
	die( "wp-load.php not found.\n" );
}
require_once $wp_load;

global $wpdb;

$manufacturer_slug = 'wieland';
$cats_file         = __DIR__ . '/wieland_categorias_v2.csv';
$prods_file        = __DIR__ . '/wieland_productos_v2.csv';

$table_c = $wpdb->prefix . 'aoe_catalog_categories';
$table_p = $wpdb->prefix . 'aoe_catalog_products';
$table_m = $wpdb->prefix . 'aoe_catalog_manufacturers';

function wi_cap( string $name ): string {
	$name = trim( $name );
	if ( preg_match( '/^[a-z]/', $name ) ) {
		$name = ucfirst( $name );
	}
	return $name;
}

// ---- Manufacturer ----
$manufacturer = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_m WHERE slug = %s", $manufacturer_slug ) );
if ( ! $manufacturer ) {
	die( "Manufacturer not found: $manufacturer_slug\n" );
}
$mfr_id = (int) $manufacturer->id;

// ---- Parse categories CSV (semicolon) ----
$cats = [];
$fh = fopen( $cats_file, 'r' );
if ( ! $fh ) die( "Cannot open $cats_file\n" );
$hc = fgetcsv( $fh, 0, ';' ); $hc[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $hc[0] );
$ccols = array_map( 'trim', $hc );
while ( ( $r = fgetcsv( $fh, 0, ';' ) ) !== false ) {
	if ( count( $r ) < count( $ccols ) ) continue;
	$row = array_combine( $ccols, array_map( 'trim', $r ) );
	$cats[ (int) $row['category_id'] ] = $row;
}
fclose( $fh );
echo "Categorias CSV: " . count( $cats ) . "\n";

// ---- Parse products CSV (semicolon) ----
$prods = [];
$fh = fopen( $prods_file, 'r' );
if ( ! $fh ) die( "Cannot open $prods_file\n" );
$hp = fgetcsv( $fh, 0, ';' ); $hp[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $hp[0] );
$pcols = array_map( 'trim', $hp );
while ( ( $r = fgetcsv( $fh, 0, ';' ) ) !== false ) {
	if ( count( $r ) < count( $pcols ) ) continue;
	$prods[] = array_combine( $pcols, array_map( 'trim', $r ) );
}
fclose( $fh );
echo "Productos CSV: " . count( $prods ) . "\n";

// ---- Snapshot existing products ----
$products = $wpdb->get_results( $wpdb->prepare(
	"SELECT id, sku, category_id FROM $table_p WHERE manufacturer_id = %d", $mfr_id
) );
echo "Products en DB: " . count( $products ) . "\n";

// ---- Delete existing categories ----
echo "Deleting existing Wieland categories...\n";
$wpdb->delete( $table_c, [ 'manufacturer_id' => $mfr_id ], [ '%d' ] );

// ---- Insert L1 families ----
$l1_by_mkid = [];
$inserted = 0;
foreach ( $cats as $cid => $row ) {
	if ( (int) ( $row['parent_id'] ?? 0 ) !== 0 ) continue;
	$name = trim( $row['name'] ?? '' );
	if ( '' === $name ) continue;
	$meta = [
		'wieland_id' => (string) $cid,
		'url'        => $row['url'] ?? '',
	];
	$meta = array_filter( $meta, static function ( $v ) { return $v !== '' && $v !== null; } );
	$wpdb->insert( $table_c, [
		'manufacturer_id' => $mfr_id,
		'parent_id'       => null,
		'name'            => wi_cap( $name ),
		'slug'            => sanitize_title( $name ),
		'type'            => 'category',
		'description'     => $row['description'] ?? '',
		'image'           => '',
		'level'           => 1,
		'products_count'  => 0,
		'metadata_json'   => wp_json_encode( $meta, JSON_UNESCAPED_SLASHES ),
	], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ] );
	$l1_by_mkid[ $cid ] = (int) $wpdb->insert_id;
	$inserted++;
}
echo "  L1 familias: $inserted\n";

// ---- Insert L2 subcategories ----
$l2_by_mkid = [];
$inserted = 0;
foreach ( $cats as $cid => $row ) {
	$pid = (int) ( $row['parent_id'] ?? 0 );
	if ( $pid <= 0 || ! isset( $l1_by_mkid[ $pid ] ) ) continue;
	$name = trim( $row['name'] ?? '' );
	if ( '' === $name ) continue;
	$meta = [
		'wieland_id' => (string) $cid,
		'url'        => $row['url'] ?? '',
	];
	$meta = array_filter( $meta, static function ( $v ) { return $v !== '' && $v !== null; } );
	$wpdb->insert( $table_c, [
		'manufacturer_id' => $mfr_id,
		'parent_id'       => $l1_by_mkid[ $pid ],
		'name'            => wi_cap( $name ),
		'slug'            => sanitize_title( $name ),
		'type'            => 'category',
		'description'     => $row['description'] ?? '',
		'image'           => '',
		'level'           => 2,
		'products_count'  => 0,
		'metadata_json'   => wp_json_encode( $meta, JSON_UNESCAPED_SLASHES ),
	], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ] );
	$l2_by_mkid[ $cid ] = (int) $wpdb->insert_id;
	$inserted++;
}
echo "  L2 subcategorias: $inserted\n";

// ---- Build category_id => db_id map ----
$all_cat_ids = $l1_by_mkid + $l2_by_mkid;

// ---- Uncategorized fallback ----
$wpdb->insert( $table_c, [
	'manufacturer_id' => $mfr_id,
	'parent_id'       => null,
	'name'            => 'Uncategorized',
	'slug'            => 'uncategorized',
	'type'            => 'category',
	'description'     => '',
	'image'           => '',
	'level'           => 1,
	'products_count'  => 0,
	'metadata_json'   => '[]',
], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ] );
$uncat_db = (int) $wpdb->insert_id;
echo "  Fallback: uncategorized\n";

// ---- Re-attribute products by SKU -> category_id -> db_id ----
$prods_by_sku = [];
foreach ( $prods as $row ) {
	$sku = strtoupper( trim( $row['sku'] ?? '' ) );
	$cat_id = (int) ( $row['category'] ?? 0 );
	if ( '' === $sku ) continue;
	$prods_by_sku[ $sku ] = $cat_id;
}
echo "  SKU->cat map: " . count( $prods_by_sku ) . " entries\n";
echo "  all_cat_ids map: " . count( $all_cat_ids ) . " entries\n";

$remapped = 0;
$to_uncat = 0;
foreach ( $products as $p ) {
	$sku    = strtoupper( trim( $p->sku ) );
	$new_id = null;
	if ( isset( $prods_by_sku[ $sku ] ) ) {
		$cat_id = $prods_by_sku[ $sku ];
		if ( isset( $all_cat_ids[ $cat_id ] ) ) {
			$new_id = $all_cat_ids[ $cat_id ];
		}
	}
	if ( null === $new_id ) {
		$new_id = $uncat_db;
		$to_uncat++;
	} else {
		$remapped++;
	}
	$wpdb->update( $table_p, [ 'category_id' => $new_id ], [ 'id' => $p->id ], [ '%d' ], [ '%d' ] );
}
echo "Products re-attribute: $remapped | to uncategorized: $to_uncat | total DB: " . count( $products ) . "\n";

// ---- Verify products_count ----
$all_cats = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $table_c WHERE manufacturer_id = %d", $mfr_id ) );
foreach ( $all_cats as $cid ) {
	$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table_p WHERE category_id = %d", (int) $cid ) );
	$wpdb->update( $table_c, [ 'products_count' => $count ], [ 'id' => $cid ], [ '%d' ], [ '%d' ] );
}

// ---- Regenerate pages ----
echo "\nRegenerando paginas...\n";
$processor_mgr = new \AOE\CatalogEngine\Import\ProcessorManager();
$bp = new \AOE\CatalogEngine\Import\BatchProcessor( $processor_mgr );
$bp->pack_catalog( $mfr_id, $manufacturer_slug, $processor_mgr->get_processor( $manufacturer_slug ) );
echo "Paginas regeneradas.\n\nDone.\n";
