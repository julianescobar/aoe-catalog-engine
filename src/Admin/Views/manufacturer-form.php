<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_edit = isset( $_GET['action'] ) && 'edit' === $_GET['action'];
$title = $is_edit ? 'Editar Fabricante' : 'Añadir fabricante';
$btn_text = $is_edit ? 'Actualizar Fabricante' : 'Guardar Fabricante';

$name = $is_edit && isset( $manufacturer ) ? $manufacturer->name : '';
$slug = $is_edit && isset( $manufacturer ) ? $manufacturer->slug : '';
$wp_post_id = $is_edit && isset( $manufacturer ) ? $manufacturer->wp_post_id : 0;

// Fetch all 'catalogo_online' CPT posts to populate drop-down
$catalogs = get_posts( [
	'post_type'      => 'catalogo_online',
	'posts_per_page' => -1,
	'post_status'    => [ 'publish', 'draft' ],
] );
?>
<div class="wrap aoe-wrap">
	<div class="aoe-header">
		<h1><?php echo esc_html( $title ); ?></h1>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=aoe-catalog-manufacturers' ) ); ?>" class="page-title-action">Volver al listado</a>
	</div>

	<?php if ( isset( $error ) ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
	<?php endif; ?>

	<div class="aoe-card">
		<h2>Información del fabricante</h2>
		
		<form method="post" action="">
			<?php wp_nonce_field( 'save_manufacturer_action', 'aoe_manufacturer_nonce' ); ?>
			<input type="hidden" name="manufacturer_id" value="<?php echo esc_attr( isset( $manufacturer ) ? $manufacturer->id : 0 ); ?>" />

			<div class="aoe-form-group">
				<label for="m_name">Nombre</label>
				<input type="text" name="name" id="m_name" value="<?php echo esc_attr( $name ); ?>" required placeholder="Ej. Samtec" />
			</div>

			<div class="aoe-form-group">
				<label for="m_slug">Slug</label>
				<input type="text" name="slug" id="m_slug" value="<?php echo esc_attr( $slug ); ?>" required placeholder="ej-samtec" />
				<p class="description">Slug identificativo único. Se usará también para determinar el Processor de importación.</p>
			</div>

			<div class="aoe-form-group">
				<label for="m_post_id">Catálogo Online asociado en WordPress</label>
				<select name="wp_post_id" id="m_post_id">
					<option value="0">Seleccionar catálogo...</option>
					<?php foreach ( $catalogs as $c ) : ?>
						<option value="<?php echo esc_attr( $c->ID ); ?>" <?php selected( $wp_post_id, $c->ID ); ?>>
							<?php echo esc_html( $c->post_title ); ?> (ID: <?php echo esc_attr( $c->ID ); ?>)
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="aoe-btn-row">
				<input type="submit" name="save_manufacturer" class="button button-primary" value="<?php echo esc_attr( $btn_text ); ?>" />
			</div>
		</form>
	</div>
</div>
