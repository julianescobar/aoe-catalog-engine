<?php
/**
 * Export catalogo_online CPT posts with their Fusion Builder metadata.
 * Exports WITHOUT fixed IDs to avoid production conflicts.
 *
 * Usage:
 *   php tools/export-template-pages.php [output.sql]
 */

if ( ! in_array( PHP_SAPI, [ 'cli', 'cgi-fcgi' ], true ) ) {
	die( 'CLI only' );
}
header_remove( 'Content-type' );

$output = $argv[1] ?? __DIR__ . '/../template-pages-export.sql';

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
$p = $m[1] ?? 'wp_';

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

$fh = fopen( $output, 'w' );
fwrite( $fh, "-- Template pages export (ID-less)\nSET NAMES utf8mb4;\n\n" );

// Get posts referenced by manufacturers
$result = $mysqli->query(
	"SELECT p.* FROM {$p}posts p
	 WHERE p.post_type = 'catalogo_online'
	 AND p.ID IN (SELECT m.wp_post_id FROM {$p}aoe_catalog_manufacturers m WHERE m.wp_post_id > 0)"
);

$count_posts = 0;
$id_map = [];

while ( $row = $result->fetch_assoc() ) {
	$old_id = (int) $row['ID'];
	$post = $mysqli->real_escape_string( $row['post_content'] );
	$title = $mysqli->real_escape_string( $row['post_title'] );
	$name = $mysqli->real_escape_string( $row['post_name'] );
	$excerpt = $mysqli->real_escape_string( $row['post_excerpt'] );
	$date = $mysqli->real_escape_string( $row['post_date'] );
	$modified = $mysqli->real_escape_string( $row['post_modified'] );

	fwrite( $fh, "-- Post: $title (old ID: $old_id, slug: $name)\n" );
	fwrite( $fh, "INSERT INTO {$p}posts (post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, post_name, post_modified, post_modified_gmt, post_type, comment_status, ping_status) VALUES ('$date', '$date', '$post', '$title', '$excerpt', 'publish', '$name', '$modified', '$modified', 'catalogo_online', 'closed', 'closed');\n" );
	fwrite( $fh, "SET @new_post_id = LAST_INSERT_ID();\n" );

	// Export postmeta
	$meta_result = $mysqli->query( "SELECT meta_key, meta_value FROM {$p}postmeta WHERE post_id = $old_id" );
	while ( $meta = $meta_result->fetch_assoc() ) {
		$key = $mysqli->real_escape_string( $meta['meta_key'] );
		$val = $mysqli->real_escape_string( $meta['meta_value'] );
		fwrite( $fh, "INSERT INTO {$p}postmeta (post_id, meta_key, meta_value) VALUES (@new_post_id, '$key', '$val');\n" );
	}
	$meta_result->close();
	fwrite( $fh, "\n" );

	$id_map[] = [ 'old_id' => $old_id, 'slug' => $name, 'title' => $row['post_title'] ];
	$count_posts++;
}
$result->close();

fclose( $fh );

echo "Exportados: $count_posts posts de tipo catalogo_online.\n";
echo "Archivo: $output\n\n";
echo "--- MAPA DE IDs (para actualizar wp_post_id en produccion) ---\n";
echo "Ejecuta esto en produccion DESPUES de importar:\n\n";
echo "UPDATE {$p}aoe_catalog_manufacturers m\n";
echo "JOIN {$p}posts p ON p.post_name = (\n";
echo "  SELECT post_name FROM {$p}posts WHERE ID = m.wp_post_id\n";
echo ")\n";
echo "SET m.wp_post_id = p.ID\n";
echo "WHERE m.wp_post_id IN (" . implode(', ', array_column($id_map, 'old_id')) . ");\n\n";
echo "O busca manualmente los nuevos IDs por post_name:\n";
foreach ( $id_map as $item ) {
	echo "  '{$item['title']}' (old: {$item['old_id']}) → post_name: '{$item['slug']}'\n";
}
