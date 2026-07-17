<?php
/**
 * CLI: Import Bivar categories from categories.csv.
 *
 * Usage:
 *   php tools/import-bivar-categories.php [--test] <csv_path>
 *
 * CSV columns: url,path,node_type,level,name,description,image_url,breadcrumb_names,breadcrumb_urls,filter_options
 *
 * Creates level 1 (category), level 2 (subcategory), level 3 (series/viewitems).
 * On conflict (same manufacturer + name + parent_id): updates description, image.
 */

if ( ! in_array( PHP_SAPI, [ 'cli', 'cgi-fcgi' ], true ) ) {
	die( 'CLI only' . "\n" );
}

if ( ob_get_level() ) {
	ob_end_flush();
}
ob_implicit_flush( true );

// Parse args
$args   = array_slice( $argv, 1 );
$csv_path = '';
$is_test  = false;
foreach ( $args as $arg ) {
	if ( $arg === '--test' ) {
		$is_test = true;
	} else {
		$csv_path = $arg;
	}
}

if ( ! $csv_path ) {
	echo "Usage: php tools/import-bivar-categories.php [--test] <csv_path>\n";
	exit( 1 );
}

if ( ! file_exists( $csv_path ) ) {
	echo "Error: file not found: $csv_path\n";
	exit( 1 );
}

echo "Loading WordPress...\n";
require_once dirname( __DIR__ ) . '/wp-load.php';

global $wpdb;

$manufacturer_slug = 'bivar';
$manufacturer = $wpdb->get_row( $wpdb->prepare(
	"SELECT id, name FROM wp_aoe_catalog_manufacturers WHERE slug = %s",
	$manufacturer_slug
) );

if ( ! $manufacturer ) {
	echo "Error: manufacturer '$manufacturer_slug' not found. Create it in the admin first.\n";
	exit( 1 );
}

$mfr_id  = (int) $manufacturer->id;
$mfr_name = $manufacturer->name;
$table_c = $wpdb->prefix . 'aoe_catalog_categories';

// Open CSV
$fh = fopen( $csv_path, 'r' );
if ( ! $fh ) {
	echo "Error: could not open CSV\n";
	exit( 1 );
}

// Read header
$header = fgetcsv( $fh, 0, ',' );
if ( ! $header ) {
	echo "Error: empty CSV\n";
	exit( 1 );
}

// Map columns
$col_map = array_flip( array_map( 'trim', $header ) );

$required = [ 'name', 'level' ];
foreach ( $required as $col ) {
	if ( ! isset( $col_map[ $col ] ) ) {
		echo "Error: missing required column '$col'\n";
		exit( 1 );
	}
}

// Column names in the CSV
$idx_name        = $col_map['name'];
$idx_level       = $col_map['level'];
$idx_description = $col_map['description'] ?? null;
$idx_image_url   = $col_map['image_url'] ?? null;
$idx_node_type   = $col_map['node_type'] ?? null;
$idx_path        = $col_map['path'] ?? null;
$idx_breadcrumb  = $col_map['breadcrumb_names'] ?? null;

/**
 * Find a category by name and parent_id within this manufacturer.
 */
function find_category( $wpdb, $table_c, $mfr_id, $name, $parent_id ) {
	if ( $parent_id === null ) {
		return $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM $table_c WHERE manufacturer_id = %d AND name = %s AND parent_id IS NULL LIMIT 1",
			$mfr_id, $name
		) );
	}
	return $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM $table_c WHERE manufacturer_id = %d AND name = %s AND parent_id = %d LIMIT 1",
		$mfr_id, $name, $parent_id
	) );
}

$created = 0;
$updated = 0;
$skipped = 0;
$row_num = 0;

// We'll buffer all rows first to resolve parents properly.
$rows = [];
while ( ( $line = fgetcsv( $fh, 0, ',' ) ) !== false ) {
	$row_num++;
	if ( count( $line ) < count( $header ) ) {
		continue;
	}
	$data = array_combine( array_keys( $col_map ), array_map( function( $idx ) use ( $line ) {
		return isset( $line[ $idx ] ) ? trim( $line[ $idx ] ) : '';
	}, array_values( $col_map ) ) );

	$level = (int) $data['level'];
	// Skip level 1 (All Categories root)
	if ( $level <= 1 ) {
		continue;
	}

	$data['_level_normalized'] = $level >= 5 ? 3 : ( $level - 1 );

	$rows[] = $data;
}
fclose( $fh );

echo sprintf( "Read %d rows from CSV\n", count( $rows ) );

// Process sorted by level asc so parents exist before children
usort( $rows, function( $a, $b ) {
	return $a['_level_normalized'] - $b['_level_normalized'];
} );

// Map path => DB category id for parent resolution
$path_to_id = []; // CSV "path" -> DB id

