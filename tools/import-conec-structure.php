<?php
/**
 * CLI: Import Amphenol Conec category structure + re-attribute products.
 *
 * Reads tools/conec_categorias.csv (L1 -> L2 hierarchy, with conec category
 * ids as composite "L1|L2" paths, descriptions as JSON arrays of paragraphs,
 * urls and images) and tools/conec_productos.csv (products) and rebuilds the
 * aoe_catalog_categories rows for amphenolconec:
 *   - L1 (level 1) and L2 (level 2) with metadata conec_id + url
 *   - hierarchical slugs (parent-slug + own-slug) so repeated names do not collide
 *   - descriptions converted from JSON arrays to "•"-separated bullets (the
 *     render converts them to <p> + <ul> via aoe_catalog_bullets_to_html)
 *   - images from the CSV (remote conec.com URLs)
 *   - uncategorized fallback
 *   - re-attributes existing products by SKU, building the conec id from the
 *     product category_l1/l2 columns (falling back to L1)
 *   - prunes empty categories (bottom-up)
 *   - regenerates pages via pack_catalog
 *
 * IMPORTANT ORDER (like RF/Lutze/Industrial):
 *   1) php tools/full-import.php --manufacturer=amphenolconec --csv=tools/conec_productos.csv --mode=replace
 *   2) php tools/import-conec-structure.php
 *
 * Usage:
 *   php tools/import-conec-structure.php
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

$manufacturer_slug = 'amphenolconec';
$cats_file         = __DIR__ . '/conec_categorias.csv';
$prods_file        = __DIR__ . '/conec_productos.csv';

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
	$cid = $row['category_id'] ?? '';
	if ( '' === $cid ) continue;
	$cats[ $cid ] = $row;
}
fclose( $fh );
echo "Categorias CSV: " . count( $cats ) . "\n";

// ---- Parse products CSV (semicolon) -> SKU => conec path candidates ----
$prods_by_sku = [];
$fh = fopen( $prods_file, 'r' );
if ( ! $fh ) die( "Cannot open $prods_file\n" );
$hp = fgetcsv( $fh, 0, ';' ); $hp[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $hp[0] );
$pcols = array_map( 'trim', $hp );
$idx_sku = array_search( 'part_number', $pcols, true );
if ( false === $idx_sku ) die( "Missing part_number column\n" );
$idx_l1 = array_search( 'category_l1', $pcols, true );
$idx_l2 = array_search( 'category_l2', $pcols, true );
while ( ( $r = fgetcsv( $fh, 0, ';' ) ) !== false ) {
	if ( count( $r ) < count( $pcols ) ) continue;
	$sku = strtoupper( trim( $r[ $idx_sku ] ) );
	if ( '' === $sku ) continue;
	$l1 = false !== $idx_l1 ? trim( $r[ $idx_l1 ] ) : '';
	$l2 = false !== $idx_l2 ? trim( $r[ $idx_l2 ] ) : '';
	$path = $l1;
	if ( '' !== $l2 ) $path .= '|' . $l2;
	$prods_by_sku[ $sku ] = $path;
}
fclose( $fh );
echo "SKUs en CSV de productos: " . count( $prods_by_sku ) . "\n";

// ---- Snapshot existing products ----
$products = $wpdb->get_results( $wpdb->prepare(
	"SELECT id, sku, category_id FROM $table_p WHERE manufacturer_id = %d", $mfr_id
) );
echo "Productos en DB: " . count( $products ) . "\n";

// ---- Delete existing categories ----
echo "Deleting existing Conec categories...\n";
$wpdb->delete( $table_c, [ 'manufacturer_id' => $mfr_id ], [ '%d' ] );

// ---- Insert categories level by level (hierarchical slugs) ----
$db_by_conec = [];  // conec category_id => db_id
$slug_by_conec = [];
$counts = [ 'L1' => 0, 'L2' => 0 ];
foreach ( [ '1', '2' ] as $level ) {
	foreach ( $cats as $aid => $row ) {
		if ( ( $row['level'] ?? '' ) !== $level ) continue;
		$parent_db = null;
		$parent_aid = trim( $row['parent_id'] ?? '' );
		if ( $level !== '1' && $parent_aid !== '' && isset( $db_by_conec[ $parent_aid ] ) ) {
			$parent_db = $db_by_conec[ $parent_aid ];
		}
		$base_slug = sanitize_title( $row['name'] );
		$slug      = $base_slug;
		if ( $level !== '1' && '' !== $parent_aid && isset( $slug_by_conec[ $parent_aid ] ) && '' !== $slug_by_conec[ $parent_aid ] ) {
			$slug = $slug_by_conec[ $parent_aid ] . '-' . $base_slug;
		}
		if ( '' === $slug ) {
			$slug = sanitize_title( $aid );
		}
		$meta = [
			'conec_id' => $aid,
			'url'      => $row['url'] ?? '',
		];
		$meta = array_filter( $meta, static function ( $v ) { return $v !== '' && $v !== null; } );

		// Description: CSV carries a JSON array of paragraphs -> join as bullets.
		$desc = trim( $row['description'] ?? '' );
		$decoded = json_decode( $desc, true );
		if ( is_array( $decoded ) ) {
			$parts = array_values( array_filter( array_map( 'trim', $decoded ), static function ( $p ) { return '' !== $p; } ) );
			$desc  = implode( " \xE2\x80\xA2 ", $parts );
		}

		$wpdb->insert( $table_c, [
			'manufacturer_id' => $mfr_id,
			'parent_id'       => $parent_db,
			'name'            => trim( $row['name'] ),
			'slug'            => $slug,
			'type'            => 'category',
			'description'     => $desc,
			'image'           => isset( $row['image_url'] ) ? trim( $row['image_url'] ) : '',
			'level'           => (int) $level,
			'products_count'  => 0,
			'metadata_json'   => wp_json_encode( $meta, JSON_UNESCAPED_SLASHES ),
		], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ] );
		$db_by_conec[ $aid ] = (int) $wpdb->insert_id;
		$slug_by_conec[ $aid ] = $slug;
		$counts[ 'L' . $level ]++;
	}
}
echo "  Insertadas: L1={$counts['L1']} L2={$counts['L2']}\n";

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

// ---- Re-attribute products by SKU -> conec path (fallback up levels) ----
$remapped = 0;
$to_uncat = 0;
foreach ( $products as $p ) {
	$sku    = strtoupper( $p->sku );
	$new_id = null;
	if ( isset( $prods_by_sku[ $sku ] ) ) {
		$path = $prods_by_sku[ $sku ];
		while ( '' !== $path ) {
			if ( isset( $db_by_conec[ $path ] ) ) {
				$new_id = $db_by_conec[ $path ];
				break;
			}
			$pos = strrpos( $path, '|' );
			$path = false !== $pos ? substr( $path, 0, $pos ) : '';
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
echo "Insertadas L1={$counts['L1']} L2={$counts['L2']} | fallback: $uncat_slug | despues prune: $final_total\n";

// ---- Regenerate pages ----
echo "\nRegenerando paginas...\n";
$processor_mgr = new \AOE\CatalogEngine\Import\ProcessorManager();
$bp = new \AOE\CatalogEngine\Import\BatchProcessor( $processor_mgr );
$bp->pack_catalog( $mfr_id, $manufacturer_slug, $processor_mgr->get_processor( $manufacturer_slug ) );
echo "Paginas regeneradas.\n\nDone.\n";
