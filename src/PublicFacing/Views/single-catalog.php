<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$catalog_css_path = dirname( dirname( dirname( __DIR__ ) ) ) . '/assets/css/catalog-render.css';
wp_enqueue_style(
	'aoe-catalog-render',
	plugin_dir_url( dirname( dirname( dirname( __DIR__ ) ) ) . '/aoe-catalog-engine.php' ) . 'assets/css/catalog-render.css',
	[],
	file_exists( $catalog_css_path ) ? filemtime( $catalog_css_path ) : '1.0.0'
);

$manufacturer_slug = get_query_var( 'aoe_catalog_manufacturer' ) ?: get_query_var( 'aoe_catalog' );
$category_slug     = get_query_var( 'aoe_catalog_category' );
$page_num          = max( 1, intval( get_query_var( 'aoe_catalog_page', 1 ) ) );
$is_test           = ( strpos( $manufacturer_slug, 'test-' ) === 0 );

if ( $is_test ) {
	$preview_data = get_transient( 'aoe_preview_' . $manufacturer_slug );
	if ( ! $preview_data ) {
		wp_die( 'La prueba temporal ha expirado o no existe.', 'Prueba Expirada', [ 'response' => 404 ] );
	}
	$all_products    = $preview_data['products'] ?? [];
	$total_products  = count( $all_products );
	$per_page        = 200;
	$total_pages     = max( 1, ceil( $total_products / $per_page ) );
	$current_page    = min( $page_num, $total_pages );
	$offset          = ( $current_page - 1 ) * $per_page;
	$page_products   = array_slice( $all_products, $offset, $per_page );
	$first           = $page_products[0] ?? $all_products[0] ?? [];
	$category_name   = ! empty( $first['category'] ) ? $first['category'] : ( $preview_data['first_category'] ?? 'Catalogo' );
	$manufacturer_name = $preview_data['manufacturer_name'] ?? 'Prueba Temporal';
	$template_post_id  = intval( $preview_data['template_post_id'] ?? 0 );

	require_once __DIR__ . '/catalog-render-html.php';

	$catalog_html = aoe_catalog_render_html(
		$manufacturer_name,
		$manufacturer_slug . '/' . sanitize_title( $category_name ),
		$category_name,
		$page_products,
		$current_page,
		$total_pages,
		true
	);

	$template_post = $template_post_id ? get_post( $template_post_id ) : null;
	if ( ! $template_post ) {
		wp_die( 'La plantilla asociada a este fabricante no existe.', 'Plantilla no encontrada', [ 'response' => 404 ] );
	}

	global $post;
	$post = $template_post;
	setup_postdata( $post );

	$content = apply_filters( 'the_content', $template_post->post_content );
	$content = str_replace( [ '<p>[catalogo]</p>', '[catalogo]' ], $catalog_html, $content );

	get_header();
	echo $content;
	wp_reset_postdata();
	get_footer();
	exit;
}

// --- Production mode ---

$catalog_type = get_query_var( 'aoe_catalog_type' );

if ( 'grouped' === $catalog_type ) {
	$page_slug_base = $manufacturer_slug . '/productos';
	$page_slug      = $page_slug_base . ( $page_num > 1 ? '-' . $page_num : '' );
} elseif ( $category_slug ) {
	$page_slug_base = $manufacturer_slug . '/' . $category_slug;
	$page_slug      = $page_slug_base . ( $page_num > 1 ? '-' . $page_num : '' );
} else {
	$page_slug_base = $manufacturer_slug;
	$page_slug      = $page_slug_base . ( $page_num > 1 ? '-' . $page_num : '' );
}

$table_pages = $wpdb->prefix . 'aoe_catalog_pregenerated_pages';
$table_seg   = $wpdb->prefix . 'aoe_catalog_page_segments';
$table_cat   = $wpdb->prefix . 'aoe_catalog_categories';
$table_prod  = $wpdb->prefix . 'aoe_catalog_products';
$table_m     = $wpdb->prefix . 'aoe_catalog_manufacturers';

$page = $wpdb->get_row( $wpdb->prepare(
	"SELECT p.*, m.name AS manufacturer_name, m.wp_post_id AS template_post_id
	 FROM $table_pages p
	 JOIN $table_m m ON p.manufacturer_id = m.id
	 WHERE p.slug = %s",
	$page_slug
) );

if ( ! $page ) {
	$page = $wpdb->get_row( $wpdb->prepare(
		"SELECT p.*, m.name AS manufacturer_name, m.wp_post_id AS template_post_id
		 FROM $table_pages p
		 JOIN $table_m m ON p.manufacturer_id = m.id
		 WHERE p.slug = %s",
		$manufacturer_slug
	) );
}

if ( ! $page ) {
	global $wp_query;
	$wp_query->set_404();
	status_header( 404 );
	get_template_part( '404' );
	exit;
}

$manufacturer_slug_base = $wpdb->get_var( $wpdb->prepare(
	"SELECT slug FROM $table_m WHERE id = %d", $page->manufacturer_id
) ) ?: $manufacturer_slug;

