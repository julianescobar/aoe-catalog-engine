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

$catalog_js_path = dirname( dirname( dirname( __DIR__ ) ) ) . '/assets/js/catalog.js';
wp_enqueue_script(
	'aoe-catalog-js',
	plugin_dir_url( dirname( dirname( dirname( __DIR__ ) ) ) . '/aoe-catalog-engine.php' ) . 'assets/js/catalog.js',
	[ 'jquery' ],
	file_exists( $catalog_js_path ) ? filemtime( $catalog_js_path ) : '1.0.0',
	true
);

$manufacturer_row = $wpdb->get_row( $wpdb->prepare(
	"SELECT name FROM {$wpdb->prefix}aoe_catalog_manufacturers WHERE slug = %s",
	$manufacturer_slug
) );
wp_localize_script( 'aoe-catalog-js', 'aoeCatalog', [
	'manufacturerName' => $manufacturer_row ? $manufacturer_row->name : '',
] );
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

$cached = \AOE\CatalogEngine\PublicFacing\CacheCatalog::get( $manufacturer_slug, $page_slug );
if ( null !== $cached ) {
	echo $cached;
	exit;
}
ob_start();

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
	// Only fall back to manufacturer tree if no specific category/grouped was requested
	if ( empty( $category_slug ) && 'grouped' !== $catalog_type ) {
		$page = $wpdb->get_row( $wpdb->prepare(
			"SELECT p.*, m.name AS manufacturer_name, m.wp_post_id AS template_post_id
			 FROM $table_pages p
			 JOIN $table_m m ON p.manufacturer_id = m.id
			 WHERE p.slug = %s",
			$manufacturer_slug
		) );
	}
}

if ( ! $page ) {
	global $wp_query;
	$wp_query->set_404();
	status_header( 404 );
	ob_end_clean();
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
	"SELECT s.*, c.name AS category_name, c.slug AS category_slug, c.parent_id, c.level
	 FROM $table_seg s
	 JOIN $table_cat c ON s.category_id = c.id
	 WHERE s.page_id = %d
	 ORDER BY s.sort_order ASC",
	$page->id
) );

$page_products = [];
$grouped_segments = [];
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
	// Build category hierarchy lookup
	$all_cats = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, name, parent_id FROM $table_cat WHERE manufacturer_id = %d",
		$page->manufacturer_id
	) );
	$cat_name = [];
	$cat_parent = [];
	foreach ( $all_cats as $c ) {
		$cat_name[ (int) $c->id ] = $c->name;
		$cat_parent[ (int) $c->id ] = (int) $c->parent_id;
	}
	$cat_hierarchies = [];
	foreach ( $segments as $seg ) {
		$cid = (int) $seg->category_id;
		$path = [];
		$cur = $cid;
		while ( $cur && isset( $cat_name[ $cur ] ) ) {
			array_unshift( $path, $cat_name[ $cur ] );
			$cur = $cat_parent[ $cur ] ?? 0;
		}
		$cat_hierarchies[ $cid ] = $path;

		$seg_prods = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $table_prod WHERE category_id = %d ORDER BY sku ASC LIMIT %d",
			$seg->category_id, (int) $seg->products_to
		) );
		$grouped_segments[] = [
			'category_path' => $path,
			'products'      => $seg_prods,
		];
	}
	$total_grouped = $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM $table_pages WHERE manufacturer_id = %d AND type = 'grouped'",
		$page->manufacturer_id
	) );
	$total_pages = max( 1, (int) $total_grouped );
}

// If tree page, show category list and exit without render table
if ( 'tree' === $page_type || ( 'grouped' !== $page_type && empty( $display_category ) && empty( $page_products ) ) ) {
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
		<?php if ( $total_pages > 1 ) : ?>
		<nav class="aoe-catalog-pagination" aria-label="Paginacion de categorias">
			<span class="aoe-catalog-bold">Ir a la pagina:</span>
			<?php for ( $i = 1; $i <= $total_pages; $i++ ) : ?>
				<?php
				$page_url = ( $i === 1 )
					? home_url( '/catalogo/' . $manufacturer_slug . '/' )
					: home_url( '/catalogo/' . $manufacturer_slug . '-' . $i . '/' );
				?>
				<?php if ( $i === (int) $page->page_number ) : ?>
					<span class="aoe-catalog-page-link current"><?php echo $i; ?></span>
				<?php else : ?>
					<a class="aoe-catalog-page-link" href="<?php echo esc_url( $page_url ); ?>"><?php echo $i; ?></a>
				<?php endif; ?>
			<?php endfor; ?>
		</nav>
		<?php endif; ?>
		<?php
		$tree_by_parent = [];
		foreach ( $segments as $seg ) {
			$pid = (int) $seg->parent_id;
			if ( ! isset( $tree_by_parent[ $pid ] ) ) {
				$tree_by_parent[ $pid ] = [];
			}
			$tree_by_parent[ $pid ][] = $seg;
		}

		function aoe_render_cat_tree( array $items, array $tree_by_parent, array $cat_page_map, int $level = 0 ) {
			if ( empty( $items ) ) return;
			echo '<ul class="aoe-cat-list' . ( $level > 0 ? ' aoe-cat-sublist' : '' ) . '">';
			foreach ( $items as $item ) {
				$cat_url = isset( $cat_page_map[ $item->category_id ] )
					? home_url( '/catalogo/' . $cat_page_map[ $item->category_id ] . '/' )
					: '#';
				$children = $tree_by_parent[ $item->category_id ] ?? [];
				echo '<li class="aoe-cat-item aoe-cat-level-' . (int) $item->level . '">';
				echo '<a href="' . esc_url( $cat_url ) . '">' . esc_html( $item->category_name ) . '</a>';
				echo ' <span class="count">(' . esc_html( $item->products_to ?? 0 ) . ')</span>';
				aoe_render_cat_tree( $children, $tree_by_parent, $cat_page_map, $level + 1 );
				echo '</li>';
			}
			echo '</ul>';
		}

		$root_items = $tree_by_parent[0] ?? $tree_by_parent[ null ] ?? $tree_by_parent[ '' ] ?? [];
		if ( empty( $root_items ) ) {
			$root_items = $segments;
		}
		aoe_render_cat_tree( $root_items, $tree_by_parent, $cat_page_map );
		?>
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
	$html = ob_get_clean();
	\AOE\CatalogEngine\PublicFacing\CacheCatalog::set( $manufacturer_slug, $page_slug, $html );
	echo $html;
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
	false,
	$manufacturer_slug_base,
	$grouped_segments
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
$html = ob_get_clean();
\AOE\CatalogEngine\PublicFacing\CacheCatalog::set( $manufacturer_slug, $page_slug, $html );
echo $html;
