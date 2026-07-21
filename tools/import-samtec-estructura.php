<?php
/**
 * CLI: Import Samtec category structure from catalogo.csv.
 *
 * Replicates ajax_import_samtec_categories() but reads the CSV file directly
 * server-side, bypassing browser/AJAX limits for large files.
 *
 * Usage:
 *   php tools/import-samtec-estructura.php samtec /path/to/catalogo.csv
 *
 * CSV columns: tipo,categoria,subcategoria,serie,producto,codigo_serie,nombre,titulo,descripcion,caracteristicas,imagen
 * Separator: auto-detected (tab, semicolon, comma)
 */

if ( ! in_array( PHP_SAPI, [ 'cli', 'cgi-fcgi' ], true ) ) {
	die( 'CLI only' );
}

$manufacturer_slug = $argv[1] ?? '';
$csv_path          = $argv[2] ?? '';

if ( empty( $manufacturer_slug ) || empty( $csv_path ) ) {
	die( "Usage: php tools/import-samtec-estructura.php <manufacturer_slug> <path/to/catalogo.csv>\n" );
}

if ( ! file_exists( $csv_path ) ) {
	die( "File not found: $csv_path\n" );
}

// Bootstrap WordPress
$wp_load = __DIR__ . '/../../../../../wp-load.php';
if ( ! file_exists( $wp_load ) ) {
	$wp_load = __DIR__ . '/../../../../wp-load.php';
}
if ( ! file_exists( $wp_load ) ) {
	$wp_load = __DIR__ . '/../../../wp-load.php';
}
if ( ! file_exists( $wp_load ) ) {
	die( "wp-load.php not found\n" );
}
require_once $wp_load;

global $wpdb;

// Check manufacturer exists
$table_m = $wpdb->prefix . 'aoe_catalog_manufacturers';
$manufacturer = $wpdb->get_row( $wpdb->prepare(
	"SELECT * FROM $table_m WHERE slug = %s",
	$manufacturer_slug
) );
if ( ! $manufacturer ) {
	die( "Manufacturer not found: $manufacturer_slug\n" );
}
$mfr_id = (int) $manufacturer->id;

// Auto-detect separator from first line
$handle = fopen( $csv_path, 'r' );
if ( ! $handle ) {
	die( "Cannot open file: $csv_path\n" );
}
$first_line = fgets( $handle );
fclose( $handle );

$sep = "\t";
if ( strpos( $first_line, ';' ) !== false ) {
	$sep = ';';
} elseif ( strpos( $first_line, ',' ) !== false ) {
	$sep = ',';
}

// Parse CSV into rows (same structure as rows_json from JS)
echo "Reading CSV (separator: " . ( $sep === "\t" ? 'tab' : $sep ) . ")...\n";
$handle = fopen( $csv_path, 'r' );
$header = fgetcsv( $handle, 0, $sep );
$header = array_map( 'trim', $header );

// Strip UTF-8 BOM from first column if present
$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $header[0] );

if ( ! $header ) {
	die( "Empty CSV or no header found.\n" );
}

$rows = [];
$line = 1;
while ( ( $cols = fgetcsv( $handle, 0, $sep ) ) !== false ) {
	$line++;
	$row = [];
	foreach ( $header as $idx => $col_name ) {
		$row[ $col_name ] = isset( $cols[ $idx ] ) ? trim( $cols[ $idx ] ) : '';
	}
	$rows[] = $row;
}
fclose( $handle );

echo "Parsed " . count( $rows ) . " rows.\n";

// ---- Replicate ajax_import_samtec_categories() logic ----
$table_c  = $wpdb->prefix . 'aoe_catalog_categories';
$table_p  = $wpdb->prefix . 'aoe_catalog_products';

$cat_map    = [];
$subcat_map = [];
$serie_map  = [];
$stats      = [ 'categorias' => 0, 'subcategorias' => 0, 'series' => 0, 'productos' => 0 ];

