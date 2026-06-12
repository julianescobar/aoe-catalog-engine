<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$table_m    = $wpdb->prefix . 'aoe_catalog_manufacturers';
$table_prod = $wpdb->prefix . 'aoe_catalog_products';
$table_cat  = $wpdb->prefix . 'aoe_catalog_categories';

$selected_mfr_slug = isset( $_GET['manufacturer'] ) ? sanitize_text_field( $_GET['manufacturer'] ) : '';
$selected_mfr      = null;
$products          = [];
$missing_images    = 0;
$missing_pdfs      = 0;
$total_products    = 0;

if ( $selected_mfr_slug ) {
	$selected_mfr = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM $table_m WHERE slug = %s", $selected_mfr_slug
	) );
}

if ( $selected_mfr ) {
	$products = $wpdb->get_results( $wpdb->prepare(
		"SELECT p.*, c.name AS category_name
		 FROM $table_prod p
		 LEFT JOIN $table_cat c ON p.category_id = c.id
		 WHERE p.manufacturer_id = %d
		 ORDER BY p.sku ASC",
		$selected_mfr->id
	) );
	$total_products = count( $products );

	$upload_dir = wp_upload_dir();
	$base_dir   = $upload_dir['basedir'] . '/catalogo/' . $selected_mfr_slug;

	foreach ( $products as $prod ) {
		$images = (array) ( json_decode( $prod->urls_images ?? '[]', true ) ?: [] );
		$pdfs   = (array) ( json_decode( $prod->url_pdf ?? '[]', true ) ?: [] );

		$prod->valid_images = [];
		$prod->missing_images = [];
		foreach ( $images as $img ) {
			if ( preg_match( '#^https?://#i', $img ) ) {
				// Remote URL — skip check or do a lightweight HEAD
				$prod->valid_images[] = $img;
			} else {
				$file_path = $base_dir . '/images/' . $img;
				if ( file_exists( $file_path ) ) {
					$prod->valid_images[] = $img;
				} else {
					$prod->missing_images[] = $img;
				}
			}
		}

		$prod->valid_pdfs = [];
		$prod->missing_pdfs = [];
		foreach ( $pdfs as $key => $url ) {
			if ( preg_match( '#^https?://#i', $url ) ) {
				$prod->valid_pdfs[] = [ $key, $url ];
			} else {
				$file_path = $base_dir . '/pdfs/' . $url;
				if ( file_exists( $file_path ) ) {
					$prod->valid_pdfs[] = [ $key, $url ];
				} else {
					$prod->missing_pdfs[] = [ $key, $url ];
				}
			}
		}

		$missing_pdfs   += count( $prod->missing_pdfs );
		$missing_images += count( $prod->missing_images );
	}
}

$manufacturers = $wpdb->get_results( "SELECT * FROM $table_m ORDER BY name ASC" );

