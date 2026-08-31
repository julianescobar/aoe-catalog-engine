<?php
/**
 * CLI: Validate wp_aoe_catalog_search_products rows for broken/inconsistent URLs.
 *
 * Checks each search row's payload_json['urls'] against the pregenerated pages
 * table and against the anchor expected by the frontend (catalog-render-html.php).
 *
 * Usage:
 *   php tools/validate-search-products.php --manufacturer=panduit --limit=200
 *   php tools/validate-search-products.php --manufacturer=bulgin --manufacturer=bivar
 *   php tools/validate-search-products.php --all --limit=0            (all rows)
 *   php tools/validate-search-products.php --stats --manufacturer=panduit
 *
 * The script is independent of the import pipeline.
 */

if ( PHP_SAPI !== 'cli' ) {
	die( "CLI only.\n" );
}

// ---- Parse args ----
$args = [];
foreach ( $argv as $arg ) {
	if ( 0 === strpos( $arg, '--' ) ) {
		$parts = explode( '=', substr( $arg, 2 ), 2 );
		$key = $parts[0];
		$val = $parts[1] ?? true;
		if ( 'manufacturer' === $key && true === $val ) {
			// repeated --manufacturer=a --manufacturer=b
			$args['manufacturer'][] = $val;
		} elseif ( array_key_exists( $key, $args ) && 'manufacturer' === $key ) {
			$args[ $key ][] = $val;
		} else {
			$args[ $key ] = $val;
		}
	}
}

// Normalize manufacturer arg into an array
if ( isset( $args['manufacturer'] ) ) {
	$args['manufacturer'] = (array) $args['manufacturer'];
}

$show_help = ( ! isset( $args['manufacturer'] ) && ! isset( $args['all'] ) && ! isset( $args['stats'] ) );
if ( $show_help ) {
	echo <<<HELP
Usage:
  php tools/validate-search-products.php --manufacturer=slug --limit=200
  php tools/validate-search-products.php --manufacturer=a --manufacturer=b
  php tools/validate-search-products.php --all --limit=0
  php tools/validate-search-products.php --stats [--manufacturer=slug]

Options:
  --manufacturer=slug   Validate one manufacturer (repeatable for several)
  --all                 Validate every indexed manufacturer
  --limit=N             Max rows to check per manufacturer (0 = all). Default 200
  --stats               Only show per-manufacturer row counts, no check
  --broken-only         Only print rows with issues (default shows summary per mfr)

HELP;
	exit( 0 );
}

ini_set( 'memory_limit', '1G' );
ob_implicit_flush( true );

// ---- Bootstrap WordPress ----
if ( getenv( 'AOE_DB_HOST' ) && ! defined( 'DB_HOST' ) ) {
	define( 'DB_HOST', getenv( 'AOE_DB_HOST' ) );
}
$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
if ( ! file_exists( $wp_load ) ) {
	$wp_load = dirname( __DIR__, 5 ) . '/wp-load.php';
}
if ( ! file_exists( $wp_load ) ) {
	die( "wp-load.php not found.\n" );
}
require_once $wp_load;

global $wpdb;

$table_search = $wpdb->prefix . 'aoe_catalog_search_products';

// ---- Helpers ----
function nv_slug( string $u ): string {
	$u = preg_replace( '#^https?://[^/]+#', '', $u );
	$u = preg_replace( '#^/catalogo/#', '', $u );
	$u = preg_replace( '/#.*$/', '', $u );
	return rtrim( $u, '/' );
}

// Frontend anchor generator (mirror of aoe_catalog_sku_anchor in catalog-render-html.php)
function nv_anchor( string $sku ): string {
	$text = preg_replace( '/[áàâã]/u', 'A', $sku );
	$text = preg_replace( '/[éèêë]/u', 'E', $text );
	$text = preg_replace( '/[íìîï]/u', 'I', $text );
	$text = preg_replace( '/[óòôõ]/u', 'O', $text );
	$text = preg_replace( '/[úùûü]/u', 'U', $text );
	$text = preg_replace( '/[ñ]/u', 'N', $text );
	$text = preg_replace( '/[ç]/u', 'C', $text );
	$text = strtoupper( $text );
	$text = preg_replace( '/[^A-Z0-9]/', '', $text );
	return 'producto-' . $text;
}

