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

	// Inject CSS rule to neutralize stacking context on .fusion-builder-row when modal is open
	$('<style>').prop('type', 'text/css')
		.text('.aoe-modal-open .fusion-builder-row { z-index: auto !important; }')
		.appendTo('head');

	var manufacturerName = typeof aoeCatalog !== 'undefined' ? aoeCatalog.manufacturerName : '';
	var aoeDownloadUrl = '';
	var aoeProductName = '';

	function aoeShowModal($target) {
		$('body').addClass('aoe-modal-open');
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
			$('body').append($('#aoe-catalog-modal'));
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
		var $modal = $('#aoe-catalog-modal');
		$modal.find('#modal-sku-titulo').text(sku);
		$modal.find('#modal-nombre-subtitulo').text(nombre);
		$modal.find('#modal-img-producto').attr('src', imagen || '').attr('alt', altText).show();
		$modal.find('#modal-img-producto').closest('.aoe-catalog-product-image-wrap').show();
		$modal.find('#modal-heading-1').text(sku);
		$modal.find('#btn-contacto-modal').attr('data-sku-link', sku);
		$modal.find('#btn-contacto-modal').attr('title', 'Quiero más información sobre ' + manufacturerName + ' ' + sku + ' ' + nombre);

		var pdfLabels = {
			'datasheet': 'Datasheet',
			'print': 'Print',
			'footprint': 'Footprint',
			'catalog_page': 'Catalog Page',
			'spec_sheet': 'Spec Sheet'
		};
		var pdfData = {};
		try { pdfData = JSON.parse($btn.attr('data-pdf-json') || '{}'); } catch (e) { }
		var pdfHtml = '';
		$.each(pdfData, function (key, url) {
			if (!url) return;
			var label = pdfLabels[key] || key.replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
			pdfHtml += '<a class="aoe-catalog-doc-card" href="#"'
				+ ' data-doc="' + url + '"'
				+ ' data-target=".fusion-modal.descargar"'
				+ ' title="' + label + '"'
				+ ' aria-label="' + label + '">'
				+ '<i class="fas fa-file-pdf"></i>'
				+ '<span><strong>' + label + '</strong><em>Oficial ' + manufacturerName + ' Document</em></span>'
				+ '</a>';
		});
		$modal.find('#titulo-documentacion').text('Descarga de catálogos de ' + sku);
		var $docs = $modal.find('#lista-pdfs-dinamica');
		if ($docs.length) $docs.html(pdfHtml);
		$modal.find('#contenedor-documentacion-bloque').toggle(!!pdfHtml);

		var specsData = {};
		try { specsData = JSON.parse($btn.attr('data-specs-json') || '{}'); } catch (e) { }
		var specsHtml = '';
		$.each(specsData, function (key, value) {
			if (!value) return;
			specsHtml += '<tr><td class="aoe-spec-key">' + key + '</td><td class="aoe-spec-value">' + value + '</td></tr>';
		});
		var $specsList = $modal.find('#lista-specs-dinamica');
		if ($specsList.length) $specsList.html(specsHtml);
		$modal.find('#contenedor-specs-bloque').toggle(!!specsHtml);

		$('body').append($('#aoe-catalog-modal'));
		$('#aoe-catalog-modal').modal('show');
	});

	$(document).on('click', '.aoe-catalog-modal__close', function () {
		$('#aoe-catalog-modal').modal('hide');
	});

	function _getWidgetId($container) {
		var persisted = $container.data('awb-widget-id');
		if (persisted !== undefined) return persisted;
		var containerId = $container.attr('id');
		if (containerId && typeof active_captcha !== 'undefined' && active_captcha[containerId] !== undefined) {
			return active_captcha[containerId];
		}
		return undefined;
	}

	function aoeResetRecaptcha($modal) {
		if (typeof grecaptcha === 'undefined') return;
		$modal.find('.fusion-form-recaptcha-v2, .fusion-form-recaptcha-v3.recaptcha-container').each(function () {
			var $c = $(this);
			var wid = _getWidgetId($c);
			if (wid !== undefined) {
				grecaptcha.reset(wid);
			}
		});
	}

	function aoeRefreshRecaptcha($modal) {
		if (typeof grecaptcha === 'undefined') {
			console.warn('[AOE] grecaptcha not loaded');
			return;
		}
		setTimeout(function () {
			var $containers = $modal.find('.fusion-form-recaptcha-v2, .fusion-form-recaptcha-v3.recaptcha-container');
			$containers.each(function () {
				var $c = $(this);
				var id = $c.attr('id');
				var sitekey = $c.data('sitekey');
				if (!id || !sitekey) return;

				var wid = _getWidgetId($c);

				if (wid !== undefined) {
					grecaptcha.execute(wid, { action: 'contact_form' }).then(function (token) {
						$modal.find('#' + id).closest('.fusion-form-field').find('.g-recaptcha-response').val(token);
					});
					return;
				}

				wid = grecaptcha.render(id, {
					sitekey: sitekey,
					badge: $c.data('badge') || 'inline',
					size: 'invisible'
				});
				$c.data('awb-widget-id', wid);
				if (typeof active_captcha !== 'undefined') active_captcha[id] = wid;
				grecaptcha.execute(wid, { action: 'contact_form' }).then(function (token) {
					$modal.find('#' + id).closest('.fusion-form-field').find('.g-recaptcha-response').val(token);
				});
			});
		}, 800);
	}

	function aoeRefreshFormNonce($modal) {
		$modal.find('.fusion-form-builder').each(function () {
			var $wrapper = $(this);
			var formId = $wrapper.data('form-id');
			if (!formId) return;
			var $form = $wrapper.find('form.fusion-form');
			if (!$form.length) return;
			$form.find('input[name="fusion-form-nonce-' + formId + '"]').remove();
			if (window.fusionForms && typeof window.fusionForms.ajaxUpdateView === 'function') {
				window.fusionForms.ajaxUpdateView(this);
			}
		});
	}

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
		aoeRefreshFormNonce($modal);
		aoeRefreshRecaptcha($modal);
	});

	// Contact modal — direct handler with stopPropagation to block inline ejecutarInyeccionDefinitiva
	$('#btn-contacto-modal').on('click', function (e) {
		e.stopPropagation();

		var sku = $(this).attr('data-sku-link') || '';
		$('#modal-contacto-sku-info').text('Producto: ' + sku);
		$('#aoe-contacto-sku').val(sku);
		var title = $(this).attr('title') || '';
		$('.fusion-modal.modal-productos-formulario').data('contact-title', title);

		var $ta = $('.fusion-modal.modal-productos-formulario #quierodescargar textarea');
		if ($ta.length && title) {
			var count = 30;
			(function poll() {
				if ($ta.val() !== title) $ta.val(title);
				if (--count > 0) setTimeout(poll, 100);
			})();
		}

		aoeHideProduct();
		var $modal = $('.fusion-modal.modal-productos-formulario');
		aoeShowModal($modal);
		aoeRefreshFormNonce($modal);
		aoeRefreshRecaptcha($modal);
	});

	// Remove body class when no modals are open (restores z-index on rows)
	$(document).on('hidden.bs.modal', function () {
		if (!$('.modal.in').length) {
			$('body').removeClass('aoe-modal-open');
		}
	});

	// Reset reCAPTCHA when product modal closes (other modals handled below)
	$(document).on('hidden.bs.modal', '#aoe-catalog-modal', function () {
		aoeResetRecaptcha($(this));
	});

	// Return to product modal when download/contact close
	$(document).on('hidden.bs.modal', '.fusion-modal.descargar, .modal-productos-formulario', function () {
		aoeResetRecaptcha($(this));
		aoeShowProduct();
	});

	// Trigger PDF download after Avada form AJAX succeeds
	$(window).on('fusion-form-ajax-submit-done', function (event, data) {
		if (!data || !data.result || data.result.status !== 'success') return;
		var formId = data.formConfig ? data.formConfig.form_id : data.form_id;
		if (!formId) return;
		var $modal = $('.fusion-modal.descargar:visible');
		var $form = $modal.find('.fusion-form-' + formId);
		if ($form.length && aoeDownloadUrl) {
			window.open(aoeDownloadUrl, '_blank');
		}
	});

});
