<?php
/**
 * Debug helper for catalog routing.
 * Place in wp-content/plugins/aoe-catalog-engine/tools/ and visit via browser.
 * Replace wp-load.php path if needed.
 */
require_once dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-load.php';

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( 'Acceso denegado' );
}

global $wpdb;

$slug = isset( $_GET['slug'] ) ? sanitize_title( $_GET['slug'] ) : 'samtec-2';

echo "<pre>\n";
echo "=== DEBUG CATALOG ===\n\n";

// 1. Manufacturer
$mfr = $wpdb->get_row( $wpdb->prepare(
	"SELECT * FROM {$wpdb->prefix}aoe_catalog_manufacturers WHERE slug = %s", $slug
) );
if ( $mfr ) {
	echo "✅ Fabricante encontrado: id={$mfr->id}, name={$mfr->name}, slug={$mfr->slug}\n";
	echo "   config_json: {$mfr->config_json}\n";
	$config = json_decode( $mfr->config_json, true );
	echo "   tree_layout: " . ( $config['tree_layout'] ?? '(no set)' ) . "\n";
	echo "   tree_columns: " . ( $config['tree_columns'] ?? '(no set)' ) . "\n";
} else {
	echo "❌ Fabricante con slug '{$slug}' NO encontrado\n";

	// Try splitting slug-page
	$parts = explode( '-', $slug );
	$page_num = (int) end( $parts );
	$base_slug = implode( '-', array_slice( $parts, 0, -1 ) );
	if ( $page_num > 0 ) {
		$mfr2 = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}aoe_catalog_manufacturers WHERE slug = %s", $base_slug
		) );
		if ( $mfr2 ) {
			echo "  ➋ Pero existe fabricante '{$base_slug}' + page={$page_num}\n";
		}
		// Try full slug
		$mfr3 = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}aoe_catalog_manufacturers WHERE slug = %s",
			$base_slug . '-' . $page_num
		) );
		if ( $mfr3 ) {
			echo "  ➌ Existe fabricante '{$base_slug}-{$page_num}' (slug completo)\n";
			echo "     config_json: {$mfr3->config_json}\n";
			$config = json_decode( $mfr3->config_json, true );
			echo "     tree_layout: " . ( $config['tree_layout'] ?? '(no set)' ) . "\n";
		}
	}
}

// 2. Pregenerated pages
echo "\n--- Páginas para slug '{$slug}' ---\n";
$pages = $wpdb->get_results( $wpdb->prepare(
	"SELECT p.id, p.type, p.slug, p.page_number, p.link_count
	 FROM {$wpdb->prefix}aoe_catalog_pregenerated_pages p
	 JOIN {$wpdb->prefix}aoe_catalog_manufacturers m ON p.manufacturer_id = m.id
	 WHERE m.slug = %s
	 ORDER BY p.page_number ASC",
	$slug
) );
if ( $pages ) {
	foreach ( $pages as $p ) {
		echo "  - id={$p->id} type={$p->type} slug={$p->slug} page={$p->page_number} links={$p->link_count}\n";
	}
} else {
	echo "  (ninguna)\n";
}

// 3. Try the page lookup the same way single-catalog.php does
echo "\n--- Simulación de single-catalog.php ---\n";
echo "URL visitada: /catalogo/{$slug}/\n";

// Check rewrite matches
$test_slugs = [ $slug ];
// If slug looks like slug-N, also test split
if ( preg_match( '/^(.+)-(\d+)$/', $slug, $m ) ) {
	$test_slugs[] = $m[1]; // slug without page number
	echo "  Posible split: slug='{$m[1]}' page='{$m[2]}'\n";
}
foreach ( $test_slugs as $ts ) {
	$p = $wpdb->get_row( $wpdb->prepare(
		"SELECT p.*, m.slug AS mfr_slug
		 FROM {$wpdb->prefix}aoe_catalog_pregenerated_pages p
		 JOIN {$wpdb->prefix}aoe_catalog_manufacturers m ON p.manufacturer_id = m.id
		 WHERE p.slug = %s", $ts
	) );
	if ( $p ) {
		echo "  Query slug='{$ts}' → encontrada page={$p->page_number} type={$p->type} mfr_slug={$p->mfr_slug}\n";
	} else {
		echo "  Query slug='{$ts}' → (no encontrada)\n";
	}
}

// 4. Show all manufacturers (for context)
echo "\n--- Todos los fabricantes ---\n";
$all_mfr = $wpdb->get_results( "SELECT id, slug, config_json FROM {$wpdb->prefix}aoe_catalog_manufacturers ORDER BY id" );
foreach ( $all_mfr as $m ) {
	echo "  id={$m->id} slug={$m->slug}\n";
}

echo "\n=== FIN DEBUG ===\n";
echo "</pre>\n";
