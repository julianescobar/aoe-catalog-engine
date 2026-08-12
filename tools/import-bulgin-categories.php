<?php
/**
 * CLI: Import Bulgin curated category structure + content (new process).
 *
 * Reads tools/categoriasbulginv2.csv (new Magento export) + tools/bulgin-cat-map.json
 * and rebuilds the aoe_catalog_categories rows for Bulgin:
 *   - 79 curated categories (slug EXACT from slug_map; CSV level 3 -> DB level 1,
 *     CSV level 4 -> DB level 2, CSV level 5 -> DB level 3)
 *   - uncategorized fallback
 *   - description / image / metadata (titulo, heading, summary, highlights, image_url, pdf_url)
 *   - re-attributes existing products to the rebuilt categories by slug
 *   - regenerates pages via pack_catalog
 *
 * IMPORTANT ORDER (prod): this CLI runs AFTER full-import --mode=replace, because
 * replace wipes ALL Bulgin categories (full-import.php line 117). So:
 *   1) php tools/full-import.php --manufacturer=bulgin --csv=productosbulginv2-serie.csv --mode=replace
 *   2) php tools/import-bulgin-categories.php
 *   3) php tools/pack-catalog.php bulgin   (also done automatically by step 2)
 *
 * Usage:
 *   php tools/import-bulgin-categories.php
 */

if ( PHP_SAPI !== 'cli' ) {
	die( "CLI only.\n" );
}

// Bootstrap WordPress
// Optional local override: AOE_DB_HOST=127.0.0.1:10006 to bypass Local's socket-only host.
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

$table_c = $wpdb->prefix . 'aoe_catalog_categories';
$table_p = $wpdb->prefix . 'aoe_catalog_products';

$manufacturer_slug = 'bulgin';
$map_file          = __DIR__ . '/bulgin-cat-map.json';
$csv_file          = __DIR__ . '/categoriasbulginv2.csv';

if ( ! file_exists( $map_file ) ) {
	die( "Map not found: $map_file\n" );
}
if ( ! file_exists( $csv_file ) ) {
	die( "CSV not found: $csv_file\n" );
}

$map = json_decode( file_get_contents( $map_file ), true );
if ( ! is_array( $map ) || empty( $map['slug_map'] ) ) {
	die( "Invalid map JSON.\n" );
}
$slug_map = $map['slug_map']; // new category_id => final slug
$skip_set = array_fill_keys( array_map( 'intval', $map['skip_ids'] ?? [] ), true );

// Manufacturer
$table_m = $wpdb->prefix . 'aoe_catalog_manufacturers';
$manufacturer = $wpdb->get_row( $wpdb->prepare(
	"SELECT * FROM $table_m WHERE slug = %s",
	$manufacturer_slug
) );
if ( ! $manufacturer ) {
	die( "Manufacturer not found: $manufacturer_slug\n" );
}
$mfr_id = (int) $manufacturer->id;

// ---- Parse CSV (loadNew pattern: ';', BOM, short rows, is_active) ----
$fh = fopen( $csv_file, 'r' );
if ( ! $fh ) {
	die( "Cannot open CSV: $csv_file\n" );
}
$header = fgetcsv( $fh, 0, ';' );
$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $header[0] );
$cols = array_map( 'trim', $header );
$rows = [];
while ( ( $r = fgetcsv( $fh, 0, ';' ) ) !== false ) {
	if ( count( $r ) < count( $cols ) ) {
		continue;
	}
	$row = array_combine( $cols, array_map( 'trim', $r ) );
	if ( isset( $row['is_active'] ) && $row['is_active'] !== '1' ) {
		continue;
	}
	$rows[] = $row;
}
fclose( $fh );
echo "CSV parsed: " . count( $rows ) . " active rows.\n";

$by_id = [];
foreach ( $rows as $row ) {
	$by_id[ (int) $row['category_id'] ] = $row;
}

$kept_ids = array_map( 'intval', array_keys( $slug_map ) );
foreach ( $kept_ids as $kid ) {
	if ( ! isset( $by_id[ $kid ] ) ) {
		die( "Kept id $kid missing from CSV.\n" );
	}
}

