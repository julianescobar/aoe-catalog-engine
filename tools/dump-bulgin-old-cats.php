<?php
/**
 * Dump current Bulgin product -> category assignments from the live DB.
 *
 * Run on PROD (where the old Bulgin import lives):
 *   php tools/dump-bulgin-old-cats.php
 *
 * Output: tools/old-product-cats.csv  (sku;old_slug;old_name;old_level)
 * This is the fallback source to re-categorize products that the new
 * Magento export ships without category_ids.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('memory_limit', '1024M');
set_time_limit(0);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cgi-fcgi') die('CLI only');

$w = __DIR__ . '/../../../../../wp-load.php';
if (!file_exists($w)) { $w = __DIR__ . '/../../../../wp-load.php'; }
if (!file_exists($w)) { $w = __DIR__ . '/../../../wp-load.php'; }
require_once $w;

global $wpdb;

$t_man  = $wpdb->prefix . 'aoe_catalog_manufacturers';
$t_cat  = $wpdb->prefix . 'aoe_catalog_categories';
$t_prod = $wpdb->prefix . 'aoe_catalog_products';

$mfr = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_man WHERE slug = %s", 'bulgin'));
if (!$mfr) {
	$mfr = $wpdb->get_row("SELECT * FROM $t_man WHERE name LIKE '%ulgin%' LIMIT 1");
}
if (!$mfr) die("Manufacturer Bulgin no encontrado\n");
$mfr_id = (int)$mfr->id;
echo "Manufacturer: {$mfr->name} (id=$mfr_id, slug={$mfr->slug})\n";

$total = (int)$wpdb->get_var($wpdb->prepare(
	"SELECT COUNT(*) FROM $t_prod WHERE manufacturer_id = %d", $mfr_id
));
echo "Productos Bulgin en BD: $total\n";

$out = __DIR__ . '/old-product-cats.csv';
$fh  = fopen($out, 'w');
fwrite($fh, "sku;old_slug;old_name;old_level\n");

$noCat = 0;
$step  = 2000;
$skip  = 0;
while ($skip < $total) {
	$rows = $wpdb->get_results($wpdb->prepare(
		"SELECT p.sku, c.slug AS old_slug, c.name AS old_name, c.level AS old_level
		   FROM $t_prod p
		   LEFT JOIN $t_cat c ON c.id = p.category_id
		  WHERE p.manufacturer_id = %d
		  ORDER BY p.id ASC
		  LIMIT %d OFFSET %d",
		$mfr_id, $step, $skip
	));
	if (!$rows) break;
	foreach ($rows as $r) {
		if ($r->old_slug === null) {
			$noCat++;
			fwrite($fh, $r->sku . ";;;;\n");
			continue;
		}
		fwrite($fh, implode(';', [$r->sku, $r->old_slug, $r->old_name, $r->old_level]) . "\n");
	}
	$skip += $step;
}

fclose($fh);
echo "CSV escrito: $out\n";
echo "Sin categoría (null): $noCat\n";
echo "Filas: " . ($skip) . "\n";
