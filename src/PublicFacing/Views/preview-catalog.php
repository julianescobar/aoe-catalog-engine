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

$template_post_id = intval( $preview_data['template_post_id'] ?? 0 );
$template_post    = $template_post_id ? get_post( $template_post_id ) : null;

if ( ! $template_post ) {
	wp_die( 'La plantilla asociada a este fabricante no existe.', 'Plantilla no encontrada', [ 'response' => 404 ] );
}

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

		$html .= '<a class="aoe-preview-pdf" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a>';
	}

	return $html;
}

function aoe_preview_render_catalog_html( array $preview_data ): string {
	$manufacturer = (string) ( $preview_data['manufacturer_name'] ?? '' );
	$category     = (string) ( $preview_data['category'] ?? '' );
	$products     = is_array( $preview_data['products'] ?? null ) ? $preview_data['products'] : [];
	$first        = $products[0] ?? [];
	$family_image = aoe_preview_get_first_value( is_array( $first['images'] ?? null ) ? $first['images'] : [] );
	$family_pdf   = is_array( $first['pdf'] ?? null ) ? $first['pdf'] : [];

	ob_start();
	?>
	<style>
		.aoe-preview-catalog h2 { font-size: 18px; line-height: 1.35; margin: 0 0 10px; }
		.aoe-preview-catalog p { margin: 0 0 14px; }
		.aoe-preview-assets { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; margin: 0 0 18px; }
		.aoe-preview-assets img { max-width: 120px; height: auto; border: 1px solid #ddd; }
		.aoe-preview-pdf, .aoe-preview-page-link { display: inline-block; border: 1px solid #d5d5d5; border-radius: 4px; padding: 4px 8px; margin: 0 6px 6px 0; text-decoration: none; }
		.aoe-preview-pagination { margin: 0 0 14px; }
		.aoe-preview-table { width: 100%; border-collapse: collapse; font-size: 14px; }
		.aoe-preview-table th, .aoe-preview-table td { border: 1px solid #ddd; padding: 8px; text-align: left; vertical-align: top; }
		.aoe-preview-table th { background: #f7f7f7; }
		.aoe-preview-table th.aoe-preview-underlined { text-decoration: underline; }
		.aoe-preview-sku { color: #005ea8; cursor: pointer; text-decoration: underline; }
		.aoe-preview-modal { display: none; position: fixed; z-index: 99999; inset: 0; background: rgba(0, 0, 0, 0.55); align-items: center; justify-content: center; padding: 20px; }
		.aoe-preview-modal.is-open { display: flex; }
		.aoe-preview-modal__inner { background: #fff; max-width: 620px; width: 100%; padding: 22px; position: relative; }
		.aoe-preview-modal__close { position: absolute; top: 8px; right: 12px; border: 0; background: transparent; font-size: 26px; line-height: 1; cursor: pointer; }
		.aoe-preview-modal img { max-width: 220px; height: auto; display: block; margin: 12px 0; }
		.aoe-preview-lead-form { margin-top: 16px; padding-top: 14px; border-top: 1px solid #ddd; }
		.aoe-preview-lead-form input, .aoe-preview-lead-form button { max-width: 100%; }
	</style>

	<div class="aoe-preview-catalog">
		<header>
			<h2><?php echo esc_html( $category ); ?></h2>
			<p>Listado de prueba para <?php echo esc_html( $manufacturer ); ?>, generado con un maximo de 200 productos del primer modelo detectado.</p>
			<div class="aoe-preview-assets">
				<?php if ( $family_image ) : ?>
					<img src="<?php echo esc_url( $family_image ); ?>" alt="<?php echo esc_attr( $category ); ?>" />
				<?php endif; ?>
				<div><?php echo aoe_preview_render_pdf_links( $family_pdf ); ?></div>
			</div>
		</header>

		<nav class="aoe-preview-pagination" aria-label="Paginacion de productos">
			<span>Ir a la pagina:</span>
			<a class="aoe-preview-page-link" href="<?php echo esc_url( home_url( '/catalogo/' . ( $preview_data['test_slug'] ?? '' ) . '/' . sanitize_title( $category ) . '/' ) ); ?>">1</a>
		</nav>

		<table class="aoe-preview-table" itemscope itemtype="https://schema.org/ItemList">
			<thead>
				<tr>
					<th>Codigo</th>
					<th class="aoe-preview-underlined">Nombre</th>
					<th class="aoe-preview-underlined">Fabricante</th>
					<th>PDFs</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $products as $product ) : ?>
					<?php
					$sku       = (string) ( $product['sku'] ?? '' );
					$name      = (string) ( $product['name'] ?? '' );
					$images    = is_array( $product['images'] ?? null ) ? $product['images'] : [];
					$pdf       = is_array( $product['pdf'] ?? null ) ? $product['pdf'] : [];
					$image_url = aoe_preview_get_first_value( $images );
					?>
					<tr itemprop="itemListElement" itemscope itemtype="https://schema.org/Product"
						data-sku="<?php echo esc_attr( $sku ); ?>"
						data-name="<?php echo esc_attr( $name ); ?>"
						data-image="<?php echo esc_url( $image_url ); ?>"
						data-print="<?php echo esc_url( $pdf['print'] ?? '' ); ?>"
						data-footprint="<?php echo esc_url( $pdf['footprint'] ?? '' ); ?>"
						data-catalog-page="<?php echo esc_url( $pdf['catalog_page'] ?? '' ); ?>"
						data-spec-sheet="<?php echo esc_url( $pdf['spec_sheet'] ?? '' ); ?>">
						<td><span class="aoe-preview-sku" itemprop="sku"><?php echo esc_html( $sku ); ?></span></td>
						<td><span itemprop="name"><?php echo esc_html( $name ); ?></span></td>
						<td itemprop="brand" itemscope itemtype="https://schema.org/Brand"><span itemprop="name"><?php echo esc_html( $manufacturer ); ?></span></td>
						<td><?php echo aoe_preview_render_pdf_links( $pdf ); ?></td>
						<td hidden itemprop="offers" itemscope itemtype="https://schema.org/Offer"><link itemprop="availability" href="https://schema.org/InStock" /></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<div class="aoe-preview-modal" id="aoe-preview-modal" aria-hidden="true">
			<div class="aoe-preview-modal__inner" role="dialog" aria-modal="true" aria-labelledby="aoe-preview-modal-title">
				<button class="aoe-preview-modal__close" type="button" aria-label="Cerrar">&times;</button>
				<h3 id="aoe-preview-modal-title"></h3>
				<img id="aoe-preview-modal-image" src="" alt="" />
				<div id="aoe-preview-modal-pdfs"></div>
				<form class="aoe-preview-lead-form">
					<input type="hidden" name="sku" id="aoe-preview-lead-sku" value="" />
					<input type="hidden" name="pdf" id="aoe-preview-lead-pdf" value="" />
					<label>Correo electronico<br /><input type="email" name="email" /></label>
					<button type="button">Solicitar informacion</button>
				</form>
			</div>
		</div>
	</div>

	<script>
		document.addEventListener('click', function(event) {
			var skuTarget = event.target.closest('.aoe-preview-sku');
			var closeTarget = event.target.closest('.aoe-preview-modal__close');
			var modal = document.getElementById('aoe-preview-modal');

			if (!modal) return;

			if (closeTarget || event.target === modal) {
				modal.classList.remove('is-open');
				modal.setAttribute('aria-hidden', 'true');
				return;
			}

			if (!skuTarget) return;

			var row = skuTarget.closest('tr');
			var sku = row.getAttribute('data-sku') || '';
			var name = row.getAttribute('data-name') || '';
			var image = row.getAttribute('data-image') || '';
			var pdfs = [
				['Print', row.getAttribute('data-print') || ''],
				['Footprint', row.getAttribute('data-footprint') || ''],
				['Catalog Page', row.getAttribute('data-catalog-page') || ''],
				['Spec Sheet', row.getAttribute('data-spec-sheet') || '']
			];
			var pdfHtml = '';
			var firstPdf = '';

			pdfs.forEach(function(item) {
				if (!item[1]) return;
				if (!firstPdf) firstPdf = item[1];
				pdfHtml += '<a class="aoe-preview-pdf" href="' + item[1] + '" target="_blank" rel="noopener">' + item[0] + '</a>';
			});

			document.getElementById('aoe-preview-modal-title').textContent = sku + ' - ' + name;
			document.getElementById('aoe-preview-modal-image').src = image;
			document.getElementById('aoe-preview-modal-image').alt = sku;
			document.getElementById('aoe-preview-modal-pdfs').innerHTML = pdfHtml;
			document.getElementById('aoe-preview-lead-sku').value = sku;
			document.getElementById('aoe-preview-lead-pdf').value = firstPdf;

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

$catalog_html = aoe_preview_render_catalog_html( $preview_data );
$content      = apply_filters( 'the_content', $template_post->post_content );
$content      = str_replace( [ '<p>[catalogo]</p>', '[catalogo]' ], $catalog_html, $content );

get_header();
echo $content;
wp_reset_postdata();
get_footer();
