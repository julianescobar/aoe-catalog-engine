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

function aoe_catalog_pdf_labels(): array {
	return [
		'datasheet'    => 'Datasheet',
		'print'        => 'Print',
		'footprint'    => 'Footprint',
		'catalog_page' => 'Catalog Page',
		'spec_sheet'   => 'Spec Sheet',
	];
}

function aoe_catalog_render_pdf_links( array $pdf ): string {
	$labels = aoe_catalog_pdf_labels();
	$html = '';
	foreach ( $pdf as $key => $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			continue;
		}
		$label = $labels[ $key ] ?? ucfirst( str_replace( '_', ' ', $key ) );
		$html .= '<a class="aoe-catalog-pdf" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a>';
	}
	return $html;
}

function aoe_catalog_render_pdf_icon_links( bool $has_specs = false, bool $has_image = true ): string {
	$html = '';
	if ( $has_image ) {
		$html .= '	<a class="abrir-modal-dinamico aoe-catalog-icon-link" href="#" aria-label="Ver imagen del producto"><i class="fas fa-image"></i></a>';
	}
	$html .= '<a class="abrir-modal-dinamico aoe-catalog-icon-link" href="#" aria-label="Ver documentos del producto"><i class="fas fa-file-pdf"></i></a>';
	if ( $has_specs ) {
		$html .= '<a class="abrir-modal-dinamico aoe-catalog-icon-link aoe-catalog-specs-icon" href="#" aria-label="Ver ficha tecnica"><i class="fas fa-clipboard-list"></i></a>';
	}
	return $html;
}

if ( ! defined( 'AOE_CATALOG_MEDIA_URL' ) ) {
	define( 'AOE_CATALOG_MEDIA_URL', content_url( 'uploads/catalogo' ) );
}

function aoe_catalog_get_upload_base(): string {
	$upload = wp_upload_dir();
	return trailingslashit( $upload['basedir'] ) . 'catalogo';
}

/**
 * Resolve media URL: prefers local files over remote URLs.
 * - If remote URL, extracts filename and checks local first (with .webp fallback for images)
 * - Falls back to original URL if local file doesn't exist
 */
function aoe_catalog_resolve_media_url( string $path, string $manufacturer_slug, string $type = 'images', string $media_source = 'local' ): string {
	if ( '' === $path ) {
		return '';
	}

	if ( 'remote' === $media_source ) {
		return $path;
	}

	$is_remote = ( strpos( $path, 'http' ) === 0 || strpos( $path, '//' ) === 0 );

	if ( $is_remote ) {
		$parsed  = parse_url( $path );
		$filename_raw = basename( $parsed['path'] ?? '' );
		if ( '' === $filename_raw ) {
			return $path;
		}
		// Try both decoded (new files) and raw (existing downloads with URL-encoded names)
		$filenames_to_try = [ urldecode( $filename_raw ), $filename_raw ];
		$base_dir = aoe_catalog_get_upload_base() . '/' . $manufacturer_slug . '/' . $type . '/';

		foreach ( $filenames_to_try as $filename ) {
			$filename_enc = rawurlencode( $filename );
			if ( 'images' === $type ) {
				$no_ext    = pathinfo( $filename, PATHINFO_FILENAME );
				$no_ext_enc = rawurlencode( $no_ext );
				$local_webp = $base_dir . $no_ext . '.webp';
				if ( file_exists( $local_webp ) ) {
					return trailingslashit( AOE_CATALOG_MEDIA_URL ) . $manufacturer_slug . '/images/' . $no_ext_enc . '.webp';
				}
			}
			$local_orig = $base_dir . $filename;
			if ( file_exists( $local_orig ) ) {
				return trailingslashit( AOE_CATALOG_MEDIA_URL ) . $manufacturer_slug . '/' . $type . '/' . $filename_enc;
			}
		}

		return $path;
	}

	// Local path already
	return trailingslashit( AOE_CATALOG_MEDIA_URL ) . $manufacturer_slug . '/' . $type . '/' . ltrim( $path, '/' );
}

function aoe_catalog_get_pdf_base(): string {
	return trailingslashit( AOE_CATALOG_PDF_DIR );
}

