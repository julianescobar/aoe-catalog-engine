<?php
/**
 * CLI: Build search index table for chatbot (Nina).
 *
 * Creates and populates wp_aoe_catalog_search_products with normalized SKU,
 * manufacturer, full-text search_text, and a payload_json containing the
 * full product data (name, description, category_path, images, docs, specs, page_urls).
 *
 * Thin wrapper around AOE\CatalogEngine\Database\SearchIndexer (single source of truth).
 *
 * Usage:
 *   php tools/build-search-index.php --manufacturer=panduit
 *   php tools/build-search-index.php --all
 *   php tools/build-search-index.php                    (shows help)
 */

if ( PHP_SAPI !== 'cli' ) {
	die( "CLI only.\n" );
}

// ---- Parse args ----
$args = [];
foreach ( $argv as $arg ) {
	if ( 0 === strpos( $arg, '--' ) ) {
		$parts = explode( '=', substr( $arg, 2 ), 2 );
		$args[ $parts[0] ] = $parts[1] ?? true;
	}
}

ini_set( 'memory_limit', '1G' );
ob_implicit_flush( true );

if ( ! isset( $args['manufacturer'] ) && ! isset( $args['all'] ) && ! isset( $args['stats'] ) ) {
	echo <<<HELP
Usage:
  php tools/build-search-index.php --manufacturer=slug   Index one manufacturer
  php tools/build-search-index.php --all                 Index all manufacturers
  php tools/build-search-index.php --stats               Show table stats

HELP;
	exit( 0 );
}

// ---- Bootstrap WordPress ----
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

use AOE\CatalogEngine\Database\SearchIndexer;

$table_search = $wpdb->prefix . 'aoe_catalog_search_products';
$table_m      = $wpdb->prefix . 'aoe_catalog_manufacturers';

// ---- Create table if not exists ----
SearchIndexer::ensure_table();

echo "Table $table_search ready.\n\n";

// ---- Stats mode ----
if ( isset( $args['stats'] ) ) {
	$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_search" );
	echo "Total indexed products: $total\n\n";
	$rows = $wpdb->get_results( "SELECT manufacturer_name, manufacturer_normalized, COUNT(*) AS cnt FROM $table_search GROUP BY manufacturer_normalized ORDER BY cnt DESC" );
	if ( $rows ) {
		echo sprintf( "%-30s %-20s %s\n", 'Manufacturer', 'Normalized', 'Count' );
		echo str_repeat( '-', 60 ) . "\n";
		foreach ( $rows as $r ) {
			echo sprintf( "%-30s %-20s %d\n", $r->manufacturer_name, $r->manufacturer_normalized, $r->cnt );
		}
	}
	exit( 0 );
}

// ---- Determine manufacturers to index ----
if ( isset( $args['all'] ) ) {
	$manufacturers = $wpdb->get_results( "SELECT id, slug, name FROM $table_m ORDER BY name ASC" );
} else {
	$slug = $args['manufacturer'];
	$manufacturers = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, slug, name FROM $table_m WHERE slug = %s", $slug
	) );
	if ( empty( $manufacturers ) ) {
		die( "Manufacturer not found: $slug\n" );
	}
}

$progress_key = $args['progress-key'] ?? '';

// ---- Index each manufacturer ----
$total_indexed = 0;
foreach ( $manufacturers as $mfr ) {
	echo "=== {$mfr->name} ({$mfr->slug}) ===\n";

	$count = SearchIndexer::index_manufacturer(
		(int) $mfr->id,
		$mfr->slug,
		$mfr->name,
		1000,
		$progress_key,
		static function ( array $state ) {
			if ( ! empty( $state['errors'] ) ) {
				echo "  SQL ERROR: batch had {$state['errors']} failed statements\n";
			}
			echo "  Indexed: {$state['count']} / {$state['total']} (batch OK, mem: "
				. round( memory_get_peak_usage( true ) / 1048576, 1 ) . "MB)\n";
			flush();
		},
		static function ( int $total, int $categories, int $hidden ) {
			echo "  Categories: $categories (hidden: $hidden)\n";
			echo "  Products: $total\n";
		}
	);

	echo "\n  Done: $count products indexed.\n\n";
	$total_indexed += $count;
}

echo "=== Total indexed: $total_indexed products ===\n";
