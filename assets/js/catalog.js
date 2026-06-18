jQuery(document).ready(function ($) {

	var manufacturerName = typeof aoeCatalog !== 'undefined' ? aoeCatalog.manufacturerName : '';

	$(document).on('click', '.abrir-modal-dinamico, .fila-producto', function (e) {
		var $btn = $(this).closest('.fila-producto');
		if (!$btn.length) return;

		var sku = $btn.attr('data-sku') || '';
		var nombre = $btn.attr('data-nombre') || '';
		var imagen = $btn.attr('data-img') || '';

		$('#modal-sku-titulo').text(sku);
		$('#modal-nombre-subtitulo').text(nombre);
		$('#modal-img-producto').attr('src', imagen).attr('alt', sku);
		$('#modal-heading-1').text(sku);

		$('#btn-contacto-modal').attr('data-sku-link', sku);
		$('#btn-contacto-modal').attr('title', 'Quiero más información sobre ' + manufacturerName + ' ' + sku + ' ' + nombre);

		var pdfLabels = {
			'datasheet': 'Datasheet',
			'print': 'Print',
			'footprint': 'Footprint',
			'catalog_page': 'Catalog Page',
			'spec_sheet': 'Spec Sheet'
		};
		var pdfData = {};
		try { pdfData = JSON.parse($btn.attr('data-pdf-json') || '{}'); } catch(e) {}
		var pdfHtml = '';
		$.each(pdfData, function (key, url) {
			if (!url) return;
			var label = pdfLabels[key] || key.replace(/_/g, ' ').replace(/\b\w/g, function(c) { return c.toUpperCase(); });
			pdfHtml += '<a class="aoe-catalog-doc-card" href="#"'
				+ ' data-doc="' + url + '"'
				+ ' data-toggle="modal" data-target=".fusion-modal.descargar"'
				+ ' title="' + label + '"'
				+ ' aria-label="' + label + '">'
				+ '<i class="fas fa-file-pdf"></i>'
				+ '<span><strong>' + label + '</strong><em>Oficial ' + manufacturerName + ' Document</em></span>'
				+ '</a>';
		});
		$('#titulo-documentacion').text('Descarga de catálogos de ' + sku);
		var $docs = $('#lista-pdfs-dinamica');
		if ($docs.length) $docs.html(pdfHtml);
		$('#contenedor-documentacion-bloque').toggle(!!pdfHtml);

		var specsData = {};
		try { specsData = JSON.parse($btn.attr('data-specs-json') || '{}'); } catch(e) {}
		var specsHtml = '';
		$.each(specsData, function (key, value) {
			if (!value) return;
			specsHtml += '<tr><td class="aoe-spec-key">' + key + '</td><td class="aoe-spec-value">' + value + '</td></tr>';
		});
		var $specsList = $('#lista-specs-dinamica');
		if ($specsList.length) $specsList.html(specsHtml);
		$('#contenedor-specs-bloque').toggle(!!specsHtml);

		$('#aoe-catalog-modal').modal('show');
	});

	$(document).on('click', '.aoe-catalog-modal__close', function () {
		$('#aoe-catalog-modal').modal('hide');
	});

	$(document).on('show.bs.modal', '.modal-productos-formulario', function () {
		var sku = $('#btn-contacto-modal').attr('data-sku-link') || '';
		$('#modal-contacto-sku-info').text('Producto: ' + sku);
		$('#aoe-contacto-sku').val(sku);
	});

	$(document).on('click', '#btn-contacto-modal', function () {
		$('#quierodescargar textarea').val($(this).attr('title') || '');
	});

	var aoeDownloadUrl = '';

	// Handle download modal open - set data-doc, heading and show modal
	$(document).on('click', 'a[data-toggle="modal"][data-target=".fusion-modal.descargar"]', function () {
		aoeDownloadUrl = $(this).attr('data-doc') || '';
		var title = $(this).attr('title') || '';

		$('.fusion-modal.descargar').find('[name="archivo_a_descargar"]').val(aoeDownloadUrl);

		if (title.toLowerCase() === 'datasheet') {
			$('.fusion-modal.descargar').find('[id^="modal-heading-"]').text('Descargar ficha técnica');
		} else {
			$('.fusion-modal.descargar').find('[id^="modal-heading-"]').text('Descargar el documento "' + title + '"');
		}

		$('#aoe-catalog-modal').modal('hide');
	});

	$(document).on('hidden.bs.modal', '.fusion-modal.descargar', function () {
		$('#aoe-catalog-modal').modal('show');
	});

	// Trigger PDF download after Avada form AJAX succeeds
	$(window).on('fusion-form-ajax-submit-done', function (event, data) {
		console.log('fusion-form-ajax-submit-done fired', data);
		if (data && data.formConfig && data.formConfig.form_id) {
			var $modal = $('.fusion-modal.descargar:visible');
			var $form = $modal.find('.fusion-form-' + data.formConfig.form_id);
			console.log('Modal found:', $modal.length, 'Form found:', $form.length);
			if ($form.length) {
				console.log('Download URL from saved var:', aoeDownloadUrl);
				if (aoeDownloadUrl) {
					window.open(aoeDownloadUrl, '_blank');
				}
			}
		}
	});

});
