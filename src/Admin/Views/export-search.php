<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap aoe-wrap">
	<div class="aoe-header">
		<h1>Exportar datos</h1>
	</div>

	<div class="aoe-card">
		<p class="description">Gestionar y exportar datos de la tabla <code>aoe_catalog_search_products</code>.</p>

		<div class="aoe-export-actions-row">
			<div class="aoe-export-bulk">
				<span class="description">Exportar todo:</span>
				<a href="<?php echo esc_url( admin_url( 'admin-post.php?action=aoe_export_search&format=sql' ) ); ?>" class="button button-small">SQL</a>
				<a href="<?php echo esc_url( admin_url( 'admin-post.php?action=aoe_export_search&format=csv' ) ); ?>" class="button button-small">CSV</a>
				<span class="description">(<?php echo number_format( $total ); ?> productos)</span>
			</div>
			<div class="aoe-export-search-box">
				<input type="text" id="aoe-export-filter" placeholder="Buscar fabricante..." class="regular-text" />
			</div>
		</div>

		<table class="wp-list-table widefat fixed striped aoe-export-table" id="aoe-export-table">
			<thead>
				<tr>
					<th class="column-name">Nombre</th>
					<th class="column-count">Cantidad Productos</th>
					<th class="column-reindex">Actualizar</th>
					<th class="column-export">Exportar</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $manufacturers ) ) : ?>
					<tr>
						<td colspan="4">No hay datos en la tabla de búsqueda.</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $manufacturers as $m ) :
						$slug = strtolower( $m->manufacturer_normalized );
						$has_data = $m->cnt > 0;
						$formatted_cnt = number_format( $m->cnt );
						$sql_url = admin_url( 'admin-post.php?action=aoe_export_search&manufacturer=' . urlencode( $m->manufacturer_normalized ) . '&format=sql' );
						$csv_url = admin_url( 'admin-post.php?action=aoe_export_search&manufacturer=' . urlencode( $m->manufacturer_normalized ) . '&format=csv' );
					?>
						<tr data-slug="<?php echo esc_attr( $slug ); ?>" data-name="<?php echo esc_attr( strtolower( $m->manufacturer_name ) ); ?>">
							<td class="column-name"><strong><?php echo esc_html( $m->manufacturer_name ); ?></strong></td>
							<td class="column-count"><?php echo $formatted_cnt; ?></td>
							<td class="column-reindex">
								<?php if ( $has_data ) : ?>
									<button type="button" class="aoe-btn-reindex-sm" data-slug="<?php echo esc_attr( $slug ); ?>" data-action="update" title="Actualizar datos de <?php echo esc_attr( $m->manufacturer_name ); ?>">
										<span class="dashicons dashicons-update"></span>
									</button>
								<?php else : ?>
									<button type="button" class="aoe-btn-reindex-sm aoe-btn-generate" data-slug="<?php echo esc_attr( $slug ); ?>" data-action="generate" title="Generar datos de <?php echo esc_attr( $m->manufacturer_name ); ?>">
										<span class="dashicons dashicons-database-add"></span>
									</button>
								<?php endif; ?>
							</td>
							<td class="column-export">
								<?php if ( $has_data ) : ?>
									<a href="<?php echo esc_url( $sql_url ); ?>" class="button button-small">SQL</a>
									<a href="<?php echo esc_url( $csv_url ); ?>" class="button button-small">CSV</a>
								<?php else : ?>
									<span class="aoe-export-na">—</span>
								<?php endif; ?>
							</td>
						</tr>
						<tr class="aoe-progress-row" data-slug="<?php echo esc_attr( $slug ); ?>" style="display:none;">
							<td colspan="4">
								<div class="aoe-progress-inline">
									<div class="aoe-progress-info">
										<span class="aoe-progress-text">Preparando...</span>
										<span class="aoe-progress-pct"></span>
									</div>
									<div class="aoe-progress-bar-container">
										<div class="aoe-progress-bar" style="width:0%;"></div>
									</div>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
</div>

