<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap aoe-wrap">
	<div class="aoe-header">
		<h1>Gestionar Categorías</h1>
	</div>

	<div class="aoe-card">
		<p class="description">Selecciona un fabricante para ver y editar sus categorías. Arrastra para reordenar, haz click en el nombre para editar, usa el selector de visibilidad para ocultar/mostrar.</p>

		<h2>Fabricante</h2>
		<select id="aoe-cat-manufacturer" class="regular-text">
			<option value="">— Seleccionar —</option>
			<?php foreach ( $manufacturers as $m ) : ?>
				<option value="<?php echo esc_attr( $m->id ); ?>" <?php selected( $selectedManufacturer, $m->id ); ?>>
					<?php echo esc_html( $m->name ); ?> (<?php echo esc_html( $m->slug ); ?>)
				</option>
			<?php endforeach; ?>
		</select>
	</div>

	<div id="aoe-cat-loading" style="display:none;"><p>Cargando categorías...</p></div>

	<div id="aoe-cat-container" class="aoe-card" style="display:none;">
		<div id="aoe-cat-actions" style="margin-bottom:15px;display:flex;align-items:center;flex-wrap:wrap;gap:10px;">
			<button type="button" class="button" id="aoe-cat-expand-all">Expandir todo</button>
			<button type="button" class="button" id="aoe-cat-collapse-all">Colapsar todo</button>
			<button type="button" class="button button-primary" id="aoe-cat-save" disabled>Guardar cambios</button>
			<span id="aoe-cat-save-status" style="font-weight:600;"></span>
			<span class="description" style="display:none;">
				<span id="aoe-cat-count">0</span> categorías |
				<span id="aoe-cat-visible">0</span> visibles |
				<span id="aoe-cat-hidden">0</span> ocultas
			</span>
			<span id="aoe-cat-pending" style="display:none;color:#d63638;font-weight:600;">(<span id="aoe-cat-pending-num">0</span> cambios pendientes)</span>
		</div>

	<table class="wp-list-table widefat fixed striped" id="aoe-cat-table">
		<thead>
			<tr>
				<th style="width:40px;">#</th>
				<th>Nombre</th>
				<th style="width:80px;">Nivel</th>
				<th style="width:120px;">Visibilidad</th>
			</tr>
		</thead>
		<tbody id="aoe-cat-list">
			<tr><td colspan="4">Selecciona un fabricante para ver las categorías.</td></tr>
		</tbody>
	</table>
	</div>
</div>