// ---- Load page slugs once ----
$page_set = array_fill_keys(
	$wpdb->get_col( "SELECT slug FROM {$wpdb->prefix}aoe_catalog_pregenerated_pages" ),
	true
);
// Manufacturer base tree pages are always valid targets even if not in pages table
$mfr_slugs = $wpdb->get_col( "SELECT slug FROM {$wpdb->prefix}aoe_catalog_manufacturers" );
foreach ( $mfr_slugs as $ms ) {
	$page_set[ $ms ] = true;
}

// ---- Determine manufacturers to work on ----
$targets = [];
if ( isset( $args['all'] ) ) {
	$targets = $wpdb->get_col( "SELECT DISTINCT manufacturer_normalized FROM $table_search ORDER BY manufacturer_normalized" );
} elseif ( isset( $args['manufacturer'] ) ) {
	$targets = array_map( 'strtoupper', $args['manufacturer'] );
}

// ---- Stats mode ----
if ( isset( $args['stats'] ) ) {
	echo "Manufacturer            Rows\n";
	$rows = $wpdb->get_results( "SELECT manufacturer_normalized m, COUNT(*) c FROM $table_search GROUP BY manufacturer_normalized ORDER BY c DESC" );
	foreach ( $rows as $r ) {
		printf( "%-22s %d\n", $r->m, $r->c );
	}
	$total = $wpdb->get_var( "SELECT COUNT(*) FROM $table_search" );
	echo "TOTAL                   $total\n";
	exit( 0 );
}

$limit = isset( $args['limit'] ) ? (int) $args['limit'] : 200;
$broken_only = isset( $args['broken-only'] );

if ( empty( $targets ) ) {
	die( "No manufacturers selected. Use --manufacturer=slug or --all.\n" );
}

$grand_broken = 0;
$grand_checked = 0;

foreach ( $targets as $mfr ) {
	// Fetch batch of rows for this manufacturer (streamed in chunks to bound memory)
	$where = $wpdb->prepare( "manufacturer_normalized = %s", $mfr );
	$total_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_search WHERE $where" );

	if ( 0 === $total_rows ) {
		echo "[$mfr] no rows indexed.\n";
		continue;
	}
	$sample = ( $limit > 0 ) ? min( $limit, $total_rows ) : $total_rows;
	echo "[$mfr] $total_rows total, checking $sample\n";

	// Random sample across the whole manufacturer (deterministic-ish, bounded)
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, sku_normalized, payload_json FROM $table_search WHERE $where ORDER BY RAND() LIMIT %d",
		$sample
	) );

	$mfr_broken = 0;
	$checked = 0;
	foreach ( $rows as $row ) {
		$checked++;
		$pl = json_decode( $row->payload_json, true );
		$urls = (array) ( $pl['urls'] ?? [] );
		$cat = (string) ( $urls['category'] ?? '' );
		$prod = (string) ( $urls['product'] ?? '' );

		$fail = [];

		// Product URL
		if ( '' === $prod ) {
			$fail[] = "product sin URL";
		} else {
			$pslug = nv_slug( $prod );
			if ( ! isset( $page_set[ $pslug ] ) ) {
				$fail[] = "product '$pslug' sin página";
			}
			// Anchor must match frontend expected #producto-{norm}
			$expected_anchor = '#' . nv_anchor( $row->sku_normalized );
			if ( false === strpos( $prod, $expected_anchor ) ) {
				$fail[] = "anchor mal: esperado '$expected_anchor'";
			}
		}

		// Category URL
		if ( '' === $cat ) {
			$fail[] = "category vacía";
		} else {
			$cslug = nv_slug( $cat );
			if ( ! isset( $page_set[ $cslug ] ) ) {
				$fail[] = "category '$cslug' sin página";
			}
		}

		if ( $fail ) {
			$mfr_broken++;
			if ( ! $broken_only ) {
				echo "  id={$row->id} sku={$row->sku_normalized}\n";
				foreach ( $fail as $f ) echo "     ! $f\n";
				echo "     category: $cat\n     product: $prod\n";
			}
		}
	}

	echo "  -> $mfr_broken / $checked con problemas\n";
	$grand_broken += $mfr_broken;
	$grand_checked += $checked;
}

echo "\nTOTAL: $grand_broken / $grand_checked con problemas\n";
