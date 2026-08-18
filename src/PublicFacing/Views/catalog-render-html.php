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
	foreach ( $pdf as $key => $val ) {
		$url = is_array( $val ) ? trim( $val['url'] ?? '' ) : trim( (string) $val );
		if ( '' === $url ) {
			continue;
		}
		$label = $labels[ $key ] ?? ucfirst( str_replace( '_', ' ', $key ) );
		$html .= '<a class="aoe-catalog-pdf" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a>';
	}
	return $html;
}

function aoe_catalog_render_pdf_icon_links( bool $has_pdf = false, bool $has_specs = false, bool $has_image = true ): string {
	$html = '';
	if ( $has_image ) {
		$html .= '	<a class="abrir-modal-dinamico aoe-catalog-icon-link" href="#" aria-label="Ver imagen del producto"><i class="fas fa-image"></i></a>';
	}
	if ( $has_pdf ) {
		$html .= '<a class="abrir-modal-dinamico aoe-catalog-icon-link" href="#" aria-label="Ver documentos del producto"><i class="fas fa-file-pdf"></i></a>';
	}
	if ( $has_specs ) {
		$html .= '<a class="abrir-modal-dinamico aoe-catalog-icon-link aoe-catalog-specs-icon" href="#" aria-label="Ver ficha tecnica"><i class="fas fa-clipboard-list"></i></a>';
	}
	return $html;
}

if ( ! defined( 'AOE_CATALOG_MEDIA_URL' ) ) {
	define( 'AOE_CATALOG_MEDIA_URL', content_url( 'uploads/catalogo' ) );
}

if ( ! function_exists( 'aoe_catalog_bullets_to_html' ) ) {
	function aoe_catalog_bullets_to_html( string $desc ): string {
		if ( '' === $desc ) {
			return '';
		}
		if ( false !== strpos( $desc, "\xE2\x80\xA2" ) ) {
			$items = preg_split( '/\s*\x{2022}\s*/u', $desc );
			$items = array_values( array_filter( array_map( 'trim', $items ), static function ( $i ) { return '' !== $i; } ) );
			if ( count( $items ) >= 2 ) {
				$html = '';
				$first = $items[0];
				if ( preg_match( '/[.!]\s*$/', $first ) && mb_strlen( $first ) > 60 ) {
					$html .= '<p>' . esc_html( $first ) . '</p>';
					array_shift( $items );
				}
				$html .= '<ul class="aoe-cat-bullets">';
				foreach ( $items as $item ) {
					$html .= '<li>' . esc_html( $item ) . '</li>';
				}
				$html .= '</ul>';
				return $html;
			}
		}
		if ( preg_match( '/<[a-z][\s>]/', $desc ) ) {
			return $desc;
		}
		return '<p>' . esc_html( $desc ) . '</p>';
	}
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
	foreach ( $pdf as $key => $val ) {
		$url      = is_array( $val ) ? trim( $val['url'] ?? '' ) : trim( (string) $val );
		$name     = is_array( $val ) ? ( $val['name'] ?? '' ) : '';
		if ( '' === $url ) {
			$resolved[ $key ] = is_array( $val ) ? $val : '';
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
			if ( file_exists( $base_pdf . $fname ) ) {
				$resolved[ $key ] = ! empty( $name ) ? [ 'url' => trailingslashit( AOE_CATALOG_PDF_URL ) . $manufacturer_slug . '/' . $fname_encoded, 'name' => $name ] : trailingslashit( AOE_CATALOG_PDF_URL ) . $manufacturer_slug . '/' . $fname_encoded;
				continue 2;
			}
			if ( file_exists( $base_pdf . 'originals/' . $fname ) ) {
				$resolved[ $key ] = ! empty( $name ) ? [ 'url' => trailingslashit( AOE_CATALOG_PDF_URL ) . $manufacturer_slug . '/originals/' . $fname_encoded, 'name' => $name ] : trailingslashit( AOE_CATALOG_PDF_URL ) . $manufacturer_slug . '/originals/' . $fname_encoded;
				continue 2;
			}
			if ( file_exists( $base_old . $fname ) ) {
				$resolved[ $key ] = ! empty( $name ) ? [ 'url' => trailingslashit( AOE_CATALOG_MEDIA_URL ) . $manufacturer_slug . '/pdfs/' . $fname_encoded, 'name' => $name ] : trailingslashit( AOE_CATALOG_MEDIA_URL ) . $manufacturer_slug . '/pdfs/' . $fname_encoded;
				continue 2;
			}
		}

		$resolved[ $key ] = ! empty( $name ) ? [ 'url' => $url, 'name' => $name ] : $url;
	}
	return $resolved;
}