// Clean up previous CSV-imported categories before re-importing
echo "Cleaning existing categories...\n";
$wpdb->delete( $table_c, [ 'manufacturer_id' => $mfr_id, 'level' => 1 ], [ '%d', '%d' ] );
$wpdb->delete( $table_c, [ 'manufacturer_id' => $mfr_id, 'level' => 2 ], [ '%d', '%d' ] );
$wpdb->delete( $table_c, [ 'manufacturer_id' => $mfr_id, 'level' => 3 ], [ '%d', '%d' ] );
$wpdb->delete( $table_c, [ 'manufacturer_id' => $mfr_id, 'slug' => 'sin-clasificar' ], [ '%d', '%s' ] );
// Reset any previously-updated level 4 categories back to level 0
$wpdb->query( $wpdb->prepare(
	"UPDATE $table_c SET level = 0, parent_id = NULL, type = 'category' WHERE manufacturer_id = %d AND level = 4",
	$mfr_id
) );

// Pass 1: categorias (level 1)
echo "Importing categorias...\n";
foreach ( $rows as $row ) {
	if ( trim( $row['tipo'] ?? '' ) !== 'categoria' ) {
		continue;
	}
	$slug = sanitize_title( trim( $row['categoria'] ?? '' ) );
	if ( empty( $slug ) ) {
		continue;
	}
	$name   = trim( $row['nombre'] ?? '' ) ?: $slug;
	$titulo = trim( $row['titulo'] ?? '' );
	$desc   = trim( $row['descripcion'] ?? '' );
	$feats  = trim( $row['caracteristicas'] ?? '' );
	$img    = trim( $row['imagen'] ?? '' );

	$existing = $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM $table_c WHERE manufacturer_id = %d AND slug = %s AND parent_id IS NULL AND type = 'category'",
		$mfr_id, $slug
	) );

	if ( $existing ) {
		$wpdb->update( $table_c, [
			'name'          => $name,
			'description'   => $desc,
			'image'         => $img,
			'level'         => 1,
			'metadata_json' => json_encode( [
				'titulo'   => $titulo,
				'features' => $feats,
			] ),
		], [ 'id' => $existing ] );
		$cat_map[ $slug ] = (int) $existing;
	} else {
		$wpdb->insert( $table_c, [
			'manufacturer_id' => $mfr_id,
			'parent_id'       => null,
			'name'            => $name,
			'slug'            => $slug,
			'type'            => 'category',
			'description'     => $desc,
			'image'           => $img,
			'level'           => 1,
			'products_count'  => 0,
			'metadata_json'   => json_encode( [
				'titulo'   => $titulo,
				'features' => $feats,
			] ),
		], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ] );
		$cat_map[ $slug ] = (int) $wpdb->insert_id;
	}
	$stats['categorias']++;
}
echo "  Categorias: {$stats['categorias']}\n";

// Pass 2: subcategorias (level 2)
echo "Importando subcategorias...\n";
foreach ( $rows as $row ) {
	if ( trim( $row['tipo'] ?? '' ) !== 'subcategoria' ) {
		continue;
	}
	$cat_slug = sanitize_title( trim( $row['categoria'] ?? '' ) );
	$sub_slug = sanitize_title( trim( $row['subcategoria'] ?? '' ) );
	if ( empty( $sub_slug ) || ! isset( $cat_map[ $cat_slug ] ) ) {
		continue;
	}
	$parent_id = $cat_map[ $cat_slug ];
	$name      = trim( $row['nombre'] ?? '' ) ?: $sub_slug;
	$desc      = trim( $row['descripcion'] ?? '' );
	$feats     = trim( $row['caracteristicas'] ?? '' );
	$img       = trim( $row['imagen'] ?? '' );

	$existing = $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM $table_c WHERE manufacturer_id = %d AND slug = %s AND parent_id = %d",
		$mfr_id, $sub_slug, $parent_id
	) );

	if ( $existing ) {
		$wpdb->update( $table_c, [
			'name'          => $name,
			'description'   => $desc,
			'image'         => $img,
			'level'         => 2,
			'metadata_json' => json_encode( [
				'features' => $feats,
			] ),
		], [ 'id' => $existing ] );
		$subcat_map[ $cat_slug . '/' . $sub_slug ] = (int) $existing;
	} else {
		$wpdb->insert( $table_c, [
			'manufacturer_id' => $mfr_id,
			'parent_id'       => $parent_id,
			'name'            => $name,
			'slug'            => $sub_slug,
			'type'            => 'category',
			'description'     => $desc,
			'image'           => $img,
			'level'           => 2,
			'products_count'  => 0,
			'metadata_json'   => json_encode( [
				'features' => $feats,
			] ),
		], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ] );
		$subcat_map[ $cat_slug . '/' . $sub_slug ] = (int) $wpdb->insert_id;
	}
	$stats['subcategorias']++;
}
echo "  Subcategorias: {$stats['subcategorias']}\n";

