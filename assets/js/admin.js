jQuery(document).ready(function ($) {

	/**
	 * Auto-detect separator: tab, semicolon, or comma.
	 */
	function detectSeparator(content) {
		var firstLine = content.split('\n')[0] || '';
		if (firstLine.indexOf('\t') !== -1) return '\t';
		if (firstLine.indexOf(';') !== -1) return ';';
		return ',';
	}

	function getSeparatorLabel(sep) {
		return sep === '\t' ? 'Tabulación' : sep === ';' ? 'Punto y coma' : 'Coma';
	}

	function renderSeparatorSelector(detected) {
		window.aoeSeparator = detected;
		var $container = $('#aoe-separator-row');
		if (!$container.length) {
			$container = $('<div id="aoe-separator-row" style="margin-bottom:10px;"></div>');
			$('#aoe-detected-columns').prepend($container);
		}
		var html = '<label style="font-weight:600;">Separador: </label>';
		html += '<select id="aoe-sep-select" style="margin-left:6px;">';
		['\t', ';', ','].forEach(function (s) {
			var label = getSeparatorLabel(s);
			var selected = (s === detected) ? ' selected' : '';
			html += '<option value="' + s + '"' + selected + '>' + label + '</option>';
		});
		html += '</select>';
		html += ' <span style="color:#888;font-size:12px;">(auto-detectado)</span>';
		$container.html(html);

		$('#aoe-sep-select').off('change').on('change', function () {
			window.aoeSeparator = $(this).val();
			var headers = parseCSVHeaders(window.aoeImportContent);
			displayDetectedColumns(headers);
		});
	}

	/**
	 * RFC-4180 compliant CSV line parser.
	 * Handles quoted fields containing the separator, newlines, or escaped quotes.
	 */
	function parseCSVLine(line, separator) {
		var fields = [];
		var cur = '';
		var inQuotes = false;
		for (var i = 0; i < line.length; i++) {
			var ch = line[i];
			if (inQuotes) {
				if (ch === '"') {
					// Escaped quote ("") inside a quoted field
					if (line[i + 1] === '"') { cur += '"'; i++; }
					else { inQuotes = false; }
				} else {
					cur += ch;
				}
			} else {
				if (ch === '"') {
					inQuotes = true;
				} else if (ch === separator) {
					fields.push(cur.trim());
					cur = '';
				} else {
					cur += ch;
				}
			}
		}
		fields.push(cur.trim());
		return fields;
	}

	function parseCSVHeaders(content) {
		if (!content) return [];
		var firstLine = content.split('\n')[0];
		if (!firstLine) return [];
		var sep = window.aoeSeparator || detectSeparator(content);
		return parseCSVLine(firstLine, sep).filter(function (h) { return h !== ''; });
	}

	function parseCSVRows(content, headers, maxRows) {
		if (!content) return [];
		var lines = content.split('\n');
		var sep = window.aoeSeparator || detectSeparator(content);
		var rows = [];
		var count = 0;
		for (var i = 1; i < lines.length; i++) {
			var line = lines[i];
			if (!line.trim()) continue;
			var cols = parseCSVLine(line, sep);
			var rowData = {};
			headers.forEach(function (header, idx) {
				rowData[header] = cols[idx] !== undefined ? cols[idx] : '';
			});
			rows.push(rowData);
			count++;
			if (maxRows && count >= maxRows) {
				break;
			}
		}
		return rows;
	}

	function displayDetectedColumns(headers) {
		var $container = $('#aoe-detected-columns-list');
		$container.empty();
		if (headers.length === 0) {
			$('#aoe-detected-columns').hide();
			$('#aoe-preview-action').slideUp();
			$('#aoe-action-step').slideUp();
			$('#aoe-import-progress').slideUp().find('#aoe-progress-bar').css('width', '0%');
			$('#aoe-progress-text').text('0 / 0 filas procesadas');
			$('#aoe-log-box').empty().hide();
			return;
		}

		// Retrieve supported columns from processor
		var supportedCols = [];
		try {
			var colsAttr = $('#manufacturer_slug').attr('data-supported-columns');
			if (colsAttr) {
				supportedCols = JSON.parse(colsAttr);
			}
		} catch (e) {
			console.error(e);
		}

		// Filter columns to only show the ones supported by the processor if defined
		var columnsToShow = headers;
		if (supportedCols && supportedCols.length > 0) {
			columnsToShow = headers.filter(function (header) {
				return supportedCols.some(function (sc) {
					return sc.toLowerCase() === header.trim().toLowerCase();
				});
			});
		}

		// If for some reason none of them match, default to showing all headers
		if (columnsToShow.length === 0) {
			columnsToShow = headers;
		}

		var previewRows = parseCSVRows(window.aoeImportContent, headers, 5);
		if (previewRows.length === 0) {
			$('#aoe-detected-columns').hide();
			$('#aoe-preview-action').slideUp();
			$('#aoe-action-step').slideUp();
			$('#aoe-import-progress').slideUp().find('#aoe-progress-bar').css('width', '0%');
			$('#aoe-progress-text').text('0 / 0 filas procesadas');
			$('#aoe-log-box').empty().hide();
			return;
		}

		var html = '<div class="aoe-preview-table-wrapper" style="overflow-x: auto; margin-top: 10px; border: 1px solid #dcdcde; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">';
		html += '<table class="wp-list-table widefat fixed striped" style="margin: 0; min-width: 600px; border: none;">';
		html += '<thead><tr>';
		columnsToShow.forEach(function (header) {
			html += '<th style="font-weight: 600; background: #f6f7f7; padding: 10px 12px; border-bottom: 1px solid #dcdcde;">' + escHTML(header) + '</th>';
		});
		html += '</tr></thead>';
		html += '<tbody>';

		previewRows.forEach(function (row) {
			html += '<tr>';
			columnsToShow.forEach(function (header) {
				html += '<td style="padding: 10px 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 220px;">' + escHTML(row[header] || '') + '</td>';
			});
			html += '</tr>';
		});

		html += '</tbody></table></div>';
		$container.append(html);

		$('#aoe-detected-columns').show();
		renderSeparatorSelector(window.aoeSeparator || detectSeparator(window.aoeImportContent));
		$('#aoe-preview-action').slideDown();
		$('#aoe-action-step').slideUp();
		$('#aoe-import-progress').slideUp().find('#aoe-progress-bar').css('width', '0%');
		$('#aoe-progress-text').text('0 / 0 filas procesadas');
		$('#aoe-log-box').empty().hide();
	}

	function escHTML(str) {
		return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
	}

	// Clear file input and preview on click so re-selecting same file triggers change
	$('#csv_file').on('click', function () {
		this.value = '';
		$('#aoe-detected-columns').hide();
		$('#aoe-detected-columns-list').empty();
		$('#aoe-preview-action').slideUp();
		$('#aoe-action-step').slideUp();
		$('#aoe-import-progress').slideUp().find('#aoe-progress-bar').css('width', '0%');
		$('#aoe-progress-text').text('0 / 0 filas procesadas');
		$('#aoe-log-box').empty().hide();
	});

	// Trigger detection on File upload
	$('#csv_file').on('change', function (e) {
		var file = e.target.files[0];
		if (!file) return;

		var reader = new FileReader();
		reader.onload = function (evt) {
			var content = evt.target.result.replace(/^\uFEFF/, '');
			// Save parsed content in memory for batches
			window.aoeImportContent = content;
			var headers = parseCSVHeaders(content);
			displayDetectedColumns(headers);
		};
		reader.readAsText(file);
	});

	// Trigger detection on Text paste
	$('#csv_paste').on('input', function () {
		var content = $(this).val().replace(/^\uFEFF/, '');
		window.aoeImportContent = content;
		var headers = parseCSVHeaders(content);
		displayDetectedColumns(headers);
	});

	// Batch Execution Core
	function runBatchProcess(isTest) {
		if (!window.aoeImportContent) {
			alert('Please provide CSV data source first.');
			return;
		}

		var lines = window.aoeImportContent.split('\n');
		if (lines.length <= 1) {
			alert('No data rows found to import.');
			return;
		}

		var headers = parseCSVHeaders(window.aoeImportContent);
		var rows = [];

		var sep = window.aoeSeparator || detectSeparator(window.aoeImportContent);
		for (var i = 1; i < lines.length; i++) {
			var line = lines[i];
			if (!line.trim()) continue;
			var cols = parseCSVLine(line, sep);
			var rowData = {};
			headers.forEach(function (header, idx) {
				rowData[header] = cols[idx] !== undefined ? cols[idx] : '';
			});
			rows.push(rowData);
		}

		if (isTest) {
			if (rows.length === 0) {
				alert('No valid Samtec rows found for test generation. Make sure the CSV has a Part column.');
				return;
			}
			rows = rows.slice(0, 500);
			logMessage('Test mode: processing first ' + rows.length + ' rows for preview.');
		}

		// Apply row limit
		var rowLimit = parseInt($('#aoe-row-limit').val()) || 0;
		if (rowLimit > 0 && rows.length > rowLimit) {
			rows = rows.slice(0, rowLimit);
		}

		// Configure batching
		var batchSize = 500;
		var totalRows = rows.length;
		var processed = 0;
		var manufacturer = $('#manufacturer_slug').val();
		var importMode = $('input[name="import_mode"]:checked').val();
		var testSlug = '';

		if (isTest) {
			testSlug = 'test-' + manufacturer + '-' + Date.now();
		}

		$('#aoe-import-progress').slideDown();
		$('#aoe-progress-bar').css('width', '0%');
		$('#aoe-progress-text').text(isTest ? '🧪 MODO TEST — Procesando ' + totalRows + ' filas (primeras 500)' : '0 / ' + totalRows + ' rows processed');
		$('#aoe-log-box').empty().show();

		logMessage('Starting process: Manufacturer=' + manufacturer + ' | Type=' + (isTest ? 'TEST' : 'PRODUCTION') + ' | Mode=' + importMode);
		logMessage('Total rows loaded: ' + totalRows);
		if (isTest) {
			logMessage('Procesando ' + totalRows + ' filas.');
		}

		function sendBatch() {
			if (processed >= totalRows) {
				logMessage('Process completed successfully!');
				$('#aoe-progress-text').text('Completed! ' + processed + ' / ' + totalRows + ' rows processed.');
				return;
			}

			var chunk = rows.slice(processed, processed + batchSize);
			var isLastChunk = processed + chunk.length >= totalRows;

			function doRequest(retries) {
				$.ajax({
					url: ajaxurl,
					method: 'POST',
					timeout: 120000,
					data: {
						action: 'aoe_process_batch',
						manufacturer: manufacturer,
						import_mode: importMode,
						is_test: isTest ? 1 : 0,
						test_slug: testSlug,
						is_last_chunk: isLastChunk ? 1 : 0,
						offset: processed,
						total_rows: totalRows,
						rows_json: JSON.stringify(chunk)
					},
					success: function (response) {
						if (response.success) {
							processed += chunk.length;
							var pct = Math.min(100, Math.round((processed / totalRows) * 100));
							$('#aoe-progress-bar').css('width', pct + '%');
							$('#aoe-progress-text').text(processed + ' / ' + totalRows + ' rows processed');
							logMessage('Processed batch: ' + processed + ' / ' + totalRows + ' rows. ' + response.data.message);

							if (processed >= totalRows) {
								if (isTest && response.data.test_url) {
									$('#aoe-progress-text').html('<strong>¡Prueba completada con éxito!</strong> <a href="' + response.data.test_url + '" target="_blank" class="button button-secondary" style="margin-left: 10px;">Ver Página de Prueba</a>');
									logMessage('Preview URL: ' + response.data.test_url);
								} else if (!isTest) {
									var prodUrl = window.location.origin + '/catalogo/' + manufacturer;
									$('#aoe-progress-text').html('<strong>¡Importación completada!</strong> <a href="' + prodUrl + '" target="_blank" class="button button-secondary" style="margin-left: 10px;">Ver Catálogo Principal</a>');
									logMessage('Production Catalog URL: ' + prodUrl);
								}
								return;
							}

							setTimeout(sendBatch, 100);
						} else {
							logMessage('ERROR: ' + response.data);
						}
					},
					error: function (xhr, status, err) {
						var errMsg = xhr.responseText || err || 'unknown';
						logMessage('ERROR (HTTP ' + xhr.status + '): ' + errMsg.substring(0, 500));
						if (retries > 0) {
							logMessage('Reintentando lote (quedan ' + retries + ' intentos)...');
							setTimeout(function () { doRequest(retries - 1); }, 2000);
						} else {
							logMessage('Error definitivo en lote ' + Math.floor(processed / batchSize + 1) + '. Recarga la página y continúa en modo Incremental.');
							$('#aoe-progress-text').html('<strong style="color:#d63638;">Error. Recarga y continúa en Incremental desde el final.</strong>');
						}
					}
				});
			}

			doRequest(3);
		}

		sendBatch();
	}

	function logMessage(msg) {
		var $log = $('#aoe-log-box');
		var timestamp = new Date().toISOString().slice(11, 19);
		$log.append('[' + timestamp + '] ' + msg + '\n');
		$log.scrollTop($log[0].scrollHeight);
	}

	// Button Handlers
	$('#aoe-btn-test').on('click', function (e) {
		e.preventDefault();
		runBatchProcess(true);
	});

	$('#aoe-btn-show-import').on('click', function (e) {
		e.preventDefault();
		$('#aoe-action-step').slideDown();
		$('html, body').animate({
			scrollTop: $("#aoe-action-step").offset().top - 50
		}, 500);
	});

	$('#aoe-btn-import').on('click', function (e) {
		e.preventDefault();
		if (confirm('¿Está seguro de que desea ejecutar la importación final en la base de datos?')) {
			runBatchProcess(false);
		}
	});

	// === EDAC: Structure Import ===
	if ($('#csv_structure').length) {
		var $sepRow = $('<div id="aoe-structure-sep" style="margin:10px 0;"></div>');
		$('#csv_structure').after($sepRow);

		function parseStructureCSV(content, sep) {
			var lines = content.split('\n');
			if (lines.length <= 1) return null;
			var headers = parseCSVLine(lines[0], sep);
			var rows = [];
			for (var i = 1; i < lines.length; i++) {
				var line = lines[i];
				if (!line.trim()) continue;
				var cols = parseCSVLine(line, sep);
				var rowData = {};
				headers.forEach(function (header, idx) {
					rowData[header] = cols[idx] !== undefined ? cols[idx] : '';
				});
				rows.push(rowData);
			}
			return rows;
		}

		function sendStructureImport(content, sep) {
			var $status = $('#aoe-structure-status');
			var rows = parseStructureCSV(content, sep);
			if (!rows || rows.length === 0) {
				$status.html('<p style="color:#d63638;">El archivo no tiene datos.</p>');
				return;
			}
			$status.html('<p><em>Importando ' + rows.length + ' registros...</em></p>');
			var manufacturer = $('#manufacturer_slug').val();
			var action = manufacturer === 'samtec' ? 'aoe_import_samtec_categories' : 'aoe_import_structure';
			$.ajax({
				url: ajaxurl,
				method: 'POST',
				data: {
					action: action,
					manufacturer: manufacturer,
					rows_json: JSON.stringify(rows)
				},
				success: function (resp) {
					if (resp.success) {
						$status.html('<p style="color:#46b450;font-weight:600;">✓ ' + resp.data.message + '</p>');
						if ($('#aoe-skip-products').is(':checked')) {
							$('#step-data-source, #aoe-action-step, #aoe-import-progress').hide();
						}
					} else {
						$status.html('<p style="color:#d63638;">Error: ' + resp.data + '</p>');
					}
				},
				error: function () {
					$status.html('<p style="color:#d63638;">Error de conexión al importar estructura.</p>');
				}
			});
		}

		$('#csv_structure').on('change', function (e) {
			var file = e.target.files[0];
			if (!file) return;

			var reader = new FileReader();
			reader.onload = function (evt) {
				var content = evt.target.result.replace(/^\uFEFF/, '');
				window.aoeStructureContent = content;
				var firstLine = content.split('\n')[0] || '';
				var sep = firstLine.indexOf('\t') !== -1 ? '\t' : firstLine.indexOf(';') !== -1 ? ';' : ',';

				var html = '<label style="font-weight:600;">Separador: </label><select id="aoe-structure-sep-select">';
				['\t', ';', ','].forEach(function (s) {
					var label = s === '\t' ? 'Tabulación' : s === ';' ? 'Punto y coma' : 'Coma';
					html += '<option value="' + s + '"' + (s === sep ? ' selected' : '') + '>' + label + '</option>';
				});
				html += '</select> <button type="button" class="button button-primary" id="aoe-btn-import-structure" style="margin-left:10px;">Importar estructura</button>';
				$sepRow.html(html);

				var $status = $('#aoe-structure-status');
				var rows = parseStructureCSV(content, sep);
				if (rows) {
					$status.html('<p style="color:#888;">' + rows.length + ' registros detectados.</p>');
				}

				$('#aoe-structure-sep-select').off('change').on('change', function () {
					var newSep = $(this).val();
					var rows = parseStructureCSV(window.aoeStructureContent, newSep);
					if (rows) {
						$('#aoe-structure-status').html('<p style="color:#888;">' + rows.length + ' registros detectados.</p>');
					}
				});

				$('#aoe-btn-import-structure').off('click').on('click', function () {
					var chosenSep = $('#aoe-structure-sep-select').val();
					sendStructureImport(window.aoeStructureContent, chosenSep);
				});
			};
			reader.readAsText(file);
		});
	}

	// Regenerate pages per manufacturer
	$(document).on('click', '.aoe-regenerate-pages', function (e) {
		e.preventDefault();
		var $link = $(this);
		var slug = $link.data('slug');
		if (!slug) return;
		if (!confirm('¿Regenerar páginas de ' + slug + '? Esto no modifica productos ni categorías.')) return;
		$link.text('Regenerando...');
		$.ajax({
			url: aoe_catalog.ajax_url,
			method: 'POST',
			timeout: 30000,
			data: { action: 'aoe_regenerate_pages', slug: slug },
			success: function (resp) {
				if (resp.success) {
					$link.after(' <span style="color:#46b450;font-weight:600;">✓</span>');
					setTimeout(function () { $link.next('span').fadeOut(); }, 2000);
					$link.text('Regenerar páginas');
				} else {
					alert('Error: ' + (resp.data || 'Error desconocido'));
					$link.text('Regenerar páginas');
				}
			},
			error: function (xhr, status, err) {
				alert('Error de conexión: ' + status + ' - ' + err);
				$link.text('Regenerar páginas');
			}
		});
	});

	// Generate template cache per manufacturer
	$(document).on('click', '.aoe-generate-template-cache', function (e) {
		e.preventDefault();
		var $link = $(this);
		var slug = $link.data('slug');
		if (!slug) return;
		if (!confirm('¿Generar template cache para ' + slug + '?')) return;
		var clearCache = confirm('¿Borrar cachés de páginas de productos también?');
		var originalText = $link.text();
		$link.text('Preparando...');

		$.ajax({
			url: aoe_catalog.ajax_url,
			method: 'POST',
			data: { action: 'aoe_generate_template_cache', slug: slug, clear_cache: clearCache ? 1 : 0 },
			success: function (resp) {
				if (resp.success && resp.data.url) {
					window.open(resp.data.url, '_blank');
					$link.after(' <span style="color:#46b450;font-weight:600;">✓ Abierto en nueva pestaña</span>');
					setTimeout(function () { $link.next('span').fadeOut(); }, 3000);
				} else {
					alert('Error: ' + (resp.data || 'Error desconocido'));
				}
				$link.text(originalText);
			},
			error: function (xhr, status, err) {
				alert('Error: ' + status + ' - ' + (err || 'sin respuesta'));
				$link.text(originalText);
			}
		});
	});

	// Clear cache per manufacturer
	$(document).on('click', '.aoe-clear-cache', function (e) {
		e.preventDefault();
		var $link = $(this);
		var slug = $link.data('slug');
		if (!slug) return;
		if (!confirm('¿Limpiar cache de ' + slug + '?')) return;
		$.post(aoe_catalog.ajax_url, {
			action: 'aoe_clear_cache',
			slug: slug
		}, function (resp) {
			if (resp.success) {
				$link.after(' <span style="color:#46b450;font-weight:600;">✓</span>');
				setTimeout(function () { $link.next('span').fadeOut(); }, 2000);
			} else {
				alert('Error: ' + resp.data);
			}
		});
	});

	// --- Samtec Specs CSV Import ---
	var $specsInput = $('#csv_specs');
	if ($specsInput.length) {
		var specsBatchSize = 100;

		$specsInput.on('change', function () {
			var file = this.files[0];
			if (!file) return;

			var reader = new FileReader();
			reader.onload = function (e) {
				var text = e.target.result;
				var lines = text.split('\n').filter(function (l) { return l.trim() !== ''; });
				if (lines.length < 2) {
					$('#aoe-specs-status').html('<span style="color:#d63638;">El archivo no tiene datos.</span>');
					return;
				}

				var sep = detectSeparator(lines[0]);
				var headerRow = lines[0].split(sep).map(function (c) { return c.trim(); });
				var dataRows = [];
				for (var i = 1; i < lines.length; i++) {
					dataRows.push(lines[i].split(sep).map(function (c) { return c.trim(); }));
				}

				var total = dataRows.length;
				var processed = 0;
				var errors = 0;
				var mfr = $('#manufacturer_slug').val();
				var $status = $('#aoe-specs-status');
				var $progress = $('#aoe-specs-progress-container');
				var $bar = $('#aoe-specs-progress-bar');
				var $text = $('#aoe-specs-progress-text');
				var $log = $('#aoe-specs-log');

				$status.html('<span style="color:#007cba;">Iniciando importación...</span>');
				$progress.show();
				$log.show().text('');

				function sendBatch(start) {
					var batch = dataRows.slice(start, start + specsBatchSize);
					if (batch.length === 0) {
						var msg = 'Importación completa. Procesados: ' + processed + ', Errores: ' + errors;
						$status.html('<span style="color:#46b450;font-weight:600;">✓ ' + msg + '</span>');
						$text.text(msg);
						return;
					}
					// Prepend header row so PHP can map column names
					var payload = [headerRow].concat(batch);

					$.post(aoe_catalog.ajax_url, {
						action: 'aoe_import_samtec_specs',
						manufacturer: mfr,
						rows_json: JSON.stringify(payload)
					}, function (resp) {
						if (resp.success) {
							processed += resp.data.processed;
							errors += resp.data.errors;
							var done = Math.min(start + specsBatchSize, total);
							var pct = Math.round((done / total) * 100);
							$bar.css('width', pct + '%');
							$text.text(done + ' / ' + total + ' SKUs procesados (' + errors + ' errores)');
							$log.append(resp.data.processed + ' ok, ' + resp.data.errors + ' errors\n');
							$log.scrollTop($log[0].scrollHeight);
							sendBatch(done);
						} else {
							$status.html('<span style="color:#d63638;">Error: ' + (resp.data || 'Error desconocido') + '</span>');
						}
					}, 'json').fail(function (xhr, status, err) {
						$status.html('<span style="color:#d63638;">Error AJAX: ' + status + ' - ' + (err || 'sin respuesta') + '</span>');
					});
				}

				sendBatch(0);
			};
			reader.readAsText(file);
		});
	}
});
