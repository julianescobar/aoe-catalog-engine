<?php
/**
 * CLI script: warm file cache for manufacturer catalog pages in parallel.
 *
 * Usage:
 *   php tools/warm-cache.php                               # All manufacturers
 *   php tools/warm-cache.php --manufacturer=samtec          # Single manufacturer
 *   php tools/warm-cache.php --manufacturer=samtec --site-url=https://example.com
 *
 * Configurable concurrency via AOE_WARM_CONCURRENCY env var (default: 20).
 * DB_HOST override via AOE_DB_HOST env var (default: 127.0.0.1).
 * Site URL override via AOE_SITE_URL env var or --site-url= (no trailing slash).
 */

namespace AOE\CatalogEngine\Tools;

if ( 'cli' !== php_sapi_name() && ! defined( 'WP_CLI' ) ) {
	die( 'CLI only' );
}

// ---- Bootstrap DB without WordPress ----
$wp_config_path = dirname( __DIR__, 4 ) . '/wp-config.php';
if ( ! file_exists( $wp_config_path ) ) {
	fwrite( STDERR, "wp-config.php not found at: $wp_config_path\n" );
	exit( 1 );
}

// Extract DB constants and table prefix from wp-config
$config_src = file_get_contents( $wp_config_path );

$define_pattern = '/define\s*\(\s*[\'"](\w+)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)/';
preg_match_all( $define_pattern, $config_src, $defines, PREG_SET_ORDER );