function aoe_catalog_resolve_pdf_urls( array $pdf, string $manufacturer_slug, string $media_source = 'local' ): array {
	if ( 'remote' === $media_source ) {
		return $pdf;
	}

	$resolved = [];
	foreach ( $pdf as $key => $url ) {
		if ( '' === $url ) {
			$resolved[ $key ] = '';
			continue;
		}

		$parsed  = parse_url( $url );
		$filename_raw = basename( $parsed['path'] ?? '' );
		if ( '' === $filename_raw ) {
			$resolved[ $key ] = $url;
			continue;
		}

		$filenames = [ urldecode( $filename_raw ), $filename_raw ];
		$base_pdf  = aoe_catalog_get_pdf_base() . '/' . $manufacturer_slug . '/';
		$base_old  = aoe_catalog_get_upload_base() . '/' . $manufacturer_slug . '/pdfs/';

		foreach ( $filenames as $fname ) {
			$fname_encoded = rawurlencode( urldecode( $fname ) );
			// 1. Firmado
			if ( file_exists( $base_pdf . $fname ) ) {
				$resolved[ $key ] = trailingslashit( AOE_CATALOG_PDF_URL ) . $manufacturer_slug . '/' . $fname_encoded;
				continue 2;
			}
			// 2. Original nuevo
			if ( file_exists( $base_pdf . 'originals/' . $fname ) ) {
				$resolved[ $key ] = trailingslashit( AOE_CATALOG_PDF_URL ) . $manufacturer_slug . '/originals/' . $fname_encoded;
				continue 2;
			}
			// 3. Original viejo (backward compat)
			if ( file_exists( $base_old . $fname ) ) {
				$resolved[ $key ] = trailingslashit( AOE_CATALOG_MEDIA_URL ) . $manufacturer_slug . '/pdfs/' . $fname_encoded;
				continue 2;
			}
		}

		$resolved[ $key ] = $url;
	}
	return $resolved;
}

