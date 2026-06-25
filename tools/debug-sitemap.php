<?php
/**
 * Debug: check pregenerated pages for a manufacturer in the sitemap query.
 *
 * Usage: php tools/debug-sitemap.php <slug>
 */

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cgi-fcgi') {
    die('CLI only');
}
header_remove('Content-type');

$wp_config = __DIR__ . '/../../../../wp-config.php';
if (!file_exists($wp_config)) {
    echo "wp-config not found\n";
    exit(1);
}

require_once $wp_config;

mysqli_report(MYSQLI_REPORT_OFF);
$port = 3306;
$host = DB_HOST;
if (strpos($host, ':') !== false) {
    [$host, $port] = explode(':', $host, 2);
    $port = (int) $port;
}
$db = new mysqli($host, DB_USER, DB_PASSWORD, DB_NAME, $port);
if ($db->connect_error) {
    echo "DB error: {$db->connect_error}\n";
    exit(1);
}
$db->set_charset('utf8mb4');

$slug = $argv[1] ?? 'edac';
$prefix = $table_prefix ?? 'wp_';

// Get manufacturer
$mfr = $db->query("SELECT id, name, slug FROM {$prefix}aoe_catalog_manufacturers WHERE slug = '$slug'");
if (!$mfr || $mfr->num_rows === 0) {
    echo "Manufacturer '$slug' not found\n";
    exit(1);
}
$m = $mfr->fetch_assoc();
echo "Manufacturer: {$m['name']} (id: {$m['id']}, slug: {$m['slug']})\n";

// Count all pages
$r = $db->query("SELECT COUNT(*) AS c FROM {$prefix}aoe_catalog_pregenerated_pages WHERE manufacturer_id = {$m['id']}");
echo "\nTotal pregenerated pages: " . $r->fetch_assoc()['c'] . "\n";

// Count by type
$r2 = $db->query("SELECT type, COUNT(*) AS c FROM {$prefix}aoe_catalog_pregenerated_pages WHERE manufacturer_id = {$m['id']} GROUP BY type ORDER BY type");
echo "By type:\n";
while ($row = $r2->fetch_assoc()) {
    echo "  {$row['type']}: {$row['c']}\n";
}

// Count filtered (what sitemap uses)
$r3 = $db->query("SELECT COUNT(*) AS c FROM {$prefix}aoe_catalog_pregenerated_pages WHERE manufacturer_id = {$m['id']} AND type IN ('category', 'grouped', 'tree')");
echo "\nFiltered (category/grouped/tree): " . $r3->fetch_assoc()['c'] . "\n";

// List first 20 filtered
$r4 = $db->query("SELECT slug, type, page_number FROM {$prefix}aoe_catalog_pregenerated_pages WHERE manufacturer_id = {$m['id']} AND type IN ('category', 'grouped', 'tree') ORDER BY type, page_number ASC LIMIT 20");
echo "\nFirst 20 pages in sitemap scope:\n";
while ($row = $r4->fetch_assoc()) {
    echo "  {$row['type']}: {$row['slug']} (page {$row['page_number']})\n";
}

// Test the EXACT sitemap query (LIMIT 200, OFFSET 0)
echo "\n--- Sitemap query test (LIMIT 200, OFFSET 0) ---\n";
$test = $db->query(
    "SELECT DISTINCT p.slug, p.type
     FROM {$prefix}aoe_catalog_pregenerated_pages p
     JOIN {$prefix}aoe_catalog_manufacturers m ON p.manufacturer_id = m.id
     WHERE p.type IN ('category', 'grouped', 'tree')
     AND m.slug = '$slug'
     ORDER BY 
        CASE p.type 
            WHEN 'tree' THEN 1
            WHEN 'grouped' THEN 2
            WHEN 'category' THEN 3
            ELSE 4
        END,
        p.page_number ASC
     LIMIT 200 OFFSET 0"
);
echo "Rows returned: " . $test->num_rows . "\n";
echo "First 5 slugs:\n";
$i = 0;
while ($row = $test->fetch_assoc()) {
    if ($i >= 5) break;
    echo "  {$row['type']}: {$row['slug']}\n";
    $i++;
}

// Check if maybe the type column has different values
echo "\n--- Distinct types in pregenerated_pages ---\n";
$types = $db->query("SELECT DISTINCT type FROM {$prefix}aoe_catalog_pregenerated_pages WHERE manufacturer_id = {$m['id']}");
while ($row = $types->fetch_assoc()) {
    echo "  '" . $row['type'] . "'\n";
}

$db->close();
