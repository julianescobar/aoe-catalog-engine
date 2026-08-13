<?php
/**
 * CLI: Import Amphenol Anytek category structure + re-attribute products.
 *
 * Reads tools/categoriassamphenoanytek.csv (L1 group/listing -> L2 -> L3 hierarchy,
 * with anytek category ids/urls) and tools/productosamphenoanytek.csv (products)
 * and rebuilds the aoe_catalog_categories rows for amphenolanytek:
 *   - L1 (level 1), L2 (level 2), L3 (level 3) with metadata anytek_id + url
 *   - numeric-name "series" rows (kind=series && name==category_id) are skipped:
 *     they are junk rows whose ids collide with real structural categories
 *   - uncategorized fallback
 *   - re-attributes existing products to the rebuilt categories by SKU -> list_cate_id
 *   - prunes empty categories (bottom-up)
 *   - regenerates pages via pack_catalog
 *
 * IMPORTANT ORDER (like Yokowo):
 *   1) php tools/full-import.php --manufacturer=amphenolanytek --csv=tools/productosamphenoanytek.csv --mode=replace
 *   2) php tools/import-anytek-structure.php
 *
 * Usage:
 *   php tools/import-anytek-structure.php
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

$manufacturer_slug = 'amphenolanytek';
$cats_file         = __DIR__ . '/categoriassamphenoanytek.csv';
$prods_file        = __DIR__ . '/productosamphenoanytek.csv';

$table_c = $wpdb->prefix . 'aoe_catalog_categories';
$table_p = $wpdb->prefix . 'aoe_catalog_products';
$table_m = $wpdb->prefix . 'aoe_catalog_manufacturers';

// ---- Manufacturer ----
$manufacturer = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_m WHERE slug = %s", $manufacturer_slug ) );
if ( ! $manufacturer ) {
	die( "Manufacturer not found: $manufacturer_slug (create it in the admin first)\n" );
}
$mfr_id = (int) $manufacturer->id;

// ---- Parse categories CSV (semicolon, BOM header) ----
$cats = [];
$fh = fopen( $cats_file, 'r' );
if ( ! $fh ) die( "Cannot open $cats_file\n" );
$h = fgetcsv( $fh, 0, ';' ); $h[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $h[0] );
$cols = array_map( 'trim', $h );
while ( ( $r = fgetcsv( $fh, 0, ';' ) ) !== false ) {
	if ( count( $r ) < count( $cols ) ) continue;
	$row = array_combine( $cols, array_map( 'trim', $r ) );
	// Skip junk numeric-name series rows whose ids collide with structural categories
	if ( ( $row['kind'] ?? '' ) === 'series' && ( $row['name'] ?? '' ) === $row['category_id'] ) {
		continue;
	}
	$cats[ $row['category_id'] ] = $row;
}
fclose( $fh );
echo "Categorias CSV (tras filtrar series numericas): " . count( $cats ) . "\n";

// ---- Parse products CSV (semicolon) -> SKU => list_cate_id ----
$prods_by_sku = [];
$fh = fopen( $prods_file, 'r' );
if ( ! $fh ) die( "Cannot open $prods_file\n" );
$hp = fgetcsv( $fh, 0, ';' ); $hp[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $hp[0] );
$pcols = array_map( 'trim', $hp );
$idx_sku = array_search( 'catalogue_pn', $pcols, true );
$idx_cat = array_search( 'list_cate_id', $pcols, true );
if ( false === $idx_sku || false === $idx_cat ) die( "Missing catalogue_pn/list_cate_id columns\n" );
while ( ( $r = fgetcsv( $fh, 0, ';' ) ) !== false ) {
	if ( count( $r ) < count( $pcols ) ) continue;
	$sku = strtoupper( trim( $r[ $idx_sku ] ) );
	if ( '' === $sku ) continue;
	$prods_by_sku[ $sku ] = trim( $r[ $idx_cat ] );
}
fclose( $fh );
echo "SKUs en CSV de productos: " . count( $prods_by_sku ) . "\n";

// ---- Snapshot existing products ----
$products = $wpdb->get_results( $wpdb->prepare(
	"SELECT id, sku, category_id FROM $table_p WHERE manufacturer_id = %d", $mfr_id
) );
echo "Productos en DB: " . count( $products ) . "\n";

// ---- Delete existing categories ----
echo "Deleting existing Anytek categories...\n";
$wpdb->delete( $table_c, [ 'manufacturer_id' => $mfr_id ], [ '%d' ] );

// ---- Insert categories level by level ----
$db_by_anytek = []; // anytek_id => db_id
$counts = [ 'L1' => 0, 'L2' => 0, 'L3' => 0 ];
foreach ( [ '1', '2', '3' ] as $level ) {
	foreach ( $cats as $aid => $row ) {
		if ( ( $row['level'] ?? '' ) !== $level ) continue;
		$parent_db = null;
		$parent_aid = trim( $row['parent_id'] ?? '' );
		if ( $level !== '1' && $parent_aid !== '' && isset( $db_by_anytek[ $parent_aid ] ) ) {
			$parent_db = $db_by_anytek[ $parent_aid ];
		}
		$meta = [
			'anytek_id' => $aid,
			'url'       => $row['url'] ?? '',
		];
		$meta = array_filter( $meta, static function ( $v ) { return $v !== '' && $v !== null; } );
		$wpdb->insert( $table_c, [
			'manufacturer_id' => $mfr_id,
			'parent_id'       => $parent_db,
			'name'            => trim( $row['name'] ),
			'slug'            => sanitize_title( $row['name'] ),
			'type'            => 'category',
			'description'     => '',
			'image'           => '',
			'level'           => (int) $level,
			'products_count'  => 0,
			'metadata_json'   => wp_json_encode( $meta, JSON_UNESCAPED_SLASHES ),
		], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ] );
		$db_by_anytek[ $aid ] = (int) $wpdb->insert_id;
		$counts[ 'L' . $level ]++;
	}
}
echo "  Insertadas: L1={$counts['L1']} L2={$counts['L2']} L3={$counts['L3']}\n";

// ---- Uncategorized fallback ----
$uncat_slug = 'uncategorized';
$wpdb->insert( $table_c, [
	'manufacturer_id' => $mfr_id,
	'parent_id'       => null,
	'name'            => 'Uncategorized',
	'slug'            => $uncat_slug,
	'type'            => 'category',
	'description'     => '',
	'image'           => '',
	'level'           => 1,
	'products_count'  => 0,
	'metadata_json'   => '[]',
], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ] );
$uncat_db = (int) $wpdb->insert_id;
echo "  Fallback: $uncat_slug\n";

// ---- Re-attribute products by SKU -> list_cate_id ----
$remapped = 0;
$to_uncat = 0;
foreach ( $products as $p ) {
	$sku    = strtoupper( $p->sku );
	$new_id = null;
	if ( isset( $prods_by_sku[ $sku ] ) ) {
		$aid = $prods_by_sku[ $sku ];
		if ( isset( $db_by_anytek[ $aid ] ) ) {
			$new_id = $db_by_anytek[ $aid ];
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

// ---- products_count ----
$all_cats = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $table_c WHERE manufacturer_id = %d", $mfr_id ) );
foreach ( $all_cats as $cid ) {
	$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table_p WHERE category_id = %d", (int) $cid ) );
	$wpdb->update( $table_c, [ 'products_count' => $count ], [ 'id' => $cid ], [ '%d' ], [ '%d' ] );
}

// ---- Prune empty categories (bottom-up, keep uncategorized) ----
$prune_rows = $wpdb->get_results( $wpdb->prepare(
	"SELECT id, parent_id, level, products_count, name FROM $table_c WHERE manufacturer_id = %d",
	$mfr_id
), OBJECT_K );
$children_of = [];
foreach ( $prune_rows as $row ) {
	$pid = (int) ( $row->parent_id ?? 0 );
	$children_of[ $pid ][] = (int) $row->id;
}
$keep = [];
$has_content = function( int $id ) use ( &$prune_rows, &$children_of, &$keep, &$has_content ) {
	if ( isset( $keep[ $id ] ) ) return $keep[ $id ];
	$r = $prune_rows[ $id ] ?? null;
	if ( ! $r ) { $keep[ $id ] = false; return false; }
	$val = ( (int) $r->products_count ) > 0;
	if ( ! $val ) {
		foreach ( $children_of[ $id ] ?? [] as $child ) {
			if ( $has_content( $child ) ) { $val = true; break; }
		}
	}
	if ( ! $val && strtolower( trim( $r->name ) ) === 'uncategorized' ) $val = true;
	$keep[ $id ] = $val;
	return $val;
};
$deleted = 0;
foreach ( $prune_rows as $id => $r ) {
	if ( ! $has_content( (int) $id ) ) {
		$wpdb->delete( $table_c, [ 'id' => $id ], [ '%d' ] );
		$deleted++;
	}
}
echo "Pruned empty categories: $deleted\n";

// ---- Summary ----
$final_total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table_c WHERE manufacturer_id = %d", $mfr_id ) );
echo "\n=== Resumen ===\n";
echo "Insertadas L1={$counts['L1']} L2={$counts['L2']} L3={$counts['L3']} | fallback: $uncat_slug | despues prune: $final_total\n";

// ---- Regenerate pages ----
echo "\nRegenerando paginas...\n";
$processor_mgr = new \AOE\CatalogEngine\Import\ProcessorManager();
$bp = new \AOE\CatalogEngine\Import\BatchProcessor( $processor_mgr );
$bp->pack_catalog( $mfr_id, $manufacturer_slug, $processor_mgr->get_processor( $manufacturer_slug ) );
echo "Paginas regeneradas.\n\nDone.\n";