$db_config = [];
foreach ( $defines as $d ) {
	if ( in_array( $d[1], [ 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST' ] ) ) {
		$db_config[ $d[1] ] = $d[2];
	}
}

$table_prefix = '';
if ( preg_match( '/\$table_prefix\s*=\s*[\'"]([^\'"]+)[\'"]/', $config_src, $m ) ) {
	$table_prefix = $m[1];
}

// Allow env override for DB_HOST (Local needs 127.0.0.1)
if ( getenv( 'AOE_DB_HOST' ) ) {
	$db_config['DB_HOST'] = getenv( 'AOE_DB_HOST' );
} elseif ( ! isset( $db_config['DB_HOST'] ) || $db_config['DB_HOST'] === 'localhost' ) {
	$db_config['DB_HOST'] = '127.0.0.1';
}

if ( empty( $db_config['DB_NAME'] ) || empty( $db_config['DB_USER'] ) || empty( $table_prefix ) ) {
	fwrite( STDERR, "Could not extract DB config from wp-config.php\n" );
	exit( 1 );
}

$mysqli = new \mysqli(
	$db_config['DB_HOST'],
	$db_config['DB_USER'],
	$db_config['DB_PASSWORD'],
	$db_config['DB_NAME']
);

if ( $mysqli->connect_error ) {
	fwrite( STDERR, "DB connection failed: {$mysqli->connect_error}\n" );
	fwrite( STDERR, "Try: AOE_DB_HOST=localhost php tools/warm-cache.php\n" );
	exit( 1 );
}

// ---- Parse CLI args ----
$longopts = [ 'manufacturer:', 'site-url:' ];
$args     = getopt( '', $longopts );
$filter_slug = $args['manufacturer'] ?? '';

// ---- Site URL ----
$site_url = getenv( 'AOE_SITE_URL' ) ?: ( $args['site-url'] ?? '' );
if ( ! $site_url ) {
	fwrite( STDERR, "Site URL required. Set --site-url=https://... or AOE_SITE_URL env var.\n" );
	exit( 1 );
}
$site_url = rtrim( $site_url, '/' );

// ---- Query pages ----
$table_pages = $table_prefix . 'aoe_catalog_pregenerated_pages';
$table_m     = $table_prefix . 'aoe_catalog_manufacturers';

if ( $filter_slug ) {
	$stmt = $mysqli->prepare(
		"SELECT p.slug, m.slug AS manufacturer_slug
		 FROM $table_pages p
		 JOIN $table_m m ON p.manufacturer_id = m.id
		 WHERE m.slug = ?"
	);
	$stmt->bind_param( 's', $filter_slug );
	$stmt->execute();
	$result = $stmt->get_result();
} else {
	$result = $mysqli->query(
		"SELECT p.slug, m.slug AS manufacturer_slug
		 FROM $table_pages p
		 JOIN $table_m m ON p.manufacturer_id = m.id"
	);
}

$pages = $result->fetch_all( MYSQLI_ASSOC );
$result->free();
$mysqli->close();

if ( empty( $pages ) ) {
	echo "No se encontraron páginas" . ( $filter_slug ? " para {$filter_slug}" : '' ) . ".\n";
	exit;
}

$concurrency = max( 1, (int) ( getenv( 'AOE_WARM_CONCURRENCY' ) ?: 20 ) );

$urls = [];
foreach ( $pages as $p ) {
	$urls[] = $site_url . '/catalogo/' . $p['slug'] . '/';
}

$total = count( $urls );
echo "Fabricante: " . ( $filter_slug ?: 'todos' ) . "\n";
echo "Total páginas: {$total}\n";
echo "Concurrencia: {$concurrency}\n";
echo "Estimado: ~" . ceil( $total / $concurrency * 13 / 60 ) . " min\n\n";

// ---- Parallel curl ----
$multi = curl_multi_init();
$handles = [];
$ok = 0;
$errors = [];
$start_time = microtime( true );

for ( $i = 0; $i < min( $concurrency, $total ); $i++ ) {
	$handles[ $i ] = curl_init( $urls[ $i ] );
	curl_setopt_array( $handles[ $i ], [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT        => 180,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_SSL_VERIFYPEER => false,
		CURLOPT_HTTPHEADER     => [ 'X-AOE-Warm: 1' ],
	] );
	curl_multi_add_handle( $multi, $handles[ $i ] );
}

$next_idx = $concurrency;
$running = null;
$completed = 0;

do {
	do {
		$status = curl_multi_exec( $multi, $running );
	} while ( $status === CURLM_CALL_MULTI_PERFORM );

	while ( $done = curl_multi_info_read( $multi, $msgs ) ) {
		$ch = $done['handle'];
		$idx = array_search( $ch, $handles, true );
		$http = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$body = curl_multi_getcontent( $ch );
		curl_multi_remove_handle( $multi, $ch );
		curl_close( $ch );
		$completed++;

		if ( $http === 200 && strlen( $body ) > 1000 ) {
			$ok++;
		} else {
			$errors[] = [ $urls[ $idx ], $http, strlen( $body ) ];
		}

		$elapsed = microtime( true ) - $start_time;
		$rate = $completed / max( 1, $elapsed );
		$eta = ( $total - $completed ) / max( 0.01, $rate );
		printf( "\r[%d/%d] OK: %d | Err: %d | %0.1f/s | ETA: %d min   ",
			$completed, $total, $ok, count( $errors ), $rate, ceil( $eta / 60 ) );

		if ( $next_idx < $total ) {
			$ch2 = curl_init( $urls[ $next_idx ] );
			curl_setopt_array( $ch2, [
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 180,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_SSL_VERIFYPEER => false,
				CURLOPT_HTTPHEADER     => [ 'X-AOE-Warm: 1' ],
			] );
			curl_multi_add_handle( $multi, $ch2 );
			$handles[ $next_idx ] = $ch2;
			$next_idx++;
		}
	}

	if ( $running ) {
		curl_multi_select( $multi, 5 );
	}
} while ( $running || $next_idx < $total );

curl_multi_close( $multi );

$elapsed = microtime( true ) - $start_time;
echo "\n\n--- Resumen ---\n";
echo "Total: {$total} | OK: {$ok} | Errores: " . count( $errors ) . "\n";
echo "Tiempo: " . round( $elapsed / 60, 1 ) . " min\n";
if ( $errors ) {
	echo "\nErrores:\n";
	foreach ( array_slice( $errors, 0, 20 ) as $e ) {
		echo "  {$e[0]} -> HTTP {$e[1]}, size {$e[2]}\n";
	}
	if ( count( $errors ) > 20 ) {
		echo "  ... y " . ( count( $errors ) - 20 ) . " más\n";
	}
}