<style>
/* Table styling */
.aoe-export-table { border-collapse: separate; border-spacing: 0; }
.aoe-export-table thead th { background: #f9f9f9; border-bottom: 2px solid #e0e0e0; font-weight: 600; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; color: #666; padding: 12px 10px; text-align: left; }
.aoe-export-table thead th.column-count,
.aoe-export-table thead th.column-reindex,
.aoe-export-table thead th.column-export { text-align: center; }
.aoe-export-table tbody tr { transition: background 0.15s ease; }
.aoe-export-table tbody tr:hover { background: #f0f6fc !important; }
.aoe-export-table tbody td { padding: 10px; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }

/* Column widths */
.column-name { width: 35%; }
.column-count { width: 15%; text-align: right !important; font-variant-numeric: tabular-nums; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, monospace; font-size: 13px; color: #555; }
.column-reindex { width: 10%; text-align: center; }
.column-export { width: 15%; text-align: center; }
.column-export .button { margin: 0 3px; display: inline-block; vertical-align: middle; }
.aoe-export-na { color: #999; font-size: 12px; }

/* Actions row */
.aoe-export-actions-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
.aoe-export-bulk { display: flex; align-items: center; gap: 8px; }
.aoe-export-search-box input { width: 250px; border-radius: 4px; }

/* Reindex button */
.aoe-btn-reindex-sm { background: none; border: 1px solid #ddd; border-radius: 4px; cursor: pointer; padding: 4px 6px; line-height: 1; transition: all 0.15s ease; }
.aoe-btn-reindex-sm .dashicons { font-size: 16px; width: 16px; height: 16px; color: #0073aa; }
.aoe-btn-reindex-sm:hover { border-color: #0073aa; background: #f0f6fc; }
.aoe-btn-reindex-sm.aoe-btn-generate .dashicons { color: #00a32a; }
.aoe-btn-reindex-sm.aoe-btn-generate:hover { border-color: #00a32a; background: #f0faf3; }
.aoe-btn-reindex-sm:disabled { opacity: 0.5; cursor: not-allowed; }
.aoe-btn-reindex-sm.spinning .dashicons { animation: aoe-spin 1s linear infinite; }
@keyframes aoe-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

/* Inline progress */
.aoe-progress-row td { padding: 0 10px 10px !important; background: #fafafa; }
.aoe-progress-inline { padding: 8px 12px; background: #fff; border: 1px solid #e0e0e0; border-radius: 4px; }
.aoe-progress-info { display: flex; justify-content: space-between; margin-bottom: 6px; font-size: 12px; color: #666; }
.aoe-progress-text { font-weight: 500; }
.aoe-progress-pct { font-variant-numeric: tabular-nums; color: #0073aa; font-weight: 600; }
.aoe-progress-bar-container { background: #e0e0e0; border-radius: 3px; height: 6px; overflow: hidden; }
.aoe-progress-bar { background: linear-gradient(90deg, #0073aa, #00a32a); height: 100%; border-radius: 3px; transition: width 0.3s ease; }
.aoe-progress-done { color: #00a32a; font-weight: 600; }
.aoe-progress-error { color: #d63638; font-weight: 600; }
</style>

<script>
var adminurl = <?php echo wp_json_encode( admin_url( 'admin-post.php' ) ); ?>;
jQuery(function($) {
	// Search/filter
	$('#aoe-export-filter').on('keyup', function() {
		var term = $(this).val().toLowerCase();
		$('#aoe-export-table tbody tr[data-slug]').not('.aoe-progress-row').each(function() {
			var name = $(this).data('name') || '';
			var slug = $(this).data('slug') || '';
			var match = name.indexOf(term) !== -1 || slug.indexOf(term) !== -1;
			$(this).toggle(match);
			// Only hide progress rows when the manufacturer doesn't match;
			// never force-show them (only the "Actualizar" click shows them).
			if (!match) {
				$(this).next('.aoe-progress-row[data-slug="' + slug + '"]').hide();
			}
		});
	});

	// Start reindex
	$(document).on('click', '.aoe-btn-reindex-sm', function() {
		var btn = $(this);
		var slug = btn.data('slug');
		var action = btn.data('action');
		var row = btn.closest('tr');
		var progressRow = row.next('.aoe-progress-row');
		var progressText = progressRow.find('.aoe-progress-text');
		var progressPct = progressRow.find('.aoe-progress-pct');
		var progressBar = progressRow.find('.aoe-progress-bar');

		// Show progress row
		progressRow.show();
		progressText.text('Iniciando indexación...').removeClass('aoe-progress-done aoe-progress-error');
		progressPct.text('');
		progressBar.css('width', '0%');
		btn.prop('disabled', true).addClass('spinning');

		$.post(ajaxurl, {
			action: 'aoe_reindex_manufacturer',
			manufacturer: slug
		}, function(response) {
			if (response.success) {
				var progressKey = response.data.progress_key;
				progressText.text('Indexando productos...');
				pollProgress(slug, progressKey, btn, progressRow);
			} else {
				progressText.text('Error: ' + (response.data || 'No se pudo iniciar')).addClass('aoe-progress-error');
				btn.prop('disabled', false).removeClass('spinning');
			}
		});
	});

	// Sequential polling: each call advances one batch server-side.
	// setTimeout chain (not setInterval) so polls never overlap.
	function pollProgress(slug, progressKey, btn, progressRow) {
		var progressText = progressRow.find('.aoe-progress-text');
		var progressPct = progressRow.find('.aoe-progress-pct');
		var progressBar = progressRow.find('.aoe-progress-bar');

		function poll() {
			$.post(ajaxurl, {
				action: 'aoe_check_index_progress',
				progress_key: progressKey
			}).done(function(response) {
				if (!response.success) {
					progressText.text('Error: ' + (response.data || 'Respuesta inválida')).addClass('aoe-progress-error');
					btn.prop('disabled', false).removeClass('spinning');
					return;
				}
				var data = response.data;
				if (data.status === 'running') {
					var pct = data.total > 0 ? Math.round((data.count / data.total) * 100) : 0;
					progressBar.css('width', pct + '%');
					progressPct.text(pct + '%');
					progressText.text('Indexando: ' + data.count.toLocaleString() + ' / ' + data.total.toLocaleString());
					if (data.errors > 0) {
						progressText.append(' ⚠ ' + data.errors + ' errores');
					}
					setTimeout(poll, 800);
				} else if (data.status === 'starting') {
					progressText.text('Preparando...');
					setTimeout(poll, 800);
				} else if (data.status === 'completed') {
					progressBar.css('width', '100%');
					progressPct.text('100%');
					progressText.text('Completado ✓').addClass('aoe-progress-done');
					btn.prop('disabled', false).removeClass('spinning');
					refreshRow(slug, progressRow);
				} else {
					progressText.text('Estado: ' + (data.status || 'desconocido'));
					btn.prop('disabled', false).removeClass('spinning');
				}
			}).fail(function() {
				setTimeout(poll, 2000);
			});
		}
		poll();
	}

	// Refresh a single table row in place (count + SQL/CSV links) after a job finishes,
	// so we don't need to reload the whole page.
	function refreshRow(slug, progressRow) {
		var row = progressRow.prev('tr');
		$.post(ajaxurl, {
			action: 'aoe_get_row_count',
			manufacturer: slug
		}).done(function(response) {
			if (!response.success) return;
			var cnt = response.data.count;
			var name = row.find('.column-name strong').text();
			var norm = slug.toUpperCase();
			var hasData = cnt > 0;

			row.find('.column-count').text(numberFormat(cnt));

			var reindexCell = row.find('.column-reindex');
			reindexCell.empty();
			if (hasData) {
				reindexCell.append('<button type="button" class="aoe-btn-reindex-sm" data-action="update" title="Actualizar ' + name + '"><span class="dashicons dashicons-update"></span></button>');
			} else {
				reindexCell.append('<button type="button" class="aoe-btn-reindex-sm aoe-btn-generate" data-action="generate" title="Generar ' + name + '"><span class="dashicons dashicons-database-add"></span></button>');
			}
			reindexCell.find('.aoe-btn-reindex-sm').attr('data-slug', slug);

			var exportCell = row.find('.column-export');
			exportCell.empty();
			if (hasData) {
				exportCell.append(
					'<a class="button button-small" href="' + adminurl + '?action=aoe_export_search&manufacturer=' + encodeURIComponent(norm) + '&format=sql">SQL</a>' +
					'<a class="button button-small" href="' + adminurl + '?action=aoe_export_search&manufacturer=' + encodeURIComponent(norm) + '&format=csv">CSV</a>'
				);
			} else {
				exportCell.append('<span class="aoe-export-na">—</span>');
			}

			// Keep "Completado ✓" visible briefly, then hide the progress row.
			setTimeout(function() { progressRow.hide(); }, 2500);
		});
	}

	function numberFormat(n) {
		return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
	}
});</script>
