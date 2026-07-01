<?php
/**
 * Server diagnostics: PHP + OPCache info.
 *
 * Via browser: https://tc-componentes.es/wp-content/plugins/aoe-catalog-engine/tools/check-opcache.php
 * Via WP CLI:  wp eval-file tools/check-opcache.php
 */

$is_cli = defined( 'WP_CLI' ) && WP_CLI;

$info = [
	// PHP core
	'PHP Version'    => phpversion(),
	'max_execution_time' => ini_get( 'max_execution_time' ) . 's',
	'max_input_time'     => ini_get( 'max_input_time' ) . 's',
	'memory_limit'       => ini_get( 'memory_limit' ),
	'upload_max_filesize' => ini_get( 'upload_max_filesize' ),
	'post_max_size'      => ini_get( 'post_max_size' ),
	'max_input_vars'     => ini_get( 'max_input_vars' ),

	// OPCache
	'opcache.enable'   => ini_get( 'opcache.enable' ) ? 'YES' : 'NO',
	'opcache.memory_consumption' => ini_get( 'opcache.memory_consumption' ) . 'MB',
	'opcache.max_accelerated_files' => ini_get( 'opcache.max_accelerated_files' ),
	'opcache.revalidate_freq' => ini_get( 'opcache.revalidate_freq' ) . 's',
	'opcache.validate_timestamps' => ini_get( 'opcache.validate_timestamps' ) ? 'ON' : 'OFF',
	'opcache.enable_cli' => ini_get( 'opcache.enable_cli' ) ? 'YES' : 'NO',
	'opcache.max_wasted_percentage' => ini_get( 'opcache.max_wasted_percentage' ) . '%',

	// OPCache status
	'opcache_hit_rate' => function_exists( 'opcache_get_status' )
		? ( opcache_get_status( false )['opcache_statistics']['opcache_hit_rate'] ?? 'N/A' ) . '%'
		: 'N/A',
	'opcache_cache_full' => function_exists( 'opcache_get_status' )
		? ( opcache_get_status( false )['cache_full'] ? 'YES' : 'NO' )
		: 'N/A',
	'opcache_num_cached_scripts' => function_exists( 'opcache_get_status' )
		? ( opcache_get_status( false )['opcache_statistics']['num_cached_scripts'] ?? 'N/A' )
		: 'N/A',

	// Memory (OPcache)
	'opcache_memory_used' => function_exists( 'opcache_get_status' )
		? round( opcache_get_status( false )['memory_usage']['used_memory'] / 1048576, 1 ) . 'MB'
		: 'N/A',
	'opcache_memory_free' => function_exists( 'opcache_get_status' )
		? round( opcache_get_status( false )['memory_usage']['free_memory'] / 1048576, 1 ) . 'MB'
		: 'N/A',
];

// Max widths for alignment
$label_width = max( array_map( 'strlen', array_keys( $info ) ) ) + 2;

if ( $is_cli ) {
	WP_CLI::line( "=== Server Diagnostics ===\n" );
	foreach ( $info as $key => $val ) {
		WP_CLI::line( str_pad( $key . ':', $label_width ) . $val );
	}
} else {
	echo "<h2>Server Diagnostics</h2>";
	echo "<table style='border-collapse:collapse;font-family:monospace;'>";
	foreach ( $info as $key => $val ) {
		echo "<tr><td style='padding:2px 8px;font-weight:bold;'>{$key}:</td><td style='padding:2px 8px;'>{$val}</td></tr>";
	}
	echo "</table>";

	// Raw OPCache status if available
	if ( function_exists( 'opcache_get_status' ) ) {
		echo "<h3>Raw OPCache Status</h3><pre>" . print_r( opcache_get_status( false ), true ) . "</pre>";
	}
}
