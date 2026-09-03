<?php
if ( getenv( 'AOE_DB_HOST' ) && ! defined( 'DB_HOST' ) ) {
	define( 'DB_HOST', getenv( 'AOE_DB_HOST' ) );
}
$w = dirname( __DIR__, 3 ) . '/wp-load.php';
if ( ! file_exists( $w ) ) $w = dirname( __DIR__, 4 ) . '/wp-load.php';
require_once $w;
global $wpdb;

$mfr = $wpdb->get_row("SELECT id FROM wp_aoe_catalog_manufacturers WHERE slug='edac'");
$all = $wpdb->get_results($wpdb->prepare(
    "SELECT id, name, parent_id, level, products_count FROM wp_aoe_catalog_categories WHERE manufacturer_id=%d ORDER BY level, id",
    $mfr->id
));

// Simulate the has_content filter
$by_id = [];
$by_parent = [];
foreach ($all as $c) {
    $by_id[$c->id] = $c;
    $by_parent[$c->parent_id][] = $c->id;
}

$has_content = [];
foreach ($by_id as $cid => $c) {
    if ((int)$c->products_count > 0) $has_content[$cid] = true;
}
foreach ($by_id as $cid => $c) {
    if ((int)$c->products_count > 0) {
        $pid = (int)$c->parent_id;
        while ($pid > 0 && isset($by_id[$pid])) {
            $has_content[$pid] = true;
            $pid = (int)$by_id[$pid]->parent_id;
        }
    }
}

$filtered = array_filter($all, function($c) use ($has_content) {
    return !empty($has_content[$c->id]);
});

echo "Total: " . count($all) . "\n";
echo "After filter: " . count($filtered) . "\n\n";

// Check L1 categories and their children
$l1 = array_filter($filtered, function($c) { return $c->level == 1; });
foreach ($l1 as $c) {
    $children = $by_parent[$c->id] ?? [];
    $filtered_children = array_filter($children, function($cid) use ($has_content) {
        return !empty($has_content[$cid]);
    });
    echo "L1 id={$c->id} products={$c->products_count} children=" . count($children) . " filtered_children=" . count($filtered_children) . " name={$c->name}\n";
}
