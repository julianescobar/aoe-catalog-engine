<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;
$table = $wpdb->prefix . 'aoe_catalog_manufacturers';
$manufacturers = $wpdb->get_results( "SELECT * FROM $table ORDER BY name ASC" );

$root_template_id = get_option( 'aoe_catalog_root_template_post_id', 0 );
$catalogs = get_posts( [
	'post_type'      => 'catalogo_online',
	'posts_per_page' => -1,
	'post_status'    => [ 'publish', 'draft' ],
] );
?>
<div class="wrap aoe-wrap">
	<div class="aoe-header">
		<h1>Catálogo - Páginas Generadas</h1>
	</div>

	<div class="aoe-card">
		<h2>Plantilla para la página raíz <a href="<?php echo esc_url( home_url( '/catalogo/' ) ); ?>" target="_blank"><code>/catalogo/</code></a></h2>
		<p class="description">Selecciona un catálogo de Avada para usar como plantilla. El shortcode <code>[catalogo]</code> será reemplazado por el listado de fabricantes.</p>

		<form method="post" action="" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
			<?php wp_nonce_field( 'aoe_root_template' ); ?>
			<select name="root_template_post_id">
				<option value="0">Sin plantilla (usa la vista por defecto)</option>
				<?php foreach ( $catalogs as $c ) : ?>
					<option value="<?php echo esc_attr( $c->ID ); ?>" <?php selected( $root_template_id, $c->ID ); ?>>
						<?php echo esc_html( $c->post_title ); ?> (ID: <?php echo esc_attr( $c->ID ); ?>)
					</option>
				<?php endforeach; ?>
			</select>
			<input type="submit" name="save_aoe_root_template" class="button button-primary" value="Guardar" />
			<button type="button" class="button" id="aoe-regen-root-cache" onclick="aoeRegenRoot()">Regenerar caché</button>
		</form>
	</div>

	<div class="aoe-card">
		<h2>Regenerar todo el caché</h2>
		<p class="description">Limpia todos los template caches (root + fabricantes) y page caches. Luego regenera todo secuencialmente.</p>
		<button type="button" class="button button-primary" id="aoe-regen-all" onclick="aoeRegenAll()">Regenerar todo</button>
		<textarea id="aoe-regen-log" rows="12" style="width:100%;margin-top:10px;font-family:monospace;font-size:12px;" readonly></textarea>
	</div>

	<div class="aoe-card">
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
<script>
var logEl = document.getElementById('aoe-regen-log');
function aoeLog(msg) { logEl.value += msg + '\n'; logEl.scrollTop = logEl.scrollHeight; }
function aoeRegenRoot() {
	var btn = document.getElementById('aoe-regen-root-cache');
	btn.disabled = true; btn.textContent = 'Generando...';
	jQuery.post(ajaxurl, { action: 'aoe_generate_template_cache', slug: 'root' }, function(r) {
		if (r.success && r.data.url) { window.location.href = r.data.url; }
		else { alert('Error: ' + (r.data||'desconocido')); btn.disabled = false; btn.textContent = 'Regenerar caché'; }
	});
}
function aoeRegenAll() {
	var btn = document.getElementById('aoe-regen-all');
	btn.disabled = true; btn.textContent = 'Limpiando...';
	logEl.value = ''; aoeLog('=== Limpiando caché ===');
	jQuery.post(ajaxurl, { action: 'aoe_clear_all_cache' }, function(r) {
		if (r.success && r.data.logs) {
			r.data.logs.forEach(function(l) { aoeLog(l); });
			aoeLog('=== Regenerando plantillas ===');
			setTimeout(function() { window.location.href = '<?php echo esc_url( home_url( '/__gen-template/all/' ) ); ?>'; }, 500);
		} else {
			aoeLog('Error: ' + (r.data||'desconocido'));
			btn.disabled = false; btn.textContent = 'Regenerar todo';
		}
	});
}
</script>