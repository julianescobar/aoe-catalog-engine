<?php
/**
 * Plugin Name:       AOE Catalog Engine
 * Plugin URI:        https://aoe.com
 * Description:       A high-performance WordPress catalog system.
 * Version:           1.0.0
 * Author:            AOE
 * Author URI:        https://aoe.com
 * Text Domain:       aoe-catalog-engine
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'AOE_CATALOG_MEDIA_URL', content_url( 'uploads/catalogo' ) );

// Simple PSR-4 Autoloader fallback if composer is not used.
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
} else {
	spl_autoload_register( function ( $class ) {
		$prefix = 'AOE\\CatalogEngine\\';
		$base_dir = __DIR__ . '/src/';
		$len = strlen( $prefix );
		if ( strncmp( $prefix, $class, $len ) !== 0 ) {
			return;
		}
		$relative_class = substr( $class, $len );
		$file = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';
		if ( file_exists( $file ) ) {
			require $file;
		}
	} );
}

/**
 * The code that runs during plugin activation.
 */
function activate_aoe_catalog_engine() {
	require_once __DIR__ . '/src/Activator.php';
	\AOE\CatalogEngine\Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 */
function deactivate_aoe_catalog_engine() {
	// Deactivation code here
}

register_activation_hook( __FILE__, 'activate_aoe_catalog_engine' );
register_deactivation_hook( __FILE__, 'deactivate_aoe_catalog_engine' );

/**
 * Initialize the core plugin class.
 */
function run_aoe_catalog_engine() {
	$plugin = new \AOE\CatalogEngine\Plugin();
	$plugin->run();
}

run_aoe_catalog_engine();
