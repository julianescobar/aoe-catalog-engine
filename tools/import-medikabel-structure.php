<?php
/**
 * CLI: Import Medi Kabel category structure + re-attribute products.
 *
 * Reads tools/categoriasmedikabel.csv (categories: L1 families + L2 subcats)
 * and tools/productosmedikabel.csv (products: series_id/series_name) and
 * rebuilds the aoe_catalog_categories rows for Medi Kabel:
 *   - L1 families (type=category, level 1, image + metadata medikabel_id/url)
 *   - L2 subcategories (type=category, level 2)
 *   - L3 series (type=series, level 3, from products series_name, metadata series_id)
 *   - uncategorized fallback
 *   - re-attributes existing products to the rebuilt categories by resolving
 *     series_id -> subcategory_id -> category_id
 *   - regenerates pages via pack_catalog
 *
 * IMPORTANT ORDER (like Bulgin):
 *   1) php tools/full-import.php --manufacturer=medikabel --csv=tools/productosmedikabel.csv --mode=replace
 *   2) php tools/import-medikabel-structure.php
 *   3) php tools/pack-catalog.php medikabel   (auto by step 2)
 *
 * Usage:
 *   php tools/import-medikabel-structure.php
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

$manufacturer_slug = 'medi-kabel';

/**
 * Capitalize the first letter only if the name starts with a lowercase ASCII
 * letter (preserves names that already start with uppercase, digits, or symbols).
 */
function mk_cap( string $name ): string {
	$name = trim( $name );
	if ( preg_match( '/^[a-z]/', $name ) ) {
		$name = ucfirst( $name );
	}
	return $name;
}
$cats_file         = __DIR__ . '/categoriasmedikabel.csv';
$prods_file        = __DIR__ . '/productosmedikabel.csv';

$table_c = $wpdb->prefix . 'aoe_catalog_categories';
$table_p = $wpdb->prefix . 'aoe_catalog_products';
$table_m = $wpdb->prefix . 'aoe_catalog_manufacturers';

// ---- Manufacturer ----
$manufacturer = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_m WHERE slug = %s", $manufacturer_slug ) );
if ( ! $manufacturer ) {
	die( "Manufacturer not found: $manufacturer_slug (créalo primero en el admin)\n" );
}
$mfr_id = (int) $manufacturer->id;

// ---- Parse categories CSV (comma) ----
$cats = [];
$fh = fopen( $cats_file, 'r' );
if ( ! $fh ) die( "Cannot open $cats_file\n" );
$h = fgetcsv( $fh ); $h[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $h[0] );
$cols = array_map( 'trim', $h );
while ( ( $r = fgetcsv( $fh ) ) !== false ) {
	if ( count( $r ) < count( $cols ) ) continue;
	$row = array_combine( $cols, array_map( 'trim', $r ) );
	if ( $row['category_id'] === '' ) continue; // virtual cats without id (no products reference them)
	$cats[ (int) $row['category_id'] ] = $row;
}
fclose( $fh );
echo "Categorias CSV: " . count( $cats ) . "\n";

// ---- Parse products CSV (auto-detect delimiter) ----
$prods = [];
$fh = fopen( $prods_file, 'r' );
if ( ! $fh ) die( "Cannot open $prods_file\n" );
$first_line = fgets( $fh );
rewind( $fh );
$prod_sep = ( substr_count( $first_line, ';' ) > substr_count( $first_line, ',' ) ) ? ';' : ',';
echo "  Delimiter: " . ( ';' === $prod_sep ? 'semicolon' : 'comma' ) . "\n";
$hp = fgetcsv( $fh, 0, $prod_sep ); $hp[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $hp[0] );
$pcols = array_map( 'trim', $hp );
$pi = array_flip( $pcols );
while ( ( $r = fgetcsv( $fh, 0, $prod_sep ) ) !== false ) {
	if ( count( $r ) < count( $pcols ) ) continue;
	$prods[] = array_combine( $pcols, array_map( 'trim', $r ) );
}
fclose( $fh );
echo "Productos CSV: " . count( $prods ) . "\n";

// ---- Snapshot existing products (id, sku, category_id) ----
$products = $wpdb->get_results( $wpdb->prepare(
	"SELECT id, sku, category_id FROM $table_p WHERE manufacturer_id = %d", $mfr_id
) );
$prod_by_sku = [];
foreach ( $products as $p ) $prod_by_sku[ strtoupper( $p->sku ) ] = $p;

