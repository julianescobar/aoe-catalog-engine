<?php
/**
 * Restore catalog database backup (standalone, no WordPress).
 *
 * Usage: php tools/restore-db.php [path/to/dump.sql]
 *
 * Default: tools/../aoe-catalog-export-new.sql
 * Truncates ONLY the 5 aoe_catalog_* tables before importing.
 * Handles table prefix differences automatically.
 * DB_HOST can include port, e.g. "127.0.0.1:10006"
 */

if ( PHP_SAPI !== 'cli' ) {
	die( 'CLI only' );
}
if ( ini_get( 'memory_limit' ) !== '-1' ) {
	ini_set( 'memory_limit', '1024M' );
}

$dump = $argv[1] ?? __DIR__ . '/../aoe-catalog-export-new.sql';
if ( ! file_exists( $dump ) ) {
	echo "File not found: $dump\n";
	exit( 1 );
}

// Load DB credentials from wp-config
$wp_config = __DIR__ . '/../../../../wp-config.php';
if ( ! file_exists( $wp_config ) ) {
	echo "wp-config.php not found at $wp_config\n";
	exit( 1 );
}

$config = file_get_contents( $wp_config );
$defines = [];
preg_match_all( "/define\s*\(\s*['\"](DB_[A-Z_]+)['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $config, $m, PREG_SET_ORDER );
foreach ( $m as $match ) {
	$defines[ $match[1] ] = $match[2];
}
preg_match( "/\\\$table_prefix\s*=\s*['\"]([^'\"]+)['\"]/", $config, $m );
$defines['DB_PREFIX'] = $m[1] ?? 'wp_';

$host   = getenv( 'DB_HOST' ) ?: ( $defines['DB_HOST'] ?? 'localhost' );
$user   = getenv( 'DB_USER' ) ?: ( $defines['DB_USER'] ?? 'root' );
$pass   = getenv( 'DB_PASSWORD' ) ?: ( $defines['DB_PASSWORD'] ?? '' );
$dbname = getenv( 'DB_NAME' ) ?: ( $defines['DB_NAME'] ?? 'local' );
$prefix = getenv( 'DB_PREFIX' ) ?: $defines['DB_PREFIX'];

$port = 3306;
if ( str_contains( $host, ':' ) ) {
	[ $host, $port ] = explode( ':', $host, 2 );
	$port = (int) $port;
}

$mysqli = new mysqli();
$mysqli->real_connect( $host, $user, $pass, $dbname, $port );
if ( $mysqli->connect_error ) {
	echo "DB error: {$mysqli->connect_error}\n";
	exit( 1 );
}
$mysqli->set_charset( 'utf8mb4' );

// Detect prefix in dump
$head = file_get_contents( $dump, false, null, 0, 8192 );
preg_match( '/`([^`]*)aoe_catalog_/', $head, $m );
$dump_prefix = $m[1] ?? 'wp_';

$catalog_tables = [
	'aoe_catalog_manufacturers',
	'aoe_catalog_categories',
	'aoe_catalog_products',
	'aoe_catalog_pregenerated_pages',
	'aoe_catalog_page_segments',
];

echo "Dump prefix:   '{$dump_prefix}'\n";
echo "Site prefix:   '{$prefix}'\n";

if ( $dump_prefix !== $prefix ) {
	echo "Prefixes differ, will replace during import.\n";
}

echo "\nWARNING: This will TRUNCATE and re-import these tables:\n";
foreach ( $catalog_tables as $t ) {
	echo "  - {$prefix}{$t}\n";
}
echo "\nProceed? [y/N] ";
$line = trim( fgets( STDIN ) );
if ( strtolower( $line ) !== 'y' ) {
	echo "Cancelled.\n";
	exit;
}

// Truncate
echo "\nTruncating...\n";
foreach ( $catalog_tables as $table ) {
	$full = $prefix . $table;
	if ( ! $mysqli->query( "TRUNCATE TABLE `{$full}`" ) ) {
		echo "  [ERR] {$full}: {$mysqli->error}\n";
		exit( 1 );
	}
	echo "  [OK] Truncated {$full}\n";
}

// Import
echo "\nImporting...\n";
$fh = fopen( $dump, 'r' );
if ( ! $fh ) {
	echo "[ERR] Cannot open dump file\n";
	exit( 1 );
}

$stmt   = '';
$total  = 0;
$errors = 0;
$lineNo = 0;

while ( ( $line = fgets( $fh ) ) !== false ) {
	$lineNo++;

	$trimmed = trim( $line );
	if ( $trimmed === '' || str_starts_with( $trimmed, '--' ) || str_starts_with( $trimmed, '/*' ) ) {
		continue;
	}

	$stmt .= $line;

	if ( str_ends_with( trim( $stmt ), ';' ) ) {
		if ( $dump_prefix !== $prefix ) {
			$stmt = str_replace(
				"`{$dump_prefix}aoe_catalog_",
				"`{$prefix}aoe_catalog_",
				$stmt
			);
		}

		if ( ! $mysqli->query( $stmt ) ) {
			echo "  [ERR] Line {$lineNo}: " . substr( $mysqli->error, 0, 120 ) . "\n";
			$errors++;
			if ( $errors > 20 ) {
				echo "Too many errors, aborting.\n";
				break;
			}
		} else {
			$total++;
		}
		$stmt = '';

		if ( $total % 10000 === 0 ) {
			echo "  {$total} statements processed...\n";
		}
	}
}

fclose( $fh );
$mysqli->close();

echo "\nDone. {$total} statements executed, {$errors} errors.\n";
if ( $errors === 0 ) {
	echo "Restore completed successfully.\n";
}
