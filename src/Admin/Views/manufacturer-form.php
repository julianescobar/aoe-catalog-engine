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
$config_json = $is_edit && isset( $manufacturer ) ? json_decode( $manufacturer->config_json ?? '', true ) ?: [] : [];
$seo_title_template = $config_json['seo_title_template'] ?? '';
$seo_description_template = $config_json['seo_description_template'] ?? '';

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

			<div class="aoe-card" style="margin-top:20px;">
				<h2>SEO - Meta Tags</h2>
				<p class="description">Personaliza el título y la descripción que aparecen en Google y redes sociales. Usa <code>{manufacturer}</code>, <code>{category}</code>. La paginación se añade automáticamente. Vacío = usa la plantilla global.</p>

				<div class="aoe-form-group">
					<label for="m_seo_title">Meta Title (Título para buscadores)</label>
					<input type="text" name="seo_title_template" id="m_seo_title" value="<?php echo esc_attr( $seo_title_template ); ?>" placeholder="Catálogo de productos de {manufacturer}: TC Componentes{page_suffix}" style="width:100%;" />
				</div>

				<div class="aoe-form-group">
					<label for="m_seo_description">Meta Description (Descripción para buscadores)</label>
					<textarea name="seo_description_template" id="m_seo_description" rows="3" placeholder="TC Componentes es distribuidor de {manufacturer} en España. Catálogo completo de productos, documentación técnica y soporte técnico especializado." style="width:100%;"><?php echo esc_textarea( $seo_description_template ); ?></textarea>
				</div>
			</div>

			<div class="aoe-btn-row">
				<input type="submit" name="save_manufacturer" class="button button-primary" value="<?php echo esc_attr( $btn_text ); ?>" />
			</div>
		</form>
	</div>
</div>
