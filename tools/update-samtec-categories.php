<?php
/**
 * CLI: Update Samtec categories from CSV (description + caracteristicas only).
 *
 * Usage:
 *   php tools/update-samtec-categories.php [--manufacturer=samtec] [--test] <csv_path>
 *
 * CSV format (same as admin import):
 *   tipo,categoria,subcategoria,serie,producto,codigo_serie,nombre,titulo,descripcion,caracteristicas,imagen
 *
 * Only updates: description + metadata_json.features
 * Never inserts or deletes.
 */

if ( ! in_array( PHP_SAPI, [ 'cli', 'cgi-fcgi' ], true ) ) {
	die( 'CLI only' . "\n" );
}

if ( ob_get_level() ) {
	ob_end_flush();
}
ob_implicit_flush( true );

// Parse args
$args = array_slice( $argv, 1 );
$csv_path = '';
$manufacturer_slug = 'samtec';
$is_test = false;
foreach ( $args as $arg ) {
	if ( str_starts_with( $arg, '--manufacturer=' ) ) {
		$manufacturer_slug = substr( $arg, strlen( '--manufacturer=' ) );
	} elseif ( $arg === '--test' ) {
		$is_test = true;
	} else {
		$csv_path = $arg;
	}
}

if ( ! $csv_path ) {
	echo "Usage: php tools/update-samtec-categories.php [--manufacturer=samtec] [--test] <csv_path>\n";
	exit( 1 );
}

if ( ! file_exists( $csv_path ) ) {
	echo "Error: file not found: $csv_path\n";
	exit( 1 );
}

echo "Loading WordPress...\n";

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
$wpdb->show_errors();

// Resolve manufacturer
$table_m = $wpdb->prefix . 'aoe_catalog_manufacturers';
$mfr = $wpdb->get_row( $wpdb->prepare(
	"SELECT id, name FROM $table_m WHERE slug = %s",
	$manufacturer_slug
) );
if ( ! $mfr ) {
	echo "Error: manufacturer '$manufacturer_slug' not found.\n";
	exit( 1 );
}
$mfr_id = (int) $mfr->id;
echo "Manufacturer: {$mfr->name} (ID: $mfr_id)\n";
echo "CSV: $csv_path\n";
echo "Mode: " . ( $is_test ? 'TEST (first 10 rows)' : 'FULL' ) . "\n\n";

// Pre-load all categories for this manufacturer into a map
$table_c = $wpdb->prefix . 'aoe_catalog_categories';
$all_cats = $wpdb->get_results( $wpdb->prepare(
	"SELECT id, slug, parent_id, level, description FROM $table_c WHERE manufacturer_id = %d",
	$mfr_id
) );

$cat_by_id = [];
$cat_by_slug_level = []; // slug -> [id, parent_id]
foreach ( $all_cats as $c ) {
	$c->id = (int) $c->id;
	$c->parent_id = $c->parent_id ? (int) $c->parent_id : null;
	$c->level = (int) $c->level;
	$cat_by_id[ $c->id ] = $c;
	$cat_by_slug_level[ $c->slug ][] = $c;
}

echo "Loaded {$wpdb->num_rows} existing categories.\n";

/**
 * Resolve a category by its ancestry path.
 * Returns the category object or null.
 *
 * @param string $type   tipo: categoria|subcategoria|serie|producto
 * @param array  $slugs  keys: categoria, subcategoria, serie, producto
 */
function resolve_category( $type, $slugs, &$cat_by_id, &$cat_by_slug_level ) {
	switch ( $type ) {
		case 'categoria':
			$slug = sanitize_title( trim( $slugs['categoria'] ?? '' ) );
			if ( ! $slug ) return null;
			$matches = $cat_by_slug_level[ $slug ] ?? [];
			foreach ( $matches as $c ) {
				if ( $c->level === 1 && $c->parent_id === null ) {
					return $c;
				}
			}
			return null;

		case 'subcategoria':
			$parent_slug = sanitize_title( trim( $slugs['categoria'] ?? '' ) );
			$slug = sanitize_title( trim( $slugs['subcategoria'] ?? '' ) );
			if ( ! $slug || ! $parent_slug ) return null;
			// Find parent
			$parent = null;
			$pmatches = $cat_by_slug_level[ $parent_slug ] ?? [];
			foreach ( $pmatches as $c ) {
				if ( $c->level === 1 && $c->parent_id === null ) {
					$parent = $c;
					break;
				}
			}
			if ( ! $parent ) return null;
			// Find subcat with this parent
			$matches = $cat_by_slug_level[ $slug ] ?? [];
			foreach ( $matches as $c ) {
				if ( $c->level === 2 && $c->parent_id === $parent->id ) {
					return $c;
				}
			}
			return null;

		case 'serie':
			$parent_slug = sanitize_title( trim( $slugs['subcategoria'] ?? '' ) );
			$slug = sanitize_title( trim( $slugs['serie'] ?? '' ) );
			if ( ! $slug || ! $parent_slug ) return null;
			$matches = $cat_by_slug_level[ $slug ] ?? [];
			foreach ( $matches as $c ) {
				if ( $c->level === 3 ) {
					// Verify parent slug matches
					$parent = $c->parent_id ? ( $cat_by_id[ $c->parent_id ] ?? null ) : null;
					if ( $parent && $parent->slug === $parent_slug ) {
						return $c;
					}
				}
			}
			return null;

		case 'producto':
			$parent_slug = sanitize_title( trim( $slugs['serie'] ?? '' ) );
			$slug = sanitize_title( trim( $slugs['producto'] ?? '' ) );
			// Fallback to codigo_serie if producto is empty
			if ( ! $slug ) {
				$slug = sanitize_title( trim( $slugs['codigo_serie'] ?? '' ) );
			}
			if ( ! $slug || ! $parent_slug ) return null;
			$matches = $cat_by_slug_level[ $slug ] ?? [];
			foreach ( $matches as $c ) {
				if ( $c->level === 4 ) {
					$parent = $c->parent_id ? ( $cat_by_id[ $c->parent_id ] ?? null ) : null;
					if ( $parent && $parent->slug === $parent_slug ) {
						return $c;
					}
				}
			}
			return null;
	}
	return null;
}

