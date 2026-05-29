<?php
/**
 * Single Catalog Page Template
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$catalog_slug = get_query_var( 'aoe_catalog' );
$is_test = ( strpos( $catalog_slug, 'test-' ) === 0 );
$title = '';
$products = [];
$categories = [];
$manufacturer_name = '';

if ( $is_test ) {
	$preview_data = get_transient( 'aoe_preview_' . $catalog_slug );
	if ( $preview_data ) {
		$manufacturer_name = 'Prueba Temporal';
		$title = 'Prueba: ' . esc_html( $catalog_slug );
		$products = $preview_data;
		
		// Extract categories from preview data
		$cats = array_unique( array_column( $preview_data, 'category' ) );
		foreach ( $cats as $c ) {
			$categories[] = (object) [ 'name' => $c, 'products_count' => count( array_filter( $preview_data, function($item) use ($c) { return $item['category'] === $c; } ) ) ];
		}
	} else {
		wp_die( 'La prueba temporal ha expirado o no existe.', 'Prueba Expirada', [ 'response' => 404 ] );
	}
} else {
	// Production DB Lookup
	global $wpdb;
	$table_m = $wpdb->prefix . 'aoe_catalog_manufacturers';
	$table_c = $wpdb->prefix . 'aoe_catalog_categories';
	$table_p = $wpdb->prefix . 'aoe_catalog_products';

	$manufacturer = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_m WHERE slug = %s", $catalog_slug ) );

	if ( ! $manufacturer ) {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		get_template_part( '404' );
		exit;
	}

	$manufacturer_name = $manufacturer->name;
	$title = 'Catálogo ' . esc_html( $manufacturer->name );

	// Fetch categories
	$categories = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM $table_c WHERE manufacturer_id = %d ORDER BY name ASC",
		$manufacturer->id
	) );

	// Fetch products
	$products_raw = $wpdb->get_results( $wpdb->prepare(
		"SELECT p.*, c.name as category_name FROM $table_p p 
		 JOIN $table_c c ON p.category_id = c.id
		 WHERE p.manufacturer_id = %d LIMIT 500",
		$manufacturer->id
	) );

	foreach ( $products_raw as $p ) {
		$products[] = [
			'sku'         => $p->sku,
			'name'        => $p->name,
			'category'    => $p->category_name,
			'description' => $p->description
		];
	}
}

get_header();
?>
<style>
	:root {
		--aoe-primary: #3b82f6;
		--aoe-dark: #1e293b;
		--aoe-light: #f8fafc;
		--aoe-border: #e2e8f0;
	}
	.aoe-public-wrap {
		max-width: 1200px;
		margin: 40px auto;
		padding: 0 20px;
		font-family: 'Inter', -apple-system, sans-serif;
	}
	.aoe-hero {
		background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
		color: #fff;
		border-radius: 12px;
		padding: 40px;
		margin-bottom: 30px;
		position: relative;
		overflow: hidden;
	}
	.aoe-hero h1 {
		margin: 0 0 10px 0;
		font-size: 32px;
		font-weight: 700;
	}
	.aoe-hero .badge {
		display: inline-block;
		background: #ef4444;
		color: #fff;
		font-size: 12px;
		font-weight: 600;
		padding: 4px 10px;
		border-radius: 20px;
		text-transform: uppercase;
		margin-bottom: 15px;
	}
	.aoe-grid {
		display: grid;
		grid-template-columns: 280px 1fr;
		gap: 30px;
	}
	.aoe-sidebar {
		background: #fff;
		border: 1px solid var(--aoe-border);
		border-radius: 8px;
		padding: 20px;
		height: fit-content;
	}
	.aoe-sidebar h3 {
		margin-top: 0;
		font-size: 16px;
		border-bottom: 1px solid var(--aoe-border);
		padding-bottom: 10px;
		margin-bottom: 15px;
	}
	.aoe-cat-list {
		list-style: none;
		padding: 0;
		margin: 0;
	}
	.aoe-cat-list li {
		display: flex;
		justify-content: space-between;
		padding: 8px 0;
		border-bottom: 1px solid #f1f5f9;
		font-size: 14px;
	}
	.aoe-cat-list li span.count {
		background: #f1f5f9;
		color: #475569;
		padding: 2px 8px;
		border-radius: 10px;
		font-size: 11px;
		font-weight: 600;
	}
	.aoe-content-area {
		background: #fff;
		border: 1px solid var(--aoe-border);
		border-radius: 8px;
		padding: 20px;
	}
	.aoe-table {
		width: 100%;
		border-collapse: collapse;
		margin-top: 20px;
	}
	.aoe-table th, .aoe-table td {
		text-align: left;
		padding: 12px 15px;
		border-bottom: 1px solid var(--aoe-border);
	}
	.aoe-table th {
		background: var(--aoe-light);
		font-weight: 600;
		color: var(--aoe-dark);
	}
	.aoe-table tbody tr:hover {
		background: #f1f5f9;
		cursor: pointer;
	}
	.aoe-sku {
		font-family: monospace;
		background: #f1f5f9;
		padding: 2px 6px;
		border-radius: 4px;
		font-size: 13px;
		color: #0f172a;
	}
	/* Modal Styles */
	.aoe-modal {
		display: none;
		position: fixed;
		top: 0;
		left: 0;
		width: 100%;
		height: 100%;
		background: rgba(15, 23, 42, 0.6);
		backdrop-filter: blur(4px);
		z-index: 9999;
		justify-content: center;
		align-items: center;
	}
	.aoe-modal-content {
		background: #fff;
		border-radius: 12px;
		max-width: 600px;
		width: 90%;
		padding: 30px;
		position: relative;
		box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
	}
	.aoe-modal-close {
		position: absolute;
		top: 15px;
		right: 20px;
		font-size: 24px;
		cursor: pointer;
		color: #94a3b8;
	}
	.aoe-modal-close:hover {
		color: #000;
	}
