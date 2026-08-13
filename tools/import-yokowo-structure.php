<?php
/**
 * CLI: Import Yokowo category structure + re-attribute products.
 *
 * Reads tools/categoriasyokowo.csv (L1 families + L2 subcategories, with descriptions)
 * and tools/productosyokowo.csv (products) and rebuilds the aoe_catalog_categories
 * rows for Yokowo:
 *   - L1 families (type=category, level 1, description + image + metadata yokowo_id/url)
 *   - L2 subcategories (type=category, level 2)
 *   - uncategorized fallback
 *   - re-attributes existing products to the rebuilt categories by yokowo_id
 *   - prunes empty categories (bottom-up)
 *   - regenerates pages via pack_catalog
 *
 * IMPORTANT ORDER (like Bulgin/Medi Kabel):
 *   1) php tools/full-import.php --manufacturer=yokowo --csv=tools/productosyokowo.csv --mode=replace
 *   2) php tools/import-yokowo-structure.php
 *   3) php tools/pack-catalog.php yokowo   (auto by step 2)
 *
 * Usage:
 *   php tools/import-yokowo-structure.php
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

$manufacturer_slug = 'yokowo';
$cats_file         = __DIR__ . '/categoriasyokowo.csv';
$prods_file        = __DIR__ . '/productosyokowo.csv';

$table_c = $wpdb->prefix . 'aoe_catalog_categories';
$table_p = $wpdb->prefix . 'aoe_catalog_products';
$table_m = $wpdb->prefix . 'aoe_catalog_manufacturers';

/**
 * Capitalize first letter only if it starts with a lowercase ASCII letter.
 */
function yokowo_cap( string $name ): string {
	$name = trim( $name );
	if ( preg_match( '/^[a-z]/', $name ) ) {
		$name = ucfirst( $name );
	}
	return $name;
}

// ---- Manufacturer ----
$manufacturer = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_m WHERE slug = %s", $manufacturer_slug ) );
if ( ! $manufacturer ) {
	die( "Manufacturer not found: $manufacturer_slug (create it in the admin first)\n" );
}
$mfr_id = (int) $manufacturer->id;

// ---- Parse categories CSV (semicolon) ----
$cats = [];
$fh = fopen( $cats_file, 'r' );
if ( ! $fh ) die( "Cannot open $cats_file\n" );
$h = fgetcsv( $fh, 0, ';' ); $h[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $h[0] );
$cols = array_map( 'trim', $h );
while ( ( $r = fgetcsv( $fh, 0, ';' ) ) !== false ) {
	if ( count( $r ) < count( $cols ) ) continue;
	$row = array_combine( $cols, array_map( 'trim', $r ) );
	$cats[ $row['category_id'] ] = $row;
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
$prod_by_sku = [];
foreach ( $products as $p ) $prod_by_sku[ strtoupper( $p->sku ) ] = $p;
echo "Productos en DB: " . count( $products ) . "\n";

// ---- Delete existing categories ----
echo "Deleting existing Yokowo categories...\n";
$wpdb->delete( $table_c, [ 'manufacturer_id' => $mfr_id ], [ '%d' ] );

// ---- Insert L1 families ----
$l1_by_yid = []; // yokowo_id => db_id
$inserted = 0;
foreach ( $cats as $yid => $row ) {
	if ( ( $row['level'] ?? '' ) !== '1' ) continue;
	$meta = [
		'yokowo_id' => $yid,
		'url'       => $row['url'] ?? '',
	];
	$meta = array_filter( $meta, static function ( $v ) { return $v !== '' && $v !== null; } );
	$desc = str_replace( '\n', "\n", $row['description'] ?? '' );
	$wpdb->insert( $table_c, [
		'manufacturer_id' => $mfr_id,
		'parent_id'       => null,
		'name'            => yokowo_cap( $row['name'] ),
		'slug'            => sanitize_title( $row['name'] ),
		'type'            => 'category',
		'description'     => $desc,
		'image'           => '',
		'level'           => 1,
		'products_count'  => 0,
		'metadata_json'   => wp_json_encode( $meta, JSON_UNESCAPED_SLASHES ),
	], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ] );
	$l1_by_yid[ $yid ] = (int) $wpdb->insert_id;
	$inserted++;
}
echo "  L1 familias: $inserted\n";

// ---- Insert L2 subcategories ----
$l2_by_yid = []; // yokowo_id => db_id
$inserted = 0;
foreach ( $cats as $yid => $row ) {
	if ( ( $row['level'] ?? '' ) !== '2' ) continue;
	$parent_yid = $row['parent_id'] ?? '';
	if ( ! isset( $l1_by_yid[ $parent_yid ] ) ) continue;
	$meta = [
		'yokowo_id' => $yid,
		'url'       => $row['url'] ?? '',
	];
	$meta = array_filter( $meta, static function ( $v ) { return $v !== '' && $v !== null; } );
	$wpdb->insert( $table_c, [
		'manufacturer_id' => $mfr_id,
		'parent_id'       => $l1_by_yid[ $parent_yid ],
		'name'            => yokowo_cap( $row['name'] ),
		'slug'            => sanitize_title( $row['name'] ),
		'type'            => 'category',
		'description'     => '',
		'image'           => '',
		'level'           => 2,
		'products_count'  => 0,
		'metadata_json'   => wp_json_encode( $meta, JSON_UNESCAPED_SLASHES ),
	], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ] );
	$l2_by_yid[ $yid ] = (int) $wpdb->insert_id;
	$inserted++;
}
echo "  L2 subcategorias: $inserted\n";

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

// ---- Re-attribute products by SKU ----
$prods_by_sku = [];
foreach ( $prods as $row ) {
	$sku = strtoupper( trim( $row['part_number'] ?? '' ) );
	if ( $sku === '' ) continue;
	$prods_by_sku[ $sku ] = $row;
}

$remapped = 0;
$to_uncat = 0;
foreach ( $products as $p ) {
	$sku = strtoupper( $p->sku );
	$new_id = null;
	if ( isset( $prods_by_sku[ $sku ] ) ) {
		$row  = $prods_by_sku[ $sku ];
		$sub  = trim( $row['subcategory_id'] ?? '' );
		$cat  = trim( $row['category_id'] ?? '' );
		if ( $sub !== '' && isset( $l2_by_yid[ $sub ] ) ) {
			$new_id = $l2_by_yid[ $sub ];
		} elseif ( $cat !== '' && isset( $l1_by_yid[ $cat ] ) ) {
			$new_id = $l1_by_yid[ $cat ];
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
echo "L1: " . count( $l1_by_yid ) . " | L2: " . count( $l2_by_yid ) . " | fallback: $uncat_slug | total antes: " . count( $all_cats ) . " | despues prune: $final_total\n";

// ---- Regenerate pages ----
echo "\nRegenerando paginas...\n";
$processor_mgr = new \AOE\CatalogEngine\Import\ProcessorManager();
$bp = new \AOE\CatalogEngine\Import\BatchProcessor( $processor_mgr );
$bp->pack_catalog( $mfr_id, $manufacturer_slug, $processor_mgr->get_processor( $manufacturer_slug ) );
echo "Paginas regeneradas.\n\nDone.\n";