function aoe_catalog_render_html( string $manufacturer_name, string $page_slug, string $category, array $page_products, int $current_page, int $total_pages, bool $is_preview = false, string $manufacturer_slug = '', array $grouped_segments = [], ?array $category_metadata = null, array $breadcrumb_path = [] ): string {
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
	if ( empty( $manufacturer_slug ) ) {
		$slug_parts = explode( '/', $page_slug );
		$manufacturer_slug = $slug_parts[0] ?? '';
		if ( strpos( $manufacturer_slug, 'test-' ) === 0 ) {
			$manufacturer_slug = substr( $manufacturer_slug, 5 );
			$ts_pos = strrpos( $manufacturer_slug, '-' );
			if ( $ts_pos !== false ) {
				$manufacturer_slug = substr( $manufacturer_slug, 0, $ts_pos );
			}
		}
	}

	// Get media_source from manufacturer config
	global $wpdb;
	$table_m = $wpdb->prefix . 'aoe_catalog_manufacturers';
	$mfr_config_json = $wpdb->get_var( $wpdb->prepare(
		"SELECT config_json FROM $table_m WHERE slug = %s", $manufacturer_slug
	) );
	$mfr_config = json_decode( $mfr_config_json ?? '', true ) ?: [];
	$media_source = $mfr_config['media_source'] ?? 'local';

	$first_images = array_map( function( $img ) use ( $manufacturer_slug, $media_source ) {
		return aoe_catalog_resolve_media_url( $img, $manufacturer_slug, 'images', $media_source );
	}, $first_images );
	$first_pdf = aoe_catalog_resolve_pdf_urls( $first_pdf, $manufacturer_slug, $media_source );

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
			.fusion-modal.descargar.in,
			.fusion-modal.descargar.show {
				z-index: 100001 !important;
			}
			.fusion-modal.descargar.in ~ .modal-backdrop,
			.fusion-modal.descargar.show ~ .modal-backdrop {
				z-index: 100000 !important;
			}
	</style>
	<div class="aoe-catalog-render">
		<header>
			<h2>Catálogo de <?php echo esc_html( ucfirst( $manufacturer_name ) ); ?><?php echo ! empty( $category_display_name ) ? ' ' . esc_html( $category_display_name ) : ''; ?></h2>
			<!--<p>Listado para <?php /*echo esc_html( $manufacturer_name );*/ ?>, pagina <?php /*echo $current_page;*/ ?> de <?php /*echo $total_pages;*/ ?>.</p>-->

			<div class="aoe-catalog-row">
				<div class="aoe-catalog-title">
						<p>Fabricante</p>
				</div>
				<div class="aoe-catalog-data">
						<a><a href="<?php echo esc_url( home_url( '/catalogo/' . $manufacturer_slug . '/' ) ); ?>" target="_blank"><?php echo esc_html( ucfirst( $manufacturer_name ) ); ?></a></a>
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

		<?php if ( null !== $category_metadata && apply_filters( 'aoe_catalog_show_series_info', false ) ) : ?>
		<div class="aoe-catalog-series-info">
			<?php if ( ! empty( $category_metadata['features'] ) ) : ?>
				<div class="aoe-series-features">
					<h3>Características</h3>
					<?php echo wp_kses_post( $category_metadata['features'] ); ?>
				</div>
			<?php endif; ?>
			<?php if ( ! empty( $category_metadata['specifications'] ) ) : ?>
				<div class="aoe-series-specs">
					<h3>Especificaciones</h3>
					<?php echo wp_kses_post( $category_metadata['specifications'] ); ?>
				</div>
			<?php endif; ?>
			<?php if ( ! empty( $category_metadata['highlights'] ) ) : ?>
				<div class="aoe-series-highlights">
					<h3>Destacados</h3>
					<?php echo wp_kses_post( $category_metadata['highlights'] ); ?>
				</div>
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<?php if ( ! empty( $grouped_segments ) ) : ?>
			<?php foreach ( $grouped_segments as $gseg ) : ?>
				<?php
				$path_parts  = $gseg['category_path'] ?? [];
				$seg_prods   = $gseg['products'] ?? [];
				?>
				<div class="aoe-catalog-group-section">
					<h3 class="aoe-cat-breadcrumb"><?php echo esc_html( implode( ' > ', $path_parts ) ); ?></h3>
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
							<?php foreach ( $seg_prods as $product ) : ?>
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
									$additional = (array) ( json_decode( $product->additional_data ?? '{}', true ) ?: [] );
									$specs     = $additional['specs'] ?? [];
								}
								$has_specs = ! empty( $specs );
								$images = array_map( function( $img ) use ( $manufacturer_slug, $media_source ) {
									return aoe_catalog_resolve_media_url( $img, $manufacturer_slug, 'images', $media_source );
								}, $images );
								$pdf = aoe_catalog_resolve_pdf_urls( $pdf, $manufacturer_slug, $media_source );
								$image_url       = aoe_catalog_get_first_value( $images );
								$product_description = 'Conector ' . $manufacturer_name . ' ' . $name;
								$pdf_json = htmlspecialchars( json_encode( $pdf ), ENT_QUOTES, 'UTF-8' );
								$specs_json = $has_specs ? htmlspecialchars( json_encode( $specs ), ENT_QUOTES, 'UTF-8' ) : '';
								?>
								<tr class="fila-producto no-lazyload" itemprop="itemListElement" itemscope itemtype="https://schema.org/Product"
									data-sku="<?php echo esc_attr( $sku ); ?>"
									data-nombre="<?php echo esc_attr( $name ); ?>"
									data-img="<?php echo esc_url( $image_url ); ?>"
									data-pdf-json="<?php echo $pdf_json; ?>"
									data-specs-json="<?php echo $specs_json; ?>">
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
									<td itemprop="brand" itemscope itemtype="https://schema.org/Brand"><span itemprop="name"><?php echo esc_html( ucfirst( $manufacturer_name ) ); ?></span></td>
									<td class="aoe-catalog-actions"><?php echo aoe_catalog_render_pdf_icon_links( $has_specs, ! empty( $image_url ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endforeach; ?>
		<?php else : ?>
		<?php if ( ! empty( $breadcrumb_path ) ) : ?>
			<h3 class="aoe-cat-breadcrumb"><?php echo esc_html( implode( ' > ', $breadcrumb_path ) ); ?></h3>
		<?php endif; ?>
		<?php if ( ! empty( $category_metadata['description'] ) ) : ?>
		<div class="aoe-series-description"><?php echo wp_kses_post( $category_metadata['description'] ); ?></div>
		<?php endif; ?>
		<?php if ( ! empty( $category_metadata['highlights'] ) ) : ?>
		<div class="aoe-series-highlights"><?php echo wp_kses_post( nl2br( str_replace( '\n', "\n", $category_metadata['highlights'] ) ) ); ?></div>
		<?php endif; ?>
		<?php if ( ! empty( $category_metadata['features'] ) ) : ?>
		<div class="aoe-series-features"><h4 style="font-weight: bold;">Características</h4>
			<ul><?php foreach ( explode( "\n", str_replace( '\n', "\n", $category_metadata['features'] ) ) as $feat ) : ?><?php $feat = trim( $feat ); if ( '' !== $feat ) : ?><li><?php echo esc_html( $feat ); ?></li><?php endif; ?><?php endforeach; ?></ul>
		</div>
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
						$specs     = [];
					} else {
						$sku       = (string) ( $product->sku ?? '' );
						$name      = (string) ( $product->name ?? '' );
						$images    = (array) ( json_decode( $product->urls_images ?? '[]', true ) ?: [] );
						$pdf       = (array) ( json_decode( $product->url_pdf ?? '[]', true ) ?: [] );
						$additional = (array) ( json_decode( $product->additional_data ?? '{}', true ) ?: [] );
						$specs     = $additional['specs'] ?? [];
					}
					$has_specs = ! empty( $specs );
					$images = array_map( function( $img ) use ( $manufacturer_slug, $media_source ) {
						return aoe_catalog_resolve_media_url( $img, $manufacturer_slug, 'images', $media_source );
					}, $images );
					$pdf = aoe_catalog_resolve_pdf_urls( $pdf, $manufacturer_slug, $media_source );
					$image_url       = aoe_catalog_get_first_value( $images );
					$product_description = 'Conector ' . $manufacturer_name . ' ' . $name;
					$pdf_json = htmlspecialchars( json_encode( $pdf ), ENT_QUOTES, 'UTF-8' );
					$specs_json = $has_specs ? htmlspecialchars( json_encode( $specs ), ENT_QUOTES, 'UTF-8' ) : '';
					?>
					<tr class="fila-producto no-lazyload" itemprop="itemListElement" itemscope itemtype="https://schema.org/Product"
						data-sku="<?php echo esc_attr( $sku ); ?>"
						data-nombre="<?php echo esc_attr( $name ); ?>"
						data-img="<?php echo esc_url( $image_url ); ?>"
						data-pdf-json="<?php echo $pdf_json; ?>"
						data-specs-json="<?php echo $specs_json; ?>">
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
						<td itemprop="brand" itemscope itemtype="https://schema.org/Brand"><span itemprop="name"><?php echo esc_html( ucfirst( $manufacturer_name ) ); ?></span></td>
						<td class="aoe-catalog-actions"><?php echo aoe_catalog_render_pdf_icon_links( $has_specs, ! empty( $image_url ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>

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
									<p class="aoe-catalog-manufacturer"><strong>Fabricante:</strong> <?php echo esc_html( ucfirst( $manufacturer_name ) ); ?></p>
									<div class="aoe-catalog-contact-wrap">
										<a id="btn-contacto-modal" class="fusion-button button-flat fusion-button-default-size button-default fusion-button-default btn-catalogo-generico aoe-catalog-contact-button" title="" href="#" data-toggle="modal" data-target=".fusion-modal.modal-productos-formulario" data-sku-link="">
											<span id="btn-texto-dinamico" class="fusion-button-text">Quiero más información</span>
										</a>
									</div>
									<p class="aoe-catalog-support-text">TC Componentes es proveedor industrial de este producto. Podemos facilitarte muestras, ayudarte en tu diseño y proporcionar un suministro estable al mejor precio.</p>
									<div id="contenedor-specs-bloque" class="aoe-catalog-specs" style="display:none;">
										<h3>Ficha Técnica</h3>
										<div class="aoe-catalog-specs-table-wrapper">
											<table class="aoe-catalog-specs-table">
												<tbody id="lista-specs-dinamica"></tbody>
											</table>
										</div>
									</div>
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

		<!--<div class="fusion-modal modal modal-productos-formulario aoe-catalog-modal" tabindex="-1" role="dialog" aria-hidden="true">
			<div class="modal-dialog modal-lg" role="document">
				<div class="modal-content fusion-modal-content">
					<div class="modal-header">
						<button class="close" type="button" data-dismiss="modal" aria-hidden="true">&times;</button>
						<h3 class="modal-title">Quiero más información</h3>
					</div>
					<div class="modal-body fusion-clearfix">
						<p id="modal-contacto-sku-info" class="aoe-catalog-contact-sku"></p>
						<?php
						// Reemplazar con el shortcode del formulario Avada:
						// echo do_shortcode( '[fusion_form form_post_id="XXX" /]' );
						?>
						<form id="aoe-contacto-form" class="aoe-catalog-form">
							<input type="hidden" name="sku" id="aoe-contacto-sku" value="">
							<div class="aoe-form-row">
								<label for="aoe-contacto-nombre">Nombre *</label>
								<input type="text" name="nombre" id="aoe-contacto-nombre" required>
							</div>
							<div class="aoe-form-row">
								<label for="aoe-contacto-email">Email *</label>
								<input type="email" name="email" id="aoe-contacto-email" required>
							</div>
							<div class="aoe-form-row">
								<label for="aoe-contacto-telefono">Teléfono</label>
								<input type="tel" name="telefono" id="aoe-contacto-telefono">
							</div>
							<div class="aoe-form-row">
								<label for="aoe-contacto-mensaje">Mensaje *</label>
								<textarea name="mensaje" id="aoe-contacto-mensaje" rows="4" required></textarea>
							</div>
							<div class="aoe-form-row">
								<button type="submit" class="fusion-button button-flat fusion-button-default-size button-default fusion-button-default">Enviar</button>
							</div>
						</form>
					</div>
				</div>
			</div>
		</div>-->
	</div>
	<?php
	return ob_get_clean();
}
