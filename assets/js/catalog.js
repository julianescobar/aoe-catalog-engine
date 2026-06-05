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

		var pdfs = [
			['Print', $btn.attr('data-pdf-print') || ''],
			['Footprint', $btn.attr('data-pdf-foot') || ''],
			['Catalog Page', $btn.attr('data-pdf-cat') || ''],
			['Spec Sheet', $btn.attr('data-pdf-spec') || '']
		];
		var pdfHtml = '';
		$.each(pdfs, function (i, item) {
			if (!item[1]) return;
			pdfHtml += '<a class="aoe-catalog-doc-card" href="' + item[1] + '" target="_blank" rel="noopener">'
				+ '<i class="fas fa-file-pdf"></i>'
				+ '<span><strong>' + item[0] + '</strong><em>Oficial ' + manufacturerName + ' Document</em></span>'
				+ '</a>';
		});
		$('#titulo-documentacion').text('Descarga de catálogos de ' + sku);
		var $docs = $('#lista-pdfs-dinamica');
		if ($docs.length) $docs.html(pdfHtml);
		$('#contenedor-documentacion-bloque').toggle(!!pdfHtml);

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

});
