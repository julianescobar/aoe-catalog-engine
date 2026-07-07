<?php
/**
 * CLI one-shot: assign existing products to their mapped categories from sku_map.
 *
 * Usage:
 *   php tools/apply-sku-map.php samtec
 *
 * This script:
 *   1. Loads all rows from wp_aoe_catalog_sku_map for the given manufacturer
 *   2. Creates any missing categories (slug = codigo_serie, sanitized)
 *   3. Updates each product's category_id to point to the mapped category
 *   4. Recalculates products_count on affected categories
 */

if ( ! in_array( PHP_SAPI, [ 'cli', 'cgi-fcgi' ], true ) ) {
	die( 'CLI only' );
}

$manufacturer_slug = $argv[1] ?? '';
$dry_run = in_array( '--dry-run', $argv );
$limit   = null;
foreach ( $argv as $arg ) {
	if ( preg_match( '/^--limit=(\d+)$/', $arg, $m ) ) {
		$limit = (int) $m[1];
	}
}
if ( empty( $manufacturer_slug ) ) {
	die( "Usage: php tools/apply-sku-map.php <manufacturer_slug> [--dry-run] [--limit=N]\n" );
}
if ( $dry_run ) {
	echo "*** DRY RUN — no changes will be made ***\n";
}
if ( $limit ) {
	echo "*** LIMIT = $limit products ***\n";
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

$manufacturer = $wpdb->get_row( $wpdb->prepare(
	"SELECT * FROM {$wpdb->prefix}aoe_catalog_manufacturers WHERE slug = %s",
	$manufacturer_slug
) );
if ( ! $manufacturer ) {
	die( "Manufacturer not found: $manufacturer_slug\n" );
}

$mfr_id = (int) $manufacturer->id;
$map_table = $wpdb->prefix . 'aoe_catalog_sku_map';
$cat_table = $wpdb->prefix . 'aoe_catalog_categories';
$prod_table = $wpdb->prefix . 'aoe_catalog_products';

echo "Loading sku_map for {$manufacturer->name} (ID: $mfr_id)...\n";

$map_rows = $wpdb->get_results( $wpdb->prepare(
	"SELECT DISTINCT codigo_serie FROM $map_table WHERE manufacturer_id = %d",
	$mfr_id
) );

echo "Found " . count( $map_rows ) . " unique codigo_serie values.\n";

// Step 1: Create any missing categories
$created = 0;
foreach ( $map_rows as $row ) {
	$slug = sanitize_title( $row->codigo_serie );
	if ( empty( $slug ) ) {
		continue;
	}
	$exists = $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM $cat_table WHERE manufacturer_id = %d AND slug = %s",
		$mfr_id, $slug
	) );
	if ( ! $exists ) {
		// Try to find parent from existing level-3 categories
		$parent_id = null;
		$prefix = strtolower( preg_replace( '/[^a-zA-Z0-9]+.*$/', '', $slug ) );
		if ( ! empty( $prefix ) ) {
			$parent_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM $cat_table WHERE manufacturer_id = %d AND level = 3 AND (slug = %s OR slug LIKE %s) LIMIT 1",
				$mfr_id, $prefix, $prefix . '-%'
			) );
		}

		$wpdb->insert( $cat_table, [
			'manufacturer_id' => $mfr_id,
			'parent_id'       => $parent_id ? (int) $parent_id : null,
			'name'            => $row->codigo_serie,
			'slug'            => $slug,
			'type'            => 'category',
			'description'     => '',
			'image'           => '',
			'level'           => 4,
			'products_count'  => 0,
			'metadata_json'   => '[]',
		], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ] );
		$created++;
	}
}
echo "Created $created categories.\n";

// Step 2: Update products in batches
$total = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM $prod_table WHERE manufacturer_id = %d",
	$mfr_id
) );
echo "Processing $total products...\n";

$batch_size = min( 500, $limit ?? 500 );
$processed = 0;
$updated = 0;

do {
	$products = $wpdb->get_results( $wpdb->prepare(
		"SELECT p.id, p.sku, p.category_id AS old_cat_id
		 FROM $prod_table p
		 WHERE p.manufacturer_id = %d
		 LIMIT %d OFFSET %d",
		$mfr_id, $batch_size, $processed
	) );

	if ( $limit && $processed + count( $products ) > $limit ) {
		$products = array_slice( $products, 0, $limit - $processed );
	}

	if ( empty( $products ) ) {
		break;
	}

	foreach ( $products as $prod ) {
		$codigo = $wpdb->get_var( $wpdb->prepare(
			"SELECT codigo_serie FROM $map_table WHERE manufacturer_id = %d AND sku = %s",
			$mfr_id, $prod->sku
		) );
		if ( ! $codigo ) {
			continue;
		}

		$new_slug = sanitize_title( $codigo );
		$new_cat_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM $cat_table WHERE manufacturer_id = %d AND slug = %s",
			$mfr_id, $new_slug
		) );
		if ( ! $new_cat_id || $new_cat_id === (int) $prod->old_cat_id ) {
			continue;
		}

		// Decrement old category count
		if ( $prod->old_cat_id > 0 ) {
			if ( ! $dry_run ) {
				$wpdb->query( $wpdb->prepare(
					"UPDATE $cat_table SET products_count = GREATEST(products_count - 1, 0) WHERE id = %d",
					$prod->old_cat_id
				) );
			}
		}

		// Update product
		if ( ! $dry_run ) {
			$wpdb->update( $prod_table, [ 'category_id' => $new_cat_id ], [ 'id' => $prod->id ] );
		}

		// Increment new category count
		if ( ! $dry_run ) {
			$wpdb->query( $wpdb->prepare(
				"UPDATE $cat_table SET products_count = products_count + 1 WHERE id = %d",
				$new_cat_id
			) );
		}

		$updated++;
	}

	$processed += count( $products );
	echo "Processed $processed / $total products (updated: $updated)\n";

} while ( count( $products ) === $batch_size );

echo "\nDone. Updated $updated products out of $processed processed.\n";

if ( ! $dry_run ) {
	echo "Regenerating pages...\n";
	require_once __DIR__ . '/../vendor/autoload.php';
	$processor_mgr = new \AOE\CatalogEngine\Import\ProcessorManager();
	$batch = new \AOE\CatalogEngine\Import\BatchProcessor( $processor_mgr );
	$batch->pack_catalog( $mfr_id, $manufacturer_slug, $processor_mgr->get_processor( $manufacturer_slug ) );
	echo "Pages regenerated.\n";
} else {
	echo "Skipped page regeneration (dry run).\n";
}
