<?php
/**
 * Restore catalog database backup — no prompts, no warnings.
 * Directly truncates and re-imports the 5 aoe_catalog_* tables.
 *
 * Usage: php tools/restore-db-force.php [path/to/dump.sql]
 * Default: tools/../aoe-catalog-export-new.sql
 */

if ( ! in_array( PHP_SAPI, [ 'cli', 'cgi-fcgi' ], true ) ) {
	die( 'CLI only' );
}
header_remove( 'Content-type' );

$logFile = __DIR__ . '/../restore-progress.log';
function log_msg( $msg ) {
	global $logFile;
	file_put_contents( $logFile, date( 'H:i:s' ) . " $msg\n", FILE_APPEND );
}

ob_implicit_flush( true );
if ( ob_get_level() ) { ob_end_flush(); }

if ( ini_get( 'memory_limit' ) !== '-1' ) {
	ini_set( 'memory_limit', '1024M' );
}

$args = array_values( array_filter( $argv, fn( $a ) => $a[0] !== '-' ) );
$dump = $args[1] ?? __DIR__ . '/../aoe-catalog-export-new.sql';
if ( ! file_exists( $dump ) ) {
	log_msg( "File not found: $dump" );
	exit( 1 );
}

$wp_config = __DIR__ . '/../../../../wp-config.php';
if ( ! file_exists( $wp_config ) ) {
	log_msg( "wp-config.php not found at $wp_config" );
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
if ( strpos( $host, ':' ) !== false ) {
	[ $host, $port ] = explode( ':', $host, 2 );
	$port = (int) $port;
}

$mysqli = new mysqli();
$mysqli->real_connect( $host, $user, $pass, $dbname, $port );
if ( $mysqli->connect_error ) {
	log_msg( "DB error: {$mysqli->connect_error}" );
	exit( 1 );
}
$mysqli->set_charset( 'utf8mb4' );

$head = file_get_contents( $dump, false, null, 0, 8192 );
preg_match( '/`([^`]*)aoe_catalog_/', $head, $m );
$dump_prefix = $m[1] ?? 'wp_';

$catalog_tables = [
	'aoe_catalog_manufacturers',
	'aoe_catalog_categories',
	'aoe_catalog_products',
	'aoe_catalog_pregenerated_pages',
	'aoe_catalog_page_segments',
];

log_msg( "Truncating tables..." );
foreach ( $catalog_tables as $table ) {
	$full = $prefix . $table;
	if ( ! $mysqli->query( "TRUNCATE TABLE `{$full}`" ) ) {
		log_msg( "[ERR] {$full}: {$mysqli->error}" );
		exit( 1 );
	}
	log_msg( "[OK] {$full}" );
}

log_msg( "Importing..." );
$fh = fopen( $dump, 'r' );
$stmt   = '';
$total  = 0;
$errors = 0;
$lineNo = 0;

while ( ( $line = fgets( $fh ) ) !== false ) {
	$lineNo++;
	$trimmed = trim( $line );
	if ( $trimmed === '' || strpos( $trimmed, '--' ) === 0 || strpos( $trimmed, '/*' ) === 0 ) {
		continue;
	}
	$stmt .= $line;
	if ( substr( rtrim( $stmt ), -1 ) === ';' ) {
		if ( $dump_prefix !== $prefix ) {
			$stmt = str_replace(
				"`{$dump_prefix}aoe_catalog_",
				"`{$prefix}aoe_catalog_",
				$stmt
			);
		}
		if ( ! $mysqli->query( $stmt ) ) {
			log_msg( "[ERR] Line {$lineNo}: " . substr( $mysqli->error, 0, 120 ) );
			$errors++;
			if ( $errors > 20 ) {
				log_msg( "Too many errors, aborting." );
				break;
			}
		} else {
			$total++;
		}
		$stmt = '';
		if ( $total > 0 && $total % 10000 === 0 ) {
			log_msg( "  {$total} statements processed..." );
		}
	}
}

fclose( $fh );
$mysqli->close();

log_msg( "Done. {$total} statements executed, {$errors} errors." );
if ( $errors === 0 ) {
	log_msg( "Restore completed successfully." );
}