echo "Deleting existing Medi Kabel categories...\n";
$wpdb->delete( $table_c, [ 'manufacturer_id' => $mfr_id ], [ '%d' ] );

// ---- Insert L1 families ----
$l1_by_mkid = []; // medikabel category_id => db id
$inserted = 0;
foreach ( $cats as $cid => $row ) {
	if ( (int) $row['parent_id'] !== 0 && $row['parent_id'] !== '' ) continue; // level 2 only below
	$meta = [
		'medikabel_id' => (string) $cid,
		'url'          => $row['url'] ?? '',
		'image_url'    => $row['image_url'] ?? '',
	];
	$meta = array_filter( $meta, static function ( $v ) { return $v !== '' && $v !== null; } );
	$wpdb->insert( $table_c, [
		'manufacturer_id' => $mfr_id,
		'parent_id'       => null,
		'name'            => mk_cap( $row['name'] ),
		'slug'            => sanitize_title( $row['name'] ),
		'type'            => 'category',
		'description'     => '',
		'image'           => $row['image_url'] ?? '',
		'level'           => 1,
		'products_count'  => 0,
		'metadata_json'   => wp_json_encode( $meta, JSON_UNESCAPED_SLASHES ),
	], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ] );
	$l1_by_mkid[ $cid ] = (int) $wpdb->insert_id;
	$inserted++;
}
echo "  L1 familias: $inserted\n";

// ---- Insert L2 subcategories ----
$l2_by_mkid = []; // medikabel category_id => db id
$inserted = 0;
foreach ( $cats as $cid => $row ) {
	$pid = (int) $row['parent_id'];
	if ( $pid <= 0 || ! isset( $l1_by_mkid[ $pid ] ) ) continue;
	$meta = [
		'medikabel_id' => (string) $cid,
		'url'          => $row['url'] ?? '',
		'image_url'    => $row['image_url'] ?? '',
	];
	$meta = array_filter( $meta, static function ( $v ) { return $v !== '' && $v !== null; } );
	$wpdb->insert( $table_c, [
		'manufacturer_id' => $mfr_id,
		'parent_id'       => $l1_by_mkid[ $pid ],
		'name'            => mk_cap( $row['name'] ),
		'slug'            => sanitize_title( $row['name'] ),
		'type'            => 'category',
		'description'     => '',
		'image'           => $row['image_url'] ?? '',
		'level'           => 2,
		'products_count'  => 0,
		'metadata_json'   => wp_json_encode( $meta, JSON_UNESCAPED_SLASHES ),
	], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ] );
	$l2_by_mkid[ $cid ] = (int) $wpdb->insert_id;
	$inserted++;
}
echo "  L2 subcategorias: $inserted\n";

