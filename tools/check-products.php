<?php
/**
 * Muestra los primeros N productos de Samtec con su jerarquía de categorías.
 * Uso: php tools/check-products.php [--limit=10] [--sku=QMSS-048-01-H-D-DP-EM2]
 */

if ( ! in_array( PHP_SAPI, [ 'cli', 'cgi-fcgi' ], true ) ) {
	die( 'CLI only' );
}

$limit = 10;
$sku_filter = null;
foreach ( $argv as $arg ) {
	if ( preg_match( '/^--limit=(\d+)$/', $arg, $m ) ) {
		$limit = (int) $m[1];
	}
	if ( preg_match( '/^--sku=(.+)$/', $arg, $m ) ) {
		$sku_filter = $m[1];
	}
}

$wp_load = __DIR__ . '/../../../../../wp-load.php';
foreach ( [ '../../../../', '../../../' ] as $rel ) {
	$path = __DIR__ . '/' . $rel . 'wp-load.php';
	if ( file_exists( $path ) ) { $wp_load = $path; break; }
}
require_once $wp_load;

global $wpdb;

$mfr = $wpdb->get_row( "SELECT id, name FROM {$wpdb->prefix}aoe_catalog_manufacturers WHERE slug = 'samtec'" );
if ( ! $mfr ) die( "Samtec not found\n" );

$mfr_id = (int) $mfr->id;
$cat_table = $wpdb->prefix . 'aoe_catalog_categories';
$prod_table = $wpdb->prefix . 'aoe_catalog_products';
$map_table = $wpdb->prefix . 'aoe_catalog_sku_map';

$where = "p.manufacturer_id = $mfr_id";
$params = [];
if ( $sku_filter ) {
	$where = $wpdb->prepare( "p.manufacturer_id = $mfr_id AND p.sku = %s", $sku_filter );
	$limit = 1;
}

$products = $wpdb->get_results(
	"SELECT p.id, p.sku, p.category_id
	 FROM $prod_table p
	 WHERE $where
	 ORDER BY p.id
	 LIMIT $limit"
);

if ( empty( $products ) ) {
	die( "No products found.\n" );
}

function get_category_chain( $wpdb, $cat_table, $cat_id ) {
	$chain = [];
	$visited = [];
	while ( $cat_id > 0 && ! isset( $visited[ $cat_id ] ) ) {
		$visited[ $cat_id ] = true;
		$cat = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, name, slug, level, parent_id, products_count FROM $cat_table WHERE id = %d",
			$cat_id
		) );
		if ( ! $cat ) {
			$chain[] = "ID:$cat_id (DELETED)";
			break;
		}
		$chain[] = sprintf( "%s (slug: %s, level: %d, parent: %d, prods: %d)",
			$cat->name, $cat->slug, $cat->level, $cat->parent_id ?: 0, $cat->products_count
		);
		$cat_id = (int) $cat->parent_id;
	}
	return array_reverse( $chain );
}

echo "Samtec — primeros $limit productos\n";
echo str_repeat( '-', 80 ) . "\n";

foreach ( $products as $p ) {
	// Look up mapeo
	$mapped = $wpdb->get_var( $wpdb->prepare(
		"SELECT codigo_serie FROM $map_table WHERE manufacturer_id = %d AND sku = %s",
		$mfr_id, $p->sku
	) );

	echo "\nProducto: {$p->sku} (ID: {$p->id})";
	if ( $mapped ) {
		echo "\n  Mapeo → codigo_serie: $mapped";
	} else {
		echo "\n  Mapeo → (no encontrado en sku_map)";
	}
	echo "\n  Categoría actual:\n";

	$original_id = (int) $p->category_id;
	$chain = get_category_chain( $wpdb, $cat_table, $original_id );

	if ( empty( $chain ) ) {
		echo "    (sin categoría)\n";
	} else {
		foreach ( $chain as $i => $c ) {
			echo "    " . str_repeat( '  ', $i ) . "→ $c\n";
		}
	}
}

echo "\n" . str_repeat( '-', 80 ) . "\n";