</style>

<div class="aoe-public-wrap">
	<div class="aoe-hero">
		<?php if ( $is_test ) : ?>
			<span class="badge">Vista de Prueba</span>
		<?php endif; ?>
		<h1><?php echo esc_html( $title ); ?></h1>
		<p>Catálogo interactivo optimizado para SEO para la marca <strong><?php echo esc_html( $manufacturer_name ); ?></strong>.</p>
	</div>

	<div class="aoe-grid">
		<!-- Categorías Jerárquicas -->
		<aside class="aoe-sidebar">
			<h3>Categorías Detectadas</h3>
			<ul class="aoe-cat-list">
				<?php foreach ( $categories as $cat ) : ?>
					<li>
						<span><?php echo esc_html( $cat->name ); ?></span>
						<span class="count"><?php echo esc_html( $cat->products_count ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</aside>

		<!-- Listado de Productos -->
		<main class="aoe-content-area">
			<h3>Productos del Catálogo</h3>
			
			<table class="aoe-table" id="aoe-products-table">
				<thead>
					<tr>
						<th>SKU</th>
						<th>Nombre</th>
						<th>Categoría</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $products ) ) : ?>
						<tr>
							<td colspan="3">No hay productos en esta vista.</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $products as $prod ) : ?>
							<tr class="aoe-product-row" 
								data-sku="<?php echo esc_attr( $prod['sku'] ); ?>" 
								data-name="<?php echo esc_attr( $prod['name'] ); ?>" 
								data-category="<?php echo esc_attr( $prod['category'] ); ?>"
								data-desc="<?php echo esc_attr( $prod['description'] ); ?>">
								<td><span class="aoe-sku"><?php echo esc_html( $prod['sku'] ); ?></span></td>
								<td><strong><?php echo esc_html( $prod['name'] ); ?></strong></td>
								<td><?php echo esc_html( $prod['category'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</main>
	</div>
</div>

<!-- Modal Dinámico de Producto -->
<div class="aoe-modal" id="aoe-product-modal">
	<div class="aoe-modal-content">
		<span class="aoe-modal-close" id="aoe-close-modal">&times;</span>
		<h2 id="modal-product-name" style="margin-top: 0;"></h2>
		<p style="margin-bottom: 20px;"><span class="aoe-sku" id="modal-product-sku"></span> <span id="modal-product-cat" style="margin-left: 10px; color: #64748b;"></span></p>
		<hr style="border: 0; border-top: 1px solid var(--aoe-border); margin: 20px 0;" />
		<div id="modal-product-desc" style="line-height: 1.6; color: #334155;"></div>
	</div>
</div>

<script>
	jQuery(document).ready(function($) {
		$('.aoe-product-row').on('click', function() {
			var sku = $(this).data('sku');
			var name = $(this).data('name');
			var cat = $(this).data('category');
			var desc = $(this).data('desc') || 'Sin descripción disponible.';

			$('#modal-product-name').text(name);
			$('#modal-product-sku').text(sku);
			$('#modal-product-cat').text('Categoría: ' + cat);
			$('#modal-product-desc').text(desc);

			$('#aoe-product-modal').css('display', 'flex');
		});

		$('#aoe-close-modal, #aoe-product-modal').on('click', function(e) {
			if (e.target === this || e.target.id === 'aoe-close-modal') {
				$('#aoe-product-modal').hide();
			}
		});
	});
</script>

<?php
get_footer();
?>
