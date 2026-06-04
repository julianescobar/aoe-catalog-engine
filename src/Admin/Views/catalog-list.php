<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!--
<div class="wrap aoe-wrap">
	<div class="aoe-header">
		<h1>Catálogo - Páginas Generadas</h1>
	</div>

	<div class="aoe-card">
		<p class="description">Aquí se listan las páginas públicas generadas dinámicamente para los catálogos de los fabricantes.</p>
		
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th>URL</th>
					<th>Tipo de Página</th>
					<th>Fabricante</th>
					<th>Número de Productos</th>
					<th>Acciones</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $generated_pages ) ) : ?>
					<tr>
						<td colspan="5">No se han generado páginas de catálogo todavía. Importa un catálogo para empezar.</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $generated_pages as $page ) : ?>
						<tr>
							<td><strong><a href="<?php echo esc_url( $page['url'] ); ?>" target="_blank"><?php echo esc_html( $page['url'] ); ?></a></strong></td>
							<td><?php echo esc_html( ucfirst( $page['type'] ) ); ?></td>
							<td><?php echo esc_html( $page['manufacturer'] ); ?></td>
							<td><?php echo esc_html( $page['products_count'] ); ?></td>
							<td>
								<a class="button button-small" href="<?php echo esc_url( $page['url'] ); ?>" target="_blank">Ir al enlace</a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
</div>
-->