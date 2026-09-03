<?php
if ( getenv( 'AOE_DB_HOST' ) && ! defined( 'DB_HOST' ) ) {
	define( 'DB_HOST', getenv( 'AOE_DB_HOST' ) );
}
$w = dirname( __DIR__, 3 ) . '/wp-load.php';
if ( ! file_exists( $w ) ) $w = dirname( __DIR__, 4 ) . '/wp-load.php';
require_once $w;
global $wpdb;

$mfr = $wpdb->get_row("SELECT id, slug FROM wp_aoe_catalog_manufacturers WHERE slug='edac'");
echo "EDAC id={$mfr->id}\n";
$total = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM wp_aoe_catalog_categories WHERE manufacturer_id=%d', $mfr->id));
echo "Total categories: {$total}\n";

$l1 = $wpdb->get_results($wpdb->prepare(
    "SELECT id, name, slug, level, parent_id FROM wp_aoe_catalog_categories WHERE manufacturer_id=%d AND level=1 ORDER BY sort_order, id LIMIT 20",
    $mfr->id
));
echo "Level 1:\n";
foreach ($l1 as $c) echo "  id={$c->id} slug={$c->slug} name={$c->name}\n";

$l2count = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM wp_aoe_catalog_categories WHERE manufacturer_id=%d AND level=2', $mfr->id));
echo "Level 2 count: {$l2count}\n";

$l2 = $wpdb->get_results($wpdb->prepare(
    "SELECT id, name, slug, level, parent_id FROM wp_aoe_catalog_categories WHERE manufacturer_id=%d AND level=2 ORDER BY id LIMIT 10",
    $mfr->id
));
foreach ($l2 as $c) echo "  L2 id={$c->id} parent={$c->parent_id} slug={$c->slug} name={$c->name}\n";

$l3count = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM wp_aoe_catalog_categories WHERE manufacturer_id=%d AND level=3', $mfr->id));
echo "Level 3 count: {$l3count}\n";