// Effective parent for each kept id: nearest kept ancestor (0 = root)
$parent = [];
foreach ( $kept_ids as $kid ) {
	$cur = (int) $by_id[ $kid ]['parent_id'];
	$p   = 0;
	while ( $cur > 0 && isset( $by_id[ $cur ] ) ) {
		if ( isset( $slug_map[ (string) $cur ] ) ) {
			$p = $cur;
			break;
		}
		$cur = (int) $by_id[ $cur ]['parent_id'];
	}
	$parent[ $kid ] = $p;
}

$children = [];
foreach ( $kept_ids as $kid ) {
	if ( $parent[ $kid ] > 0 ) {
		$children[ $parent[ $kid ] ][] = $kid;
	}
}

// Map CSV level column to DB level (1-based depth among kept categories):
// kept levels start at 3 (families) -> DB 1, 4 (series) -> DB 2, 5 -> DB 3.
$base_level = null;
foreach ( $kept_ids as $kid ) {
	$lv = (int) $by_id[ $kid ]['level'];
	if ( $base_level === null || $lv < $base_level ) {
		$base_level = $lv;
	}
}
echo "CSV base level: $base_level (families), series levels " . ( $base_level + 1 ) . " and " . ( $base_level + 2 ) . " mapped to DB levels 2 and 3.\n";

// ---- Snapshot current categories + products before deleting ----
echo "Snapshotting current categories and products...\n";
$old_cats = $wpdb->get_results( $wpdb->prepare(
	"SELECT id, slug FROM $table_c WHERE manufacturer_id = %d", $mfr_id
) );
$old_slug_by_id = [];
foreach ( $old_cats as $oc ) {
	$old_slug_by_id[ (int) $oc->id ] = $oc->slug;
}

$products = $wpdb->get_results( $wpdb->prepare(
	"SELECT id, category_id FROM $table_p WHERE manufacturer_id = %d", $mfr_id
) );

echo "Deleting existing Bulgin categories...\n";
$wpdb->delete( $table_c, [ 'manufacturer_id' => $mfr_id ], [ '%d' ] );

// ---- Insert top-level families (level 1) ----
$new_by_slug = [];
$fam_count = 0;
$ser_count = 0;

function bulgin_content( $row ) {
	$desc   = $row['description'] ?? '';
	$image  = $row['image_url'] ?? '';
	$meta   = [
		'titulo'     => $row['title'] ?? '',
		'heading'    => $row['heading'] ?? '',
		'summary'    => $row['summary'] ?? '',
		'highlights' => $row['summary'] ?? '',
		'image_url'  => $image,
		'pdf_url'    => $row['pdf_url'] ?? '',
		'series_id'  => (int) $row['category_id'],
	];
	$meta   = array_filter( $meta, static function ( $v ) {
		return $v !== '' && $v !== null;
	} );
	return [ 'description' => $desc, 'image' => $image, 'metadata_json' => wp_json_encode( $meta, JSON_UNESCAPED_SLASHES ) ];
}

function bulgin_insert( $mfr_id, $slug, $name, $parent_id, $level, $content, $table_c ) {
	global $wpdb;
	$wpdb->insert( $table_c, [
		'manufacturer_id' => $mfr_id,
		'parent_id'       => $parent_id,
		'name'            => $name,
		'slug'            => $slug,
		'type'            => 'category',
		'description'     => $content['description'],
		'image'           => $content['image'],
		'level'           => $level,
		'products_count'  => 0,
		'metadata_json'   => $content['metadata_json'],
	], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ] );
	return (int) $wpdb->insert_id;
}

echo "Inserting top-level families (level 1)...\n";
foreach ( $kept_ids as $kid ) {
	if ( $parent[ $kid ] > 0 ) {
		continue;
	}
	$slug = $slug_map[ (string) $kid ];
	$row  = $by_id[ $kid ];
	$level = (int) $row['level'] - $base_level + 1;
	$new_by_slug[ $slug ] = bulgin_insert( $mfr_id, $slug, $row['name'], null, $level, bulgin_content( $row ), $table_c );
	$fam_count++;
}
echo "  Families: $fam_count\n";