$manufacturer_name = $page->manufacturer_name;
$page_type         = $page->type;
$template_post_id  = intval( $page->template_post_id ?? 0 );
$per_page          = 200;
$current_page      = $page_num;
$total_pages       = 1;

$segments = $wpdb->get_results( $wpdb->prepare(
	"SELECT s.*, c.name AS category_name, c.slug AS category_slug
	 FROM $table_seg s
	 JOIN $table_cat c ON s.category_id = c.id
	 WHERE s.page_id = %d
	 ORDER BY s.sort_order ASC",
	$page->id
) );

$page_products = [];
$display_category = '';

if ( 'category' === $page_type ) {
	$cat_seg = $segments[0] ?? null;
	if ( $cat_seg ) {
		$display_category = $cat_seg->category_name;
		$from    = (int) $cat_seg->products_from;
		$to      = (int) $cat_seg->products_to;
		$limit   = $to - $from;
		$total_products = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $table_prod WHERE category_id = %d",
			$cat_seg->category_id
		) );
		$total_pages = max( 1, ceil( $total_products / $per_page ) );

		$page_products = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $table_prod WHERE category_id = %d ORDER BY sku ASC LIMIT %d OFFSET %d",
			$cat_seg->category_id, $limit, $from
		) );
	}
} elseif ( 'grouped' === $page_type ) {
	foreach ( $segments as $seg ) {
		$seg_prods = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $table_prod WHERE category_id = %d ORDER BY sku ASC LIMIT %d",
			$seg->category_id, (int) $seg->products_to
		) );
		$page_products = array_merge( $page_products, $seg_prods );
	}
	$total_grouped = $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM $table_pages WHERE manufacturer_id = %d AND type = 'grouped'",
		$page->manufacturer_id
	) );
	$total_pages = max( 1, (int) $total_grouped );
}

// If tree page, show category list and exit without render table
if ( 'tree' === $page_type || ( empty( $display_category ) && empty( $page_products ) ) ) {
	$tree_pages = $wpdb->get_results( $wpdb->prepare(
		"SELECT page_number, link_count FROM $table_pages
		 WHERE manufacturer_id = %d AND type = 'tree'
		 ORDER BY page_number ASC",
		$page->manufacturer_id
	) );
	$total_pages = count( $tree_pages );

	// Build a lookup: for each category_id, find the best page slug
	$cat_page_map = [];
	$cat_pages_raw = $wpdb->get_results( $wpdb->prepare(
		"SELECT s.category_id, p.slug AS page_slug, p.type
		 FROM $table_seg s
		 JOIN $table_pages p ON s.page_id = p.id
		 WHERE p.manufacturer_id = %d AND p.type IN ('category', 'grouped')
		 ORDER BY (p.type = 'category') DESC",
		$page->manufacturer_id
	) );
	foreach ( $cat_pages_raw as $cp ) {
		if ( ! isset( $cat_page_map[ $cp->category_id ] ) ) {
			$cat_page_map[ $cp->category_id ] = $cp->page_slug;
		}
	}

	$template_post = $template_post_id ? get_post( $template_post_id ) : null;
	if ( ! $template_post ) {
		wp_die( 'La plantilla asociada a este fabricante no existe.', 'Plantilla no encontrada', [ 'response' => 404 ] );
	}

	ob_start();
	?>
	<div class="aoe-tree">
		<h3>Categorías</h3>
		<ul class="aoe-cat-list">
			<?php foreach ( $segments as $seg ) : ?>
				<?php
				$cat_url = isset( $cat_page_map[ $seg->category_id ] )
					? home_url( '/catalogo/' . $cat_page_map[ $seg->category_id ] . '/' )
					: '#';
				?>
				<li>
					<a href="<?php echo esc_url( $cat_url ); ?>"><?php echo esc_html( $seg->category_name ); ?></a>
					<span class="count"><?php echo esc_html( $seg->products_to ?? 0 ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php
	$tree_html = ob_get_clean();

	global $post;
	$post = $template_post;
	setup_postdata( $post );

	$content = apply_filters( 'the_content', $template_post->post_content );
	$content = str_replace( [ '<p>[catalogo]</p>', '[catalogo]' ], $tree_html, $content );

	get_header();
	echo $content;
	wp_reset_postdata();
	get_footer();
	exit;
}

// Category or grouped page — render product table
$template_post = $template_post_id ? get_post( $template_post_id ) : null;
if ( ! $template_post ) {
	wp_die( 'La plantilla asociada a este fabricante no existe.', 'Plantilla no encontrada', [ 'response' => 404 ] );
}

require_once __DIR__ . '/catalog-render-html.php';

$catalog_html = aoe_catalog_render_html(
	$manufacturer_name,
	$page_slug_base,
	$display_category,
	$page_products,
	$current_page,
	$total_pages,
	false
);

global $post;
$post = $template_post;
setup_postdata( $post );

$content = apply_filters( 'the_content', $template_post->post_content );
$content = str_replace( [ '<p>[catalogo]</p>', '[catalogo]' ], $catalog_html, $content );

get_header();
echo $content;
wp_reset_postdata();
get_footer();
