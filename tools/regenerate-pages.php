<?php
/**
 * Run pack_catalog only (after products are already imported via AJAX).
 *
 * Usage:
 *   php tools/regenerate-pages.php --manufacturer=camdenboss
 *   php tools/regenerate-pages.php --manufacturer=samtec
 */

namespace AOE\CatalogEngine\Tools;

if ( PHP_SAPI !== 'cli' ) {
	die( "CLI only.\n" );
}

$longopts = [ 'manufacturer:' ];
$args     = getopt( '', $longopts );
$slug     = $args['manufacturer'] ?? '';

if ( empty( $slug ) ) {
	die( "Uso: php tools/regenerate-pages.php --manufacturer=slug\n" );
}

$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php' ?: dirname( __DIR__, 5 ) . '/wp-load.php';
require_once $wp_load;

global $wpdb;

$manufacturer = $wpdb->get_row( $wpdb->prepare(
	"SELECT * FROM {$wpdb->prefix}aoe_catalog_manufacturers WHERE slug = %s", $slug
) );
if ( ! $manufacturer ) {
	die( "Fabricante no encontrado: {$slug}\n" );
}

$manager   = new \AOE\CatalogEngine\Import\ProcessorManager();
$processor = $manager->get_processor( $slug );
$batch     = new \AOE\CatalogEngine\Import\BatchProcessor( $manager );

echo "Regenerando páginas para {$slug}...\n";
$start = microtime( true );
$batch->pack_catalog( (int) $manufacturer->id, $slug, $processor );
echo "Completado en " . round( microtime( true ) - $start, 1 ) . "s\n";
