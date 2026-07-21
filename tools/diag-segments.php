<?php
$w = __DIR__ . '/../../../../../wp-load.php';
if (!file_exists($w)) { $w = __DIR__ . '/../../../../wp-load.php'; }
if (!file_exists($w)) { $w = __DIR__ . '/../../../wp-load.php'; }
require_once $w;
global $wpdb;
$mfr_id = 1;

echo "=== Diagnóstico páginas vs productos ===\n\n";

echo "1. Productos en categorías (SUM products_count): " . number_format($wpdb->get_var("SELECT SUM(products_count) FROM {$wpdb->prefix}aoe_catalog_categories WHERE manufacturer_id=$mfr_id")) . "\n";

echo "2. Productos reales (COUNT rows): " . number_format($wpdb->get_var("SELECT COUNT(1) FROM {$wpdb->prefix}aoe_catalog_products WHERE manufacturer_id=$mfr_id")) . "\n";

echo "\n3. Página principal (samtec):\n";
$pid = $wpdb->get_var("SELECT id FROM {$wpdb->prefix}aoe_catalog_pregenerated_pages WHERE slug='samtec' AND manufacturer_id=$mfr_id");
if ($pid) {
	$segs = $wpdb->get_results("SELECT ps.*, c.name, c.slug, c.level, c.products_count FROM {$wpdb->prefix}aoe_catalog_page_segments ps JOIN {$wpdb->prefix}aoe_catalog_categories c ON c.id=ps.category_id WHERE ps.page_id=$pid ORDER BY ps.sort_order LIMIT 20");
	echo "  Segments on page #$pid: " . count($segs) . "\n";
	foreach ($segs as $s) {
		echo "  [{$s->sort_order}] cat_id={$s->category_id} name={$s->name} slug={$s->slug} level={$s->level} cat_prods={$s->products_count} seg_from={$s->products_from} seg_to={$s->products_to}\n";
	}
} else {
	echo "  NOT FOUND\n";
}

echo "\n4. Productos sin categoría en ningún segmento:\n";
$orphans = $wpdb->get_var("SELECT COUNT(1) FROM {$wpdb->prefix}aoe_catalog_products p WHERE p.manufacturer_id=$mfr_id AND p.category_id NOT IN (SELECT DISTINCT category_id FROM {$wpdb->prefix}aoe_catalog_page_segments WHERE manufacturer_id=$mfr_id)");
echo "  Total: $orphans\n";

echo "\n5. Sample category page:\n";
$cp = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}aoe_catalog_pregenerated_pages WHERE slug LIKE 'samtec/ltm%' AND manufacturer_id=$mfr_id LIMIT 1");
if ($cp) {
	echo "  slug={$cp->slug} type={$cp->type} page={$cp->page_number} link={$cp->link_count}\n";
	$sg = $wpdb->get_row("SELECT ps.*, c.name, c.products_count FROM {$wpdb->prefix}aoe_catalog_page_segments ps JOIN {$wpdb->prefix}aoe_catalog_categories c ON c.id=ps.category_id WHERE ps.page_id={$cp->id}");
	if ($sg) echo "  segment: cat_id={$sg->category_id} name={$sg->name} cat_prods={$sg->products_count} from={$sg->products_from} to={$sg->products_to}\n";
} else echo "  NOT FOUND\n";

echo "\nDone.\n";
