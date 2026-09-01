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
		<p class="description">Selecciona un fabricante para ver y editar sus categorías. Arrastra para reordenar, haz click en el nombre para editar, usa el icono del ojo para ocultar/mostrar.</p>

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
		<div id="aoe-cat-actions" style="margin-bottom:15px;">
			<button type="button" class="button" id="aoe-cat-expand-all">Expandir todo</button>
			<button type="button" class="button" id="aoe-cat-collapse-all">Colapsar todo</button>
			<span class="description" style="margin-left:15px;">
				<span id="aoe-cat-count">0</span> categorías |
				<span id="aoe-cat-visible">0</span> visibles |
				<span id="aoe-cat-hidden">0</span> ocultas
			</span>
		</div>

		<table class="wp-list-table widefat fixed striped" id="aoe-cat-table">
			<thead>
				<tr>
					<th style="width:40px;">#</th>
					<th style="width:40px;"></th>
					<th>Nombre</th>
					<th style="width:80px;">Nivel</th>
					<th style="width:120px;">Visible</th>
				</tr>
			</thead>
			<tbody id="aoe-cat-list">
				<tr><td colspan="5">Selecciona un fabricante para ver las categorías.</td></tr>
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

	manufacturerSelect.on('change', function() {
		var mfrId = $(this).val();
		if (!mfrId) {
			catContainer.hide();
			return;
		}
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
				renderCategories(response.data);
				catContainer.show();
			} else {
				catList.html('<tr><td colspan="6">Error al cargar categorías.</td></tr>');
				catContainer.show();
			}
		});
	}

	function renderCategories(cats) {
		catList.empty();
		var visible = 0, hidden = 0;

		// Build tree
		var byParent = {};
		$.each(cats, function(i, cat) {
			var pid = cat.parent_id || 0;
			if (!byParent[pid]) byParent[pid] = [];
			byParent[pid].push(cat);
		});

		function renderLevel(parentId, depth) {
			var children = byParent[parentId] || [];
			$.each(children, function(i, cat) {
				var indent = depth * 25;
				var rowClass = cat.is_hidden == 1 ? 'aoe-cat-hidden' : '';
				var nameStyle = cat.is_hidden == 1 ? 'opacity:0.5;' : '';
				var selectedVisible = cat.is_hidden == 1 ? '' : ' selected';
				var hasChildren = byParent[cat.id] && byParent[cat.id].length > 0;
				var toggleIcon = hasChildren ? 'dashicons-arrow-down-alt2' : 'dashicons-minus';

				if (cat.is_hidden == 1) hidden++; else visible++;

				var row = $('<tr class="' + rowClass + '" data-id="' + cat.id + '" data-depth="' + depth + '">' +
					'<td><span class="aoe-cat-handle dashicons dashicons-move"></span></td>' +
					'<td style="padding-left:' + (indent + 10) + 'px;"><span class="aoe-cat-toggle dashicons ' + toggleIcon + '" data-parent="' + cat.id + '" data-depth="' + depth + '" style="cursor:pointer;"></span></td>' +
					'<td><span class="aoe-cat-name" style="' + nameStyle + '" data-id="' + cat.id + '">' + escapeHtml(cat.name) + '</span></td>' +
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

		catCount.text(cats.length);
		catVisible.text(visible);
		catHidden.text(hidden);

		initSortable();
		initInlineEdit();
		initToggleHidden();
		initCollapse();

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

	function initSortable() {
		catList.sortable({
			items: 'tr[data-depth]',
			helper: function(e, ui) {
				ui.children().each(function() {
					$(this).width($(this).width());
				});
				return ui;
			},
			update: function() {
				var order = [];
				catList.find('tr[data-depth]').each(function() {
					order.push($(this).data('id'));
				});
				$.post(ajaxurl, {
					action: 'aoe_reorder_categories',
					ordered_ids: order
				}, function(response) {
					if (response.success) {
						catList.find('tr[data-depth]').each(function(i) {
							$(this).find('td:first').text(i + 1);
						});
					}
				});
			}
		});
	}

	function initInlineEdit() {
		catList.on('dblclick', '.aoe-cat-name', function() {
			var span = $(this);
			var id = span.data('id');
			var currentName = span.text();
			var input = $('<input type="text" class="regular-text" value="' + escapeHtml(currentName) + '" />');
			span.replaceWith(input);
			input.focus().select();

			function save() {
				var newName = input.val().trim();
				if (newName && newName !== currentName) {
					$.post(ajaxurl, {
						action: 'aoe_update_category',
						id: id,
						name: newName
					}, function(response) {
						if (response.success) {
							input.replaceWith('<span class="aoe-cat-name" data-id="' + id + '">' + escapeHtml(newName) + '</span>');
						} else {
							input.replaceWith('<span class="aoe-cat-name" data-id="' + id + '">' + escapeHtml(currentName) + '</span>');
						}
					});
				} else {
					input.replaceWith('<span class="aoe-cat-name" data-id="' + id + '">' + escapeHtml(currentName) + '</span>');
				}
			}

			input.on('blur', save);
			input.on('keydown', function(e) {
				if (e.keyCode === 13) input.blur();
				if (e.keyCode === 27) {
					input.val(currentName);
					input.blur();
				}
			});
		});
	}

	function initToggleHidden() {
		catList.on('change', '.aoe-cat-visible-select', function() {
			var select = $(this);
			var id = select.data('id');
			var newHidden = select.val() == '1' ? 0 : 1;
			$.post(ajaxurl, {
				action: 'aoe_toggle_category_hidden',
				id: id,
				is_hidden: newHidden
			}, function(response) {
				if (response.success) {
					var row = select.closest('tr');
					var isHidden = response.data.is_hidden;
					row.toggleClass('aoe-cat-hidden', isHidden == 1);
					row.find('.aoe-cat-name').css('opacity', isHidden == 1 ? 0.5 : 1);
					// Update counts
					var visibleCount = catList.find('tr:not(.aoe-cat-hidden)').length;
					var hiddenCount = catList.find('tr.aoe-cat-hidden').length;
					catVisible.text(visibleCount);
					catHidden.text(hiddenCount);
				}
			});
		});
	}

	function initCollapse() {
		catList.on('click', '.aoe-cat-toggle', function() {
			var icon = $(this);
			var parentDepth = parseInt(icon.data('depth'));
			icon.toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-right-alt2');
			var isCollapsed = icon.hasClass('dashicons-arrow-right-alt2');
			// Hide/show all subsequent rows with greater depth until same or lesser depth
			var row = icon.closest('tr');
			while (row.next().length) {
				row = row.next();
				var rowDepth = parseInt(row.data('depth'));
				if (isNaN(rowDepth) || rowDepth <= parentDepth) break;
				if (isCollapsed) {
					row.hide();
					// Also collapse any open toggles in hidden rows
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
			// Show only root level
			catList.find('tr[data-depth="0"]').show();
		});
	}
});
</script>

<style>
.aoe-cat-handle { color: #999; }
.aoe-cat-handle:hover { color: #333; }
.aoe-cat-hidden { opacity: 0.6; }
.aoe-cat-name { cursor: pointer; }
.aoe-cat-name:hover { color: #0073aa; }
.aoe-cat-visible-select { cursor: pointer; }
#aoe-cat-list tr[data-depth] { cursor: move; }
.ui-sortable-helper { background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.2); }
</style>
