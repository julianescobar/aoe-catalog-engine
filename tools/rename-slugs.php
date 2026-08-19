<?php
/**
 * Script to rename manufacturer slugs in the database.
 *
 * Usage:
 *   php tools/rename-slugs.php
 *
 * Edit the $mapping array below with old_slug => new_slug pairs.
 * Files must be updated separately via FTP.
 */

$mapping = [
	'amphenolanytek'    => 'amphenol-anytek',
	'amphenolltw'       => 'amphenol-ltw',
	'amphenolrf'        => 'amphenol-rf',
	'amphenollutze'     => 'amphenol-lutze',
	'amphenolindustrial'=> 'amphenol-industrial',
	'amphenolconec'     => 'amphenol-conec',
	'medikabel'         => 'medi-kabel',
	'mhconnectors'      => 'mh-connectors',
];

if ( empty( $mapping ) ) {
	echo "No mapping defined. Edit the \$mapping array in this file.\n";
	exit( 1 );
}

$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
if ( ! file_exists( $wp_load ) ) {
	$wp_load = dirname( __DIR__, 5 ) . '/wp-load.php';
}
if ( ! file_exists( $wp_load ) ) {
	die( "Could not find wp-load.php. Run from plugin root or adjust path.\n" );
}
require_once $wp_load;

$table = $wpdb->prefix . 'aoe_catalog_manufacturers';

foreach ( $mapping as $old => $new ) {
	$result = $wpdb->update(
		$table,
		[ 'slug' => $new ],
		[ 'slug' => $old ],
		[ '%s' ],
		[ '%s' ]
	);
	$count = is_int( $result ) ? $result : 0;
	echo "$old -> $new ({$count} rows)\n";
}

echo "\nDone. Files must be updated separately via FTP.\n";