// Pass 3: series (level 3)
echo "Importando series...\n";
foreach ( $rows as $row ) {
	if ( trim( $row['tipo'] ?? '' ) !== 'serie' ) {
		continue;
	}
	$cat_slug  = sanitize_title( trim( $row['categoria'] ?? '' ) );
	$sub_slug  = sanitize_title( trim( $row['subcategoria'] ?? '' ) );
	$ser_slug  = sanitize_title( trim( $row['serie'] ?? '' ) );
	$path      = $cat_slug . '/' . $sub_slug;
	if ( empty( $ser_slug ) || ! isset( $subcat_map[ $path ] ) ) {
		continue;
	}
	$parent_id = $subcat_map[ $path ];
	$name      = trim( $row['nombre'] ?? '' ) ?: ( trim( $row['serie'] ?? '' ) ?: $ser_slug );
	$desc      = trim( $row['descripcion'] ?? '' );
	$feats     = trim( $row['caracteristicas'] ?? '' );
	$img       = trim( $row['imagen'] ?? '' );
	$titulo    = trim( $row['titulo'] ?? '' );

	$existing = $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM $table_c WHERE manufacturer_id = %d AND slug = %s AND parent_id = %d",
		$mfr_id, $ser_slug, $parent_id
	) );

	if ( $existing ) {
		$wpdb->update( $table_c, [
			'name'          => $name,
			'description'   => $desc,
			'image'         => $img,
			'level'         => 3,
			'metadata_json' => json_encode( [
				'titulo'   => $titulo,
				'features' => $feats,
			] ),
		], [ 'id' => $existing ] );
		$serie_map[ $path . '/' . $ser_slug ] = (int) $existing;
	} else {
		$wpdb->insert( $table_c, [
			'manufacturer_id' => $mfr_id,
			'parent_id'       => $parent_id,
			'name'            => $name,
			'slug'            => $ser_slug,
			'type'            => 'series',
			'description'     => $desc,
			'image'           => $img,
			'level'           => 3,
			'products_count'  => 0,
			'metadata_json'   => json_encode( [
				'titulo'   => $titulo,
				'features' => $feats,
			] ),
		], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ] );
		$serie_map[ $path . '/' . $ser_slug ] = (int) $wpdb->insert_id;
	}
	$stats['series']++;
}
echo "  Series: {$stats['series']}\n";

// Pass 4: productos (level 4) — reassign products to parent serie
echo "Reasignando productos a series...\n";
foreach ( $rows as $row ) {
	if ( trim( $row['tipo'] ?? '' ) !== 'producto' ) {
		continue;
	}
	$cat_slug  = sanitize_title( trim( $row['categoria'] ?? '' ) );
	$sub_slug  = sanitize_title( trim( $row['subcategoria'] ?? '' ) );
	$ser_slug  = sanitize_title( trim( $row['serie'] ?? '' ) );
	$prod_slug = sanitize_title( trim( $row['producto'] ?? '' ) );
	$cod_serie = sanitize_title( trim( $row['codigo_serie'] ?? '' ) );

	if ( empty( $prod_slug ) ) {
		continue;
	}

	$serie_path   = $cat_slug . '/' . $sub_slug . '/' . $ser_slug;
	$parent_serie = isset( $serie_map[ $serie_path ] ) ? $serie_map[ $serie_path ] : null;
	if ( ! $parent_serie && ! empty( $sub_slug ) ) {
		$subcat_path = $cat_slug . '/' . $sub_slug;
		$parent_serie = isset( $subcat_map[ $subcat_path ] ) ? $subcat_map[ $subcat_path ] : null;
	}
	if ( ! $parent_serie && isset( $cat_map[ $cat_slug ] ) ) {
		$parent_serie = $cat_map[ $cat_slug ];
	}

	$existing = $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM $table_c WHERE manufacturer_id = %d AND slug = %s",
		$mfr_id, $prod_slug
	) );
	if ( ! $existing && ! empty( $cod_serie ) && $cod_serie !== $prod_slug ) {
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM $table_c WHERE manufacturer_id = %d AND slug = %s",
			$mfr_id, $cod_serie
		) );
	}

	$name   = trim( $row['nombre'] ?? '' ) ?: ( trim( $row['producto'] ?? '' ) ?: $prod_slug );
	$desc   = trim( $row['descripcion'] ?? '' );
	$feats  = trim( $row['caracteristicas'] ?? '' );
	$img    = trim( $row['imagen'] ?? '' );
	$titulo = trim( $row['titulo'] ?? '' );

	if ( $existing ) {
		$wpdb->update( $table_c, [
			'parent_id'     => $parent_serie,
			'name'          => $name,
			'description'   => $desc,
			'image'         => $img,
			'level'         => 4,
			'type'          => 'series',
			'metadata_json' => json_encode( [
				'titulo'   => $titulo,
				'features' => $feats,
			] ),
		], [ 'id' => $existing ] );
	} else {
		$wpdb->insert( $table_c, [
			'manufacturer_id' => $mfr_id,
			'parent_id'       => $parent_serie,
			'name'            => $name,
			'slug'            => $prod_slug,
			'type'            => 'series',
			'description'     => $desc,
			'image'           => $img,
			'level'           => 4,
			'products_count'  => 0,
			'metadata_json'   => json_encode( [
				'titulo'   => $titulo,
				'features' => $feats,
			] ),
		], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ] );
	}

	$stats['productos']++;
}
echo "  Productos reasignados: {$stats['productos']}\n";

