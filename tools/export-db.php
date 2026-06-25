<?php
/**
 * Export catalog tables to SQL.
 *
 * Usage: php tools/export-db.php [output.sql]
 *
 * Reads DB_HOST, DB_USER, DB_PASSWORD, DB_NAME, DB_PREFIX from env or wp-config.
 * DB_HOST can include port, e.g. "127.0.0.1:10006"
 */

if ( ! in_array( PHP_SAPI, [ 'cli', 'cgi-fcgi' ], true ) ) {
	die( 'CLI only' );
}
header_remove( 'Content-type' );
ini_set( 'memory_limit', '2048M' );

$timestamp = date( 'Ymd-His' );
$output = $argv[1] ?? __DIR__ . '/../aoe-catalog-export-' . $timestamp . '.sql';

$wp_config = __DIR__ . '/../../../../wp-config.php';
if ( ! file_exists( $wp_config ) ) {
	echo "wp-config not found\n";
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

mysqli_report( MYSQLI_REPORT_OFF );
$mysqli = new mysqli();
$mysqli->options( MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 0 );
$mysqli->real_connect( $host, $user, $pass, $dbname, $port );
if ( $mysqli->connect_error && $port === 3306 ) {
	$port = 10006;
	$mysqli->real_connect( $host, $user, $pass, $dbname, $port );
}
if ( $mysqli->connect_error ) {
	echo "DB error: {$mysqli->connect_error}\n";
	exit( 1 );
}
$mysqli->set_charset( 'utf8mb4' );

$tables = [
	'manufacturers', 'categories', 'products',
	'pregenerated_pages', 'page_segments',
];

$fh = fopen( $output, 'w' );
if ( ! $fh ) {
	echo "Cannot write: $output\n";
	exit( 1 );
}

fwrite( $fh, "-- AOE Catalog Engine export\n" );
fwrite( $fh, "-- Generated: " . date( 'Y-m-d H:i:s' ) . "\n" );
fwrite( $fh, "-- Prefix: {$prefix}\n\nSET NAMES utf8mb4;\n\n" );

$tableRows = 0;
foreach ( $tables as $table ) {
	$full = $prefix . 'aoe_catalog_' . $table;

	$result = $mysqli->query( "SELECT * FROM `{$full}`", MYSQLI_USE_RESULT );
	if ( ! $result ) {
		fwrite( $fh, "-- Table {$full}: {$mysqli->error}\n\n" );
		continue;
	}

	$fields = $result->fetch_fields();
	$col_names = [];
	foreach ( $fields as $f ) {
		$col_names[] = $f->name;
	}
	$col_list = '`' . implode( '`, `', $col_names ) . '`';

	$rowsThis = 0;
	while ( $row = $result->fetch_row() ) {
		$rowsThis++;
		$values = [];
		foreach ( $row as $val ) {
			if ( $val === null ) {
				$values[] = 'NULL';
			} else {
				$values[] = "'" . $mysqli->real_escape_string( $val ) . "'";
			}
		}
		fwrite( $fh, "INSERT INTO `{$full}` ({$col_list}) VALUES (" . implode( ', ', $values ) . ");\n" );

		if ( $rowsThis % 5000 === 0 ) {
			echo "  {$full}: {$rowsThis} rows...\n";
		}
	}
	$result->free();
	unset( $result );

	fwrite( $fh, "\n" );
	echo "  {$full}: {$rowsThis} rows\n";
	$tableRows += $rowsThis;
}

fclose( $fh );
$mysqli->close();

$size = round( filesize( $output ) / 1024 / 1024, 2 );
echo "\nExport saved: {$output} ({$size} MB, {$tableRows} rows total)\n";
