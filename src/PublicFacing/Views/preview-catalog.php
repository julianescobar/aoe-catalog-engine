<?php
/**
 * Virtual preview catalog template.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$preview_slug = get_query_var( 'aoe_catalog_preview' );
$preview_data = get_transient( 'aoe_preview_' . $preview_slug );

if ( ! is_array( $preview_data ) ) {
	global $wp_query;
	$wp_query->set_404();
	status_header( 404 );
	get_template_part( '404' );
	exit;
}

$current_page = max( 1, intval( get_query_var( 'aoe_catalog_page', 1 ) ) );

$all_products  = is_array( $preview_data['products'] ?? null ) ? $preview_data['products'] : [];
$total_pages   = max( 1, ceil( count( $all_products ) / 200 ) );
$current_page  = min( $current_page, $total_pages );
$offset        = ( $current_page - 1 ) * 200;
$page_products = array_slice( $all_products, $offset, 200 );

// Get category name from first product or from payload
$first         = $page_products[0] ?? $all_products[0] ?? [];
$category      = ! empty( $first['category'] ) ? $first['category'] : ( $preview_data['first_category'] ?? $preview_data['category'] ?? 'Catalogo' );

$template_post_id = intval( $preview_data['template_post_id'] ?? 0 );
$template_post    = $template_post_id ? get_post( $template_post_id ) : null;

if ( ! $template_post ) {
	wp_die( 'La plantilla asociada a este fabricante no existe.', 'Plantilla no encontrada', [ 'response' => 404 ] );
}

require_once __DIR__ . '/catalog-render-html.php';

$catalog_css_path = dirname( dirname( dirname( __DIR__ ) ) ) . '/assets/css/catalog-render.css';

wp_enqueue_style(
	'aoe-catalog-render',
	plugin_dir_url( dirname( dirname( dirname( __DIR__ ) ) ) . '/aoe-catalog-engine.php' ) . 'assets/css/catalog-render.css',
	[],
	file_exists( $catalog_css_path ) ? filemtime( $catalog_css_path ) : '1.0.0'
);

global $post;
$post = $template_post;
setup_postdata( $post );

$catalog_html = aoe_catalog_render_html(
	$preview_data['manufacturer_name'] ?? '',
	$preview_data['test_slug'] . '/' . sanitize_title( $category ),
	$category,
	$page_products,
	$current_page,
	$total_pages,
	true
);
$content      = apply_filters( 'the_content', $template_post->post_content );
$content      = str_replace( [ '<p>[catalogo]</p>', '[catalogo]' ], $catalog_html, $content );

get_header();
echo $content;
wp_reset_postdata();
get_footer();
