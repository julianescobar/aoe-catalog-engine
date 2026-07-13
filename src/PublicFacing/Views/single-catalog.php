<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// --- Inline profiler (define AOE_DEBUG=true in wp-config to enable) ---
// Then use: curl -H "X-AOE-Profile: 1" ...
if ( ! function_exists( 'aoe_profile_mark' ) ) {
	if ( defined( 'AOE_DEBUG' ) && AOE_DEBUG ) {
		global $aoe_profile_marks, $aoe_profile_start, $aoe_profile_active;
		$aoe_profile_active = ! empty( $_SERVER['HTTP_X_AOE_PROFILE'] );
		if ( $aoe_profile_active ) {
			$aoe_profile_start = microtime( true );
			$aoe_profile_marks = [];
		}
		function aoe_profile_mark( $label ) {
			global $aoe_profile_marks, $aoe_profile_start, $aoe_profile_active;
			if ( ! $aoe_profile_active ) return;
			$aoe_profile_marks[] = [ microtime( true ), $label, microtime( true ) - $aoe_profile_start ];
		}
		function aoe_profile_flush() {
			global $aoe_profile_marks, $aoe_profile_start, $aoe_profile_active;
			if ( ! $aoe_profile_active || empty( $aoe_profile_marks ) ) return;
			$lines = "<!-- AOE PROFILE\n";
			$lines .= "=== " . date( 'H:i:s' ) . ' | ' . ( $_SERVER['REQUEST_URI'] ?? '' ) . " ===\n";
			$prev = $aoe_profile_start;
			foreach ( $aoe_profile_marks as $m ) {
				$diff = sprintf( '+%0.4f', $m[0] - $prev );
				$lines .= sprintf( "  %-50s %0.4f (%s)\n", $m[1], $m[2], $diff );
				$prev = $m[0];
			}
			$lines .= "END AOE PROFILE -->\n";
			echo $lines;
		}
		register_shutdown_function( 'aoe_profile_flush' );
		aoe_profile_mark( 'start' );
	} else {
		function aoe_profile_mark( $label ) {}
	}
}

require_once __DIR__ . '/catalog-head-injector.php';

/**
 * Get a post regardless of its status (publish, draft, etc.)
 */
function aoe_get_post_any_status( $post_id ) {
	$post = get_post( $post_id );
	if ( $post ) {
		return $post;
	}
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM {$wpdb->posts} WHERE ID = %d", $post_id
	) );
	return $row ? new WP_Post( $row ) : null;
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
	'ajaxurl'          => admin_url( 'admin-ajax.php' ),
] );
$category_slug     = get_query_var( 'aoe_catalog_category' );
$page_num          = max( 1, intval( get_query_var( 'aoe_catalog_page', 1 ) ) );

// Disambiguate slugs ending in numbers like "samtec-2" /
// The rewrite rule ([^/]+)-([0-9]+) wrongly splits it as slug="samtec", page="2"
if ( $page_num > 1 ) {
	$mfr_exists = $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$wpdb->prefix}aoe_catalog_manufacturers WHERE slug = %s",
		$manufacturer_slug
	) );
	if ( ! $mfr_exists ) {
		$candidate = $manufacturer_slug . '-' . $page_num;
		$mfr_exists2 = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}aoe_catalog_manufacturers WHERE slug = %s",
			$candidate
		) );
		if ( $mfr_exists2 ) {
			$manufacturer_slug = $candidate;
			$page_num = 1;
		}
	}
}

$is_logged_in = is_user_logged_in();
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

	$template_post = $template_post_id ? aoe_get_post_any_status( $template_post_id ) : null;
	if ( ! $template_post ) {
		wp_die( 'La plantilla asociada a este fabricante no existe.', 'Plantilla no encontrada', [ 'response' => 404 ] );
	}

	global $post, $wp_query;
	$post = $template_post;
	setup_postdata( $post );
	$wp_query->queried_object    = $template_post;
	$wp_query->queried_object_id = $template_post->ID;

	aoe_profile_mark( 'before_the_content' );
	$content = apply_filters( 'the_content', $template_post->post_content );
	aoe_profile_mark( 'after_the_content' );
	$content = str_replace( [ '<p>[catalogo]</p>', '[catalogo]' ], $catalog_html, $content );

	aoe_profile_mark( 'before_get_header' );
	get_header();
	aoe_profile_mark( 'after_get_header' );
	echo $content;
	wp_reset_postdata();
	get_footer();
	aoe_profile_mark( 'after_get_footer' );
	$html = ob_get_clean();
	if ( ! $is_logged_in ) {
		\AOE\CatalogEngine\PublicFacing\CacheCatalog::set( $manufacturer_slug, $page_slug, $html );
	}
	echo $html;
	aoe_profile_mark( 'done' );
	exit;
}

// --- Production mode ---

$catalog_type = get_query_var( 'aoe_catalog_type' );

aoe_profile_mark( 'query_vars_parsed' );

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

// Set Last-Modified from the page cache file if it exists
$upload_dir = wp_upload_dir();
$cache_file = $upload_dir['basedir'] . '/aoe-cache-catalog/' . $manufacturer_slug . '/' . str_replace( '/', '_', $page_slug ) . '.html';
if ( file_exists( $cache_file ) ) {
	header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', filemtime( $cache_file ) ) . ' GMT' );
}

