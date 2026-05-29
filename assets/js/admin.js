jQuery(document).ready(function($) {

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
		return parseCSVLine(firstLine, sep).filter(function(h) { return h !== ''; });
	}

	function displayDetectedColumns(headers) {
		var $container = $('#aoe-detected-columns-list');
		$container.empty();
		if (headers.length === 0) {
			$container.append('<span class="description">No columns detected. Make sure the CSV format is correct.</span>');
			return;
		}
		headers.forEach(function(header) {
			$container.append('<span class="aoe-column-badge">' + escHTML(header) + '</span>');
		});
		$('#aoe-preview-action').slideDown();
		$('#aoe-action-step').slideDown();
	}

	function escHTML(str) {
		return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
	}

	// Trigger detection on File upload
	$('#csv_file').on('change', function(e) {
		var file = e.target.files[0];
		if (!file) return;

		var reader = new FileReader();
		reader.onload = function(evt) {
			var content = evt.target.result;
			var headers = parseCSVHeaders(content);
			displayDetectedColumns(headers);
			// Save parsed content in memory for batches
			window.aoeImportContent = content;
		};
		reader.readAsText(file);
	});

	// Trigger detection on Text paste
	$('#csv_paste').on('input', function() {
		var content = $(this).val();
		var headers = parseCSVHeaders(content);
		displayDetectedColumns(headers);
		window.aoeImportContent = content;
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
			headers.forEach(function(header, idx) {
				rowData[header] = cols[idx] !== undefined ? cols[idx] : '';
			});
			rows.push(rowData);
		}

		if (isTest) {
			var firstModel = '';
			rows = rows.filter(function(row) {
				var part = (row.Part || '').trim();
				var model = part.split('-')[0];

				if (!part) return false;
				if (!firstModel) firstModel = model;

				return model === firstModel;
			}).slice(0, 200);

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
			logMessage('Test mode: only the first detected model will be used, up to 200 products.');
		}

		function sendBatch() {
			if (processed >= totalRows) {
				logMessage('Process completed successfully!');
				$('#aoe-progress-text').text('Completed! ' + processed + ' / ' + totalRows + ' rows processed.');
				return;
			}

			var chunk = rows.slice(processed, processed + batchSize);

			$.ajax({
				url: ajaxurl,
				method: 'POST',
				data: {
					action: 'aoe_process_batch',
					manufacturer: manufacturer,
					import_mode: importMode,
					is_test: isTest ? 1 : 0,
					test_slug: testSlug,
					rows: chunk,
					offset: processed,
					total_rows: totalRows
				},
				success: function(response) {
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
				error: function(xhr, status, err) {
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
	$('#aoe-btn-test').on('click', function(e) {
		e.preventDefault();
		runBatchProcess(true);
	});

	$('#aoe-btn-import').on('click', function(e) {
		e.preventDefault();
		if (confirm('Are you sure you want to run the final import into the database?')) {
			runBatchProcess(false);
		}
	});
});
