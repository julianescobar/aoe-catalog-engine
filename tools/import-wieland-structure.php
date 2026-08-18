<?php
/**
 * CLI: Import Wieland category structure + re-attribute products.
 *
 * Reads tools/wieland_productos.csv and extracts unique categories from the
 * "category" column. Creates flat (no hierarchy) category rows and re-attributes
 * products by SKU → category name.
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
$prods_file        = __DIR__ . '/wieland_productos.csv';

$table_c = $wpdb->prefix . 'aoe_catalog_categories';
$table_p = $wpdb->prefix . 'aoe_catalog_products';
$table_m = $wpdb->prefix . 'aoe_catalog_manufacturers';

// ---- Manufacturer ----
$manufacturer = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_m WHERE slug = %s", $manufacturer_slug ) );
if ( ! $manufacturer ) {
	die( "Manufacturer not found: $manufacturer_slug\n" );
}
$mfr_id = (int) $manufacturer->id;

// ---- Parse products CSV -> SKU => category name ----
$prods_by_sku = [];
$cat_counts   = [];
$fh = fopen( $prods_file, 'r' );
if ( ! $fh ) die( "Cannot open $prods_file\n" );
$hp = fgetcsv( $fh, 0, ';' );
$hp[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $hp[0] );
$pcols = array_map( 'trim', $hp );
$idx_sku = array_search( 'sku', $pcols, true );
$idx_cat = array_search( 'category', $pcols, true );
if ( false === $idx_sku ) die( "Missing sku column\n" );
while ( ( $r = fgetcsv( $fh, 0, ';' ) ) !== false ) {
	if ( count( $r ) < count( $pcols ) ) continue;
	$sku  = trim( $r[ $idx_sku ] );
	$cat  = false !== $idx_cat ? trim( $r[ $idx_cat ] ) : '';
	if ( '' === $sku ) continue;
	if ( '' === $cat ) $cat = 'Uncategorized';
	$prods_by_sku[ $sku ] = $cat;
	$cat_counts[ $cat ]   = ( $cat_counts[ $cat ] ?? 0 ) + 1;
}
fclose( $fh );
echo "SKUs: " . count( $prods_by_sku ) . "\n";
echo "Categorias unicas: " . count( $cat_counts ) . "\n";
foreach ( $cat_counts as $c => $n ) echo "  $n\t$c\n";

// ---- Snapshot existing products ----
$products = $wpdb->get_results( $wpdb->prepare(
	"SELECT id, sku, category_id FROM $table_p WHERE manufacturer_id = %d", $mfr_id
) );
echo "\nProducts en DB: " . count( $products ) . "\n";

// ---- Delete existing categories ----
echo "Deleting existing Wieland categories...\n";
$wpdb->delete( $table_c, [ 'manufacturer_id' => $mfr_id ], [ '%d' ] );

// ---- Insert flat categories ----
$cat_db_ids = [];  // category name => db id
foreach ( $cat_counts as $cat_name => $count ) {
	$slug = sanitize_title( $cat_name );
	if ( '' === $slug ) {
		$slug = sanitize_title( 'cat-' . md5( $cat_name ) );
	}
	$wpdb->insert( $table_c, [
		'manufacturer_id' => $mfr_id,
		'parent_id'       => null,
		'name'            => $cat_name,
		'slug'            => $slug,
		'type'            => 'category',
		'description'     => '',
		'image'           => '',
		'level'           => 1,
		'products_count'  => $count,
		'metadata_json'   => '[]',
	], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ] );
	$cat_db_ids[ $cat_name ] = (int) $wpdb->insert_id;
}
echo "Inserted: " . count( $cat_db_ids ) . " categories\n";

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
echo "Fallback: uncategorized\n";

// ---- Re-attribute products by SKU -> category name ----
$remapped = 0;
$to_uncat = 0;
foreach ( $products as $p ) {
	$sku    = $p->sku;
	$new_id = null;
	if ( isset( $prods_by_sku[ $sku ] ) ) {
		$cat_name = $prods_by_sku[ $sku ];
		if ( isset( $cat_db_ids[ $cat_name ] ) ) {
			$new_id = $cat_db_ids[ $cat_name ];
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

// ---- products_count (verify) ----
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