$cached = \AOE\CatalogEngine\PublicFacing\CacheCatalog::get( $manufacturer_slug, $page_slug );
if ( null !== $cached ) {
	aoe_profile_mark( 'cache_hit_serve' );
	echo $cached;
	exit;
}
aoe_profile_mark( 'cache_miss_start' );
ob_start();

$table_pages = $wpdb->prefix . 'aoe_catalog_pregenerated_pages';
$table_seg   = $wpdb->prefix . 'aoe_catalog_page_segments';
$table_cat   = $wpdb->prefix . 'aoe_catalog_categories';
$table_prod  = $wpdb->prefix . 'aoe_catalog_products';
$table_m     = $wpdb->prefix . 'aoe_catalog_manufacturers';

aoe_profile_mark( 'before_page_query' );
$page = $wpdb->get_row( $wpdb->prepare(
	"SELECT p.*, m.name AS manufacturer_name, m.wp_post_id AS template_post_id, m.config_json
	 FROM $table_pages p
	 JOIN $table_m m ON p.manufacturer_id = m.id
	 WHERE p.slug = %s",
	$page_slug
) );
aoe_profile_mark( 'after_page_query' );

if ( ! $page ) {
	// Only fall back to manufacturer tree if no specific category/grouped was requested
	if ( empty( $category_slug ) && 'grouped' !== $catalog_type ) {
		$page = $wpdb->get_row( $wpdb->prepare(
			"SELECT p.*, m.name AS manufacturer_name, m.wp_post_id AS template_post_id, m.config_json
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
// Always use the real slug from DB for cache keys
$manufacturer_slug = $manufacturer_slug_base;

$manufacturer_name = $page->manufacturer_name;
$page_type         = $page->type;
$template_post_id  = intval( $page->template_post_id ?? 0 );
$per_page          = 200;
$current_page      = $page_num;
$total_pages       = 1;

// Tree layout config
$mfr_config = json_decode( $page->config_json ?? '', true ) ?: [];
$tree_layout = $mfr_config['tree_layout'] ?? 'normal';
// Samtec uses hierarchical normal layout regardless of config
if ( $manufacturer_slug === 'samtec' ) {
	$tree_layout = 'normal';
}
$tree_columns = min( 8, max( 2, intval( $mfr_config['tree_columns'] ?? 4 ) ) );

aoe_profile_mark( 'before_segments_query' );
$segments = $wpdb->get_results( $wpdb->prepare(
	"SELECT s.*, c.name AS category_name, c.slug AS category_slug, c.parent_id, c.level, c.metadata_json, c.description AS category_description
	 FROM $table_seg s
	 JOIN $table_cat c ON s.category_id = c.id
	 WHERE s.page_id = %d
	 ORDER BY s.sort_order ASC",
	$page->id
) );
aoe_profile_mark( 'after_segments_query' );

$page_products = [];
$grouped_segments = [];
$display_category = '';

$category_metadata = null;
$breadcrumb_path = [];

// Build hierarchy lookup for breadcrumbs
$all_cats_lookup = $wpdb->get_results( $wpdb->prepare(
	"SELECT id, name, parent_id FROM $table_cat WHERE manufacturer_id = %d",
	$page->manufacturer_id
) );
$cat_name_lookup = [];
$cat_parent_lookup = [];
foreach ( $all_cats_lookup as $c ) {
	$cat_name_lookup[ (int) $c->id ] = $c->name;
	$cat_parent_lookup[ (int) $c->id ] = (int) $c->parent_id;
}

if ( 'category' === $page_type ) {
	$cat_seg = $segments[0] ?? null;
		if ( $cat_seg ) {
			$meta = ! empty( $cat_seg->metadata_json ) ? json_decode( $cat_seg->metadata_json, true ) : [];
			$wp_post_id = ! empty( $meta['wp_post_id'] ) ? intval( $meta['wp_post_id'] ) : 0;
			$is_page_one = $current_page <= 1;
			if ( $wp_post_id && $is_page_one ) {
				ob_end_clean();
				wp_redirect( get_permalink( $wp_post_id ), 301 );
				exit;
			}

		$display_category = $cat_seg->category_name;

		// Build breadcrumb from parent chain
		$cur = (int) $cat_seg->category_id;
		while ( $cur && isset( $cat_name_lookup[ $cur ] ) ) {
			array_unshift( $breadcrumb_path, $cat_name_lookup[ $cur ] );
			$cur = $cat_parent_lookup[ $cur ] ?? 0;
		}

		// Fetch category metadata (description + series info)
		$cat_row = $wpdb->get_row( $wpdb->prepare(
			"SELECT description, metadata_json, image FROM $table_cat WHERE id = %d",
			$cat_seg->category_id
		) );
		$category_metadata = [ 'description' => '', 'features' => '', 'specifications' => '', 'highlights' => '', 'image' => '' ];
		if ( $cat_row ) {
			$category_metadata['description'] = $cat_row->description ?? '';
			$category_metadata['image']       = $cat_row->image ?? '';
			if ( ! empty( $cat_row->metadata_json ) ) {
				$cat_meta = json_decode( $cat_row->metadata_json, true );
				if ( is_array( $cat_meta ) ) {
					$category_metadata['features']       = $cat_meta['features'] ?? '';
					$category_metadata['specifications'] = $cat_meta['specifications'] ?? '';
					$category_metadata['highlights']     = $cat_meta['highlights'] ?? '';
				}
			}
		}

		// Build category chain (all ancestors with their content)
		$category_chain = [];
		$chain_ids = [];
		$cur = (int) $cat_seg->category_id;
		while ( $cur && isset( $cat_name_lookup[ $cur ] ) ) {
			array_unshift( $chain_ids, $cur );
			$cur = $cat_parent_lookup[ $cur ] ?? 0;
		}
		if ( ! empty( $chain_ids ) ) {
			$ids_placeholders = implode( ',', array_fill( 0, count( $chain_ids ), '%d' ) );
			$chain_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, name, level, description, metadata_json, image FROM $table_cat WHERE id IN ($ids_placeholders) ORDER BY FIELD(id, $ids_placeholders)",
				array_merge( $chain_ids, $chain_ids )
			) );
			foreach ( $chain_rows as $cr ) {
				$meta = ! empty( $cr->metadata_json ) ? json_decode( $cr->metadata_json, true ) : [];
				$category_chain[] = [
					'name'        => $cr->name,
					'level'       => (int) $cr->level,
					'description' => $cr->description ?? '',
					'image'       => $cr->image ?? '',
					'features'    => $meta['features'] ?? '',
				];
			}
		}

		$from    = (int) $cat_seg->products_from;
		$to      = (int) $cat_seg->products_to;
		$limit   = $to - $from;
		$total_products = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $table_prod WHERE category_id = %d",
			$cat_seg->category_id
		) );
		$total_pages = max( 1, ceil( $total_products / $per_page ) );

		$page_products = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $table_prod WHERE category_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
			$cat_seg->category_id, $limit, $from
		) );
	}
} elseif ( 'grouped' === $page_type ) {
	$cat_hierarchies = [];
	$cat_ids = [];
	$cat_limits = [];
	foreach ( $segments as $seg ) {
		$cid = (int) $seg->category_id;
		$cat_ids[] = $cid;
		$cat_limits[ $cid ] = (int) $seg->products_to;
	}
	// Batch-fetch all products for all categories in one query
	$placeholders = implode( ',', array_fill( 0, count( $cat_ids ), '%d' ) );
	$all_products = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM $table_prod WHERE category_id IN ($placeholders) ORDER BY category_id, id ASC",
		$cat_ids
	) );
	// Partition by category_id
	$products_by_cat = [];
	foreach ( $all_products as $p ) {
		$products_by_cat[ (int) $p->category_id ][] = $p;
	}
	foreach ( $segments as $seg ) {
		$cid = (int) $seg->category_id;
		$path = [];
		$cur = $cid;
		while ( $cur && isset( $cat_name_lookup[ $cur ] ) ) {
			array_unshift( $path, $cat_name_lookup[ $cur ] );
			$cur = $cat_parent_lookup[ $cur ] ?? 0;
		}
		$cat_hierarchies[ $cid ] = $path;

		$seg_prods = array_slice( $products_by_cat[ $cid ] ?? [], 0, $cat_limits[ $cid ] );
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
	$is_samtec_subtree = ( $manufacturer_slug === 'samtec' && strpos( $page->slug, '/' ) !== false );
	if ( $is_samtec_subtree ) {
		// Samtec subtree: count only pages under the same level-1 slug
		$slug_prefix = $page->slug;
		$slug_prefix = preg_replace( '/-\d+$/', '', $slug_prefix );
		$tree_pages = $wpdb->get_results( $wpdb->prepare(
			"SELECT page_number, link_count FROM $table_pages
			 WHERE manufacturer_id = %d AND type = 'tree' AND slug LIKE %s
			 ORDER BY page_number ASC",
			$page->manufacturer_id,
			$slug_prefix . '%'
		) );
	} else {
		$tree_pages = $wpdb->get_results( $wpdb->prepare(
			"SELECT page_number, link_count FROM $table_pages
			 WHERE manufacturer_id = %d AND type = 'tree'
			 ORDER BY page_number ASC",
			$page->manufacturer_id
		) );
	}
	$total_pages = count( $tree_pages );

	// Build a lookup: for each category_id, find the best page slug
	$cat_page_map = [];
	$cat_has_dedicated_page = [];
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
		if ( $cp->type === 'category' ) {
			$cat_has_dedicated_page[ (int) $cp->category_id ] = true;
		}
	}

	aoe_profile_mark( 'tree_start' );

	$template_post = $template_post_id ? aoe_get_post_any_status( $template_post_id ) : null;
	if ( ! $template_post ) {
		wp_die( 'La plantilla asociada a este fabricante no existe.', 'Plantilla no encontrada', [ 'response' => 404 ] );
	}

	ob_start();

	$is_samtec_index = ( $manufacturer_slug === 'samtec' && strpos( $page->slug, '/' ) === false );

	?>
	<div class="aoe-tree aoe-tree-<?php echo esc_attr( $manufacturer_slug ); ?>" id="aoe-catalog-container">
		<h2>Catálogo de componentes <?php echo esc_html( $page->manufacturer_name ); ?></h2>
		<?php if ( $manufacturer_slug === 'samtec' ) :
			if ( $is_samtec_index ) {
				$level1_for_nav = $segments;
			} else {
				$level1_for_nav = $wpdb->get_results( $wpdb->prepare(
					"SELECT c.slug AS category_slug, c.name AS category_name
					 FROM {$wpdb->prefix}aoe_catalog_page_segments ps
					 JOIN {$wpdb->prefix}aoe_catalog_pregenerated_pages p ON ps.page_id = p.id
					 JOIN {$wpdb->prefix}aoe_catalog_categories c ON ps.category_id = c.id
					 WHERE p.manufacturer_id = %d AND p.type = 'tree' AND p.slug = %s
					 ORDER BY ps.sort_order ASC",
					$page->manufacturer_id,
					$manufacturer_slug_base
				) );
			}
		?>
		<?php if ( $is_samtec_index || ( $is_samtec_subtree ) ) :
			// Build map of level-1 slugs that have a subtree page 1
			$tree_page_slugs = $wpdb->get_col( $wpdb->prepare(
				"SELECT slug FROM {$wpdb->prefix}aoe_catalog_pregenerated_pages
				 WHERE manufacturer_id = %d AND type = 'tree' AND slug LIKE %s",
				$page->manufacturer_id,
				$wpdb->esc_like( $manufacturer_slug_base . '/' ) . '%'
			) );
			$valid_slug_map = [];
			foreach ( $tree_page_slugs as $s ) {
				$parts = explode( '/', $s, 2 );
				$cat_slug = isset( $parts[1] ) ? preg_replace( '/-\d+$/', '', $parts[1] ) : '';
				if ( $cat_slug !== '' ) {
					$valid_slug_map[ $cat_slug ] = true;
				}
			}
			// Current category slug for highlighting
			$current_cat_slug = $is_samtec_subtree
				? ( preg_match( '~^[^/]+/([^/]+)~', $page->slug, $m ) ? preg_replace( '/-\d+$/', '', $m[1] ) : '' )
				: '';
		?>
		<nav class="aoe-catalog-pagination" aria-label="Categoria">
			<span class="aoe-catalog-bold">Categoría:</span>
			<?php foreach ( $level1_for_nav as $l1 ) :
				if ( ! isset( $valid_slug_map[ $l1->category_slug ] ) ) continue;
				$cat_url = home_url( '/catalogo/' . $manufacturer_slug_base . '/' . $l1->category_slug . '/' );
				$is_current = ( $l1->category_slug === $current_cat_slug );
			?>
				<?php if ( $is_current ) : ?>
					<span class="aoe-catalog-page-link current"><?php echo esc_html( $l1->category_name ); ?></span>
				<?php else : ?>
					<a class="aoe-catalog-page-link" href="<?php echo esc_url( $cat_url ); ?>"><?php echo esc_html( $l1->category_name ); ?></a>
				<?php endif; ?>
			<?php endforeach; ?>
		</nav>
		<?php endif; ?>
		<?php endif; ?>
		<?php if ( $total_pages > 1 && ! $is_samtec_index ) : ?>
		<nav class="aoe-catalog-pagination" aria-label="Paginacion de categorias">
			<span class="aoe-catalog-bold">Ir a la pagina:</span>
			<?php for ( $i = 1; $i <= $total_pages; $i++ ) : ?>
				<?php
				if ( $is_samtec_subtree ) {
					$slug_prefix = preg_replace( '/-\d+$/', '', $page->slug );
					$page_url = ( $i === 1 )
						? home_url( '/catalogo/' . $slug_prefix . '/' )
						: home_url( '/catalogo/' . $slug_prefix . '-' . $i . '/' );
				} else {
					$page_url = ( $i === 1 )
						? home_url( '/catalogo/' . $manufacturer_slug_base . '/' )
						: home_url( '/catalogo/' . $manufacturer_slug_base . '-' . $i . '/' );
				}
				?>
				<?php if ( $i === (int) $page->page_number ) : ?>
					<span class="aoe-catalog-page-link current"><?php echo $i; ?></span>
				<?php else : ?>
					<a class="aoe-catalog-page-link" href="<?php echo esc_url( $page_url ); ?>"><?php echo $i; ?></a>
				<?php endif; ?>
			<?php endfor; ?>
		</nav>
		<?php endif; ?>
		<?php if ( ! $is_samtec_index ) : ?>
		<?php
		global $wpdb;
		$level3_with_children = [];
		$cats_with_descendants = [];
		if ( ! empty( $segments ) ) {
			$mfr_id = (int) $segments[0]->manufacturer_id;
			$all_parent_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT parent_id FROM {$wpdb->prefix}aoe_catalog_categories WHERE manufacturer_id = %d AND parent_id IS NOT NULL AND parent_id > 0",
				$mfr_id
			) );
			$cats_with_descendants = array_flip( array_map( 'intval', $all_parent_ids ) );
			if ( $manufacturer_slug === 'samtec' ) {
				$l3_ids = $wpdb->get_col( $wpdb->prepare(
					"SELECT DISTINCT parent_id FROM {$wpdb->prefix}aoe_catalog_categories WHERE manufacturer_id = %d AND level = 4 AND parent_id IS NOT NULL",
					$mfr_id
				) );
				$level3_with_children = array_flip( array_map( 'intval', $l3_ids ) );
			}
		}

		$tree_by_parent = [];
		$max_level = 0;
		foreach ( $segments as $seg ) {
			$pid = (int) $seg->parent_id;
			if ( ! isset( $tree_by_parent[ $pid ] ) ) {
				$tree_by_parent[ $pid ] = [];
			}
			$tree_by_parent[ $pid ][] = $seg;
			if ( (int) $seg->level > $max_level ) {
				$max_level = (int) $seg->level;
			}
		}

		function aoe_has_visible_descendants( int $cat_id, array $tree_by_parent, array $segments_by_id ): bool {
			$children = $tree_by_parent[ $cat_id ] ?? [];
			foreach ( $children as $child ) {
				$child_count = (int) ( $segments_by_id[ $child->category_id ]->products_to ?? 0 );
				if ( $child_count > 0 ) return true;
				if ( aoe_has_visible_descendants( $child->category_id, $tree_by_parent, $segments_by_id ) ) return true;
			}
			return false;
		}

		$segments_by_id = [];
		foreach ( $segments as $seg ) {
			$segments_by_id[ $seg->category_id ] = $seg;
		}

		function aoe_render_cat_tree( array $items, array $tree_by_parent, array $segments_by_id, array $cat_page_map, int $level = 0, bool $is_root = true, int &$leaf_idx = 0, string $tree_layout = 'normal', int $tree_columns = 4, string $manufacturer_slug = '', array $level3_with_children = [], array $cat_has_dedicated_page = [], int $max_level = 4, array $cats_with_descendants = [] ) {
			if ( empty( $items ) ) return;

			if ( $is_root && $tree_layout === 'columns' ) {
				echo '<div class="aoe-cat-grid">';
				// Collect items, then group into rows
				$grid_items = [];
				foreach ( $items as $item ) {
					$count = (int) ( $segments_by_id[ $item->category_id ]->products_to ?? 0 );
					$children = $tree_by_parent[ $item->category_id ] ?? [];
					$has_children_with_content = aoe_has_visible_descendants( $item->category_id, $tree_by_parent, $segments_by_id );
					if ( $count === 0 && ! $has_children_with_content ) continue;

					$meta = ! empty( $item->metadata_json ) ? json_decode( $item->metadata_json, true ) : [];
					$wp_post_id = ! empty( $meta['wp_post_id'] ) ? intval( $meta['wp_post_id'] ) : 0;

					if ( $wp_post_id ) {
						$cat_url = get_permalink( $wp_post_id );
					} elseif ( isset( $cat_page_map[ $item->category_id ] ) ) {
						$cat_url = home_url( '/catalogo/' . $cat_page_map[ $item->category_id ] . '/' );
					} else {
						$cat_url = '#';
					}

					$is_leaf = empty( $children ) || ! $has_children_with_content;
					$display_level = ( (int) $item->level === 4 ) ? 4 : ( $is_leaf ? 3 : (int) $item->level );
					$row_class = 'aoe-cat-row aoe-cat-level-' . $display_level;
					if ( $is_leaf ) {
						$row_class .= ( $leaf_idx % 2 === 0 ) ? ' aoe-cat-row-even' : ' aoe-cat-row-odd';
						$leaf_idx++;
					}

					$grid_items[] = [
						'url'   => $cat_url,
						'name'  => $item->category_name,
						'count' => $count,
						'class' => $row_class,
					];
				}
				// Render grouped rows
				$chunks = array_chunk( $grid_items, $tree_columns );
				foreach ( $chunks as $row_idx => $chunk ) {
					$is_even = ( $row_idx % 2 === 0 );
					echo '<div class="aoe-cat-grid-row' . ( $is_even ? ' even' : ' odd' ) . '">';
					foreach ( $chunk as $gi ) {
						echo '<div class="' . $gi['class'] . '">';
						if ( $gi['url'] !== '#' ) {
							echo '<a href="' . esc_url( $gi['url'] ) . '">' . esc_html( $gi['name'] ) . '</a>';
						} else {
							echo esc_html( $gi['name'] );
						}
						if ( $gi['count'] > 0 ) {
							echo ' <span class="count">(' . esc_html( $gi['count'] ) . ')</span>';
						}
						echo '</div>';
					}
					// Fill remaining cells in last row
					$remaining = $tree_columns - count( $chunk );
					for ( $i = 0; $i < $remaining; $i++ ) {
						echo '<div class="aoe-cat-grid-empty"></div>';
					}
					echo '</div>';
				}
				echo '</div>';
				return;
			}

			if ( $manufacturer_slug === 'samtec' && $tree_layout !== 'columns' ) {
				$real_level = ! empty( $items ) ? (int) $items[0]->level : $level;
				if ( $real_level >= 4 ) {
					echo '<div class="aoe-cat-table-level-4"><table class="aoe-cat-tree-table">';
					foreach ( $items as $item ) {
						$count = (int) ( $segments_by_id[ $item->category_id ]->products_to ?? 0 );
						if ( $count === 0 ) continue;

						$meta = ! empty( $item->metadata_json ) ? json_decode( $item->metadata_json, true ) : [];
						$wp_post_id = ! empty( $meta['wp_post_id'] ) ? intval( $meta['wp_post_id'] ) : 0;

						$is_leaf = empty( $tree_by_parent[ (int) $item->category_id ] ?? [] );
						if ( $is_leaf && $wp_post_id ) {
							$cat_url = get_permalink( $wp_post_id );
						} elseif ( $is_leaf && isset( $cat_page_map[ $item->category_id ] ) ) {
							$cat_url = home_url( '/catalogo/' . $cat_page_map[ $item->category_id ] . '/' );
						} else {
							$cat_url = '#';
						}

						$display_level = min( (int) $item->level, 4 );
						$row_class = 'aoe-cat-row aoe-cat-level-' . $display_level;
						$row_class .= ( $leaf_idx % 2 === 0 ) ? ' aoe-cat-row-even' : ' aoe-cat-row-odd';
						$leaf_idx++;

						$desc = ! empty( $item->category_description ) ? trim( str_replace( '\n', "\n", $item->category_description ) ) : '';
						if ( ! empty( $desc ) ) {
							if ( preg_match( '/<p[^>]*>.*?<\/p>/s', $desc, $m ) ) {
								$desc = $m[0];
							} elseif ( ! preg_match( '/<[a-z][\s>]/', $desc ) ) {
								$desc = '<p>' . esc_html( $desc ) . '</p>';
							}
						}
						$has_desc = ! empty( $desc );

						echo '<tr class="' . $row_class . '">';
						echo '<td class="aoe-cat-name"' . ( $has_desc ? '' : ' colspan="2"' ) . '>';
						if ( $cat_url !== '#' ) {
							echo '<a href="' . esc_url( $cat_url ) . '">' . esc_html( $item->category_name ) . '</a>';
						} else {
							echo esc_html( $item->category_name );
						}
						if ( $count > 0 ) {
							echo ' <span class="count">(' . esc_html( $count ) . ')</span>';
						}
						echo '</td>';
						if ( $has_desc ) {
							echo '<td class="aoe-cat-desc">' . wp_kses_post( $desc ) . '</td>';
						}
						echo '</tr>';
					}
					echo '</table></div>';
					return;
				}

				$heading = $real_level <= 1 ? 'h3' : ( $real_level === 2 ? 'h4' : 'h5' );
				foreach ( $items as $item ) {
					$count = (int) ( $segments_by_id[ $item->category_id ]->products_to ?? 0 );
					$children = $tree_by_parent[ $item->category_id ] ?? [];
					if ( (int) $item->level === $max_level && $count === 0 ) continue;
					if ( (int) $item->level === 3 && ! isset( $level3_with_children[ (int) $item->category_id ] ) ) continue;
					if ( (int) $item->level < $max_level && ! isset( $cats_with_descendants[ (int) $item->category_id ] ) && $count === 0 ) continue;

					$desc = ! empty( $item->category_description ) ? trim( str_replace( '\n', "\n", $item->category_description ) ) : '';
					if ( ! empty( $desc ) ) {
						if ( preg_match( '/<p[^>]*>.*?<\/p>/s', $desc, $m ) ) {
							$desc = $m[0];
						} elseif ( ! preg_match( '/<[a-z][\s>]/', $desc ) ) {
							$desc = '<p>' . esc_html( $desc ) . '</p>';
						}
					}

					$meta = ! empty( $item->metadata_json ) ? json_decode( $item->metadata_json, true ) : [];
					$wp_post_id = ! empty( $meta['wp_post_id'] ) ? intval( $meta['wp_post_id'] ) : 0;
					$is_leaf = empty( $tree_by_parent[ (int) $item->category_id ] ?? [] );
					if ( $is_leaf && $wp_post_id ) {
						$cat_url = get_permalink( $wp_post_id );
					} elseif ( $is_leaf && isset( $cat_page_map[ $item->category_id ] ) ) {
						$cat_url = home_url( '/catalogo/' . $cat_page_map[ $item->category_id ] . '/' );
					} else {
						$cat_url = '#';
					}

					$sin_id = ( $item->category_slug ?? '' ) === 'sin-clasificar' ? ' id="aoe-cat-uncategorized"' : '';
					echo '<div class="aoe-cat-level-' . (int) $item->level . '"' . $sin_id . '>';
					echo '<' . $heading . ' class="aoe-cat-heading">';
					if ( $cat_url !== '#' ) {
						echo '<a href="' . esc_url( $cat_url ) . '">' . esc_html( $item->category_name ) . '</a>';
					} else {
						echo esc_html( $item->category_name );
					}
					echo '</' . $heading . '>';

					if ( ! empty( $desc ) ) {
						echo str_replace( '<p', '<p class="aoe-cat-desc"', wp_kses_post( $desc ) );
					}

					aoe_render_cat_tree( $children, $tree_by_parent, $segments_by_id, $cat_page_map, $level + 1, false, $leaf_idx, $tree_layout, $tree_columns, $manufacturer_slug, $level3_with_children, $cat_has_dedicated_page, $max_level, $cats_with_descendants );
					echo '</div>';
				}
				return;
			}

			if ( $is_root ) {
				echo '<table class="aoe-cat-tree-table">';
			}

			foreach ( $items as $item ) {
				$count = (int) ( $segments_by_id[ $item->category_id ]->products_to ?? 0 );
				$children = $tree_by_parent[ $item->category_id ] ?? [];
				// Hide leaf items with no products
				if ( (int) $item->level === $max_level && $count === 0 ) continue;
				// Samtec: hide level-3 items with no level-4 children in DB
				if ( $manufacturer_slug === 'samtec' && (int) $item->level === 3 && ! isset( $level3_with_children[ (int) $item->category_id ] ) ) continue;
				// Hide non-leaf items with no descendants and no products
				if ( (int) $item->level < $max_level && ! isset( $cats_with_descendants[ (int) $item->category_id ] ) && $count === 0 ) continue;

				$meta = ! empty( $item->metadata_json ) ? json_decode( $item->metadata_json, true ) : [];
				$wp_post_id = ! empty( $meta['wp_post_id'] ) ? intval( $meta['wp_post_id'] ) : 0;

				$is_leaf = empty( $tree_by_parent[ (int) $item->category_id ] ?? [] );
				$can_link = $is_leaf;
				if ( $can_link && $wp_post_id ) {
					$cat_url = get_permalink( $wp_post_id );
				} elseif ( $can_link && isset( $cat_page_map[ $item->category_id ] ) ) {
					$cat_url = home_url( '/catalogo/' . $cat_page_map[ $item->category_id ] . '/' );
				} else {
					$cat_url = '#';
				}
				$display_level = (int) $item->level;
				$row_class = 'aoe-cat-row aoe-cat-level-' . $display_level;
				if ( $is_leaf ) {
					$row_class .= ( $leaf_idx % 2 === 0 ) ? ' aoe-cat-row-even' : ' aoe-cat-row-odd';
					$leaf_idx++;
				}

				$desc = ! empty( $item->category_description ) ? trim( str_replace( '\n', "\n", $item->category_description ) ) : '';

				if ( $manufacturer_slug === 'samtec' && ! empty( $desc ) ) {
					if ( preg_match( '/<p[^>]*>.*?<\/p>/s', $desc, $m ) ) {
						$desc = $m[0];
					} elseif ( ! preg_match( '/<[a-z][\s>]/', $desc ) ) {
						$desc = '<p>' . esc_html( $desc ) . '</p>';
					}
				}

				$has_desc = ! empty( $desc );
				$desc_separate_row = $has_desc && (int) $item->level < $max_level;

				echo '<tr class="' . $row_class . '">';
				echo '<td class="aoe-cat-name"' . ( $has_desc ? '' : ' colspan="2"' ) . '>';

				if ( ! $is_leaf && $level === 0 ) {
					echo '<h3>';
				} elseif ( ! $is_leaf && $level === 1 ) {
					echo '<h4>';
				} elseif ( ! $is_leaf && $level === 2 ) {
					echo '<h5>';
				}

				if ( $cat_url !== '#' ) {
					echo '<a href="' . esc_url( $cat_url ) . '">' . esc_html( $item->category_name ) . '</a>';
				} else {
					echo esc_html( $item->category_name );
				}

				if ( ! $is_leaf && $level === 0 ) {
					echo '</h3>';
				} elseif ( ! $is_leaf && $level === 1 ) {
					echo '</h4>';
				} elseif ( ! $is_leaf && $level === 2 ) {
					echo '</h5>';
				}

				if ( $is_leaf && $count > 0 ) {
					echo ' <span class="count">(' . esc_html( $count ) . ')</span>';
				}

				echo '</td>';

				if ( $desc_separate_row ) {
					echo '</tr>';
					echo '<tr class="aoe-cat-desc-row aoe-cat-level-' . (int) $item->level . '">';
					echo '<td class="aoe-cat-desc" colspan="2">' . wp_kses_post( $desc ) . '</td>';
					echo '</tr>';
				} elseif ( $has_desc ) {
					echo '<td class="aoe-cat-desc">' . wp_kses_post( $desc ) . '</td>';
					echo '</tr>';
				} else {
					echo '</tr>';
				}

				aoe_render_cat_tree( $children, $tree_by_parent, $segments_by_id, $cat_page_map, $level + 1, false, $leaf_idx, $tree_layout, $tree_columns, $manufacturer_slug, $level3_with_children, $cat_has_dedicated_page, $max_level, $cats_with_descendants );
			}
			if ( $is_root ) {
				echo '</table>';
			}
		}

		$root_items = $tree_by_parent[0] ?? $tree_by_parent[ null ] ?? $tree_by_parent[ '' ] ?? [];
		if ( empty( $root_items ) ) {
			$root_items = $segments;
		}
		$leaf_idx = 0;
		aoe_render_cat_tree( $root_items, $tree_by_parent, $segments_by_id, $cat_page_map, 0, true, $leaf_idx, $tree_layout, $tree_columns, $manufacturer_slug, $level3_with_children, $cat_has_dedicated_page, $max_level, $cats_with_descendants );
		?><?php endif; ?>
	</div>
	<?php
	aoe_profile_mark( 'tree_rendered' );
	$tree_html = ob_get_clean();

	if ( \AOE\CatalogEngine\PublicFacing\TemplateCache::exists( $manufacturer_slug ) ) {
		$template_full = \AOE\CatalogEngine\PublicFacing\TemplateCache::get( $manufacturer_slug );
		if ( $template_full !== null ) {
			$seo_ctx = aoe_get_catalog_seo_context( [
				'manufacturer_slug' => $manufacturer_slug,
				'manufacturer_name' => $manufacturer_name,
				'page_num'          => (int) $page->page_number,
				'page_type'         => 'tree',
			] );
			$html = str_replace( '[catalogo]', $tree_html, $template_full );
			$html = aoe_inject_dynamic_head( $html, $seo_ctx );
			if ( ! $is_logged_in ) {
				\AOE\CatalogEngine\PublicFacing\CacheCatalog::set( $manufacturer_slug, $page_slug, $html );
			}
			echo $html;
			aoe_profile_mark( 'tree_done' );
			exit;
		}
	}

	global $post, $wp_query;
	$post = $template_post;
	setup_postdata( $post );
	$wp_query->queried_object    = $template_post;
	$wp_query->queried_object_id = $template_post->ID;

	aoe_profile_mark( 'tree_before_the_content' );
	$content = apply_filters( 'the_content', $template_post->post_content );
	aoe_profile_mark( 'tree_after_the_content' );
	$content = str_replace( [ '<p>[catalogo]</p>', '[catalogo]' ], $tree_html, $content );

	aoe_profile_mark( 'tree_before_get_header' );
	get_header();
	aoe_profile_mark( 'tree_after_get_header' );
	echo $content;
	wp_reset_postdata();
	get_footer();
	aoe_profile_mark( 'tree_after_get_footer' );
	$html = ob_get_clean();
	if ( ! $is_logged_in && \AOE\CatalogEngine\PublicFacing\TemplateCache::exists( $manufacturer_slug ) ) {
		\AOE\CatalogEngine\PublicFacing\CacheCatalog::set( $manufacturer_slug, $page_slug, $html );
	}
	echo $html;
	aoe_profile_mark( 'tree_done' );
	exit;
}

