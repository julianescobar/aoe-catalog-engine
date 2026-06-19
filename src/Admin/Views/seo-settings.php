<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap aoe-wrap">
	<div class="aoe-header">
		<h1>SEO - Catálogo</h1>
	</div>

	<div class="aoe-card">
		<h2>Plantillas globales (por defecto)</h2>
		<p class="description">Estos valores se usan para todos los fabricantes que no tengan sus propias plantillas SEO configuradas. Usa <code>{manufacturer}</code>, <code>{category}</code>. La paginación se añade automáticamente.</p>

		<form method="post" action="">
			<?php wp_nonce_field( 'aoe_seo_settings' ); ?>

			<div class="aoe-form-group">
				<label for="seo_title">Meta Title (Título para buscadores)</label>
				<input type="text" name="seo_title_template" id="seo_title" value="<?php echo esc_attr( $title_template ); ?>" style="width:100%;" />
			</div>

			<div class="aoe-form-group">
				<label for="seo_desc">Meta Description (Descripción para buscadores)</label>
				<textarea name="seo_description_template" id="seo_desc" rows="3" style="width:100%;"><?php echo esc_textarea( $desc_template ); ?></textarea>
			</div>

			<div class="aoe-btn-row">
				<input type="submit" name="save_aoe_seo" class="button button-primary" value="Guardar ajustes SEO" />
			</div>
		</form>
	</div>

	<div class="aoe-card" style="margin-top:20px;">
		<h2>Placeholders disponibles</h2>
		<table class="wp-list-table widefat fixed striped">
			<thead><tr><th>Placeholder</th><th>Descripción</th></tr></thead>
			<tbody>
				<tr><td><code>{manufacturer}</code></td><td>Nombre del fabricante (ej. Edac)</td></tr>
				<tr><td><code>{category}</code></td><td>Nombre de la categoría/serie (ej. 271 Series)</td></tr>
				<tr><td><em>automático</em></td><td><code> - Página 2</code> se añade al final del title cuando hay paginación</td></tr>
			</tbody>
		</table>
	</div>
</div>
