<?php
/**
 * Virtual preview catalog template.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$preview_slug = get_query_var( 'aoe_catalog_preview' );
$preview_data = get_transient( 'aoe_preview_' . $preview_slug );

if ( ! is_array( $preview_data ) ) {
	global $wp_query;
	$wp_query->set_404();
	status_header( 404 );
	get_template_part( '404' );
	exit;
}

$current_page = max( 1, intval( get_query_var( 'aoe_catalog_page', 1 ) ) );

$all_products  = is_array( $preview_data['products'] ?? null ) ? $preview_data['products'] : [];
$total_pages   = max( 1, ceil( count( $all_products ) / 200 ) );
$current_page  = min( $current_page, $total_pages );
$offset        = ( $current_page - 1 ) * 200;
$page_products = array_slice( $all_products, $offset, 200 );

// Get category name from first product or from payload
$first         = $page_products[0] ?? $all_products[0] ?? [];
$category      = ! empty( $first['category'] ) ? $first['category'] : ( $preview_data['first_category'] ?? $preview_data['category'] ?? 'Catalogo' );

$template_post_id = intval( $preview_data['template_post_id'] ?? 0 );
$template_post    = $template_post_id ? get_post( $template_post_id ) : null;

if ( ! $template_post ) {
	wp_die( 'La plantilla asociada a este fabricante no existe.', 'Plantilla no encontrada', [ 'response' => 404 ] );
}

$catalog_css_path = dirname( dirname( dirname( __DIR__ ) ) ) . '/assets/css/catalog-render.css';

wp_enqueue_style(
	'aoe-catalog-render',
	plugin_dir_url( dirname( dirname( dirname( __DIR__ ) ) ) . '/aoe-catalog-engine.php' ) . 'assets/css/catalog-render.css',
	[],
	file_exists( $catalog_css_path ) ? filemtime( $catalog_css_path ) : '1.0.0'
);

function aoe_preview_get_first_value( array $values ): string {
	foreach ( $values as $value ) {
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			return trim( $value );
		}
	}

	return '';
}

function aoe_preview_render_pdf_links( array $pdf ): string {
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

function aoe_preview_render_pdf_icon_links( array $pdf ): string {
	$first_pdf = aoe_preview_get_first_value( [
		$pdf['print'] ?? '',
		$pdf['spec_sheet'] ?? '',
		$pdf['footprint'] ?? '',
		$pdf['catalog_page'] ?? '',
	] );

	return '<a class="abrir-modal-dinamico aoe-catalog-icon-link" data-toggle="modal" data-target=".fusion-modal.modal-productos" href="#" aria-label="Ver imagen del producto"><i class="fas fa-image"></i></a>'
		. '<a class="abrir-modal-dinamico aoe-catalog-icon-link" data-toggle="modal" data-target=".fusion-modal.modal-productos" href="' . esc_url( $first_pdf ? $first_pdf : '#' ) . '" aria-label="Ver documentos del producto"><i class="fas fa-file-pdf"></i></a>';
}

function aoe_preview_render_catalog_html( array $preview_data, string $category, array $page_products, int $current_page, int $total_pages ): string {
	$manufacturer = (string) ( $preview_data['manufacturer_name'] ?? '' );
	$first        = $page_products[0] ?? [];
	$family_image = aoe_preview_get_first_value( is_array( $first['images'] ?? null ) ? $first['images'] : [] );
	$family_pdf   = is_array( $first['pdf'] ?? null ) ? $first['pdf'] : [];
	$category_display_name = ! empty( $first['name'] ) ? (string) $first['name'] : $category;

	ob_start();
	?>
	<div class="aoe-catalog-render">
		<header>
			<h2>Catálogo de <?= $manufacturer; ?> <?php echo esc_html( $category_display_name ); ?></h2>
			<p>Listado de prueba para <?php echo esc_html( $manufacturer ); ?>, pagina <?php echo $current_page; ?> de <?php echo $total_pages; ?>.</p>

			<div class="aoe-catalog-row">
				<div class="aoe-catalog-title">
						<p>Fabricante</p>
				</div>
				<div class="aoe-catalog-data">
						<a><a href="<?= site_url()."/".strtolower($manufacturer)?>" target="_blank"><?php echo esc_html( $manufacturer ); ?></a></a>
				</div>
			</div>
			<!--<div class="aoe-catalog-assets">
				<?php /* if ( $family_image ) : ?>
					<img src="<?php echo esc_url( $family_image ); ?>" alt="<?php echo esc_attr( $category ); ?>" />
				<?php endif; */ ?>
				<div><?php /* echo aoe_preview_render_pdf_links( $family_pdf );*/ ?></div>
			</div>-->
		</header>

		<nav class="aoe-catalog-pagination" aria-label="Paginacion de productos">
			<span class="aoe-catalog-bold">Ir a la pagina:</span>
			<?php for ( $i = 1; $i <= $total_pages; $i++ ) : ?>
				<?php
				$page_url = ( $i === 1 )
					? home_url( '/catalogo/' . $preview_data['test_slug'] . '/' . sanitize_title( $category ) . '/' )
					: home_url( '/catalogo/' . $preview_data['test_slug'] . '/' . sanitize_title( $category ) . '-' . $i . '/' );
				?>
				<?php if ( $i === $current_page ) : ?>
					<span class="aoe-catalog-page-link current"><?php echo $i; ?></span>
				<?php else : ?>
					<a class="aoe-catalog-page-link" href="<?php echo esc_url( $page_url ); ?>"><?php echo $i; ?></a>
				<?php endif; ?>
			<?php endfor; ?>
		</nav>

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
					$sku       = (string) ( $product['sku'] ?? '' );
					$name      = (string) ( $product['name'] ?? '' );
					$images    = is_array( $product['images'] ?? null ) ? $product['images'] : [];
					$pdf       = is_array( $product['pdf'] ?? null ) ? $product['pdf'] : [];
					$image_url = aoe_preview_get_first_value( $images );
					$product_description = 'Conector ' . $manufacturer . ' ' . $name;
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
							<a class="aoe-catalog-sku" data-toggle="modal" data-target=".fusion-modal.modal-productos" href="#">
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
						<td itemprop="brand" itemscope itemtype="https://schema.org/Brand"><span itemprop="name"><?php echo esc_html( $manufacturer ); ?></span></td>
						<td class="aoe-catalog-actions"><?php echo aoe_preview_render_pdf_icon_links( $pdf ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<div class="fusion-modal modal fade modal-productos aoe-catalog-modal" id="aoe-catalog-modal" aria-hidden="true">
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
									<p class="aoe-catalog-manufacturer"><strong>Fabricante:</strong> <?php echo esc_html( $manufacturer ); ?></p>
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
			var skuTarget = event.target.closest('.abrir-modal-dinamico');
			var closeTarget = event.target.closest('.aoe-catalog-modal__close');
			var modal = document.getElementById('aoe-catalog-modal');

			if (!modal) return;

			if (closeTarget || event.target === modal) {
				modal.classList.remove('is-open');
				modal.setAttribute('aria-hidden', 'true');
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
					+ '<span><strong>' + item[0] + '</strong><em>Oficial <?php echo esc_js( $manufacturer ); ?> Document</em></span>'
					+ '</a>';
			});

			document.getElementById('modal-heading-1').textContent = sku;
			document.getElementById('modal-sku-titulo').textContent = sku;
			document.getElementById('modal-nombre-subtitulo').textContent = name;
			document.getElementById('modal-img-producto').src = image;
			document.getElementById('modal-img-producto').alt = sku;
			document.getElementById('titulo-documentacion').textContent = 'Descarga de catálogos de ' + sku;
			document.getElementById('btn-contacto-modal').setAttribute('title', 'Quiero más información sobre <?php echo esc_js( $manufacturer ); ?> ' + sku);
			document.getElementById('btn-contacto-modal').setAttribute('data-sku-link', sku);
			if (docsContainer) {
				docsContainer.innerHTML = pdfHtml;
			}
			if (docsBlock) {
				docsBlock.hidden = !pdfHtml;
			}

			modal.classList.add('is-open');
			modal.setAttribute('aria-hidden', 'false');
		});
	</script>
	<?php
	return ob_get_clean();
}

global $post;
$post = $template_post;
setup_postdata( $post );

$catalog_html = aoe_preview_render_catalog_html( $preview_data, $category, $page_products, $current_page, $total_pages );
$content      = apply_filters( 'the_content', $template_post->post_content );
$content      = str_replace( [ '<p>[catalogo]</p>', '[catalogo]' ], $catalog_html, $content );

get_header();
echo $content;
wp_reset_postdata();
get_footer();
