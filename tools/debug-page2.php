<?php
/**
 * Debug: simula exactamente lo que hace single-catalog.php para /catalogo/samtec-2/
 */
require_once dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-load.php';
if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Acceso denegado' );

global $wpdb;

$manufacturer_slug = 'samtec';
$page_num = 2;
$category_slug = get_query_var( 'aoe_catalog_category' );
$catalog_type = get_query_var( 'aoe_catalog_type' );

echo "<pre>\n";
echo "manufacturer_slug: $manufacturer_slug\n";
echo "page_num: $page_num\n";
echo "category_slug: " . var_export($category_slug, true) . "\n";
echo "catalog_type: " . var_export($catalog_type, true) . "\n\n";

// Build page_slug como single-catalog.php
$page_slug_base = $manufacturer_slug;
$page_slug = $page_slug_base . ( $page_num > 1 ? '-' . $page_num : '' );
echo "page_slug_base: $page_slug_base\n";
echo "page_slug: $page_slug\n\n";

// Query como single-catalog.php
$table_pages = $wpdb->prefix . 'aoe_catalog_pregenerated_pages';
$table_seg   = $wpdb->prefix . 'aoe_catalog_page_segments';
$table_cat   = $wpdb->prefix . 'aoe_catalog_categories';
$table_prod  = $wpdb->prefix . 'aoe_catalog_products';
$table_m     = $wpdb->prefix . 'aoe_catalog_manufacturers';

$page = $wpdb->get_row( $wpdb->prepare(
	"SELECT p.*, m.name AS manufacturer_name, m.wp_post_id AS template_post_id, m.config_json
	 FROM $table_pages p
	 JOIN $table_m m ON p.manufacturer_id = m.id
	 WHERE p.slug = %s",
	$page_slug
) );

if ( $page ) {
	echo "✅ Page encontrada:\n";
	echo "  id={$page->id} type={$page->type} slug={$page->slug}\n";
	echo "  page_number={$page->page_number} link_count={$page->link_count}\n";
	echo "  config_json (raw): " . var_export( $page->config_json, true ) . "\n";
	
	$mfr_config = json_decode( $page->config_json ?? '', true ) ?: [];
	echo "  parsed tree_layout: " . ( $mfr_config['tree_layout'] ?? 'NOT SET (default=normal)' ) . "\n";
	echo "  parsed tree_columns: " . ( $mfr_config['tree_columns'] ?? 'NOT SET' ) . "\n";

	// Comprobar el cache
	$upload = wp_upload_dir();
	$cache_dir = $upload['basedir'] . '/aoe-cache-catalog';
	$cache_file = $cache_dir . '/' . $manufacturer_slug . '/' . str_replace( '/', '_', $page_slug ) . '.html';
	echo "\n  Cache esperado: $cache_file\n";
	echo "  " . ( file_exists( $cache_file ) ? "EXISTE (borrar manualmente)" : "NO existe (se generará en visita)" ) . "\n";
} else {
	echo "❌ Page NO encontrada para slug='$page_slug'\n";
	
	// Fallback
	if ( empty( $category_slug ) && 'grouped' !== $catalog_type ) {
		$page2 = $wpdb->get_row( $wpdb->prepare(
			"SELECT p.*, m.name AS manufacturer_name, m.wp_post_id AS template_post_id, m.config_json
			 FROM $table_pages p
			 JOIN $table_m m ON p.manufacturer_id = m.id
			 WHERE p.slug = %s",
			$manufacturer_slug
		) );
		if ( $page2 ) {
			echo "  Fallback encontró slug='{$manufacturer_slug}' type={$page2->type}\n";
		} else {
			echo "  Fallback tampoco encontró nada\n";
		}
	}
}

echo "\n=== ¿Qué espera la cache?\n";
echo "manufacturer_slug en cache: $manufacturer_slug\n";
$safe_key = str_replace( '/', '_', $page_slug ) . '.html';
echo "cache key: {$manufacturer_slug}/{$safe_key}\n";

echo "</pre>\n";
