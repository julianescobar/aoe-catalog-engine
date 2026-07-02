<?php
/**
 * Generate template cache for a manufacturer.
 *
 * The cached template includes header, footer, and the_content
 * with [catalogo] as a placeholder.
 *
 * Usage: php tools/generate-template-cache.php --manufacturer=samtec
 *        php tools/generate-template-cache.php --manufacturer=camdenboss --force
 */

namespace AOE\CatalogEngine\Tools;

if ( PHP_SAPI !== 'cli' ) {
	die( "CLI only.\n" );
}

$longopts = [ 'manufacturer:', 'force' ];
$args     = getopt( '', $longopts );
$slug     = $args['manufacturer'] ?? '';
$force    = isset( $args['force'] );

if ( empty( $slug ) ) {
	die( "Uso: php tools/generate-template-cache.php --manufacturer=slug [--force]\n" );
}

$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
if ( ! file_exists( $wp_load ) ) {
	$wp_load = dirname( __DIR__, 5 ) . '/wp-load.php';
}
if ( ! file_exists( $wp_load ) ) {
	die( "wp-load.php no encontrado.\n" );
}
require_once $wp_load;

global $wpdb;

$manufacturer = $wpdb->get_row( $wpdb->prepare(
	"SELECT * FROM {$wpdb->prefix}aoe_catalog_manufacturers WHERE slug = %s", $slug
) );
if ( ! $manufacturer ) {
	die( "Fabricante no encontrado: {$slug}\n" );
}

$template_post_id = (int) $manufacturer->wp_post_id;
if ( ! $template_post_id ) {
	die( "El fabricante {$slug} no tiene post plantilla asignado.\n" );
}

$template_post = get_post( $template_post_id );
if ( ! $template_post ) {
	die( "Post plantilla ID {$template_post_id} no encontrado.\n" );
}

$cache_dir = WP_CONTENT_DIR . '/uploads/aoe-cache-templates';
if ( ! is_dir( $cache_dir ) ) {
	wp_mkdir_p( $cache_dir );
}

// Check if already cached
$cache_file = $cache_dir . '/' . $slug . '.html';
if ( file_exists( $cache_file ) && ! $force ) {
	echo "Template cache ya existe para {$slug}. Usá --force para regenerar.\n";
	exit;
}

echo "Generando template cache para {$slug}...\n";

global $post;
$post = $template_post;
setup_postdata( $post );

ob_start();
get_header();
$header = ob_get_clean();

ob_start();
get_footer();
$footer = ob_get_clean();

$content = apply_filters( 'the_content', $template_post->post_content );

wp_reset_postdata();

$html = $header . "\n" . $content . "\n" . $footer;

// Save with [catalogo] placeholder intact
$bytes = file_put_contents( $cache_file, $html );
if ( false === $bytes ) {
	die( "Error escribiendo {$cache_file}\n" );
}

echo "OK: {$cache_file} ({$bytes} bytes)\n";
echo "Tiempo: ~" . round( microtime( true ) - $_SERVER['REQUEST_TIME_FLOAT'], 1 ) . "s\n";
