<?php
/**
 * One-time fix: revert double/triple UTF-8 mojibake in products name & description.
 *
 * Usage:
 *   php -d memory_limit=512M tools/fix-encoding-db.php
 *   php -d memory_limit=512M tools/fix-encoding-db.php --dry-run
 */

if ( ! in_array( PHP_SAPI, [ 'cli', 'cgi-fcgi' ], true ) ) {
	die( 'CLI only' );
}
header_remove( 'Content-type' );

$dry_run = in_array( '--dry-run', $argv ?? [], true );

$wp_config = __DIR__ . '/../../../../wp-config.php';
if ( ! file_exists( $wp_config ) ) {
	echo "wp-config not found\n";
	exit( 1 );
}

$config = file_get_contents( $wp_config );
foreach ( [ 'DB_NAME', 'DB_USER', 'DB_PASSWORD' ] as $const ) {
	if ( ! preg_match( "/define\(\s*'$const',\s*'([^']+)'/", $config, $m ) ) {
		echo "$const not found in wp-config\n";
		exit( 1 );
	}
	$$const = $m[1];
}
preg_match( "/\\\$table_prefix\s*=\s*'([^']+)'/", $config, $m );
$table_prefix = $m[1] ?? 'wp_';
$table = $table_prefix . 'aoe_catalog_products';

mysqli_report( MYSQLI_REPORT_OFF );
$port = 3306;
$host = '127.0.0.1';

$mysqli = new mysqli( $host, $DB_USER, $DB_PASSWORD, $DB_NAME, $port );
if ( $mysqli->connect_error ) {
	$port = 10006;
	$mysqli = new mysqli( $host, $DB_USER, $DB_PASSWORD, $DB_NAME, $port );
}
if ( $mysqli->connect_error ) {
	echo "DB error: {$mysqli->connect_error}\n";
	exit( 1 );
}
$mysqli->set_charset( 'utf8mb4' );

/**
 * Fix double/triple UTF-8 mojibake.
 * Only applies if the converted result is valid UTF-8 (avoids corrupting correct data).
 */
function fix_mojibake( $str ) {
	// Windows-1252: reverts double mojibake (â„¢ → ™, Â® → ®, Ã± → ñ, etc.)
	$to_w1252 = @mb_convert_encoding( $str, 'Windows-1252', 'UTF-8' );
	if ( $to_w1252 !== false && @mb_check_encoding( $to_w1252, 'UTF-8' ) ) {
		$str = $to_w1252;
	}
	// Second pass for triple mojibake (Ã¢â€žÂ¢ → â„¢ → ™)
	$to_w1252 = @mb_convert_encoding( $str, 'Windows-1252', 'UTF-8' );
	if ( $to_w1252 !== false && @mb_check_encoding( $to_w1252, 'UTF-8' ) ) {
		$str = $to_w1252;
	}
	return $str;
}

// Search for products with mojibake patterns
$result = $mysqli->query(
	"SELECT id, name, sku FROM $table WHERE name LIKE '%Ã%' OR name LIKE '%Â%' OR name LIKE '%â%' OR name LIKE '%€%' OR name LIKE '%\\x80%'"
);

if ( ! $result || $result->num_rows === 0 ) {
	echo "No se encontraron productos con posible doble codificacion.\n";
	exit;
}

$fixed_count  = 0;
$skipped_count = 0;
$total_issues = $result->num_rows;
echo "Encontrados $total_issues productos con posible mojibake.\n\n";

while ( $row = $result->fetch_assoc() ) {
	$original = $row['name'];
	$fixed    = fix_mojibake( $original );

	if ( $fixed === $original ) {
		$skipped_count++;
		continue;
	}

	echo "  {$row['sku']}: " . mb_substr( $original, 0, 70 ) . "\n";
	echo "         → " . mb_substr( $fixed, 0, 70 ) . "\n";
	echo "         hex: " . bin2hex( $original ) . " → " . bin2hex( $fixed ) . "\n\n";

	if ( ! $dry_run ) {
		$stmt = $mysqli->prepare( "UPDATE $table SET name = ? WHERE id = ?" );
		$stmt->bind_param( 'si', $fixed, $row['id'] );
		$stmt->execute();
		$stmt->close();
	}
	$fixed_count++;
}

$result->close();
$mysqli->close();

echo "\nResumen: $fixed_count corregidos, $skipped_count sin cambios" . ( $dry_run ? ' (dry-run)' : '' ) . ".\n";
