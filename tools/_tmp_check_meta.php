<?php
if ( getenv( 'AOE_DB_HOST' ) && ! defined( 'DB_HOST' ) ) {
    define( 'DB_HOST', getenv( 'AOE_DB_HOST' ) );
}
$w = dirname( __DIR__, 3 ) . '/wp-load.php';
if ( ! file_exists( $w ) ) $w = dirname( __DIR__, 4 ) . '/wp-load.php';
require_once $w;
global $wpdb;

$row = $wpdb->get_row($wpdb->prepare(
    "SELECT id, name, slug, metadata_json FROM wp_aoe_catalog_categories WHERE id=%d",
    12022
));
echo "Category: {$row->name} ({$row->slug})\n";
echo "metadata_json: {$row->metadata_json}\n";

$meta = json_decode($row->metadata_json, true);
echo "wp_post_id: " . ($meta['wp_post_id'] ?? 'NOT SET') . "\n";
