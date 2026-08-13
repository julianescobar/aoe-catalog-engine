<?php
/**
 * CLI: Re-pack catalog pages for a single manufacturer (pack_catalog only).
 *
 * Usage:
 *   php tools/repack-catalog.php <manufacturer_slug>
 */

if ( PHP_SAPI !== 'cli' ) {
	die( "CLI only.\n" );
}
$slug = $argv[1] ?? '';
if ( '' === $slug ) {
	die( "Usage: php tools/repack-catalog.php <manufacturer_slug>\n" );
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
$table_m = $wpdb->prefix . 'aoe_catalog_manufacturers';
$mfr     = $wpdb->get_row( $wpdb->prepare( "SELECT id, slug FROM $table_m WHERE slug = %s", $slug ) );
if ( ! $mfr ) {
	die( "Manufacturer '$slug' not found.\n" );
}

$processor_mgr = new \AOE\CatalogEngine\Import\ProcessorManager();
$processor     = $processor_mgr->get_processor( $slug );
$bp            = new \AOE\CatalogEngine\Import\BatchProcessor( $processor_mgr );
$bp->pack_catalog( (int) $mfr->id, $slug, $processor );

$r = $wpdb->get_results( $wpdb->prepare(
	"SELECT type, COUNT(*) c FROM {$wpdb->prefix}aoe_catalog_pregenerated_pages WHERE manufacturer_id = %d GROUP BY type",
	$mfr->id
) );
foreach ( $r as $row ) {
	echo "{$row->type}: {$row->c}\n";
}
echo "Done.\n";
