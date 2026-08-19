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
	// 'old_slug' => 'new_slug',
];

if ( empty( $mapping ) ) {
	echo "No mapping defined. Edit the \$mapping array in this file.\n";
	exit( 1 );
}

require_once dirname( __DIR__ ) . '/wp-load.php';

$table = 'wp_aoe_catalog_manufacturers';

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
