<?php
/**
 * Diagnóstico de un fabricante: páginas, segmentos, categorías y caché.
 *
 * Usage:
 *   php -d register_argc_argv=1 tools/diag-manufacturer.php --manufacturer=amphenol-pcd
 *
 * Opcional (local): AOE_DB_HOST=127.0.0.1:10006
 */

namespace AOE\CatalogEngine\Tools;

if ( PHP_SAPI !== 'cli' ) {
	die( "CLI only.\n" );
}

$args = getopt( '', [ 'manufacturer:' ] );
$slug = $args['manufacturer'] ?? '';
if ( empty( $slug ) ) {
	die( "Uso: php tools/diag-manufacturer.php --manufacturer=slug\n" );
}

if ( getenv( 'AOE_DB_HOST' ) && ! defined( 'DB_HOST' ) ) {
	define( 'DB_HOST', getenv( 'AOE_DB_HOST' ) );
}

require_once dirname( __DIR__, 4 ) . '/wp-load.php';

global $wpdb;

$P = function ( string $label, $value ) {
	printf( "%-42s %s\n", $label . ':', (string) $value );
};

$mfr = $wpdb->get_row( $wpdb->prepare(
	"SELECT id, slug, name, config_json FROM {$wpdb->prefix}aoe_catalog_manufacturers WHERE slug = %s", $slug
) );
if ( ! $mfr ) {
	die( "Fabricante no encontrado: {$slug}\n" );
}

echo "===== {$mfr->name} ({$mfr->slug}) id={$mfr->id} =====\n";
$cfg = json_decode( $mfr->config_json ?? '', true );
$P( 'tree_layout', $cfg['tree_layout'] ?? '(no definido)' );
$P( 'tree_columns', $cfg['tree_columns'] ?? '(no definido)' );
$P( 'media_source', $cfg['media_source'] ?? '(no definido)' );

$pages = "{$wpdb->prefix}aoe_catalog_pregenerated_pages";
$segs  = "{$wpdb->prefix}aoe_catalog_page_segments";
$cats  = "{$wpdb->prefix}aoe_catalog_categories";
$prods = "{$wpdb->prefix}aoe_catalog_products";

echo "\n--- Páginas pregeneradas ---\n";
$by_type = $wpdb->get_results( $wpdb->prepare(
	"SELECT type, COUNT(*) AS n FROM $pages WHERE manufacturer_id = %d GROUP BY type ORDER BY type", $mfr->id
) );
if ( empty( $by_type ) ) {
	echo "  (sin páginas)\n";
} else {
	foreach ( $by_type as $r ) {
		$P( "  type {$r->type}", $r->n );
	}
}

$tree_pages = $wpdb->get_results( $wpdb->prepare(
	"SELECT id, slug, link_count FROM $pages WHERE manufacturer_id = %d AND type = 'tree' ORDER BY page_number ASC", $mfr->id
) );
if ( $tree_pages ) {
	echo "  Tree pages: " . count( $tree_pages ) . "\n";
	foreach ( $tree_pages as $tp ) {
		$n_seg = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $segs WHERE page_id = %d", $tp->id ) );
		printf( "    - slug=%-40s links=%d segments=%d\n", $tp->slug, $tp->link_count, (int) $n_seg );
	}
}

echo "\n--- Segmentos (totales) ---\n";
$tot_seg = $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM $segs s JOIN $pages p ON p.id = s.page_id WHERE p.manufacturer_id = %d", $mfr->id
) );
$P( 'segmentos totales', $tot_seg );

echo "\n--- Categorías ---\n";
$levels = $wpdb->get_results( $wpdb->prepare(
	"SELECT level, COUNT(*) AS n FROM $cats WHERE manufacturer_id = %d GROUP BY level ORDER BY level", $mfr->id
) );
foreach ( $levels as $l ) {
	$P( "  level {$l->level}", $l->n );
}
$uncat = $wpdb->get_row( $wpdb->prepare(
	"SELECT id, slug FROM $cats WHERE manufacturer_id = %d AND slug LIKE %s LIMIT 1", $mfr->id, '%uncategorized%'
) );
if ( $uncat ) {
	$n_uncat = $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM $prods WHERE manufacturer_id = %d AND category_id = %d", $mfr->id, $uncat->id
	) );
	$P( "uncategorized ({$uncat->slug})", $n_uncat );
}

$total_prods = $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM $prods WHERE manufacturer_id = %d", $mfr->id
) );
$P( 'productos totales', $total_prods );

echo "\n--- Categorías ocultas (is_hidden) ---\n";
$hidden_rows = $wpdb->get_results( $wpdb->prepare(
	"SELECT id, slug, is_hidden FROM $cats WHERE manufacturer_id = %d AND is_hidden = 1 ORDER BY level, id", $mfr->id
) );
$P( 'categorias con is_hidden=1', count( $hidden_rows ) );
foreach ( $hidden_rows as $hr ) {
	echo "    - id={$hr->id} slug={$hr->slug}\n";
}

