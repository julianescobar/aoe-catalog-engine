<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap aoe-wrap">
	<div class="aoe-header">
		<h1>Logs de Importación</h1>
	</div>

	<div class="aoe-card">
		<p class="description">Historial de eventos y registro de cargas de catálogos realizadas en el sistema.</p>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th>Fecha</th>
					<th>Evento</th>
					<th>Fabricante</th>
					<th>Detalles</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $logs ) ) : ?>
					<tr>
						<td colspan="4">No se han registrado eventos todavía.</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $logs as $log ) : ?>
						<tr>
							<td><code><?php echo esc_html( $log['date'] ); ?></code></td>
							<td><?php echo esc_html( $log['event'] ); ?></td>
							<td><?php echo esc_html( $log['manufacturer'] ); ?></td>
							<td><?php echo esc_html( $log['details'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
</div>