foreach ( $rows as $row ) {
	$level    = $row['_level_normalized'];
	$name     = $row[ $idx_name ];
	$path_val = $idx_path !== null ? ( $row[ $idx_path ] ?? '' ) : '';

	if ( empty( $name ) ) {
		$skipped++;
		continue;
	}

	// Determine parent
	$parent_id = null;

	if ( $level > 1 ) {
		// Use breadcrumb to find parent: second-to-last segment is parent name
		$bc = $idx_breadcrumb !== null ? ( $row[ $idx_breadcrumb ] ?? '' ) : '';
		if ( ! empty( $bc ) ) {
			$parts = array_map( 'trim', explode( '>', $bc ) );
			// Remove "All Categories" if present
			$parts = array_values( array_filter( $parts, function( $p ) {
				return strtolower( $p ) !== 'all categories' && strtolower( $p ) !== 'view items';
			} ) );

			if ( count( $parts ) >= 2 ) {
				// Parent is the second-to-last breadcrumb element
				$parent_name_for_level = $parts[ count( $parts ) - 2 ];
				$parent_id_for_level = find_category( $wpdb, $table_c, $mfr_id, $parent_name_for_level, null );

				// We need to search deeper if parent is not a top-level cat
				if ( ! $parent_id_for_level && count( $parts ) > 2 ) {
					// Walk backwards to find grandparent etc
					$grandparent_name = $parts[ count( $parts ) - 3 ] ?? '';
					$grandparent_id   = null;
					if ( ! empty( $grandparent_name ) ) {
						$grandparent_id = find_category( $wpdb, $table_c, $mfr_id, $grandparent_name, null );
					}
					$parent_id_for_level = find_category( $wpdb, $table_c, $mfr_id, $parent_name_for_level, $grandparent_id );
				}

				if ( $parent_id_for_level ) {
					$parent_id = $parent_id_for_level;
				}
			} elseif ( count( $parts ) === 1 ) {
				// Only one breadcrumb segment -> level 1, parent is null
				$parent_id = null;
			}
		}

		// Fallback: derive parent from path segments
		if ( $parent_id === null && ! empty( $path_val ) ) {
			$segments = array_values( array_filter( explode( '/', $path_val ) ) );
			if ( count( $segments ) >= 2 ) {
				$parent_path = '/' . implode( '/', array_slice( $segments, 0, -1 ) );
				if ( isset( $path_to_id[ $parent_path ] ) ) {
					$parent_id = $path_to_id[ $parent_path ];
				}
			}
		}
	}

	// Check if category exists
	$existing_id = find_category( $wpdb, $table_c, $mfr_id, $name, $parent_id );

	if ( $existing_id ) {
		// Update description and image
		$updates = [];
		$update_types = [];
		if ( $idx_description !== null && ! empty( $row[ $idx_description ] ) ) {
			$updates['description'] = $row[ $idx_description ];
			$update_types[] = '%s';
		}
		if ( $idx_image_url !== null && ! empty( $row[ $idx_image_url ] ) ) {
			$updates['image'] = $row[ $idx_image_url ];
			$update_types[] = '%s';
		}
		if ( ! empty( $updates ) ) {
			if ( ! $is_test ) {
				$wpdb->update( $table_c, $updates, [ 'id' => $existing_id ], $update_types, [ '%d' ] );
			}
			$updated++;
			if ( ! $is_test ) {
				echo "  UPDATED [level $level]: $name\n";
			}
		} else {
			$skipped++;
		}
	} else {
		// Create new category
		$slug = sanitize_title( $name );
		$insert_data = [
			'manufacturer_id' => $mfr_id,
			'parent_id'       => $parent_id,
			'name'            => $name,
			'slug'            => $slug,
			'type'            => 'category',
			'description'     => $idx_description !== null ? ( $row[ $idx_description ] ?? '' ) : '',
			'image'           => $idx_image_url !== null ? ( $row[ $idx_image_url ] ?? '' ) : '',
			'level'           => $level,
			'products_count'  => 0,
			'metadata_json'   => json_encode( [] ),
		];
		if ( ! $is_test ) {
			$wpdb->insert( $table_c, $insert_data, [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ] );
			$new_id = (int) $wpdb->insert_id;
			if ( ! empty( $path_val ) ) {
				$path_to_id[ $path_val ] = $new_id;
			}
		}
		$created++;
		echo "  CREATED [level $level]: $name\n";
	}
}

echo "\n--- Done ---\n";
if ( $is_test ) {
	echo "TEST MODE - no changes were made.\n";
}
echo sprintf(
	"Created: %d | Updated: %d | Skipped: %d | Manufacturer: %s (ID: %d)\n",
	$created, $updated, $skipped, $mfr_name, $mfr_id
);