// Open CSV with auto UTF-16 detection
$fh = fopen( $csv_path, 'r' );
if ( ! $fh ) {
	echo "Error: cannot open file.\n";
	exit( 1 );
}

// Detect UTF-16
$bom = fread( $fh, 3 );
if ( $bom === "\xFF\xFE" || $bom === "\xFE\xFF" ) {
	rewind( $fh );
	stream_filter_append( $fh, 'convert.iconv.UTF-16/UTF-8' );
	echo "UTF-16 BOM detected, converting to UTF-8.\n";
} elseif ( str_starts_with( $bom, "\x00" ) || str_contains( $bom, "\x00" ) ) {
	rewind( $fh );
	stream_filter_append( $fh, 'convert.iconv.UTF-16/UTF-8' );
	echo "Null bytes detected, converting from UTF-16.\n";
} else {
	rewind( $fh );
}

// Read headers and strip BOM
$headers = fgetcsv( $fh, 0, ',' );
if ( ! $headers ) {
	echo "Error: empty CSV.\n";
	exit( 1 );
}
$headers[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $headers[0] );
echo "Headers: " . implode( ', ', $headers ) . "\n\n";

// Map column indices
$col_map = array_flip( $headers );
$col_tipo          = $col_map['tipo'] ?? null;
$col_categoria     = $col_map['categoria'] ?? null;
$col_subcategoria  = $col_map['subcategoria'] ?? null;
$col_serie         = $col_map['serie'] ?? null;
$col_producto      = $col_map['producto'] ?? null;
$col_codigo_serie  = $col_map['codigo_serie'] ?? null;
$col_descripcion   = $col_map['descripcion'] ?? null;
$col_caracteristicas = $col_map['caracteristicas'] ?? null;

$row_count = 0;
$update_count = 0;
$skip_count = 0;
$not_found_count = 0;
$stats_by_type = [];

$stmt = $wpdb->prepare(
	"UPDATE $table_c SET description = %s, metadata_json = %s WHERE id = %d"
); // We'll build per-row

$max_rows = $is_test ? 10 : PHP_INT_MAX;

echo "Processing rows...\n";

while ( ( $row = fgetcsv( $fh, 0, ',' ) ) !== false ) {
	if ( $row_count >= $max_rows ) break;

	$row_count++;
	$row = array_map( 'trim', $row );
	$tipo = $row[ $col_tipo ] ?? '';

	if ( $tipo === 'categoria' ) $stats_by_type['categorias'] = ( $stats_by_type['categorias'] ?? 0 ) + 1;
	elseif ( $tipo === 'subcategoria' ) $stats_by_type['subcategorias'] = ( $stats_by_type['subcategorias'] ?? 0 ) + 1;
	elseif ( $tipo === 'serie' ) $stats_by_type['series'] = ( $stats_by_type['series'] ?? 0 ) + 1;
	elseif ( $tipo === 'producto' ) $stats_by_type['productos'] = ( $stats_by_type['productos'] ?? 0 ) + 1;
	else continue;

	// Resolve the category
	$cat = resolve_category( $tipo, [
		'categoria'    => $row[ $col_categoria ] ?? '',
		'subcategoria' => $row[ $col_subcategoria ] ?? '',
		'serie'        => $row[ $col_serie ] ?? '',
		'producto'     => $row[ $col_producto ] ?? '',
		'codigo_serie' => $row[ $col_codigo_serie ] ?? '',
	], $cat_by_id, $cat_by_slug_level );

	if ( ! $cat ) {
		$not_found_count++;
		if ( $is_test ) {
			echo "  [NOT FOUND] $tipo: " . ( $row[ $col_categoria ] ?? '' ) . '/' . ( $row[ $col_subcategoria ] ?? '' ) . '/' . ( $row[ $col_serie ] ?? '' ) . '/' . ( $row[ $col_producto ] ?? '' ) . "\n";
		}
		continue;
	}

	$desc = $row[ $col_descripcion ] ?? '';
	$feats = $row[ $col_caracteristicas ] ?? '';

	// Decode existing metadata_json
	$meta = $cat->metadata_json ? json_decode( $cat->metadata_json, true ) : [];
	if ( ! is_array( $meta ) ) {
		$meta = [];
	}
	$meta['features'] = $feats;
	$new_meta_json = json_encode( $meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

	$updated = $wpdb->update(
		$table_c,
		[
			'description'   => $desc,
			'metadata_json' => $new_meta_json,
		],
		[ 'id' => $cat->id ],
		[ '%s', '%s' ],
		[ '%d' ]
	);

	if ( $updated !== false ) {
		$update_count++;
		if ( $is_test ) {
			echo "  [OK] $tipo ID {$cat->id}: {$cat->slug}\n";
		}
	} else {
		$skip_count++;
	}
}

fclose( $fh );

echo "\n--- Done ---\n";
echo "Rows read: $row_count\n";
echo "Updated: $update_count\n";
echo "Not found: $not_found_count\n";
echo "Errors: $skip_count\n";
echo "By type: " . json_encode( $stats_by_type, JSON_UNESCAPED_UNICODE ) . "\n";

if ( $is_test && $row_count === 10 ) {
	echo "\nTest complete. Remove --test to run full import.\n";
}
