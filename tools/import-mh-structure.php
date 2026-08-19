<?php
/**
 * CLI: Import MH Connectors category structure + re-attribute products + copy series specs.
 *
 * Reads tools/mh_catalog.csv (3-level hierarchy: category → subcategory → series)
 * and tools/mh_productos.csv (products linked to series via series_id).
 *
 * Usage:
 *   php tools/import-mh-structure.php
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

$manufacturer_slug = 'mh-connectors';
$catalog_file      = __DIR__ . '/mh_catalog.csv';
$prods_file        = __DIR__ . '/mh_productos.csv';

$table_c = $wpdb->prefix . 'aoe_catalog_categories';
$table_p = $wpdb->prefix . 'aoe_catalog_products';
$table_m = $wpdb->prefix . 'aoe_catalog_manufacturers';

// ---- Manufacturer ----
$manufacturer = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_m WHERE slug = %s", $manufacturer_slug ) );
if ( ! $manufacturer ) {
	die( "Manufacturer not found: $manufacturer_slug\n" );
}
$mfr_id = (int) $manufacturer->id;

// ---- Parse catalog CSV ----
$catalog = [];
$fh = fopen( $catalog_file, 'r' );
if ( ! $fh ) die( "Cannot open $catalog_file\n" );
$cols = fgetcsv( $fh, 0, ';' );
$cols[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $cols[0] );
$cols = array_map( 'trim', $cols );
while ( ( $r = fgetcsv( $fh, 0, ';' ) ) !== false ) {
	if ( count( $r ) < count( $cols ) ) continue;
	$catalog[] = array_combine( $cols, $r );
}
fclose( $fh );

$categories    = array_filter( $catalog, fn( $c ) => $c['type'] === 'category' );
$subcategories = array_filter( $catalog, fn( $c ) => $c['type'] === 'subcategory' );
$series_rows   = array_filter( $catalog, fn( $c ) => $c['type'] === 'series' );

echo "Catalog: " . count( $categories ) . " categories, " . count( $subcategories ) . " subcategories, " . count( $series_rows ) . " series\n";

// ---- Parse products CSV -> series_id => [sku, ...] and series_id => specs ----
$prods_by_series = [];
$fh = fopen( $prods_file, 'r' );
$pcols_raw = fgetcsv( $fh, 0, ';' );
$pcols_raw[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $pcols_raw[0] );
$pcols = array_map( 'trim', $pcols_raw );
while ( ( $r = fgetcsv( $fh, 0, ';' ) ) !== false ) {
	if ( count( $r ) < count( $pcols ) ) continue;
	$d = array_combine( $pcols, $r );
	$sid = trim( $d['series_id'] ?? '' );
	$sku = trim( $d['part_number'] ?? '' );
	if ( '' === $sid || '' === $sku ) continue;
	$prods_by_series[ $sid ][] = $sku;
}
fclose( $fh );
echo "Products: " . array_sum( array_map( 'count', $prods_by_series ) ) . " across " . count( $prods_by_series ) . " series\n";

// ---- Snapshot existing products ----
$products = $wpdb->get_results( $wpdb->prepare(
	"SELECT id, sku, category_id, additional_data FROM $table_p WHERE manufacturer_id = %d", $mfr_id
) );
echo "Products en DB: " . count( $products ) . "\n";

// ---- Delete existing categories ----
echo "Deleting existing categories...\n";
$wpdb->delete( $table_c, [ 'manufacturer_id' => $mfr_id ], [ '%d' ] );

// ---- Build category hierarchy ----
$cat_db_ids = [];   // node_key => db_id
$cat_specs  = [];   // series_id => specs array (for copying to products)

// Level 1: categories
foreach ( $categories as $c ) {
	$slug = sanitize_title( $c['name'] );
	$wpdb->insert( $table_c, [
		'manufacturer_id' => $mfr_id,
		'parent_id'       => null,
		'name'            => $c['name'],
		'slug'            => $slug,
		'type'            => 'category',
		'description'     => $c['description'] ?? '',
		'image'           => $c['image_url'] ?? '',
		'level'           => 1,
		'products_count'  => 0,
		'metadata_json'   => '[]',
	], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ] );
	$cat_db_ids[ $c['node_key'] ] = (int) $wpdb->insert_id;
}
echo "  L1: " . count( array_filter( $cat_db_ids, fn( $k ) => str_starts_with( $k, 'cat:' ) ) ) . "\n";

// Level 2: subcategories
foreach ( $subcategories as $c ) {
	$parent_key = $c['parent_key'] ?? '';
	$parent_id  = $cat_db_ids[ $parent_key ] ?? null;
	$slug       = sanitize_title( $c['name'] );
	$wpdb->insert( $table_c, [
		'manufacturer_id' => $mfr_id,
		'parent_id'       => $parent_id,
		'name'            => $c['name'],
		'slug'            => $slug,
		'type'            => 'category',
		'description'     => $c['description'] ?? '',
		'image'           => $c['image_url'] ?? '',
		'level'           => 2,
		'products_count'  => 0,
		'metadata_json'   => '[]',
	], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ] );
	$cat_db_ids[ $c['node_key'] ] = (int) $wpdb->insert_id;
}
echo "  L2: " . count( array_filter( $cat_db_ids, fn( $k ) => str_starts_with( $k, 'sub:' ) ) ) . "\n";

// Level 3: series (with descriptions, features, specifications)
foreach ( $series_rows as $c ) {
	$parent_key = $c['parent_key'] ?? '';
	$parent_id  = $cat_db_ids[ $parent_key ] ?? null;
	$series_id  = $c['series_id'] ?? '';
	$slug       = sanitize_title( $c['name'] );

	// Parse specifications: "\n"-separated "Key: Value" pairs (literal \n in CSV).
	$specs = [];
	$spec_raw = $c['specifications'] ?? '';
	if ( '' !== $spec_raw ) {
		$spec_raw = str_replace( '\\n', "\n", $spec_raw );
		$lines = array_filter( array_map( 'trim', explode( "\n", $spec_raw ) ) );
		foreach ( $lines as $line ) {
			$parts = explode( ': ', $line, 2 );
			if ( count( $parts ) === 2 ) {
				$specs[ trim( $parts[0] ) ] = trim( $parts[1] );
			}
		}
	}
	if ( ! empty( $specs ) ) {
		$cat_specs[ $series_id ] = $specs;
	}

	// Build metadata: features as bullet points, specs for the product row.
	$meta = [];
	$features = $c['features'] ?? '';
	if ( '' !== $features ) {
		$feat_lines = array_filter( array_map( 'trim', explode( "\n", $features ) ) );
		if ( ! empty( $feat_lines ) ) {
			$meta['features'] = implode( " \xE2\x80\xA2 ", $feat_lines );
		}
	}
	if ( ! empty( $specs ) ) {
		$meta['specifications'] = $spec_raw;
	}
	$series_url = $c['series_url'] ?? '';
	if ( '' !== $series_url ) {
		$meta['series_url'] = $series_url;
	}

	$wpdb->insert( $table_c, [
		'manufacturer_id' => $mfr_id,
		'parent_id'       => $parent_id,
		'name'            => $c['name'],
		'slug'            => $slug,
		'type'            => 'category',
		'description'     => $c['description'] ?? '',
		'image'           => $c['image_url'] ?? $c['image_large_url'] ?? '',
		'level'           => 3,
		'products_count'  => 0,
		'metadata_json'   => wp_json_encode( $meta, JSON_UNESCAPED_SLASHES ),
	], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ] );
	$cat_db_ids[ $c['node_key'] ] = (int) $wpdb->insert_id;
}
echo "  L3: " . count( array_filter( $cat_db_ids, fn( $k ) => str_starts_with( $k, 'ser:' ) ) ) . "\n";

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

// ---- Build series_id → db_id lookup ----
$series_key_to_db = [];
foreach ( $series_rows as $c ) {
	$series_id = $c['series_id'] ?? '';
	if ( '' !== $series_id && isset( $cat_db_ids[ $c['node_key'] ] ) ) {
		$series_key_to_db[ $series_id ] = $cat_db_ids[ $c['node_key'] ];
	}
}

// ---- Re-attribute products by series_id + copy series specs ----
$remapped = 0;
$to_uncat = 0;
$specs_copied = 0;
foreach ( $products as $p ) {
	$add = json_decode( $p->additional_data ?? '{}', true ) ?: [];
	$sid = $add['series_id'] ?? '';
	$new_id = null;
	if ( '' !== $sid && isset( $series_key_to_db[ $sid ] ) ) {
		$new_id = $series_key_to_db[ $sid ];
	}

	// Copy series specs to product (merge: series as base, product attrs overlay).
	$product_specs = $add['specs'] ?? [];
	if ( '' !== $sid && isset( $cat_specs[ $sid ] ) ) {
		$merged = $cat_specs[ $sid ];
		foreach ( $product_specs as $k => $v ) {
			$merged[ $k ] = $v;
		}
		$add['specs'] = $merged;
		$specs_copied++;
	}

	if ( null === $new_id ) {
		$new_id = $uncat_db;
		$to_uncat++;
	} else {
		$remapped++;
	}

	$wpdb->update( $table_p,
		[ 'category_id' => $new_id, 'additional_data' => wp_json_encode( $add, JSON_UNESCAPED_SLASHES ) ],
		[ 'id' => $p->id ],
		[ '%d', '%s' ],
		[ '%d' ]
	);
}
echo "Products re-attribute: $remapped | to uncategorized: $to_uncat | specs copied: $specs_copied\n";

// ---- products_count ----
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
