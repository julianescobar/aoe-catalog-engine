<?php
if ( ! in_array( PHP_SAPI, [ 'cli', 'cgi-fcgi' ], true ) ) die( 'CLI only' );

$slug = $argv[1] ?? '';
if ( empty( $slug ) ) die( "Usage: php tools/check-sku-map.php <manufacturer_slug>\n" );

require_once __DIR__ . '/../../../../wp-load.php';
global $wpdb;

$mfr = $wpdb->get_row( $wpdb->prepare(
	"SELECT * FROM {$wpdb->prefix}aoe_catalog_manufacturers WHERE slug = %s", $slug
) );
if ( ! $mfr ) die( "Manufacturer not found\n" );
$mfr_id = (int) $mfr->id;

$cat_table = $wpdb->prefix . 'aoe_catalog_categories';
$prod_table = $wpdb->prefix . 'aoe_catalog_products';
$map_table = $wpdb->prefix . 'aoe_catalog_sku_map';

echo "=== {$mfr->name} — SKU Map Validation ===\n\n";

// 1. Total products
$total = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM $prod_table WHERE manufacturer_id = %d", $mfr_id
) );
echo "Total products: $total\n";

// 2. Products in sku_map
$mapped = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(DISTINCT sku) FROM $map_table WHERE manufacturer_id = %d", $mfr_id
) );
echo "Products in sku_map: $mapped\n";

// 3. Products whose category_id matches their sku_map mapping
$correct = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM $prod_table p
	 JOIN $map_table m ON p.sku = m.sku AND m.manufacturer_id = p.manufacturer_id
	 JOIN $cat_table c ON c.manufacturer_id = p.manufacturer_id AND c.slug = (SELECT sanitize_title(m.codigo_serie))
	 WHERE p.manufacturer_id = %d AND p.category_id = c.id",
	$mfr_id
) );
// Can't use sanitize_title in SQL — use REPLACE
$correct = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM $prod_table p
	 JOIN $map_table m ON p.sku = m.sku AND m.manufacturer_id = p.manufacturer_id
	 WHERE p.manufacturer_id = %d",
	$mfr_id
) );
// Instead, count those that DON'T match
$wrong = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM $prod_table p
	 JOIN $map_table m ON p.sku = m.sku AND m.manufacturer_id = p.manufacturer_id
	 LEFT JOIN $cat_table c ON c.manufacturer_id = p.manufacturer_id AND c.slug = LOWER(REPLACE(m.codigo_serie, ' ', '-'))
	 WHERE p.manufacturer_id = %d AND (c.id IS NULL OR p.category_id != c.id)",
	$mfr_id
) );
echo "Products still WRONG after migration: $wrong\n\n";

// 4. Show some sample products
echo "=== Sample products (first 10 with sku_map) ===\n";
$samples = $wpdb->get_results( $wpdb->prepare(
	"SELECT p.sku, p.category_id AS current_cat, c.name AS current_cat_name,
	        m.codigo_serie, c2.name AS mapped_cat_name, c2.id AS mapped_cat_id
	 FROM $prod_table p
	 JOIN $map_table m ON p.sku = m.sku AND m.manufacturer_id = p.manufacturer_id
	 LEFT JOIN $cat_table c ON p.category_id = c.id
	 LEFT JOIN $cat_table c2 ON c2.manufacturer_id = p.manufacturer_id AND c2.slug = LOWER(REPLACE(m.codigo_serie, ' ', '-'))
	 WHERE p.manufacturer_id = %d
	 LIMIT 10",
	$mfr_id
) );
$ok = 0;
$bad = 0;
foreach ( $samples as $s ) {
	$match = $s->current_cat == $s->mapped_cat_id ? '✓' : '✗';
	if ( $s->current_cat == $s->mapped_cat_id ) $ok++; else $bad++;
	echo "  $match SKU: {$s->sku} | cat: {$s->current_cat_name} ({$s->current_cat}) → should be: {$s->mapped_cat_name} ({$s->mapped_cat_id})\n";
}
echo "  Sample: $ok correct, $bad wrong\n\n";

// 5. Check orphaned level-4 categories
$orphans = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM $cat_table c
	 LEFT JOIN $cat_table p ON c.parent_id = p.id
	 WHERE c.manufacturer_id = %d AND c.level = 4 AND c.parent_id IS NOT NULL AND p.id IS NULL",
	$mfr_id
) );
echo "Orphaned level-4 (parent missing): $orphans\n";

// 6. Level-4 without products
$empty = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM $cat_table WHERE manufacturer_id = %d AND level = 4 AND products_count = 0",
	$mfr_id
) );
// Actually count products from table
$real_empty = $wpdb->get_results( $wpdb->prepare(
	"SELECT c.id, c.name, c.products_count AS stored,
	        (SELECT COUNT(*) FROM $prod_table p WHERE p.category_id = c.id) AS real_count
	 FROM $cat_table c
	 WHERE c.manufacturer_id = %d AND c.level = 4
	 HAVING real_count = 0
	 ORDER BY c.name
	 LIMIT 5",
	$mfr_id
) );
echo "Level-4 with 0 products (first 5): \n";
foreach ( $real_empty as $e ) {
	echo "  {$e->name} (ID {$e->id}) — stored: {$e->stored}, real: {$e->real_count}\n";
}

echo "\n=== Done ===\n";