// ---- Insert L3 series (from products) ----
// key: <parent_db_id>|<series_id> -> db id  (series under its subcategory, else family)
$series_by_key = [];
$series_by_serid = []; // series_id => db id (first occurrence, for product re-attribution)
$created_series = 0;
$ser_names_used = []; // parent_db_id => [slug => series_id] for collision-safe slugs
foreach ( $prods as $row ) {
	$series_id   = trim( $row['series_id'] ?? '' );
	$series_name = trim( $row['series_name'] ?? '' );
	$subcat_id   = (int) ( $row['subcategory_id'] ?? 0 );
	$cat_id      = (int) ( $row['category_id'] ?? 0 );

	if ( $series_id === '' || $series_name === '' ) continue;

	$parent_db = isset( $l2_by_mkid[ $subcat_id ] ) ? $l2_by_mkid[ $subcat_id ] : ( isset( $l1_by_mkid[ $cat_id ] ) ? $l1_by_mkid[ $cat_id ] : null );
	if ( null === $parent_db ) continue;

	// If parent is L1 (no L2 intermediary), this becomes L2; otherwise L3.
	$parent_is_l1 = isset( $l1_by_mkid[ $cat_id ] ) && ! isset( $l2_by_mkid[ $subcat_id ] );
	$item_level = $parent_is_l1 ? 2 : 3;

	$key = $parent_db . '|' . $series_id;
	if ( isset( $series_by_key[ $key ] ) ) continue;

	// collision-safe slug within parent
	$slug = sanitize_title( $series_name );
	$ser_names_used[ $parent_db ] = $ser_names_used[ $parent_db ] ?? [];
	if ( isset( $ser_names_used[ $parent_db ][ $slug ] ) ) {
		$slug .= '-' . $series_id;
	}
	$ser_names_used[ $parent_db ][ $slug ] = true;

	$meta = [
		'series_id'    => $series_id,
		'series_name'  => $series_name,
		'series_group' => trim( $row['series_group'] ?? '' ),
		'series_url'   => trim( $row['series_url'] ?? '' ),
		'image_url'    => trim( $row['image_url'] ?? '' ),
	];
	$meta = array_filter( $meta, static function ( $v ) { return $v !== '' && $v !== null; } );

	$wpdb->insert( $table_c, [
		'manufacturer_id' => $mfr_id,
		'parent_id'       => $parent_db,
		'name'            => mk_cap( $series_name ),
		'slug'            => $slug,
		'type'            => 'series',
		'description'     => '',
		'image'           => trim( $row['image_url'] ?? '' ),
		'level'           => $item_level,
		'products_count'  => 0,
		'metadata_json'   => wp_json_encode( $meta, JSON_UNESCAPED_SLASHES ),
	], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ] );
	$series_by_key[ $key ] = (int) $wpdb->insert_id;
	if ( ! isset( $series_by_serid[ $series_id ] ) ) {
		$series_by_serid[ $series_id ] = (int) $wpdb->insert_id;
	}
	$created_series++;
}
echo "  L3 series: $created_series\n";

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

// ---- Re-attribute products by SKU (resolve series -> subcat -> cat) ----
$remapped = 0;
$to_uncat = 0;
$prods_by_sku = [];
foreach ( $prods as $row ) {
	$sku = strtoupper( trim( $row['article_number'] ?? '' ) );
	if ( $sku === '' ) continue;
	$prods_by_sku[ $sku ] = $row;
}

foreach ( $products as $p ) {
	$sku = strtoupper( $p->sku );
	$new_id = null;
	if ( isset( $prods_by_sku[ $sku ] ) ) {
		$row   = $prods_by_sku[ $sku ];
		$serid = trim( $row['series_id'] ?? '' );
		$subid = (int) ( $row['subcategory_id'] ?? 0 );
		$catid = (int) ( $row['category_id'] ?? 0 );
		if ( $serid !== '' && isset( $series_by_serid[ $serid ] ) ) {
			$new_id = $series_by_serid[ $serid ];
		} elseif ( $subid > 0 && isset( $l2_by_mkid[ $subid ] ) ) {
			$new_id = $l2_by_mkid[ $subid ];
		} elseif ( $catid > 0 && isset( $l1_by_mkid[ $catid ] ) ) {
			$new_id = $l1_by_mkid[ $catid ];
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

// ---- Prune empty categories (no products AND no non-empty descendants) ----
// Bottom-up: a category is kept if it has products OR any kept child.
$prune_rows = $wpdb->get_results( $wpdb->prepare(
	"SELECT id, parent_id, level, products_count, name FROM $table_c WHERE manufacturer_id = %d",
	$mfr_id
), OBJECT_K );
$children_of = [];
foreach ( $prune_rows as $row ) {
	$pid = (int) ( $row->parent_id ?? 0 );
	$children_of[ $pid ][] = (int) $row->id;
}
$keep = []; // id => bool
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
	// Always keep the uncategorized fallback
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

echo "\n=== Resumen ===\n";
$final_total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table_c WHERE manufacturer_id = %d", $mfr_id ) );
echo "L1: " . count( $l1_by_mkid ) . " | L2: " . count( $l2_by_mkid ) . " | L3 series: $created_series | fallback: $uncat_slug | total antes: " . count( $all_cats ) . " | despues prune: $final_total\n";

// ---- Regenerate pages ----
echo "\nRegenerando paginas...\n";
$processor_mgr = new \AOE\CatalogEngine\Import\ProcessorManager();
$bp = new \AOE\CatalogEngine\Import\BatchProcessor( $processor_mgr );
$bp->pack_catalog( $mfr_id, $manufacturer_slug, $processor_mgr->get_processor( $manufacturer_slug ) );
echo "Paginas regeneradas.\n\nDone.\n";
