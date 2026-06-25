function aoeCatalogFixParentWidth() {
	if (window.innerWidth > 720) return;
	var el = document.querySelector('.aoe-catalog-render, .aoe-tree');
	if (!el) return;
	var p = el.parentElement;
	var maxDepth = 10;
	while (p && p !== document.body && maxDepth > 0) {
		if (p.style.width !== '100%') {
			p.style.width = '100%';
			p.style.maxWidth = '100%';
		}
		p = p.parentElement;
		maxDepth--;
	}
}

jQuery(document).ready(function ($) {
	aoeCatalogFixParentWidth();
	$(window).on('resize', aoeCatalogFixParentWidth);

	var manufacturerName = typeof aoeCatalog !== 'undefined' ? aoeCatalog.manufacturerName : '';
	var aoeDownloadUrl = '';
	var aoeProductName = '';

	function aoeShowModal($target) {
		setTimeout(function () {
			$target.modal('show');
			$target.css('z-index', '100001');
			$('.modal-backdrop').last().css({ 'z-index': '100000', 'opacity': '0.85' });
			$('body').children('.modal-backdrop').not(':last').hide();
		}, 350);
	}

	function aoeHideProduct() {
		$('#aoe-catalog-modal').modal('hide');
	}

	function aoeShowProduct() {
		setTimeout(function () {
			$('#aoe-catalog-modal').modal('show');
		}, 350);
	}

	$(document).on('click', '.fila-producto', function (e) {
		e.preventDefault();
		var $btn = $(this);
		if (!$btn.length) return;

		var sku = $btn.attr('data-sku') || '';
		var nombre = $btn.attr('data-nombre') || '';
		var imagen = $btn.attr('data-img') || '';
		var altText = sku + ': ' + nombre + ' de ' + manufacturerName;
		altText = altText.charAt(0).toUpperCase() + altText.slice(1);

		aoeProductName = nombre;
		$('#modal-sku-titulo').text(sku);
		$('#modal-nombre-subtitulo').text(nombre);
		$('#modal-img-producto').attr('src', imagen || '').attr('alt', altText).show();
		$('#modal-img-producto').closest('.aoe-catalog-product-image-wrap').show();
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
				+ ' data-target=".fusion-modal.descargar"'
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

	// Download modal
	$(document).on('click', '.aoe-catalog-doc-card', function (e) {
		e.preventDefault();
		e.stopPropagation();
		aoeDownloadUrl = $(this).attr('data-doc') || '';
		var title = $(this).attr('title') || '';
		var $modal = $('.fusion-modal.descargar');

		$modal.find('[name="archivo_a_descargar"]').val(aoeDownloadUrl);

		if (title.toLowerCase() === 'datasheet') {
			$modal.find('[id^="modal-heading-"]').text('Descargar ficha técnica de ' + aoeProductName);
		} else {
			$modal.find('[id^="modal-heading-"]').text('Descargar el documento "' + title + '" de ' + aoeProductName);
		}

		aoeHideProduct();
		aoeShowModal($modal);
	});

	// Contact modal
	$(document).on('click', '#btn-contacto-modal', function (e) {
		e.preventDefault();
		var sku = $(this).attr('data-sku-link') || '';
		$('#modal-contacto-sku-info').text('Producto: ' + sku);
		$('#aoe-contacto-sku').val(sku);
		$('#quierodescargar textarea').val($(this).attr('title') || '');

		aoeHideProduct();
		aoeShowModal($('.fusion-modal.modal-productos-formulario'));
	});

	// Return to product modal when download/contact close
	$(document).on('hidden.bs.modal', '.fusion-modal.descargar, .modal-productos-formulario', function () {
		aoeShowProduct();
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
