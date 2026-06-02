<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap aoe-wrap">
	<div class="aoe-header">
		<h1>Fabricantes</h1>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=aoe-catalog-manufacturers&action=add' ) ); ?>" class="page-title-action">Añadir fabricante</a>
	</div>

	<?php if ( isset( $_GET['message'] ) && 'deleted' === $_GET['message'] ) : ?>
		<div class="notice notice-success is-dismissible"><p>Fabricante eliminado correctamente.</p></div>
	<?php endif; ?>

	<div class="aoe-card">
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th>Nombre</th>
					<th>Slug</th>
					<th>Catálogo Asociado</th>
					<th>Acciones</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $manufacturers ) ) : ?>
					<tr>
						<td colspan="4">No hay fabricantes registrados. Haz clic en "Añadir fabricante" para crear uno.</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $manufacturers as $m ) : ?>
						<?php 
						$edit_url = admin_url( 'admin.php?page=aoe-catalog-manufacturers&action=edit&id=' . $m->id );
						$delete_url = wp_nonce_url( admin_url( 'admin.php?page=aoe-catalog-manufacturers&action=delete&id=' . $m->id ), 'delete_manufacturer_' . $m->id );
						$import_url = admin_url( 'admin.php?page=aoe-catalog-manufacturers&action=import&id=' . $m->id );
						
						// Get associated catalog name
						$catalog_name = 'Ninguno';
						if ( $m->wp_post_id ) {
							$post = get_post( $m->wp_post_id );
							if ( $post ) {
								$catalog_name = $post->post_title . ' (ID: ' . $post->ID . ')';
							}
						}
						?>
						<tr>
							<td><strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $m->name ); ?></a></strong></td>
							<td><code><?php echo esc_html( $m->slug ); ?></code></td>
							<td><?php echo esc_html( $catalog_name ); ?></td>
							<td>
								<a href="<?php echo esc_url( $edit_url ); ?>">Ver/Editar</a> | 
								<a href="<?php echo esc_url( $delete_url ); ?>" class="submitdelete" onclick="return confirm('¿Seguro que deseas eliminar este fabricante?');" style="color: #b32d2e;">Eliminar</a> | 
								<a href="<?php echo esc_url( $import_url ); ?>" style="font-weight: 600;">Importar catálogo</a> | 
								<a href="<?php echo esc_url( home_url( '/catalog/' . $m->slug ) ); ?>" target="_blank">Ver Catálogo</a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
</div>
