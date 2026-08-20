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
$tree_layout = $config_json['tree_layout'] ?? 'normal';
$tree_columns = $config_json['tree_columns'] ?? 4;
$media_source = $config_json['media_source'] ?? 'local';
$logo_mode    = $config_json['manufacturer_logo_mode'] ?? 'template';
$logo_url     = $config_json['manufacturer_logo_url'] ?? '';

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
				<h2>Vista del Árbol de Categorías</h2>

				<div class="aoe-form-group">
					<label for="m_tree_layout">Formato</label>
					<select name="tree_layout" id="m_tree_layout">
						<option value="normal" <?php selected( $tree_layout, 'normal' ); ?>>Normal (tabla jerárquica)</option>
						<option value="columns" <?php selected( $tree_layout, 'columns' ); ?>>Columnas (grilla plana)</option>
						<option value="table_desc" <?php selected( $tree_layout, 'table_desc' ); ?>>Tabla con descripción</option>
						<option value="auto" <?php selected( $tree_layout, 'auto' ); ?>>Auto (detecta hojas como tabla)</option>
					</select>
					<p class="description">"Tabla con descripción" agrega una columna extra con la descripción al final del árbol. Ideal para series/familias de productos.</p>
				</div>

				<div class="aoe-form-group" id="aoe-columns-field" style="<?php echo $tree_layout !== 'columns' ? 'display:none;' : ''; ?>">
					<label for="m_tree_columns">Nº de columnas</label>
					<input type="number" name="tree_columns" id="m_tree_columns" value="<?php echo esc_attr( $tree_columns ); ?>" min="2" max="8" step="1" />
					<p class="description">Solo aplica si el formato es "Columnas".</p>
				</div>

				<div class="aoe-form-group">
					<label for="m_media_source">Origen de imágenes y PDFs</label>
					<select name="media_source" id="m_media_source">
						<option value="local" <?php selected( $media_source, 'local' ); ?>>Local (archivos propios en el servidor)</option>
						<option value="remote" <?php selected( $media_source, 'remote' ); ?>>Remoto (enlaces directos a la URL del fabricante)</option>
					</select>
				</div>
			</div>

			<div class="aoe-card" style="margin-top:20px;">
				<h2>Logo del Fabricante</h2>

				<div class="aoe-form-group">
					<label>Utilizar el mismo logo de la plantilla</label>
					<label style="display:inline-block;margin-right:15px;">
						<input type="radio" name="manufacturer_logo_mode" value="template" <?php checked( $logo_mode, 'template' ); ?>> Sí
					</label>
					<label style="display:inline-block;">
						<input type="radio" name="manufacturer_logo_mode" value="custom" <?php checked( $logo_mode, 'custom' ); ?>> No, elegir otro
					</label>
				</div>

				<div class="aoe-form-group" id="aoe-logo-custom-field" style="<?php echo $logo_mode !== 'custom' ? 'display:none;' : ''; ?>">
					<label for="m_manufacturer_logo_url">Logo personalizado</label>
					<div style="display:flex;align-items:center;gap:10px;">
						<input type="text" name="manufacturer_logo_url" id="m_manufacturer_logo_url" value="<?php echo esc_url( $logo_url ); ?>" class="regular-text" placeholder="URL de la imagen" />
						<button type="button" id="aoe-logo-upload" class="button">Seleccionar imagen</button>
						<button type="button" id="aoe-logo-remove" class="button" style="<?php echo empty( $logo_url ) ? 'display:none;' : ''; ?>">Quitar</button>
					</div>
					<div id="aoe-logo-preview" style="margin-top:10px;">
						<?php if ( ! empty( $logo_url ) ) : ?>
							<img src="<?php echo esc_url( $logo_url ); ?>" style="max-height:60px;" />
						<?php endif; ?>
					</div>
				</div>
			</div>

			<div class="aoe-btn-row">
				<input type="submit" name="save_manufacturer" class="button button-primary" value="<?php echo esc_attr( $btn_text ); ?>" />
			</div>
		</form>
	</div>
</div>

</div>
