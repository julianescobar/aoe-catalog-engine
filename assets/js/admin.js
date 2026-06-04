jQuery(document).ready(function ($) {

	/**
	 * Auto-detect separator: tab or comma.
	 * Checks the first line for tabs first.
	 */
	function detectSeparator(content) {
		var firstLine = content.split('\n')[0] || '';
		return (firstLine.indexOf('\t') !== -1) ? '\t' : ',';
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
		var sep = detectSeparator(content);
		return parseCSVLine(firstLine, sep).filter(function (h) { return h !== ''; });
	}

	function parseCSVRows(content, headers, maxRows) {
		if (!content) return [];
		var lines = content.split('\n');
		var sep = detectSeparator(content);
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
			$container.append('<span class="description">No se detectaron columnas. Asegúrate de que el formato CSV sea correcto.</span>');
			$('#aoe-preview-action').slideUp();
			$('#aoe-action-step').slideUp();
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
			$container.append('<span class="description">No se encontraron registros.</span>');
			$('#aoe-preview-action').slideUp();
			$('#aoe-action-step').slideUp();
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

		$('#aoe-preview-action').slideDown();
		$('#aoe-action-step').slideUp();
	}

	function escHTML(str) {
		return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
	}

	// Trigger detection on File upload
	$('#csv_file').on('change', function (e) {
		var file = e.target.files[0];
		if (!file) return;

		var reader = new FileReader();
		reader.onload = function (evt) {
			var content = evt.target.result;
			// Save parsed content in memory for batches
			window.aoeImportContent = content;
			var headers = parseCSVHeaders(content);
			displayDetectedColumns(headers);
		};
		reader.readAsText(file);
	});

	// Trigger detection on Text paste
	$('#csv_paste').on('input', function () {
		var content = $(this).val();
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

		// Parse lines into row objects using the RFC-4180 compliant parser
		var sep = detectSeparator(window.aoeImportContent);
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
			// Send all rows; server handles pagination
			logMessage('Test mode: all categories and products will be processed for preview.');

			if (rows.length === 0) {
				alert('No valid Samtec rows found for test generation. Make sure the CSV has a Part column.');
				return;
			}
		}

		// Configure batching
		var batchSize = 50;
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
		$('#aoe-progress-text').text('0 / ' + totalRows + ' rows processed');
		$('#aoe-log-box').empty().show();

		logMessage('Starting process: Manufacturer=' + manufacturer + ' | Type=' + (isTest ? 'TEST' : 'PRODUCTION') + ' | Mode=' + importMode);
		logMessage('Total rows loaded: ' + totalRows);
		if (isTest) {
			logMessage('Test mode: all ' + totalRows + ' rows will be processed to generate paginated preview.');
		}

		function sendBatch() {
			if (processed >= totalRows) {
				logMessage('Process completed successfully!');
				$('#aoe-progress-text').text('Completed! ' + processed + ' / ' + totalRows + ' rows processed.');
				return;
			}

			var chunk = rows.slice(processed, processed + batchSize);
			var isLastChunk = processed + chunk.length >= totalRows;

			$.ajax({
				url: ajaxurl,
				method: 'POST',
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
								var prodUrl = window.location.origin + '/catalog/' + manufacturer;
								$('#aoe-progress-text').html('<strong>¡Importación completada!</strong> <a href="' + prodUrl + '" target="_blank" class="button button-secondary" style="margin-left: 10px;">Ver Catálogo Principal</a>');
								logMessage('Production Catalog URL: ' + prodUrl);
							}
							return;
						}

						sendBatch();
					} else {
						logMessage('ERROR: ' + response.data);
					}
				},
				error: function (xhr, status, err) {
					logMessage('AJAX Connection Error: ' + err);
				}
			});
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
});