function aoe_catalog_render_html( string $manufacturer_name, string $page_slug, string $category, array $page_products, int $current_page, int $total_pages, bool $is_preview = false, string $manufacturer_slug = '', array $grouped_segments = [], ?array $category_metadata = null, array $breadcrumb_path = [], array $category_chain = [], string $post_url = '' ): string {
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
	$show_features_col = in_array( $manufacturer_slug, [ 'samtec', 'edac', 'camdenboss', 'bivar', 'panduit', 'bulgin', 'medikabel', 'yokowo', 'amphenolanytek', 'amphenolltw', 'amphenolrf', 'amphenollutze', 'amphenolindustrial', 'amphenolconec', 'wieland', 'mhconnectors' ], true );
	$show_subtitle_desc = in_array( $manufacturer_slug, [ 'panduit' ], true );
	if ( $show_features_col && ! $is_preview ) {
		$has_any_specs = false;
		foreach ( $page_products as $pp ) {
			$additional = is_array( $pp->additional_data ?? '' ) ? $pp->additional_data : ( json_decode( $pp->additional_data ?? '{}', true ) ?: [] );
			if ( ! empty( $additional['specs'] ) ) {
				$has_any_specs = true;
				break;
			}
		}
		if ( ! $has_any_specs ) $show_features_col = false;
	}

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
	<?php $tree_layout = $category_metadata['tree_layout'] ?? ''; ?>
	<div class="aoe-catalog-render aoe-catalog-<?php echo esc_attr( $manufacturer_slug ); ?> aoe-tree-layout-<?php echo esc_attr( $tree_layout ); ?>" id="aoe-catalog-container">
		<header>
			<h2>Catálogo de componentes <?php echo esc_html( ucfirst( $manufacturer_name ) ); ?><?php
				if ( ! empty( $category_display_name ) ) {
					echo ' - ' . esc_html( $category_display_name );
				} elseif ( ! empty( $grouped_segments ) ) {
					echo ' - Productos';
				}
				if ( $total_pages > 1 ) {
					echo ' (Página ' . intval( $current_page ) . ' de ' . intval( $total_pages ) . ')';
				}
			?></h2>

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
				if ( $i === 1 && ! empty( $post_url ) ) {
					$page_url = $post_url;
				} elseif ( $i === 1 ) {
					$page_url = home_url( '/catalogo/' . $page_slug . '/' );
				} else {
					$page_url = home_url( '/catalogo/' . $page_slug . '-' . $i . '/' );
				}
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
				<table class="aoe-catalog-table<?php echo $show_features_col ? ' aoe-catalog-table-with-features' : ''; ?> aoe-catalog-table-<?php echo esc_attr( $manufacturer_slug ); ?> cat-grouped" itemscope itemtype="https://schema.org/ItemList">
						<thead>
							<tr>
								<th>Codigo</th>
								<th class="aoe-catalog-underlined">Nombre</th>
								<?php if ( $show_features_col ) : ?>
								<th>Características</th>
								<?php endif; ?>
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
									$specs     = [];
									$additional = is_array( $product['additional_data'] ?? null ) ? $product['additional_data'] : ( json_decode( (string) ( $product['additional_data'] ?? '{}' ), true ) ?: [] );
									$subtitle   = $additional['subtitle'] ?? '';
									$desc_line  = (string) ( $product['description'] ?? '' );
								} else {
									$sku       = (string) ( $product->sku ?? '' );
									$name      = (string) ( $product->name ?? '' );
									$images    = (array) ( json_decode( $product->urls_images ?? '[]', true ) ?: [] );
									$pdf       = (array) ( json_decode( $product->url_pdf ?? '[]', true ) ?: [] );
								$additional = (array) ( json_decode( $product->additional_data ?? '{}', true ) ?: [] );
								$specs     = $additional['specs'] ?? [];
								$subtitle   = $additional['subtitle'] ?? '';
								$desc_line  = (string) ( $product->description ?? '' );
							}
					$has_specs = ! empty( $specs );
					$specs_flat = '';
					if ( $has_specs ) {
						$pairs = [];
						foreach ( $specs as $skey => $sval ) {
							$sval = trim( (string) $sval );
							if ( '' === $sval ) continue;
							$pairs[] = $skey . '=' . str_replace( '\\n', ' ', $sval );
							if ( count( $pairs ) >= 10 ) break;
						}
						$specs_flat = implode( ', ', $pairs );
					}
					$images = array_map( function( $img ) use ( $manufacturer_slug, $media_source ) {
									return aoe_catalog_resolve_media_url( $img, $manufacturer_slug, 'images', $media_source );
								}, $images );
								$pdf = aoe_catalog_resolve_pdf_urls( $pdf, $manufacturer_slug, $media_source );
								$image_url       = aoe_catalog_get_first_value( $images );
								$product_description = 'Conector ' . $manufacturer_name . ' ' . $name;
								$pdf_json = htmlspecialchars( json_encode( $pdf ), ENT_QUOTES, 'UTF-8' );
								$has_pdf = ! empty( array_filter( $pdf, function( $v ) { return ! empty( $v ); } ) );
								$specs_json = $has_specs ? htmlspecialchars( json_encode( $specs ), ENT_QUOTES, 'UTF-8' ) : '';
								?>
								<tr class="fila-producto no-lazyload" itemprop="itemListElement" itemscope itemtype="https://schema.org/Product"
									data-sku="<?php echo esc_attr( $sku ); ?>"
									data-nombre="<?php echo esc_attr( $name ); ?>"
									<?php if ( $show_subtitle_desc && '' !== $subtitle ) : ?>
									data-subtitulo="<?php echo esc_attr( $subtitle ); ?>"
									<?php endif; ?>
									<?php if ( $show_subtitle_desc && '' !== $desc_line ) : ?>
									data-descripcion="<?php echo esc_attr( $desc_line ); ?>"
									<?php endif; ?>
									<?php if ( $show_subtitle_desc ) : ?>
									data-doc-names="1"
									<?php endif; ?>
									data-img="<?php echo esc_url( $image_url ); ?>"
									<?php if ( $has_pdf ) : ?>
									data-pdf-json="<?php echo $pdf_json; ?>"
									<?php endif; ?>
									data-specs-json="<?php echo $specs_json; ?>">
									<td>
									<span class="aoe-catalog-sku">
										<span itemprop="sku"><?php echo esc_html( $sku ); ?></span>
										<svg class="aoe-catalog-sku-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
									</span>
									</td>
									<td>
										<span class="aoe-catalog-product-name" itemprop="name"><?php echo esc_html( $name ); ?></span>
								<?php if ( $show_subtitle_desc && '' !== $subtitle ) : ?>
									<span class="aoe-catalog-product-subtitle"><?php echo esc_html( $subtitle ); ?></span>
								<?php endif; ?>
								<?php if ( $show_subtitle_desc && '' !== $desc_line ) : ?>
									<span class="aoe-catalog-product-desc"><?php echo esc_html( $desc_line ); ?></span>
								<?php endif; ?>
										<?php if ( ! empty( $image_url ) ) : ?>
											<meta itemprop="image" content="<?php echo esc_url( $image_url ); ?>" class="no-lazyload">
										<?php endif; ?>
										<meta itemprop="description" content="<?php echo esc_attr( $product_description ); ?>">
										<div itemprop="offers" itemscope itemtype="https://schema.org/Offer" hidden>
											<link itemprop="availability" href="https://schema.org/InStock">
										</div>
									</td>
									<?php if ( $show_features_col ) : ?>
									<td class="aoe-catalog-caracteristicas">
										<span class="aoe-catalog-caracteristicas-texto"><?php echo esc_html( $specs_flat ); ?></span>
										<?php if ( $has_specs ) : ?>
										<div class="aoe-caracteristicas-popup">
											<table class="aoe-caracteristicas-popup-table">
												<tbody>
												<?php foreach ( $specs as $skey => $sval ) : ?>
												<tr><th><?php echo esc_html( $skey ); ?></th><td><?php echo nl2br( esc_html( str_replace( '\\n', "\n", $sval ) ) ); ?></td></tr>
												<?php endforeach; ?>
												</tbody>
											</table>
										</div>
										<?php endif; ?>
									</td>
									<?php endif; ?>
									<td itemprop="brand" itemscope itemtype="https://schema.org/Brand"><span itemprop="name"><?php echo esc_html( ucfirst( $manufacturer_name ) ); ?></span></td>
									<td class="aoe-catalog-actions"><?php echo aoe_catalog_render_pdf_icon_links( $has_pdf, $has_specs, ! empty( $image_url ) ); ?></td>
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
		<?php if ( in_array( $manufacturer_slug, [ 'samtec', 'bivar' ], true ) && ! empty( $category_chain ) ) : ?>
		<div class="aoe-category-chain">
			<?php foreach ( $category_chain as $link ) :
				$name  = $link['name'] ?? '';
				$desc  = $link['description'] ?? '';
				$feats = $link['features'] ?? '';
				$img   = $link['image'] ?? '';
				$level = $link['level'] ?? 0;
				if ( empty( $name ) ) continue;
				$heading_tag = $level <= 1 ? 'h3' : ( $level === 2 ? 'h4' : 'h5' );
				$desc_wrapper = $level >= 3 || preg_match( '/<[a-z][\s>]/', $desc ) ? 'div' : 'p';
			?>
			<div class="aoe-chain-level aoe-chain-level-<?php echo (int) $level; ?> aoe-cat-level-<?php echo (int) $level; ?>">
				<<?php echo $heading_tag; ?>><?php echo esc_html( $name ); ?></<?php echo $heading_tag; ?>>
				<?php if ( ! empty( $desc ) ) :
					if ( $manufacturer_slug === 'bivar' && $level >= 3 ) {
						$lines = explode( "\n", str_replace( '\n', "\n", $desc ) );
						echo '<ul class="aoe-chain-desc">';
						foreach ( $lines as $line ) {
							$line = trim( $line );
							if ( $line === '' ) continue;
							$line = preg_replace( '/^-\s*/', '', $line );
							echo '<li>' . esc_html( $line ) . '</li>';
						}
						echo '</ul>';
					} else {
				?><<?php echo $desc_wrapper; ?> class="aoe-chain-desc"><?php echo wp_kses_post( str_replace( '\n', "\n", $desc ) ); ?></<?php echo $desc_wrapper; ?>>
				<?php } endif; ?>
				<?php if ( ! empty( $img ) ) : ?>
				<div class="aoe-chain-img"><img src="<?php echo esc_url( $img ); ?>" alt="<?php echo esc_attr( $name ); ?>"></div>
				<?php endif; ?>
				<?php $feats = trim( $feats ); if ( $feats !== '' ) : ?>
				<div class="aoe-chain-features"><?php echo wp_kses_post( $feats ); ?></div>
				<?php endif; ?>
			</div>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
		<?php if ( ! in_array( $manufacturer_slug, [ 'samtec', 'bivar' ], true ) ) : ?>
		<?php if ( ! empty( $category_metadata['description'] ) ) : ?>
		<div class="aoe-series-description"><?php echo wp_kses_post( in_array( $manufacturer_slug, [ 'amphenolltw', 'amphenolindustrial', 'amphenolconec', 'mhconnectors' ], true ) ? aoe_catalog_bullets_to_html( str_replace( '\n', "\n", $category_metadata['description'] ) ) : wpautop( str_replace( '\n', "\n", $category_metadata['description'] ) ) ); ?></div>
		<?php endif; ?>
		<?php if ( ! empty( $category_metadata['highlights'] ) ) : ?>
		<div class="aoe-series-highlights"><?php echo wp_kses_post( nl2br( str_replace( '\n', "\n", $category_metadata['highlights'] ) ) ); ?></div>
		<?php endif; ?>
		<?php if ( ! empty( $category_metadata['features'] ) ) : ?>
		<div class="aoe-series-features"><h4 style="font-weight: bold;">Características</h4>
			<ul><?php foreach ( explode( "\n", str_replace( '\n', "\n", $category_metadata['features'] ) ) as $feat ) : ?><?php $feat = trim( $feat ); if ( '' !== $feat ) : ?><li><?php echo esc_html( $feat ); ?></li><?php endif; ?><?php endforeach; ?></ul>
		</div>
		<?php endif; ?>
		<?php endif; ?>
			<table class="aoe-catalog-table<?php echo $show_features_col ? ' aoe-catalog-table-with-features' : ''; ?> aoe-catalog-table-<?php echo esc_attr( $manufacturer_slug ); ?><?php echo ! empty( $page_slug ) ? ' cat-' . esc_attr( sanitize_title( $page_slug ) ) : ''; ?>" itemscope itemtype="https://schema.org/ItemList">
			<thead>
				<tr>
					<th>Codigo</th>
					<th class="aoe-catalog-underlined">Nombre</th>
					<?php if ( $show_features_col ) : ?>
					<th>Características</th>
					<?php endif; ?>
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
						$additional = is_array( $product['additional_data'] ?? null ) ? $product['additional_data'] : ( json_decode( (string) ( $product['additional_data'] ?? '{}' ), true ) ?: [] );
						$subtitle   = $additional['subtitle'] ?? '';
						$desc_line  = (string) ( $product['description'] ?? '' );
					} else {
						$sku       = (string) ( $product->sku ?? '' );
						$name      = (string) ( $product->name ?? '' );
						$images    = (array) ( json_decode( $product->urls_images ?? '[]', true ) ?: [] );
						$pdf       = (array) ( json_decode( $product->url_pdf ?? '[]', true ) ?: [] );
						$additional = (array) ( json_decode( $product->additional_data ?? '{}', true ) ?: [] );
						$specs     = $additional['specs'] ?? [];
						$subtitle   = $additional['subtitle'] ?? '';
						$desc_line  = (string) ( $product->description ?? '' );
					}
					$has_specs = ! empty( $specs );
					$specs_flat = '';
					if ( $has_specs ) {
						$pairs = [];
						foreach ( $specs as $skey => $sval ) {
							$sval = trim( (string) $sval );
							if ( '' === $sval ) continue;
							$pairs[] = $skey . '=' . str_replace( '\\n', ' ', $sval );
							if ( count( $pairs ) >= 10 ) break;
						}
						$specs_flat = implode( ', ', $pairs );
					}
					$images = array_map( function( $img ) use ( $manufacturer_slug, $media_source ) {
						return aoe_catalog_resolve_media_url( $img, $manufacturer_slug, 'images', $media_source );
					}, $images );
					$pdf = aoe_catalog_resolve_pdf_urls( $pdf, $manufacturer_slug, $media_source );
					$image_url       = aoe_catalog_get_first_value( $images );
					$product_description = 'Conector ' . $manufacturer_name . ' ' . $name;
					$pdf_json = htmlspecialchars( json_encode( $pdf ), ENT_QUOTES, 'UTF-8' );
					$has_pdf = ! empty( array_filter( $pdf, function( $v ) { return ! empty( $v ); } ) );
					$specs_json = $has_specs ? htmlspecialchars( json_encode( $specs ), ENT_QUOTES, 'UTF-8' ) : '';
					?>
					<tr class="fila-producto no-lazyload" itemprop="itemListElement" itemscope itemtype="https://schema.org/Product"
						data-sku="<?php echo esc_attr( $sku ); ?>"
						data-nombre="<?php echo esc_attr( $name ); ?>"
						<?php if ( $show_subtitle_desc && '' !== $subtitle ) : ?>
						data-subtitulo="<?php echo esc_attr( $subtitle ); ?>"
						<?php endif; ?>
						<?php if ( $show_subtitle_desc && '' !== $desc_line ) : ?>
						data-descripcion="<?php echo esc_attr( $desc_line ); ?>"
						<?php endif; ?>
						<?php if ( $show_subtitle_desc ) : ?>
						data-doc-names="1"
						<?php endif; ?>
						data-img="<?php echo esc_url( $image_url ); ?>"
						<?php if ( $has_pdf ) : ?>
						data-pdf-json="<?php echo $pdf_json; ?>"
						<?php endif; ?>
						data-specs-json="<?php echo $specs_json; ?>">
						<td>
							<span class="aoe-catalog-sku">
								<span itemprop="sku"><?php echo esc_html( $sku ); ?></span>
								<svg class="aoe-catalog-sku-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
							</span>
						</td>
						<td>
							<span class="aoe-catalog-product-name" itemprop="name"><?php echo esc_html( $name ); ?></span>
								<?php if ( $show_subtitle_desc && '' !== $subtitle ) : ?>
									<span class="aoe-catalog-product-subtitle"><?php echo esc_html( $subtitle ); ?></span>
								<?php endif; ?>
								<?php if ( $show_subtitle_desc && '' !== $desc_line ) : ?>
									<span class="aoe-catalog-product-desc"><?php echo esc_html( $desc_line ); ?></span>
								<?php endif; ?>
							<?php if ( ! empty( $image_url ) ) : ?>
								<meta itemprop="image" content="<?php echo esc_url( $image_url ); ?>" class="no-lazyload">
							<?php endif; ?>
							<meta itemprop="description" content="<?php echo esc_attr( $product_description ); ?>">
							<div itemprop="offers" itemscope itemtype="https://schema.org/Offer" hidden>
								<link itemprop="availability" href="https://schema.org/InStock">
							</div>
						</td>
						<?php if ( $show_features_col ) : ?>
						<td class="aoe-catalog-caracteristicas">
							<span class="aoe-catalog-caracteristicas-texto"><?php echo esc_html( $specs_flat ); ?></span>
							<?php if ( $has_specs ) : ?>
							<div class="aoe-caracteristicas-popup">
								<table class="aoe-caracteristicas-popup-table">
									<tbody>
									<?php foreach ( $specs as $skey => $sval ) : ?>
									<tr><th><?php echo esc_html( $skey ); ?></th><td><?php echo nl2br( esc_html( str_replace( '\\n', "\n", $sval ) ) ); ?></td></tr>
									<?php endforeach; ?>
									</tbody>
								</table>
							</div>
							<?php endif; ?>
						</td>
						<?php endif; ?>
						<td itemprop="brand" itemscope itemtype="https://schema.org/Brand"><span itemprop="name"><?php echo esc_html( ucfirst( $manufacturer_name ) ); ?></span></td>
						<td class="aoe-catalog-actions"><?php echo aoe_catalog_render_pdf_icon_links( $has_pdf, $has_specs, ! empty( $image_url ) ); ?></td>
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
									<p id="modal-subtitulo" class="aoe-catalog-modal-subtitle"></p>
									<p id="modal-descripcion" class="aoe-catalog-modal-desc"></p>
									<p class="aoe-catalog-manufacturer"><strong>Fabricante:</strong> <?php echo esc_html( ucfirst( $manufacturer_name ) ); ?></p>
									<div class="aoe-catalog-contact-wrap">
										<a id="btn-contacto-modal" class="fusion-button button-flat fusion-button-default-size button-default fusion-button-default btn-catalogo-generico aoe-catalog-contact-button" title="" href="javascript:void(0)" data-sku-link="">
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
	</div>
	<?php
	return ob_get_clean();
}