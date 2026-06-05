<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;
$table = $wpdb->prefix . 'aoe_catalog_manufacturers';
$manufacturers = $wpdb->get_results( "SELECT * FROM $table ORDER BY name ASC" );
?>
<div class="wrap aoe-wrap">
	<div class="aoe-header">
		<h1>Catálogo - Páginas Generadas</h1>
	</div>

	<div class="aoe-card">
		<p class="description">Sitemaps XML generados por fabricante (Rank Math).</p>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th>Fabricante</th>
					<th>URL del Sitemap</th>
					<th>URL del Catálogo</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $manufacturers ) ) : ?>
					<tr>
						<td colspan="3">No hay fabricantes registrados.</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $manufacturers as $m ) : ?>
						<?php
						$catalog_url = home_url( '/catalogo/' . $m->slug . '/' );
						$sitemap_url = home_url( '/catalogo-' . $m->slug . '-sitemap.xml' );
						?>
						<tr>
							<td><strong><?php echo esc_html( $m->name ); ?></strong></td>
							<td><a href="<?php echo esc_url( $sitemap_url ); ?>" target="_blank"><code><?php echo esc_html( $sitemap_url ); ?></code></a></td>
							<td><a href="<?php echo esc_url( $catalog_url ); ?>" target="_blank"><code><?php echo esc_html( $catalog_url ); ?></code></a></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
</div>