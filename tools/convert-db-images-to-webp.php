<?php
/**
 * Convert stored image URLs from .jpg/.png/.gif to .webp in the database.
 *
 * Usage: php tools/convert-db-images-to-webp.php <slug>
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

$slug = $argv[1] ?? '';
if (empty($slug)) {
    echo "Usage: php tools/convert-db-images-to-webp.php <slug>\n";
    exit(1);
}

$mfr = $mysqli->query("SELECT id FROM {$prefix}aoe_catalog_manufacturers WHERE slug = '$slug'");
if (!$mfr || $mfr->num_rows === 0) {
    echo "Manufacturer not found: $slug\n";
    exit(1);
}
$mfr_id = (int) $mfr->fetch_assoc()['id'];

$extensions = ['.jpg', '.jpeg', '.png', '.gif'];
$total = 0;

foreach ($extensions as $ext) {
    $like = '%' . $ext . '%';
    $result = $mysqli->query(
        "SELECT id, urls_images FROM {$prefix}aoe_catalog_products WHERE manufacturer_id = $mfr_id AND urls_images LIKE '$like'"
    );
    while ($row = $result->fetch_assoc()) {
        $new = str_replace($ext, '.webp', $row['urls_images']);
        $id = (int) $row['id'];
        $escaped = $mysqli->real_escape_string($new);
        $mysqli->query("UPDATE {$prefix}aoe_catalog_products SET urls_images = '$escaped' WHERE id = $id");
        $total++;
    }
}

echo "Updated: $total products for '$slug'.\n";
$mysqli->close();
