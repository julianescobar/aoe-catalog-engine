<?php
/**
 * CLI: Export wp_aoe_catalog_search_products to SQL or CSV.
 *
 * Usage:
 *   php tools/export-search-products.php --all --format=sql > search-export.sql
 *   php tools/export-search-products.php --all --format=csv > search-export.csv
 *   php tools/export-search-products.php --manufacturer=bivar --format=sql > bivar.sql
 *   php tools/export-search-products.php --manufacturer=bivar --format=csv > bivar.csv
 *   php tools/export-search-products.php --stats
 */

if ( ! in_array( PHP_SAPI, [ 'cli', 'cgi-fcgi' ], true ) ) {
	die( 'CLI only' . "\n" );
}

error_reporting( E_ALL & ~E_WARNING & ~E_NOTICE );
ini_set( 'memory_limit', '1G' );

// Parse args
$args = array_slice( $argv, 1 );
$manufacturer = '';
$format       = 'sql';
$all          = false;
$stats        = false;
$prefix       = '';

foreach ( $args as $arg ) {
	if ( preg_match( '/^--manufacturer=(.+)$/', $arg, $m ) ) {
		$manufacturer = $m[1];
	} elseif ( preg_match( '/^--format=(sql|csv)$/', $arg, $m ) ) {
		$format = $m[1];
	} elseif ( preg_match( '/^--prefix=(.+)$/', $arg, $m ) ) {
		$prefix = $m[1];
	} elseif ( $arg === '--all' ) {
		$all = true;
	} elseif ( $arg === '--stats' ) {
		$stats = true;
	} elseif ( $arg === '--help' || $arg === '-h' ) {
		echo <<<HELP
Usage: php tools/export-search-products.php [options]

Options:
  --all                   Export all manufacturers
  --manufacturer=SLUG     Export specific manufacturer
  --format=sql|csv        Output format (default: sql)
  --prefix=PREFIX         Table prefix for SQL output (default: wp_)
  --stats                 Show per-manufacturer row counts
  --help                  Show this help

Examples:
  php tools/export-search-products.php --all --format=sql --prefix=tc_ > search-export.sql
  php tools/export-search-products.php --manufacturer=bivar --format=csv > bivar.csv
  php tools/export-search-products.php --stats
HELP;
		exit( 0 );
	}
}

// Load WordPress
if ( $stats || $all || $manufacturer ) {
	if ( getenv( 'AOE_DB_HOST' ) && ! defined( 'DB_HOST' ) ) {
		define( 'DB_HOST', getenv( 'AOE_DB_HOST' ) );
	}
	$w = dirname( __DIR__, 4 ) . '/wp-load.php';
	if ( ! file_exists( $w ) ) {
		$w = dirname( __DIR__, 5 ) . '/wp-load.php';
	}
	if ( file_exists( $w ) ) {
		require_once $w;
	} else {
		die( "wp-load.php not found.\n" );
	}
}

global $wpdb;
$table = $wpdb->prefix . 'aoe_catalog_search_products';

// Stats mode
if ( $stats ) {
	$rows = $wpdb->get_results(
		"SELECT manufacturer_normalized, manufacturer_name, COUNT(*) AS cnt
		 FROM $table
		 GROUP BY manufacturer_normalized, manufacturer_name
		 ORDER BY cnt DESC"
	);
	$total = 0;
	echo sprintf( "%-30s %-30s %s\n", 'MANUFACTURER', 'NAME', 'ROWS' );
	echo str_repeat( '-', 75 ) . "\n";
	foreach ( $rows as $r ) {
		echo sprintf( "%-30s %-30s %s\n", $r->manufacturer_normalized, $r->manufacturer_name, number_format( $r->cnt ) );
		$total += (int) $r->cnt;
	}
	echo str_repeat( '-', 75 ) . "\n";
	echo sprintf( "%-30s %-30s %s\n", 'TOTAL', '', number_format( $total ) );
	exit( 0 );
}

// Validate args
if ( ! $all && ! $manufacturer ) {
	die( "Error: specify --all or --manufacturer=SLUG. Use --help for usage.\n" );
}