echo "\n--- Simulación query de segmentos del tree (con filtro hidden) ---\n";
$tree_page = $wpdb->get_row( $wpdb->prepare(
	"SELECT id FROM $pages WHERE manufacturer_id = %d AND type = 'tree' ORDER BY page_number ASC LIMIT 1", $mfr->id
) );
if ( $tree_page ) {
	$all_cats = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, parent_id, is_hidden FROM $cats WHERE manufacturer_id = %d", $mfr->id
	) );
	$parent_of = [];
	$hidden_eff = [];
	foreach ( $all_cats as $c ) {
		$parent_of[ (int) $c->id ] = (int) $c->parent_id;
		if ( (int) $c->is_hidden === 1 ) {
			$hidden_eff[ (int) $c->id ] = true;
		}
	}
	foreach ( $all_cats as $c ) {
		$pid = (int) $c->parent_id;
		while ( $pid > 0 && isset( $parent_of[ $pid ] ) ) {
			if ( isset( $hidden_eff[ $pid ] ) ) {
				$hidden_eff[ (int) $c->id ] = true;
				break;
			}
			$pid = $parent_of[ $pid ];
		}
	}
	$hidden_ids = array_keys( $hidden_eff );
	$hidden_sql = '';
	$params = [ $tree_page->id ];
	if ( ! empty( $hidden_ids ) ) {
		$hidden_sql = ' AND s.category_id NOT IN (' . implode( ',', array_fill( 0, count( $hidden_ids ), '%d' ) ) . ')';
		$params = array_merge( $params, $hidden_ids );
	}
	$seg_count = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM $segs s JOIN $cats c ON s.category_id = c.id
		 WHERE s.page_id = %d$hidden_sql",
		$params
	) );
	$P( 'tree page id', $tree_page->id );
	$P( 'segmentos visibles tras filtro hidden', $seg_count );
	$P( 'categorias efectivamente ocultas', count( $hidden_ids ) );

	$root_csv = $wpdb->get_col( $wpdb->prepare(
		"SELECT c.name FROM $segs s JOIN $cats c ON s.category_id = c.id
		 WHERE s.page_id = %d$hidden_sql AND (c.parent_id IS NULL OR c.parent_id = 0)
		 ORDER BY c.id LIMIT 15",
		$params
	) );
	echo "  raices visibles: " . ( $root_csv ? implode( ' | ', $root_csv ) : '(ninguna)' ) . "\n";
} else {
	echo "  (sin tree page)\n";
}

echo "\n--- Replica query EXACTA del render (segmentos tree) ---\n";
if ( $tree_page ) {
	$hidden_sql2 = '';
	$params2 = [ $tree_page->id ];
	if ( ! empty( $hidden_ids ) ) {
		$hidden_sql2 = ' AND c.id NOT IN (' . implode( ',', array_fill( 0, count( $hidden_ids ), '%d' ) ) . ')';
		$params2 = array_merge( $params2, $hidden_ids );
	}
	$exact = $wpdb->get_results( $wpdb->prepare(
		"SELECT s.*, c.name AS category_name, c.slug AS category_slug, c.parent_id, c.level, c.metadata_json, c.description AS category_description, c.sort_order AS cat_sort_order, c.is_hidden
		 FROM $segs s
		 JOIN $cats c ON s.category_id = c.id
		 WHERE s.page_id = %d$hidden_sql2
		 ORDER BY CASE WHEN c.sort_order > 0 THEN c.sort_order ELSE s.sort_order END ASC",
		$params2
	) );
	$P( 'filas devueltas', is_array( $exact ) ? count( $exact ) : 'NO-ARRAY' );
	if ( $exact === false || ( is_array( $exact ) && empty( $exact ) ) ) {
		$P( 'last_error', $wpdb->last_error );
		echo "  --- columnas de tablas ---\n";
		$check_rows = $wpdb->get_results( "SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name IN ('" . $wpdb->prefix . "aoe_catalog_page_segments','" . $wpdb->prefix . "aoe_catalog_categories') AND column_name IN ('sort_order','level','is_hidden')" );
		if ( $check_rows ) {
			foreach ( $check_rows as $cr ) {
				echo "    {$cr->table_name}.{$cr->column_name}\n";
			}
		} else {
			echo "    (sin info de columnas)\n";
		}
	}
} else {
	echo "  (sin tree page)\n";
}

echo "\n--- Caché HTML (uploads/aoe-cache-catalog/{$slug}) ---\n";
$upload_dir = wp_upload_dir();
$cache_dir  = $upload_dir['basedir'] . '/aoe-cache-catalog/' . $slug;
if ( is_dir( $cache_dir ) ) {
	$files = array_filter( scandir( $cache_dir ), static function ( $f ) {
		return substr( $f, -5 ) === '.html';
	} );
	$P( 'archivos cache', count( $files ) );
	foreach ( array_slice( $files, 0, 10 ) as $f ) {
		$cpath = $cache_dir . '/' . $f;
		$content = @file_get_contents( $cpath );
		$mtime = gmdate( 'Y-m-d H:i:s', filemtime( $cpath ) );
		echo "    - $f (" . size_format( filesize( $cpath ) ) . ", mtime=$mtime)\n";
		if ( $content !== false ) {
			$has_rows = ( substr_count( $content, 'aoe-cat-row' ) > 0 ) ? 'SI' : 'NO';
			$has_links = ( preg_match( '/catalogo\/' . preg_quote( $slug, '/' ) . '/', $content ) ) ? 'SI' : 'NO';
			$has_error = ( stripos( $content, 'La plantilla asociada' ) !== false || stripos( $content, 'db error' ) !== false ) ? 'SI' : 'NO';
			echo "      filas aoe-cat-row: $has_rows | links conecciones: $has_links | error plantilla/DB: $has_error\n";
		}
	}
	if ( count( $files ) > 10 ) {
		echo "    ... y " . ( count( $files ) - 10 ) . " más\n";
	}
} else {
	echo "  (carpeta de caché no existe para este fabricante)\n";
}

echo "\n===== Fin diagnóstico =====\n";