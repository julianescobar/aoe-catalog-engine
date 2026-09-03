<?php
if ( getenv( 'AOE_DB_HOST' ) && ! defined( 'DB_HOST' ) ) {
	define( 'DB_HOST', getenv( 'AOE_DB_HOST' ) );
}
$w = dirname( __DIR__, 3 ) . '/wp-load.php';
if ( ! file_exists( $w ) ) $w = dirname( __DIR__, 4 ) . '/wp-load.php';
require_once $w;
global $wpdb;

// Check for any category slug that is a prefix of another within same manufacturer
$all = $wpdb->get_results(
	"SELECT manufacturer_id, slug FROM wp_aoe_catalog_categories WHERE slug != '' ORDER BY manufacturer_id, slug"
);
$by_mfr = [];
foreach ($all as $r) {
	$by_mfr[$r->manufacturer_id][] = $r->slug;
}
$collisions = 0;
foreach ($by_mfr as $mfr_id => $slugs) {
	for ($i = 0; $i < count($slugs); $i++) {
		for ($j = $i + 1; $j < count($slugs); $j++) {
			if (strpos($slugs[$j], $slugs[$i] . '-') === 0) {
				echo "COLLISION mfr=$mfr_id: '{$slugs[$i]}' is prefix of '{$slugs[$j]}'\n";
				$collisions++;
			}
		}
	}
}
if ($collisions === 0) echo "No collisions found - safe to use REPLACE\n";

// Also check pregenerated_pages for current slug patterns
$table = $wpdb->prefix . 'aoe_catalog_pregenerated_pages';
$samples = $wpdb->get_results(
	"SELECT slug FROM $table WHERE slug LIKE '%double-level%' LIMIT 10"
);
echo "\nSample pages with 'double-level':\n";
foreach ($samples as $s) echo "  {$s->slug}\n";
