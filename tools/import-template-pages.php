<?php
/**
 * Import template-pages-export.sql into production.
 * Auto-detects table prefix from wp-config.php and replaces
 * the source prefix automatically.
 *
 * Usage:
 *   php tools/import-template-pages.php [path/to/template-pages-export.sql]
 */

if ( ! in_array( PHP_SAPI, [ 'cli', 'cgi-fcgi' ], true ) ) {
	die( 'CLI only' );
}
header_remove( 'Content-type' );

$input = $argv[1] ?? __DIR__ . '/../template-pages-export.sql';
if ( ! file_exists( $input ) ) {
	echo "File not found: $input\n";
	exit( 1 );
}

$wp_config = __DIR__ . '/../../../../wp-config.php';
if ( ! file_exists( $wp_config ) ) {
	echo "wp-config not found\n";
	exit( 1 );
}

$config = file_get_contents( $wp_config );
foreach ( [ 'DB_NAME', 'DB_USER', 'DB_PASSWORD' ] as $const ) {
	if ( ! preg_match( "/define\(\s*'$const',\s*'([^']+)'/", $config, $m ) ) {
		echo "$const not found\n";
		exit( 1 );
	}
	$$const = $m[1];
}
preg_match( "/\\\$table_prefix\s*=\s*'([^']+)'/", $config, $m );
$production_prefix = $m[1] ?? 'wp_';

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

$sql = file_get_contents( $input );

// Detect source prefix (first INSERT INTO wp_ or tc_ etc.)
if ( preg_match( '/INSERT INTO (\w+?)posts /', $sql, $m ) ) {
	$source_prefix = $m[1];
	if ( $source_prefix !== $production_prefix ) {
		echo "Source prefix: {$source_prefix} → Production prefix: {$production_prefix}\n";
		$sql = str_replace( $source_prefix, $production_prefix, $sql );
	}
}

// Remove comments and split statements
$sql = preg_replace( '/-- .*/m', '', $sql );
$statements = array_filter( array_map( 'trim', explode( ";\n", $sql ) ) );

$count = 0;
$errors = [];
foreach ( $statements as $stmt ) {
	if ( empty( $stmt ) || stripos( $stmt, 'SET NAMES' ) === 0 ) {
		continue;
	}
	if ( ! $mysqli->query( $stmt ) ) {
		$errors[] = $mysqli->error . ' (stmt: ' . substr( $stmt, 0, 80 ) . '...)';
	} else {
		$count++;
	}
}

echo "\nEjecutadas: $count sentencias.\n";
if ( $errors ) {
	echo "Errores (" . count( $errors ) . "):\n";
	foreach ( $errors as $e ) {
		echo "  - $e\n";
	}
}

// Re-run the UPDATE mapping from the SQL comments
if ( preg_match_all( '/-- .*\(old ID: (\d+), slug: ([^)]+)\)/', file_get_contents( $input ), $matches, PREG_SET_ORDER ) ) {
	$old_ids = [];
	$slug_map = [];
	foreach ( $matches as $m ) {
		$old_ids[] = (int) $m[1];
		$slug_map[] = $m[2];
	}

	if ( $old_ids ) {
		$in = implode( ', ', $old_ids );
		$update_sql = "UPDATE {$production_prefix}aoe_catalog_manufacturers m
			JOIN {$production_prefix}posts p ON p.post_name = (
			  SELECT post_name FROM {$production_prefix}posts WHERE ID = m.wp_post_id
			)
			SET m.wp_post_id = p.ID
			WHERE m.wp_post_id IN ($in)";

		if ( $mysqli->query( $update_sql ) ) {
			echo "UPDATE de mapping ejecutado correctamente.\n";

			// Show new IDs
			$result = $mysqli->query( "SELECT m.id, m.name, m.wp_post_id, p.post_title
				FROM {$production_prefix}aoe_catalog_manufacturers m
				JOIN {$production_prefix}posts p ON p.ID = m.wp_post_id
				WHERE m.wp_post_id IN ($in)" );
			echo "\nNuevos IDs asignados:\n";
			while ( $row = $result->fetch_assoc() ) {
				echo "  {$row['name']} → wp_post_id: {$row['wp_post_id']} (post: {$row['post_title']})\n";
			}
		} else {
			echo "Error en UPDATE mapping: {$mysqli->error}\n";
		}
	}
}

echo "\nListo.\n";