<script>
jQuery(function($) {
	var manufacturerSelect = $('#aoe-cat-manufacturer');
	var catContainer = $('#aoe-cat-container');
	var catLoading = $('#aoe-cat-loading');
	var catList = $('#aoe-cat-list');
	var catCount = $('#aoe-cat-count');
	var catVisible = $('#aoe-cat-visible');
	var catHidden = $('#aoe-cat-hidden');
	var saveBtn = $('#aoe-cat-save');
	var saveStatus = $('#aoe-cat-save-status');
	var pendingWrap = $('#aoe-cat-pending');
	var pendingNum = $('#aoe-cat-pending-num');

	// Local pending changes (not yet sent to server)
	var pending = { names: {}, hidden: {}, order: null };

	// In-memory category model (from the server) + pristine flat order.
	var catsModel = [];
	var originalOrder = [];

	function markDirty() {
		var count = Object.keys(pending.names).length
			+ Object.keys(pending.hidden).length
			+ (pending.order ? 1 : 0);
		if (count > 0) {
			saveBtn.prop('disabled', false).text('Guardar cambios');
			pendingNum.text(count);
			pendingWrap.show();
		} else {
			saveBtn.prop('disabled', true).text('Guardar cambios');
			pendingWrap.hide();
		}
	}

	saveBtn.on('click', function() {
		var btn = $(this);
		var slug = manufacturerSelect.find('option:selected').text().match(/\(([^)]+)\)/)[1];
		btn.prop('disabled', true).text('Guardando...');
		saveStatus.text('').css('color', '').show();

		$.post(ajaxurl, {
			action: 'aoe_save_categories',
			manufacturer_id: manufacturerSelect.val(),
			changes: {
				names: pending.names,
				hidden: pending.hidden,
				order: pending.order
			}
		}, function(response) {
			if (response.success) {
				pending = { names: {}, hidden: {}, order: null };
				originalOrder = currentDomIds();
				$('#aoe-cat-table tbody tr').removeClass('aoe-cat-dirty');
				markDirty();
				updateCounts();

				saveStatus.html('<span style="color:#00a32a;">Reindexando...</span>');
				$.post(ajaxurl, { action: 'aoe_clear_cache', slug: slug })
					.then(function() {
						return $.post(ajaxurl, { action: 'aoe_reindex_manufacturer', manufacturer: slug });
					})
					.always(function() {
						saveStatus.html('<span style="color:#00a32a;">Guardado ✓</span>');
						setTimeout(function() { saveStatus.fadeOut(300); }, 2000);
					});
			} else {
				saveStatus.html('<span style="color:#d63638;">' + (response.data.message || 'Error al guardar') + '</span>').show();
				btn.prop('disabled', false).text('Guardar cambios');
				setTimeout(function() { saveStatus.fadeOut(300); }, 4000);
			}
		}).fail(function() {
			saveStatus.html('<span style="color:#d63638;">Error de red</span>').show();
			btn.prop('disabled', false).text('Guardar cambios');
			setTimeout(function() { saveStatus.fadeOut(300); }, 4000);
		});
	});

	manufacturerSelect.on('change', function() {
		var mfrId = $(this).val();
		if (!mfrId) {
			catContainer.hide();
			return;
		}
		// Reset pending when switching manufacturer
		pending = { names: {}, hidden: {}, order: null };
		markDirty();
		saveStatus.text('');
		loadCategories(mfrId);
	});

	if (manufacturerSelect.val()) {
		loadCategories(manufacturerSelect.val());
	}

	function loadCategories(mfrId) {
		catContainer.hide();
		catLoading.show();
		catList.empty();

		$.post(ajaxurl, {
			action: 'aoe_get_categories',
			manufacturer_id: mfrId
		}, function(response) {
			catLoading.hide();
			if (response.success) {
				var cats = response.data.categories || response.data;
				if (!cats || !cats.length) {
					catList.html('<tr><td colspan="4">Sin categorías para este fabricante.</td></tr>');
					catContainer.show();
					return;
				}
				renderCategories(cats);
				catContainer.show();
			} else {
				catList.html('<tr><td colspan="4">Error: ' + (response.data || 'Unknown') + '</td></tr>');
				catContainer.show();
			}
		}).fail(function(xhr, status, error) {
			catLoading.hide();
			catList.html('<tr><td colspan="4">AJAX error: ' + status + ' — ' + error + '<br>URL: ' + ajaxurl + '<br>Response: ' + (xhr.responseText || '').substring(0, 300) + '</td></tr>');
			catContainer.show();
		});
	}

	function buildByParent(cats) {
		var byParent = {};
		$.each(cats, function(i, cat) {
			var pid = cat.parent_id || 0;
			if (!byParent[pid]) byParent[pid] = [];
			byParent[pid].push(cat);
		});
		return byParent;
	}

	function currentDomIds() {
		return catList.find('tr[data-depth]').map(function() {
			return $(this).data('id');
		}).get();
	}

	function renderRows(byParent) {
		catList.empty();
		var num = 0;

		function renderLevel(parentId, depth) {
			var children = byParent[parentId] || [];
			$.each(children, function(i, cat) {
				num++;
				var indent = depth * 25;
				var rowClass = cat.is_hidden == 1 ? 'aoe-cat-hidden' : '';
				var nameStyle = cat.is_hidden == 1 ? 'opacity:0.5;' : '';
				var selectedVisible = cat.is_hidden == 1 ? '' : ' selected';
				var hasChildren = byParent[cat.id] && byParent[cat.id].length > 0;
				var toggleIcon = hasChildren ? 'dashicons-arrow-down-alt2' : 'dashicons-minus';

				var row = $('<tr class="' + rowClass + '" data-id="' + cat.id + '" data-parent-id="' + (cat.parent_id || 0) + '" data-depth="' + depth + '" data-original-name="' + escapeHtml(cat.name) + '" data-original-hidden="' + cat.is_hidden + '">' +
					'<td>' + num + '</td>' +
					'<td style="padding-left:' + (indent + 10) + 'px;"><span class="aoe-cat-toggle dashicons ' + toggleIcon + '" data-parent="' + cat.id + '" data-depth="' + depth + '" style="cursor:pointer;margin-right:8px;"></span><span class="aoe-cat-name" style="' + nameStyle + '" data-id="' + cat.id + '">' + escapeHtml(cat.name) + '</span><span class="aoe-cat-edit dashicons dashicons-edit" data-id="' + cat.id + '" style="cursor:pointer;margin-left:4px;opacity:0.5;" title="Editar nombre"></span></td>' +
					'<td>' + cat.level + '</td>' +
					'<td><select class="aoe-cat-visible-select" data-id="' + cat.id + '">' +
						'<option value="1"' + selectedVisible + '>Visible</option>' +
						'<option value="0"' + (cat.is_hidden == 1 ? ' selected' : '') + '>Oculto</option>' +
					'</select></td>' +
					'</tr>');

				catList.append(row);
				renderLevel(cat.id, depth + 1);
			});
		}

		renderLevel(0, 0);
	}

	function renderCategories(cats) {
		catsModel = cats;
		pending = { names: {}, hidden: {}, order: null };

		renderRows(buildByParent(cats));
		originalOrder = currentDomIds();

		initSortable();
		initInlineEdit();
		initToggleHidden();
		initCollapse();
		updateCounts();

		// Collapse all by default (show only root level)
		catList.find('tr[data-depth]').hide();
		catList.find('tr[data-depth="0"]').show();
		catList.find('.aoe-cat-toggle').removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-right-alt2');
	}

	function escapeHtml(text) {
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(text));
		return div.innerHTML;
	}

	function updateCounts() {
		var total = catList.find('tr[data-depth]').length;
		var vis = catList.find('tr[data-depth]').not('.aoe-cat-hidden').length;
		var hid = total - vis;
		catCount.text(total);
		catVisible.text(vis);
		catHidden.text(hid);
	}

	function initSortable() {
		if (catList.data('ui-sortable')) {
			catList.sortable('destroy');
		}
		catList.sortable({
			items: 'tr[data-depth]',
			cancel: '.aoe-cat-toggle, .aoe-cat-edit, .aoe-cat-visible-select, input, textarea, button, select, option',
			helper: function(e, ui) {
				ui.children().each(function() {
					$(this).width($(this).width());
				});
				return ui;
			},
			stop: rebuildAfterDrop
		});
	}

	// After every drag: extract each parent's sibling order from the flat DOM
	// sequence, then re-render the whole table from the in-memory model.
	// Categories left inside another group's subtree simply render back in
	// their original spot, and the fresh DOM + refresh() always leaves jQuery
	// UI in a clean state (no dead drags).
	function rebuildAfterDrop() {
		var seq = currentDomIds();
		if (!seq.length || !catsModel.length) return;

		var pidOf = {}, catOf = {};
		$.each(catsModel, function(i, c) {
			pidOf[c.id] = c.parent_id || 0;
			catOf[c.id] = c;
		});

		var byParent = {};
		$.each(seq, function(i, id) {
			var p = pidOf[id] || 0;
			if (!byParent[p]) byParent[p] = [];
			byParent[p].push(catOf[id]);
		});

		// Capture UI state (collapse, pending edits) before re-rendering.
		var state = {};
		catList.find('tr[data-depth]').each(function() {
			var row = $(this);
			var nameEl = row.find('.aoe-cat-name');
			state[row.data('id')] = {
				display: row.css('display'),
				cls: row.attr('class') || '',
				icon: row.find('.aoe-cat-toggle').attr('class') || '',
				name: nameEl.text(),
				nameStyle: nameEl.attr('style') || '',
				vis: row.find('.aoe-cat-visible-select').val()
			};
		});

		renderRows(byParent);

		// Restore UI state on the fresh rows.
		catList.find('tr[data-depth]').each(function() {
			var row = $(this);
			var s = state[row.data('id')];
			if (!s) return;
			row.attr('class', s.cls);
			if (s.display === 'none') row.hide(); else row.show();
			row.find('.aoe-cat-toggle').attr('class', s.icon);
			var nameEl = row.find('.aoe-cat-name');
			nameEl.text(s.name);
			nameEl.attr('style', s.nameStyle);
			row.find('.aoe-cat-visible-select').val(s.vis);
		});

		catList.sortable('refresh');

		// Only mark dirty when the flat order differs from the original.
		var newSeq = currentDomIds();
		if (JSON.stringify(newSeq) !== JSON.stringify(originalOrder)) {
			pending.order = newSeq;
		} else {
			pending.order = null;
		}
		markDirty();
	}

	function initInlineEdit() {
		// Click pencil icon → toggle edit mode (name ↔ input).
		catList.off('click', '.aoe-cat-edit').on('click', '.aoe-cat-edit', function(e) {
			e.stopPropagation();
			var pencil = $(this);
			var id = pencil.data('id');
			var row = pencil.closest('tr');
			var nameSpan = row.find('.aoe-cat-name');
			var input = row.find('input.aoe-cat-name-input');

			// If already editing → commit.
			if (input.length) {
				commitInput(input, id, nameSpan);
				return;
			}

			// Enter edit mode.
			var currentName = nameSpan.text();
			input = $('<input type="text" class="aoe-cat-name-input regular-text" value="' + escapeHtml(currentName) + '" style="width:90%;" />');
			nameSpan.replaceWith(input);
			pencil.addClass('dashicons-yes').removeClass('dashicons-edit').attr('title', 'Guardar nombre');
			catList.sortable('disable');
			input.focus().select();

			input.on('blur', function() {
				commitInput(input, id, nameSpan);
			});
			input.on('keydown', function(e) {
				if (e.keyCode === 13) { e.preventDefault(); input.blur(); }
				if (e.keyCode === 27) {
					input.val(currentName);
					input.blur();
				}
			});
		});

		function sanitizeName(str) {
			return str.replace(/[^a-zA-Z0-9\s\-\/()&.,#+]/g, '').replace(/\s+/g, ' ').trim();
		}

		function commitInput(input, id, originalSpan) {
			if (!input.length) return;
			var newName = sanitizeName(input.val());
			var row = input.closest('tr');
			var pencil = row.find('.aoe-cat-edit');
			var currentName = originalSpan.text();
			var nameStyle = originalSpan.attr('style') || '';
			var dataId = originalSpan.data('id') || id;

			if (!newName) newName = currentName;

			if (newName !== currentName) {
				pending.names[id] = newName;
				row.addClass('aoe-cat-dirty');
			} else {
				delete pending.names[id];
			}

			input.replaceWith('<span class="aoe-cat-name" style="' + nameStyle + '" data-id="' + dataId + '">' + escapeHtml(newName) + '</span>');
			pencil.removeClass('dashicons-yes').addClass('dashicons-edit').attr('title', 'Editar nombre');
			catList.sortable('enable');
			markDirty();
		}
	}

	function initToggleHidden() {
		catList.off('change', '.aoe-cat-visible-select').on('change', '.aoe-cat-visible-select', function() {
			var select = $(this);
			var id = select.data('id');
			var newHidden = select.val() == '1' ? 0 : 1;
			pending.hidden[id] = newHidden;
			// Update visual state immediately (local only)
			var row = select.closest('tr');
			row.toggleClass('aoe-cat-hidden', newHidden == 1);
			row.find('.aoe-cat-name').css('opacity', newHidden == 1 ? 0.5 : 1);
			row.addClass('aoe-cat-dirty');
			markDirty();
		});
	}

	function initCollapse() {
		catList.off('click', '.aoe-cat-toggle').on('click', '.aoe-cat-toggle', function() {
			var icon = $(this);
			var parentDepth = parseInt(icon.data('depth'));
			icon.toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-right-alt2');
			var isCollapsed = icon.hasClass('dashicons-arrow-right-alt2');
			var row = icon.closest('tr');
			while (row.next().length) {
				row = row.next();
				var rowDepth = parseInt(row.data('depth'));
				if (isNaN(rowDepth) || rowDepth <= parentDepth) break;
				if (isCollapsed) {
					row.hide();
					row.find('.aoe-cat-toggle').removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-right-alt2');
				} else {
					row.show();
				}
			}
		});

		$('#aoe-cat-expand-all').on('click', function() {
			catList.find('tr').show();
			catList.find('.aoe-cat-toggle').removeClass('dashicons-arrow-right-alt2').addClass('dashicons-arrow-down-alt2');
		});

		$('#aoe-cat-collapse-all').on('click', function() {
			catList.find('tr[data-depth]').hide();
			catList.find('.aoe-cat-toggle').removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-right-alt2');
			catList.find('tr[data-depth="0"]').show();
		});
	}
});
</script>

<style>
.aoe-cat-hidden { opacity: 0.6; }
.aoe-cat-name { cursor: pointer; }
.aoe-cat-name:hover { color: #0073aa; }
.aoe-cat-visible-select { cursor: pointer; }
#aoe-cat-list tr[data-depth] { cursor: move; }
#aoe-cat-list .aoe-cat-toggle, #aoe-cat-list .aoe-cat-name, #aoe-cat-list .aoe-cat-visible-select { cursor: pointer; }
.aoe-cat-dirty { background: #fff8e5 !important; }
.ui-sortable-helper { background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.2); }
</style>