echo "Inserting series (levels from CSV)...\n";
foreach ( $children as $pid => $kids ) {
	$p_slug = $slug_map[ (string) $pid ];
	if ( ! isset( $new_by_slug[ $p_slug ] ) ) {
		continue;
	}
	$p_id = $new_by_slug[ $p_slug ];
	foreach ( $kids as $kid ) {
		$slug = $slug_map[ (string) $kid ];
		$row  = $by_id[ $kid ];
		$level = (int) $row['level'] - $base_level + 1;
		$new_by_slug[ $slug ] = bulgin_insert( $mfr_id, $slug, $row['name'], $p_id, $level, bulgin_content( $row ), $table_c );
		$ser_count++;
	}
}
echo "  Series: $ser_count\n";

// ---- Uncategorized fallback (level 1, rendered last) ----
$uncat_slug = 'uncategorized';
$new_by_slug[ $uncat_slug ] = bulgin_insert( $mfr_id, $uncat_slug, 'Uncategorized', null, 1, [
	'description'   => '',
	'image'         => '',
	'metadata_json' => '[]',
], $table_c );
echo "  Fallback: $uncat_slug\n";

// ---- Remap products ----
$old_to_new = [];
foreach ( $kept_ids as $kid ) {
	$slug = $slug_map[ (string) $kid ];
	$old_to_new[ $slug ] = $new_by_slug[ $slug ];
	$old_to_new[ sanitize_title( $by_id[ $kid ]['name'] ) ] = $new_by_slug[ $slug ];
}
$uncat_new = $new_by_slug[ $uncat_slug ];

$remapped = 0;
$to_uncat = 0;
foreach ( $products as $p ) {
	$old_cid  = (int) $p->category_id;
	$old_slug = isset( $old_slug_by_id[ $old_cid ] ) ? $old_slug_by_id[ $old_cid ] : '';
	$new_id   = isset( $old_to_new[ $old_slug ] ) ? $old_to_new[ $old_slug ] : $uncat_new;
	if ( $new_id === $uncat_new && $old_slug !== $uncat_slug ) {
		$to_uncat++;
	} else {
		$remapped++;
	}
	$wpdb->update( $table_p, [ 'category_id' => $new_id ], [ 'id' => $p->id ], [ '%d' ], [ '%d' ] );
}
echo "Products remapped: $remapped | to uncategorized: $to_uncat\n";

// ---- products_count ----
foreach ( $new_by_slug as $slug => $cid ) {
	$count = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM $table_p WHERE category_id = %d", $cid
	) );
	$wpdb->update( $table_c, [ 'products_count' => $count ], [ 'id' => $cid ], [ '%d' ], [ '%d' ] );
}

// ---- Summary ----
echo "\n=== Resumen ===\n";
echo "Familias: $fam_count | Series: $ser_count | Fallback: $uncat_slug | Total categorías: " . count( $new_by_slug ) . "\n";
echo "\nJerarquía:\n";
foreach ( $kept_ids as $kid ) {
	if ( $parent[ $kid ] > 0 ) {
		continue;
	}
	$slug = $slug_map[ (string) $kid ];
	$cid  = $new_by_slug[ $slug ];
	$cnt  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT products_count FROM $table_c WHERE id = %d", $cid ) );
	$lvl  = (int) $by_id[ $kid ]['level'] - $base_level + 1;
	printf( "  L%d %-40s %-8s (%d productos)\n", $lvl, $slug, $by_id[ $kid ]['name'], $cnt );
	foreach ( $children[ $kid ] ?? [] as $child ) {
		$c_slug = $slug_map[ (string) $child ];
		$c_cid  = $new_by_slug[ $c_slug ];
		$c_cnt  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT products_count FROM $table_c WHERE id = %d", $c_cid ) );
		$c_lvl  = (int) $by_id[ $child ]['level'] - $base_level + 1;
		printf( "      L%d %-36s %-8s (%d productos)\n", $c_lvl, $c_slug, $by_id[ $child ]['name'], $c_cnt );
	}
}

// ---- Regenerate pages ----
echo "\nRegenerando paginas...\n";
$processor_mgr = new \AOE\CatalogEngine\Import\ProcessorManager();
$bp = new \AOE\CatalogEngine\Import\BatchProcessor( $processor_mgr );
$bp->pack_catalog( $mfr_id, $manufacturer_slug, $processor_mgr->get_processor( $manufacturer_slug ) );

echo "Paginas regeneradas.\n\nDone.\n";
