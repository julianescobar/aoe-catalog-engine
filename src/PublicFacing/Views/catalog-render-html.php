<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function aoe_catalog_get_first_value( array $values ): string {
	foreach ( $values as $value ) {
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			return trim( $value );
		}
	}
	return '';
}

function aoe_catalog_render_pdf_links( array $pdf ): string {
	$labels = [
		'print'        => 'Print',
		'footprint'    => 'Footprint',
		'catalog_page' => 'Catalog Page',
		'spec_sheet'   => 'Spec Sheet',
	];
	$html = '';
	foreach ( $labels as $key => $label ) {
		$url = isset( $pdf[ $key ] ) ? trim( (string) $pdf[ $key ] ) : '';
		if ( '' === $url ) {
			continue;
		}
		$html .= '<a class="aoe-catalog-pdf" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a>';
	}
	return $html;
}

function aoe_catalog_render_pdf_icon_links( array $pdf ): string {
	$first_pdf = aoe_catalog_get_first_value( [
		$pdf['print'] ?? '',
		$pdf['spec_sheet'] ?? '',
		$pdf['footprint'] ?? '',
		$pdf['catalog_page'] ?? '',
	] );
	return '	<a class="abrir-modal-dinamico aoe-catalog-icon-link" href="#" aria-label="Ver imagen del producto"><i class="fas fa-image"></i></a>'
		. '<a class="abrir-modal-dinamico aoe-catalog-icon-link" href="' . esc_url( $first_pdf ? $first_pdf : '#' ) . '" aria-label="Ver documentos del producto"><i class="fas fa-file-pdf"></i></a>';
}

