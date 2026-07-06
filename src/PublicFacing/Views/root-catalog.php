<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$root_template_id = get_option( 'aoe_catalog_root_template_post_id', 0 );
$template_post = get_post( $root_template_id );
if ( ! $template_post ) {
	wp_die( 'Plantilla raíz no encontrada.', '', [ 'response' => 404 ] );
}

$table_m = $wpdb->prefix . 'aoe_catalog_manufacturers';
$manufacturers = $wpdb->get_results( "SELECT name, slug FROM $table_m ORDER BY name ASC" );

ob_start();
?>
<div class="aoe-catalog-index">
	<h1>Catálogos disponibles</h1>
	<?php if ( empty( $manufacturers ) ) : ?>
		<p>No hay catálogos disponibles en este momento.</p>
	<?php else : ?>
		<ul class="aoe-catalog-manufacturer-list">
			<?php foreach ( $manufacturers as $mfr ) : ?>
				<li>
					<a href="<?php echo esc_url( home_url( '/catalogo/' . $mfr->slug . '/' ) ); ?>">
						<?php echo esc_html( ucfirst( $mfr->name ) ); ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
<?php
$catalog_html = ob_get_clean();

// Use template cache if available
if ( \AOE\CatalogEngine\PublicFacing\TemplateCache::exists( 'root' ) ) {
	$template_full = \AOE\CatalogEngine\PublicFacing\TemplateCache::get( 'root' );
	if ( $template_full !== null ) {
		require_once __DIR__ . '/catalog-head-injector.php';
		$html = str_replace( [ '<p>[catalogo]</p>', '[catalogo]' ], $catalog_html, $template_full );
		$html = aoe_inject_dynamic_head( $html, aoe_get_catalog_seo_context( [
			'manufacturer_name' => '',
			'page_type'         => 'root',
		] ) );
		echo $html;
		exit;
	}
}

global $post, $wp_query;
$post = $template_post;
setup_postdata( $post );
$wp_query->queried_object    = $template_post;
$wp_query->queried_object_id = $template_post->ID;

$content = apply_filters( 'the_content', $template_post->post_content );
$content = str_replace( [ '<p>[catalogo]</p>', '[catalogo]' ], $catalog_html, $content );

get_header();
echo $content;
wp_reset_postdata();
get_footer();