?>
	<h1>Validar Media de Productos</h1>

	<form method="get" class="aoe-validator-form">
		<input type="hidden" name="page" value="aoe-catalog-media-validator">
		<label for="manufacturer-select">Fabricante:</label>
		<select name="manufacturer" id="manufacturer-select" onchange="this.form.submit()">
			<option value="">— Seleccionar —</option>
			<?php foreach ( $manufacturers as $mfr ) : ?>
				<option value="<?php echo esc_attr( $mfr->slug ); ?>" <?php selected( $selected_mfr_slug, $mfr->slug ); ?>>
					<?php echo esc_html( $mfr->name ); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</form>

	<?php if ( $selected_mfr ) : ?>
		<hr>

		<?php
		$complete_count = 0;
		foreach ( $products as $prod ) {
			if ( empty( $prod->missing_images ) && empty( $prod->missing_pdfs ) ) {
				$complete_count++;
			}
		}
		?>
		<div class="aoe-validator-summary">
			<p>
				<strong>Total productos:</strong> <?php echo $total_products; ?> |
				<strong style="color:#46b450;">Completos:</strong> <?php echo $complete_count; ?> |
				<strong style="color:#d63638;">Faltan imágenes:</strong> <?php echo $missing_images; ?> |
				<strong style="color:#d63638;">Faltan PDFs:</strong> <?php echo $missing_pdfs; ?>
				<?php if ( $total_products - $complete_count > 0 ) : ?>
					| <a href="<?php echo admin_url( 'admin-post.php?action=aoe_export_media_txt&manufacturer=' . urlencode( $selected_mfr_slug ) ); ?>" class="button button-secondary">Exportar faltantes (.txt)</a>
				<?php endif; ?>
			</p>
		</div>

		<?php if ( empty( $products ) ) : ?>
			<p>No hay productos para este fabricante.</p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped aoe-validator-table">
				<thead>
					<tr>
						<th>SKU</th>
						<th>Nombre</th>
						<th>Categoría</th>
						<th>Imágenes</th>
						<th>PDFs</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $products as $prod ) : ?>
						<?php
						$has_images_issue = ! empty( $prod->missing_images );
						$has_pdfs_issue   = ! empty( $prod->missing_pdfs );
						$row_class = ( $has_images_issue || $has_pdfs_issue ) ? 'aoe-validator-row-error' : 'aoe-validator-row-ok';
						?>
						<tr class="<?php echo $row_class; ?>">
							<td><strong><?php echo esc_html( $prod->sku ); ?></strong></td>
							<td><?php echo esc_html( $prod->name ); ?></td>
							<td><?php echo esc_html( $prod->category_name ?? '—' ); ?></td>
							<td>
								<?php if ( ! empty( $prod->valid_images ) || ! empty( $prod->missing_images ) ) : ?>
									<?php if ( ! empty( $prod->valid_images ) ) : ?>
										<span class="aoe-validator-ok" title="OK: <?php echo esc_attr( implode( ', ', $prod->valid_images ) ); ?>">
											✔ <?php echo count( $prod->valid_images ); ?>
										</span>
									<?php endif; ?>
									<?php if ( ! empty( $prod->missing_images ) ) : ?>
										<span class="aoe-validator-fail" title="Faltan: <?php echo esc_attr( implode( ', ', $prod->missing_images ) ); ?>">
											✘ <?php echo count( $prod->missing_images ); ?>
										</span>
									<?php endif; ?>
								<?php else : ?>
									<span class="aoe-validator-na">—</span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( ! empty( $prod->valid_pdfs ) || ! empty( $prod->missing_pdfs ) ) : ?>
									<?php if ( ! empty( $prod->valid_pdfs ) ) : ?>
										<span class="aoe-validator-ok" title="OK: <?php echo esc_attr( implode( ', ', array_column( $prod->valid_pdfs, 1 ) ) ); ?>">
											✔ <?php echo count( $prod->valid_pdfs ); ?>
										</span>
									<?php endif; ?>
									<?php if ( ! empty( $prod->missing_pdfs ) ) : ?>
										<span class="aoe-validator-fail" title="Faltan: <?php echo esc_attr( implode( ', ', array_column( $prod->missing_pdfs, 1 ) ) ); ?>">
											✘ <?php echo count( $prod->missing_pdfs ); ?>
										</span>
									<?php endif; ?>
								<?php else : ?>
									<span class="aoe-validator-na">—</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	<?php endif; ?>
</div>

<style>
.aoe-validator-form {
	margin: 20px 0;
}
.aoe-validator-form select {
	min-width: 250px;
}
.aoe-validator-summary {
	font-size: 14px;
	margin: 10px 0;
}
.aoe-validator-table th {
	width: auto;
}
.aoe-validator-table td {
	vertical-align: middle;
}
.aoe-validator-row-error {
	background: #fff8f8 !important;
}
.aoe-validator-row-ok {
	background: #f0fff0 !important;
}
.aoe-validator-ok {
	color: #46b450;
	font-weight: bold;
	margin-right: 6px;
	cursor: help;
}
.aoe-validator-fail {
	color: #d63638;
	font-weight: bold;
	cursor: help;
}
.aoe-validator-na {
	color: #999;
}
</style>
<?php
