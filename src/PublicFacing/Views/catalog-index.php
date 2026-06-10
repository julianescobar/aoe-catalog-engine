<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$table_m = $wpdb->prefix . 'aoe_catalog_manufacturers';
$manufacturers = $wpdb->get_results( "SELECT name, slug FROM $table_m ORDER BY name ASC" );

get_header();
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
get_footer();
