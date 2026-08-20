<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function aoe_catalog_cta_message( $manufacturer, $category = '' ) {
	$manufacturer = trim( (string) $manufacturer );
	$category     = trim( (string) $category );

	if ( $category === '' || strtolower( $category ) === 'general' ) {
		return sprintf(
			'Quiero una referencia del catálogo de %s',
			$manufacturer
		);
	}

	return sprintf(
		'Quiero una referencia del catálogo de %s, categoría %s',
		$manufacturer,
		$category
	);
}

function aoe_catalog_cta_inline_style() {
	return '<style id="aoe-cta-inline-css">'
		. '.aoe-cta-wrap{margin:24px 0;text-align:center}'
		. 'button.aoe-cta-open,.aoe-cta-open{appearance:none;-webkit-appearance:none;min-width:440px;padding:10px 30px;border:0;border-radius:6px;background:#ee9b00;background-image:none;color:#fff;font-size:18px;font-weight:700;line-height:1.3;cursor:pointer;font-family:inherit}'
		. 'button.aoe-cta-open:hover,.aoe-cta-open:hover{background:#d88d00}'
		. '.aoe-cta-modal{position:fixed;inset:0;z-index:99999;display:none;align-items:center;justify-content:center;padding:20px}'
		. '.aoe-cta-modal.is-open{display:flex!important}'
		. '.aoe-cta-modal__backdrop{position:absolute;inset:0;background:rgba(0,0,0,.55)}'
		. '.aoe-cta-modal__box{position:relative;z-index:1;width:100%;max-width:720px;max-height:90vh;overflow:auto;padding:28px 32px 24px;border-radius:8px;background:#fff;box-shadow:0 12px 40px rgba(0,0,0,.25)}'
		. '.aoe-cta-modal__x{position:absolute;top:12px;right:16px;border:0;background:transparent;color:#999;font-size:28px;line-height:1;cursor:pointer}'
		. '.aoe-cta-modal__title{margin:0 40px 20px 0;font-size:24px;font-weight:700;color:#333}'
		. '.aoe-cta-modal textarea,.aoe-cta-modal textarea.fusion-form-input{color:#454343!important}'
		. '.aoe-cta-modal textarea::placeholder{color:#454343;opacity:.7}'
		. 'body.aoe-cta-modal-open{overflow:hidden}'
		. '@media(max-width:600px){button.aoe-cta-open,.aoe-cta-open{min-width:0;width:100%;font-size:16px}}'
		. '</style>';
}

function aoe_catalog_cta_inline_script() {
	return '<script id="aoe-cta-inline-js">(function(){'
		. 'function modal(){var m=document.getElementById("aoe-cta-modal");if(m&&m.parentNode!==document.body){document.body.appendChild(m);}return m;}'
		. 'function openM(msg){var m=modal();if(!m)return;m.classList.add("is-open");m.setAttribute("aria-hidden","false");document.body.classList.add("aoe-cta-modal-open");if(msg){var t=m.querySelector("textarea");if(t)t.value=msg;}}'
		. 'function closeM(){var m=modal();if(!m)return;m.classList.remove("is-open");m.setAttribute("aria-hidden","true");document.body.classList.remove("aoe-cta-modal-open");}'
		. 'document.addEventListener("click",function(e){var b=e.target.closest(".aoe-cta-open");if(b){e.preventDefault();openM(b.getAttribute("data-cta-message")||"");return;}if(e.target.closest("[data-aoe-cta-close]"))closeM();});'
		. 'document.addEventListener("keydown",function(e){if(e.key==="Escape")closeM();});'
		. 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",modal);}else{modal();}'
		. '})();</script>';
}

function aoe_catalog_cta_inject_inline( $html ) {
	if ( ! is_string( $html ) || $html === '' || strpos( $html, 'aoe-cta-open' ) === false ) {
		return $html;
	}
	$style = aoe_catalog_cta_inline_style();
	if ( strpos( $html, 'id="aoe-cta-inline-css"' ) !== false ) {
		$html = preg_replace( '/<style id="aoe-cta-inline-css">.*?<\/style>/is', $style, $html, 1 ) ?? $html;
	} else {
		$html = preg_replace( '/<\/head>/i', $style . "\n</head>", $html, 1 ) ?? $html;
	}
	if ( strpos( $html, 'id="aoe-cta-inline-js"' ) === false ) {
		$html = preg_replace( '/<\/body>/i', aoe_catalog_cta_inline_script() . "\n</body>", $html, 1 ) ?? $html;
	}
	return $html;
}

function aoe_catalog_cta_button( $manufacturer = '', $category = '' ) {
	$mensaje = aoe_catalog_cta_message( $manufacturer, $category );
	?>
	<div class="aoe-cta-wrap">
		<button type="button" class="aoe-cta-open" data-cta-message="<?php echo esc_attr( $mensaje ); ?>">
			¿No encuentras la referencia exacta? Te ayudamos
		</button>
	</div>
	<?php
}

function aoe_catalog_cta_modal( $manufacturer = '', $category = '' ) {
	static $printed = false;
	if ( $printed ) {
		return;
	}
	$printed = true;
	echo aoe_catalog_cta_inline_style();
	?>
	<div id="aoe-cta-modal" class="aoe-cta-modal" aria-hidden="true">
		<div class="aoe-cta-modal__backdrop" data-aoe-cta-close></div>
		<div class="aoe-cta-modal__box" role="dialog" aria-modal="true" aria-labelledby="aoe-cta-modal-title">
			<button type="button" class="aoe-cta-modal__x" data-aoe-cta-close aria-label="Cerrar">×</button>
			<h3 id="aoe-cta-modal-title" class="aoe-cta-modal__title">Pide más información</h3>
			<div class="aoe-cta-modal__form">
				<?php
				$form_id = ( strpos( home_url(), 'dev.tc-componentes.es' ) !== false ) ? 10487 : 11535;
				echo do_shortcode(
					'[fusion_form form_post_id="' . $form_id . '" hide_on_mobile="small-visibility,medium-visibility,large-visibility"][/fusion_form]'
				);
				?>
			</div>
		</div>
	</div>
	<?php
	echo aoe_catalog_cta_inline_script();
}