function aoe_catalog_render_html( string $manufacturer_name, string $page_slug, string $category, array $page_products, int $current_page, int $total_pages, bool $is_preview = false ): string {
	$first        = $page_products[0] ?? null;
	if ( $is_preview ) {
		$first_images = is_array( $first['images'] ?? null ) ? $first['images'] : [];
		$first_pdf    = is_array( $first['pdf'] ?? null ) ? $first['pdf'] : [];
		$first_name   = (string) ( $first['name'] ?? '' );
	} else {
		$first_images = $first ? (array) ( json_decode( $first->urls_images ?? '[]', true ) ?: [] ) : [];
		$first_pdf    = $first ? (array) ( json_decode( $first->url_pdf ?? '[]', true ) ?: [] ) : [];
		$first_name   = $first ? (string) ( $first->name ?? '' ) : '';
	}
	$family_image = aoe_catalog_get_first_value( $first_images );
	$family_pdf   = $first_pdf;
	$category_display_name = $category;

	ob_start();
	?>
	<style>
			.aoe-catalog-modal.in .modal-dialog,
			.aoe-catalog-modal.show .modal-dialog {
				position: absolute;
				top: 50%;
				left: 50%;
				transform: translate(-50%, -50%) !important;
				margin: 0;
				max-height: 90vh;
				overflow-y: auto;
			}
	</style>
	<div class="aoe-catalog-render">
		<header>
			<h2>Catálogo de <?php echo esc_html( $manufacturer_name ); ?><?php echo ! empty( $category_display_name ) ? ' ' . esc_html( $category_display_name ) : ''; ?></h2>
			<p>Listado para <?php echo esc_html( $manufacturer_name ); ?>, pagina <?php echo $current_page; ?> de <?php echo $total_pages; ?>.</p>

			<div class="aoe-catalog-row">
				<div class="aoe-catalog-title">
						<p>Fabricante</p>
				</div>
				<div class="aoe-catalog-data">
						<a><a href="<?php echo site_url() . '/' . strtolower( $manufacturer_name ); ?>" target="_blank"><?php echo esc_html( $manufacturer_name ); ?></a></a>
				</div>
			</div>
		</header>

		<?php if ( $total_pages > 1 ) : ?>
		<nav class="aoe-catalog-pagination" aria-label="Paginacion de productos">
			<span class="aoe-catalog-bold">Ir a la pagina:</span>
			<?php for ( $i = 1; $i <= $total_pages; $i++ ) : ?>
				<?php
				$page_url = ( $i === 1 )
					? home_url( '/catalogo/' . $page_slug . '/' )
					: home_url( '/catalogo/' . $page_slug . '-' . $i . '/' );
				?>
				<?php if ( $i === $current_page ) : ?>
					<span class="aoe-catalog-page-link current"><?php echo $i; ?></span>
				<?php else : ?>
					<a class="aoe-catalog-page-link" href="<?php echo esc_url( $page_url ); ?>"><?php echo $i; ?></a>
				<?php endif; ?>
			<?php endfor; ?>
		</nav>
		<?php endif; ?>

		<table class="aoe-catalog-table" itemscope itemtype="https://schema.org/ItemList">
			<thead>
				<tr>
					<th>Codigo</th>
					<th class="aoe-catalog-underlined">Nombre</th>
					<th class="aoe-catalog-underlined">Fabricante</th>
					<th>PDFs</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $page_products as $product ) : ?>
					<?php
					if ( $is_preview ) {
						$sku       = (string) ( $product['sku'] ?? '' );
						$name      = (string) ( $product['name'] ?? '' );
						$images    = is_array( $product['images'] ?? null ) ? $product['images'] : [];
						$pdf       = is_array( $product['pdf'] ?? null ) ? $product['pdf'] : [];
					} else {
						$sku       = (string) ( $product->sku ?? '' );
						$name      = (string) ( $product->name ?? '' );
						$images    = (array) ( json_decode( $product->urls_images ?? '[]', true ) ?: [] );
						$pdf       = (array) ( json_decode( $product->url_pdf ?? '[]', true ) ?: [] );
					}
					$image_url       = aoe_catalog_get_first_value( $images );
					$product_description = 'Conector ' . $manufacturer_name . ' ' . $name;
					?>
					<tr class="fila-producto no-lazyload" itemprop="itemListElement" itemscope itemtype="https://schema.org/Product"
						data-sku="<?php echo esc_attr( $sku ); ?>"
						data-nombre="<?php echo esc_attr( $name ); ?>"
						data-img="<?php echo esc_url( $image_url ); ?>"
						data-pdf-print="<?php echo esc_url( $pdf['print'] ?? '' ); ?>"
						data-pdf-foot="<?php echo esc_url( $pdf['footprint'] ?? '' ); ?>"
						data-pdf-cat="<?php echo esc_url( $pdf['catalog_page'] ?? '' ); ?>"
						data-pdf-spec="<?php echo esc_url( $pdf['spec_sheet'] ?? '' ); ?>">
						<td>
							<a class="aoe-catalog-sku" href="#">
								<span itemprop="sku"><?php echo esc_html( $sku ); ?></span>
							</a>
						</td>
						<td>
							<span itemprop="name"><?php echo esc_html( $name ); ?></span>
							<?php if ( ! empty( $image_url ) ) : ?>
								<meta itemprop="image" content="<?php echo esc_url( $image_url ); ?>" class="no-lazyload">
							<?php endif; ?>
							<meta itemprop="description" content="<?php echo esc_attr( $product_description ); ?>">
							<div itemprop="offers" itemscope itemtype="https://schema.org/Offer" hidden>
								<link itemprop="availability" href="https://schema.org/InStock">
							</div>
						</td>
						<td itemprop="brand" itemscope itemtype="https://schema.org/Brand"><span itemprop="name"><?php echo esc_html( $manufacturer_name ); ?></span></td>
						<td class="aoe-catalog-actions"><?php echo aoe_catalog_render_pdf_icon_links( $pdf ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<div class="fusion-modal modal modal-productos aoe-catalog-modal" id="aoe-catalog-modal" aria-hidden="true">
			<div class="modal-dialog modal-lg" role="document">
				<div class="modal-content fusion-modal-content">
					<div class="modal-header">
						<button class="close aoe-catalog-modal__close" type="button" data-dismiss="modal" aria-hidden="true" aria-label="Close">&times;</button>
						<h3 class="modal-title" id="modal-heading-1" data-dismiss="modal" aria-hidden="true"></h3>
					</div>
					<div class="modal-body fusion-clearfix">
						<div id="ficha-producto-modal" class="aoe-catalog-product-card">
							<div class="aoe-catalog-product-summary">
								<div class="aoe-catalog-product-image-wrap">
									<img data-skip-lazy="1" data-smush-skip="true" loading="eager" id="modal-img-producto" class="no-lazyload" src="" alt="">
								</div>
								<div class="aoe-catalog-product-info">
									<h2 id="modal-sku-titulo"></h2>
									<p id="modal-nombre-subtitulo"></p>
									<p class="aoe-catalog-manufacturer"><strong>Fabricante:</strong> <?php echo esc_html( $manufacturer_name ); ?></p>
									<div class="aoe-catalog-contact-wrap">
										<a id="btn-contacto-modal" class="fusion-button button-flat fusion-button-default-size button-default fusion-button-default btn-catalogo-generico aoe-catalog-contact-button" title="" href="#" data-toggle="modal" data-target=".fusion-modal.modal-productos-formulario" data-sku-link="">
											<span id="btn-texto-dinamique" class="fusion-button-text">Quiero más información</span>
										</a>
									</div>
									<p class="aoe-catalog-support-text">TC Componentes es proveedor industrial de este producto. Podemos facilitarte muestras, ayudarte en tu diseño y proporcionar un suministro estable al mejor precio.</p>
								</div>
							</div>
							<div id="contenedor-documentacion-bloque" class="aoe-catalog-docs">
								<h3 id="titulo-documentacion">Descarga de catálogos</h3>
								<div id="lista-pdfs-dinamica" class="aoe-catalog-docs-grid"></div>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<script>
		document.addEventListener('click', function(event) {
			var skuTarget = event.target.closest('.abrir-modal-dinamico, .fila-producto');
			var closeTarget = event.target.closest('.aoe-catalog-modal__close');
			var modal = document.getElementById('aoe-catalog-modal');

			if (!modal) return;

			if (closeTarget || event.target === modal) {
				if (typeof jQuery !== 'undefined') {
					jQuery(modal).modal('hide');
				}
				return;
			}

			if (!skuTarget) return;

			var row = skuTarget.closest('tr');
			var sku = row.getAttribute('data-sku') || '';
			var name = row.getAttribute('data-nombre') || '';
			var image = row.getAttribute('data-img') || '';
			var pdfs = [
				['Print', row.getAttribute('data-pdf-print') || ''],
				['Footprint', row.getAttribute('data-pdf-foot') || ''],
				['Catalog Page', row.getAttribute('data-pdf-cat') || ''],
				['Spec Sheet', row.getAttribute('data-pdf-spec') || '']
			];
			var pdfHtml = '';
			var firstPdf = '';
			var docsContainer = document.getElementById('lista-pdfs-dinamica');
			var docsBlock = document.getElementById('contenedor-documentacion-bloque');

			pdfs.forEach(function(item) {
				if (!item[1]) return;
				if (!firstPdf) firstPdf = item[1];
				pdfHtml += '<a class="aoe-catalog-doc-card" href="' + item[1] + '" target="_blank" rel="noopener">'
					+ '<i class="fas fa-file-pdf"></i>'
					+ '<span><strong>' + item[0] + '</strong><em>Oficial <?php echo esc_js( $manufacturer_name ); ?> Document</em></span>'
					+ '</a>';
			});

			document.getElementById('modal-heading-1').textContent = sku;
			document.getElementById('modal-sku-titulo').textContent = sku;
			document.getElementById('modal-nombre-subtitulo').textContent = name;
			document.getElementById('modal-img-producto').src = image;
			document.getElementById('modal-img-producto').alt = sku;
			document.getElementById('titulo-documentacion').textContent = 'Descarga de catálogos de ' + sku;
			document.getElementById('btn-contacto-modal').setAttribute('title', 'Quiero más información sobre <?php echo esc_js( $manufacturer_name ); ?> ' + sku);
			document.getElementById('btn-contacto-modal').setAttribute('data-sku-link', sku);
			if (docsContainer) {
				docsContainer.innerHTML = pdfHtml;
			}
			if (docsBlock) {
				docsBlock.hidden = !pdfHtml;
			}

			if (typeof jQuery !== 'undefined') {
				jQuery(modal).modal('show');
			}
		});
	</script>
	<?php
	return ob_get_clean();
}
