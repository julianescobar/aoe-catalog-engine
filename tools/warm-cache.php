<?php
/**
 * CLI script: warm file cache for ALL catalog pages in parallel.
 *
 * Usage: wp eval-file tools/warm-cache.php
 *
 * Configurable concurrency via AOE_WARM_CONCURRENCY env var (default: 20).
 * Tiempo estimado: 5863 páginas / 20 paralelo * 13s ≈ 63 minutos
 */

namespace AOE\CatalogEngine\Tools;

if ( 'cli' !== php_sapi_name() && ! defined( 'WP_CLI' ) ) {
	die( 'CLI only' );
}

global $wpdb;

$concurrency = max( 1, (int) ( getenv( 'AOE_WARM_CONCURRENCY' ) ?: 20 ) );

$table_pages = $wpdb->prefix . 'aoe_catalog_pregenerated_pages';
$table_m     = $wpdb->prefix . 'aoe_catalog_manufacturers';

$pages = $wpdb->get_results(
	"SELECT p.slug, m.slug AS manufacturer_slug
	 FROM $table_pages p
	 JOIN $table_m m ON p.manufacturer_id = m.id"
);

if ( empty( $pages ) ) {
	echo "No se encontraron páginas.\n";
	exit;
}

$site_url = rtrim( site_url(), '/' );
$urls = [];
foreach ( $pages as $p ) {
	$urls[] = $site_url . '/catalogo/' . $p->slug . '/';
}

$total = count( $urls );
echo "Total páginas: {$total}\n";
echo "Concurrencia: {$concurrency}\n";
echo "Estimado: ~" . ceil( $total / $concurrency * 13 / 60 ) . " min\n\n";

// Parallel curl
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

		// Add next URL if available
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