// Build WHERE clause
$where = '';
if ( $manufacturer ) {
	$manufacturer_normalized = strtoupper( preg_replace( '/[^a-zA-Z0-9]/', '', $manufacturer ) );
	$where = $wpdb->prepare(
		" WHERE manufacturer_normalized = %s",
		$manufacturer_normalized
	);
}

// Get total count
$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table $where" );
if ( 0 === $total ) {
	die( "No rows found.\n" );
}

$chunk = 1000;
$offset = 0;
$count = 0;

// Output SQL header
if ( 'sql' === $format ) {
	$sql_prefix = $prefix ?: $wpdb->prefix;
	$table_name = $sql_prefix . 'aoe_catalog_search_products';
	echo "-- Export from $table\n";
	echo "-- Filter: " . ( $manufacturer ? "manufacturer=$manufacturer" : "ALL" ) . "\n";
	echo "-- Rows: $total\n";
	echo "-- Prefix: $sql_prefix\n";
	echo "-- Generated: " . date( 'Y-m-d H:i:s' ) . "\n\n";
	echo "CREATE TABLE IF NOT EXISTS `$table_name` (\n";
	echo "  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,\n";
	echo "  `manufacturer_normalized` varchar(64) NOT NULL,\n";
	echo "  `manufacturer_name` varchar(255) NOT NULL,\n";
	echo "  `sku_normalized` varchar(255) NOT NULL,\n";
	echo "  `sku` varchar(255) NOT NULL,\n";
	echo "  `search_text` text NOT NULL,\n";
	echo "  `payload_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,\n";
	echo "  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,\n";
	echo "  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),\n";
	echo "  PRIMARY KEY (`id`),\n";
	echo "  UNIQUE KEY `uq_mfr_sku` (`manufacturer_normalized`,`sku`),\n";
	echo "  KEY `k_sku` (`sku_normalized`),\n";
	echo "  FULLTEXT KEY `ft_search` (`search_text`)\n";
	echo ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n\n";
}

// Output CSV header
if ( 'csv' === $format ) {
	echo "id,manufacturer_normalized,manufacturer_name,sku_normalized,sku,search_text,payload_json,created_at,updated_at\n";
}

// Export in chunks
while ( $offset < $total ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results( "SELECT * FROM $table $where ORDER BY id ASC LIMIT $chunk OFFSET $offset" );
	if ( empty( $rows ) ) {
		break;
	}

	foreach ( $rows as $r ) {
		if ( 'sql' === $format ) {
			$values = [
				$wpdb->prepare( '%s', $r->manufacturer_normalized ),
				$wpdb->prepare( '%s', $r->manufacturer_name ),
				$wpdb->prepare( '%s', $r->sku_normalized ),
				$wpdb->prepare( '%s', $r->sku ),
				$wpdb->prepare( '%s', $r->search_text ),
				$wpdb->prepare( '%s', $r->payload_json ),
				$wpdb->prepare( '%s', $r->created_at ),
				$wpdb->prepare( '%s', $r->updated_at ),
			];
			echo "INSERT INTO `$table_name` (`manufacturer_normalized`,`manufacturer_name`,`sku_normalized`,`sku`,`search_text`,`payload_json`,`created_at`,`updated_at`) VALUES (" . implode( ',', $values ) . ") ON DUPLICATE KEY UPDATE "
				. "manufacturer_name=VALUES(manufacturer_name), sku_normalized=VALUES(sku_normalized), sku=VALUES(sku), search_text=VALUES(search_text), payload_json=VALUES(payload_json), created_at=VALUES(created_at);\n";
		} else {
			$line = [
				$r->id,
				$r->manufacturer_normalized,
				$r->manufacturer_name,
				$r->sku_normalized,
				$r->sku,
				str_replace( '"', '""', $r->search_text ),
				str_replace( '"', '""', $r->payload_json ),
				$r->created_at,
				$r->updated_at,
			];
			$escaped = array_map( function( $v ) {
				return '"' . str_replace( '"', '""', $v ) . '"';
			}, $line );
			echo implode( ',', $escaped ) . "\n";
		}
		$count++;
	}

	$offset += $chunk;
	fprintf( STDERR, "\r  Exported: %d / %d", $count, $total );
}

fprintf( STDERR, "\n  Done: %d rows exported.\n", $count );
