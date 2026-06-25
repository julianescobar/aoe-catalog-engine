<?php
/**
 * Set wp_post_id on category metadata_json to link to existing WP pages.
 *
 * Usage: php tools/set-category-wp-links.php
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

$config = file_get_contents($wp_config);
$defines = [];
preg_match_all("/define\s*\(\s*['\"](DB_[A-Z_]+)['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $config, $m, PREG_SET_ORDER);
foreach ($m as $match) {
    $defines[$match[1]] = $match[2];
}
preg_match("/\\\$table_prefix\s*=\s*['\"]([^'\"]+)['\"]/", $config, $m);
$prefix = $m[1] ?? 'wp_';

$host   = $defines['DB_HOST'] ?? 'localhost';
$user   = $defines['DB_USER'] ?? 'root';
$pass   = $defines['DB_PASSWORD'] ?? '';
$dbname = $defines['DB_NAME'] ?? '';

$port = 3306;
if (strpos($host, ':') !== false) {
    [$host, $port] = explode(':', $host, 2);
    $port = (int) $port;
}

mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli($host, $user, $pass, $dbname, $port);
if ($mysqli->connect_error) {
    echo "DB error: {$mysqli->connect_error}\n";
    exit(1);
}
$mysqli->set_charset('utf8mb4');

// Get Samtec manufacturer ID
$mfr = $mysqli->query("SELECT id FROM {$prefix}aoe_catalog_manufacturers WHERE slug = 'samtec'");
if (!$mfr || $mfr->num_rows === 0) {
    echo "Manufacturer 'samtec' not found.\n";
    exit(1);
}
$mfr_id = (int) $mfr->fetch_assoc()['id'];

$links = [
    ['slug' => 'erm8', 'wp_post_id' => 10230],
    ['slug' => 'erf8', 'wp_post_id' => 10175],
];

$total = 0;
foreach ($links as $link) {
    $slug = $mysqli->real_escape_string($link['slug']);
    $wp_id = (int) $link['wp_post_id'];
    $meta_json = '{"wp_post_id":' . $wp_id . '}';

    $result = $mysqli->query(
        "UPDATE {$prefix}aoe_catalog_categories 
         SET metadata_json = '$meta_json' 
         WHERE slug = '$slug' AND manufacturer_id = $mfr_id"
    );

    if ($mysqli->affected_rows > 0) {
        echo "[OK] $slug -> wp_post_id $wp_id\n";
        $total++;
    } else {
        $check = $mysqli->query("SELECT id FROM {$prefix}aoe_catalog_categories WHERE slug = '$slug' AND manufacturer_id = $mfr_id");
        if ($check && $check->num_rows > 0) {
            echo "[OK] $slug -> wp_post_id $wp_id (already set)\n";
            $total++;
        } else {
            echo "[ERR] Category '$slug' not found under samtec\n";
        }
    }
}

echo "\nDone. $total categories updated.\n";

// Clear cache for samtec
$cache_dir = dirname(dirname($wp_config)) . '/uploads/aoe-cache-catalog/samtec';
if (is_dir($cache_dir)) {
    $files = array_diff(scandir($cache_dir), ['.', '..', 'index.php']);
    foreach ($files as $file) {
        unlink($cache_dir . '/' . $file);
    }
    echo "Samtec cache cleared.\n";
}

$mysqli->close();
