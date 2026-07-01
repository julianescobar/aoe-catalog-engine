<?php
/**
 * Profile catalog page generation.
 * 
 * Usage:
 *   php tools/profile-catalog.php <url>
 * 
 * Example:
 *   php tools/profile-catalog.php https://dev.tc-componentes.es/catalogo/samtec/
 *   php tools/profile-catalog.php https://dev.tc-componentes.es/catalogo/samtec/erf8-series/
 *   php tools/profile-catalog.php https://dev.tc-componentes.es/catalogo/samtec/productos/
 */

if ( PHP_SAPI !== 'cli' ) {
	die( 'This script must be run via CLI.' );
}

$url = $argv[1] ?? '';
if ( empty( $url ) ) {
	die( "Usage: php tools/profile-catalog.php <url>\n" );
}

echo "Profiling: $url\n\n";

// Request without cache (add a cache-busting query param)
$url .= ( strpos( $url, '?' ) === false ? '?' : '&' ) . '_t=' . time();

echo str_repeat( '-', 60 ) . "\n";
echo " 1. Total time\n";
echo str_repeat( '-', 60 ) . "\n";

$ch = curl_init();
curl_setopt_array( $ch, [
	CURLOPT_URL            => $url,
	CURLOPT_RETURNTRANSFER => true,
	CURLOPT_HEADER         => true,
	CURLOPT_FOLLOWLOCATION => true,
	CURLOPT_TIMEOUT        => 120,
	CURLOPT_SSL_VERIFYPEER => false,
	CURLOPT_USERAGENT      => 'AOE-Profiler/1.0',
]);

// Measure specific timing
$events = [
	'start'         => microtime( true ),
	'dns_lookup'    => 0,
	'connect'       => 0,
	'start_transfer' => 0,
	'total'         => 0,
];

curl_setopt( $ch, CURLOPT_DNS_CACHE_TIMEOUT, 0 ); // Force DNS lookup

$response = curl_exec( $ch );
$info = curl_getinfo( $ch );
curl_close( $ch );

echo sprintf( "  DNS Lookup:      %0.4fs\n", $info['namelookup_time'] ?? 0 );
echo sprintf( "  Connect:         %0.4fs\n", $info['connect_time'] ?? 0 );
echo sprintf( "  TTFB (first byte): %0.4fs\n", $info['starttransfer_time'] ?? 0 );
echo sprintf( "  Total:           %0.4fs\n", $info['total_time'] ?? 0 );

echo "\n";
echo str_repeat( '-', 60 ) . "\n";
echo " 2. Response size\n";
echo str_repeat( '-', 60 ) . "\n";
echo sprintf( "  Size:            %0.2f KB\n", strlen( $response ) / 1024 );

echo "\n";
echo str_repeat( '-', 60 ) . "\n";
echo " 3. HTTP status\n";
echo str_repeat( '-', 60 ) . "\n";
echo "  Status:          " . ( $info['http_code'] ?? 'N/A' ) . "\n";

echo "\nTip: Run the SAME URL twice to compare cold vs cached.\n";
echo "Tip: If the site has HTTP auth, set CURLOPT_USERPWD in the script.\n";