// Category or grouped page — render product table
$template_post = $template_post_id ? get_post( $template_post_id ) : null;
if ( ! $template_post ) {
	wp_die( 'La plantilla asociada a este fabricante no existe.', 'Plantilla no encontrada', [ 'response' => 404 ] );
}

$post_url = '';
if ( $page_type === 'category' && isset( $cat_seg ) && ! empty( $cat_seg->metadata_json ) ) {
	$sc_meta = json_decode( $cat_seg->metadata_json, true );
	if ( ! empty( $sc_meta['wp_post_id'] ) && $current_page > 1 ) {
		$post_url = get_permalink( (int) $sc_meta['wp_post_id'] );
	}
}

require_once __DIR__ . '/catalog-render-html.php';

aoe_profile_mark( 'before_render_html' );
$catalog_html = aoe_catalog_render_html(
	$manufacturer_name,
	$page_slug_base,
	$display_category,
	$page_products,
	$current_page,
	$total_pages,
	false,
	$manufacturer_slug_base,
	$grouped_segments,
	$category_metadata,
	$breadcrumb_path,
	$category_chain ?? [],
	$post_url
);
aoe_profile_mark( 'after_render_html' );

if ( \AOE\CatalogEngine\PublicFacing\TemplateCache::exists( $manufacturer_slug ) ) {
	$template_full = \AOE\CatalogEngine\PublicFacing\TemplateCache::get( $manufacturer_slug );
	if ( $template_full !== null ) {
		$seo_ctx = aoe_get_catalog_seo_context( [
			'manufacturer_slug' => $manufacturer_slug_base,
			'manufacturer_name' => $manufacturer_name,
			'category_slug'     => $category_slug,
			'category_name'     => $display_category,
			'page_num'          => $current_page,
			'page_type'         => $page_type,
			'breadcrumb_path'   => $breadcrumb_path,
		] );
		$html = str_replace( '[catalogo]', $catalog_html, $template_full );
		$html = aoe_inject_dynamic_head( $html, $seo_ctx );
		if ( ! $is_logged_in ) {
			\AOE\CatalogEngine\PublicFacing\CacheCatalog::set( $manufacturer_slug, $page_slug, $html );
		}
		echo $html;
		aoe_profile_mark( 'done' );
		exit;
	}
}

global $post, $wp_query;
$post = $template_post;
setup_postdata( $post );
$wp_query->queried_object    = $template_post;
$wp_query->queried_object_id = $template_post->ID;

aoe_profile_mark( 'before_the_content' );
$content = apply_filters( 'the_content', $template_post->post_content );
aoe_profile_mark( 'after_the_content' );
$content = str_replace( [ '<p>[catalogo]</p>', '[catalogo]' ], $catalog_html, $content );

aoe_profile_mark( 'before_get_header' );
get_header();
aoe_profile_mark( 'after_get_header' );
echo $content;
wp_reset_postdata();
get_footer();
aoe_profile_mark( 'after_get_footer' );
$html = ob_get_clean();
if ( ! $is_logged_in && \AOE\CatalogEngine\PublicFacing\TemplateCache::exists( $manufacturer_slug ) ) {
	\AOE\CatalogEngine\PublicFacing\CacheCatalog::set( $manufacturer_slug, $page_slug, $html );
}
echo $html;
aoe_profile_mark( 'done' );