// Pass 5: move leftover level-0 categories under "Sin clasificar"
echo "Buscando huerfanos...\n";
$orphans = $wpdb->get_results( $wpdb->prepare(
	"SELECT id, name, slug FROM $table_c WHERE manufacturer_id = %d AND level = 0 AND products_count > 0",
	$mfr_id
) );
if ( ! empty( $orphans ) ) {
	$uncat_id = $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM $table_c WHERE manufacturer_id = %d AND slug = 'sin-clasificar' AND level = 1",
		$mfr_id
	) );
	if ( ! $uncat_id ) {
		$wpdb->insert( $table_c, [
			'manufacturer_id' => $mfr_id,
			'parent_id'       => null,
			'name'            => 'Sin clasificar',
			'slug'            => 'sin-clasificar',
			'type'            => 'category',
			'description'     => '',
			'image'           => '',
			'level'           => 1,
			'products_count'  => 0,
			'metadata_json'   => '[]',
		], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ] );
		$uncat_id = (int) $wpdb->insert_id;
		$stats['categorias']++;
	}
	$uncat_id = (int) $uncat_id;
	foreach ( $orphans as $orphan ) {
		$wpdb->update( $table_c, [
			'parent_id' => $uncat_id,
			'level'     => 2,
		], [ 'id' => $orphan->id ] );
		$stats['huerfanos'] = ( $stats['huerfanos'] ?? 0 ) + 1;
	}
	// Update sin-clasificar products_count to reflect its new children
	$uncat_count = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT SUM(products_count) FROM $table_c WHERE parent_id = %d",
		$uncat_id
	) );
	$wpdb->update( $table_c, [ 'products_count' => $uncat_count ], [ 'id' => $uncat_id ] );
}

$msg = sprintf(
	"Importacion completada: %d categorias, %d subcategorias, %d series, %d productos.",
	$stats['categorias'],
	$stats['subcategorias'],
	$stats['series'],
	$stats['productos']
);
if ( ! empty( $stats['huerfanos'] ) ) {
	$msg .= sprintf( " %d huerfanos movidos a Sin clasificar.", $stats['huerfanos'] );
}

echo "$msg\n";

// Regenerate pages so tree reflects new hierarchy
echo "Regenerando paginas...\n";
$aoe_plugin = null;
foreach ( $GLOBALS['wp_actions'] as $key => $val ) { usleep( 1 ); } // dummy
$processor_mgr = new \AOE\CatalogEngine\Import\ProcessorManager();
$bp = new \AOE\CatalogEngine\Import\BatchProcessor( $processor_mgr );
$bp->pack_catalog( (int) $mfr_id, $manufacturer_slug, $processor_mgr->get_processor( $manufacturer_slug ) );

echo "Paginas regeneradas.\n\nDone.\n";
