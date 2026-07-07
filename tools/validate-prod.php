<?php
/**
 * Valida estado de la base de datos para deploy a producción.
 * Uso: php tools/validate-prod.php [manufacturer=samtec]
 */

if ( ! in_array( PHP_SAPI, [ 'cli', 'cgi-fcgi' ], true ) ) {
	die( 'CLI only' );
}

$slug = 'samtec';
foreach ( $argv as $arg ) {
	if ( preg_match( '/^manufacturer=(.+)$/', $arg, $m ) ) {
		$slug = $m[1];
	}
}

$wp_load = __DIR__ . '/../../../../../wp-load.php';
foreach ( [ '../../../../', '../../../' ] as $rel ) {
	$path = __DIR__ . '/' . $rel . 'wp-load.php';
	if ( file_exists( $path ) ) { $wp_load = $path; break; }
}
require_once $wp_load;

global $wpdb;

$mfr_id = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT id FROM {$wpdb->prefix}aoe_catalog_manufacturers WHERE slug = %s", $slug
) );

if ( ! $mfr_id ) {
	die( "Fabricante '$slug' no encontrado\n" );
}

echo "=== Validación: $slug (ID $mfr_id) ===\n\n";

// 1. Productos
$total_prods = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$wpdb->prefix}aoe_catalog_products WHERE manufacturer_id = %d", $mfr_id
) );
echo "Productos total: $total_prods\n";

// 2. Categorías por nivel
$levels = $wpdb->get_results( $wpdb->prepare(
	"SELECT level, COUNT(*) as cnt FROM {$wpdb->prefix}aoe_catalog_categories WHERE manufacturer_id = %d GROUP BY level ORDER BY level",
	$mfr_id
) );
echo "\nCategorías por nivel:\n";
foreach ( $levels as $l ) {
	echo "  Nivel {$l->level}: {$l->cnt}\n";
}

// 3. Nivel 4 con/sin padre
$orphans = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$wpdb->prefix}aoe_catalog_categories WHERE manufacturer_id = %d AND level = 4 AND parent_id IS NULL", $mfr_id
) );
$total_l4 = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$wpdb->prefix}aoe_catalog_categories WHERE manufacturer_id = %d AND level = 4", $mfr_id
) );
echo "\nNivel 4: $total_l4 total, $orphans huérfanos (sin padre)\n";

// 4. Nivel 0 (bug apply-sku-map)
$level0 = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$wpdb->prefix}aoe_catalog_categories WHERE manufacturer_id = %d AND level = 0", $mfr_id
) );
if ( $level0 > 0 ) {
	$names_l0 = $wpdb->get_col( $wpdb->prepare(
		"SELECT name FROM {$wpdb->prefix}aoe_catalog_categories WHERE manufacturer_id = %d AND level = 0", $mfr_id
	) );
	echo "⚠  $level0 categorías con level=0: " . implode( ', ', $names_l0 ) . "\n";
}

// 5. Nivel 3 sin hijos nivel 4
$l3_sin_hijos = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$wpdb->prefix}aoe_catalog_categories c3 WHERE manufacturer_id = %d AND level = 3 AND c3.id NOT IN (SELECT DISTINCT parent_id FROM {$wpdb->prefix}aoe_catalog_categories WHERE manufacturer_id = %d AND level = 4 AND parent_id IS NOT NULL)",
	$mfr_id, $mfr_id
) );
echo "Nivel 3 sin hijos nivel 4: $l3_sin_hijos\n";

// 6. Nivel 3 "Sin clasificar"
$sin_clasificar = $wpdb->get_var( $wpdb->prepare(
	"SELECT id FROM {$wpdb->prefix}aoe_catalog_categories WHERE manufacturer_id = %d AND name = 'Sin clasificar' AND level = 3", $mfr_id
) );
echo "\n'Sin clasificar' nivel 3: " . ( $sin_clasificar ? "EXISTE (ID $sin_clasificar)" : "NO EXISTE" ) . "\n";

// 7. sku_map
$sku_map_count = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$wpdb->prefix}aoe_catalog_sku_map WHERE manufacturer_id = %d", $mfr_id
) );
echo "sku_map entries: $sku_map_count\n";

// 8. Productos sin categoría válida
$prods_sin_cat = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$wpdb->prefix}aoe_catalog_products WHERE manufacturer_id = %d AND (category_id = 0 OR category_id IS NULL OR category_id NOT IN (SELECT id FROM {$wpdb->prefix}aoe_catalog_categories))",
	$mfr_id
) );
echo "Productos sin categoría válida: $prods_sin_cat\n";

// 9. Productos por nivel
$prods_por_nivel = $wpdb->get_results( $wpdb->prepare(
	"SELECT c.level, COUNT(*) as cnt FROM {$wpdb->prefix}aoe_catalog_products p JOIN {$wpdb->prefix}aoe_catalog_categories c ON c.id = p.category_id WHERE p.manufacturer_id = %d GROUP BY c.level",
	$mfr_id
) );
echo "\nProductos por nivel de categoría:\n";
foreach ( $prods_por_nivel as $l ) {
	echo "  Nivel {$l->level}: {$l->cnt}\n";
}

// 10. Páginas pre-generadas
$pages = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$wpdb->prefix}aoe_catalog_pregenerated_pages WHERE manufacturer_id = %d", $mfr_id
) );
$tree_pages = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$wpdb->prefix}aoe_catalog_pregenerated_pages WHERE manufacturer_id = %d AND type = 'tree'", $mfr_id
) );
echo "\nPáginas totales: $pages (tree: $tree_pages)\n";

// Resumen
echo "\n=== RESUMEN ===\n";
$issues = [];

if ( $prods_sin_cat > 0 ) {
	$issues[] = "$prods_sin_cat productos sin categoría válida";
}
if ( $orphans > 0 ) {
	$issues[] = "$orphans nivel 4 huérfanos (ejecutar fix)";
}
if ( $level0 > 0 ) {
	$issues[] = "$level0 categorías level=0 (borrar o corregir)";
}
if ( ! $sin_clasificar && $orphans > 0 ) {
	$issues[] = "Falta 'Sin clasificar' nivel 3 para asignar huérfanos";
}
if ( $sku_map_count === 0 ) {
	$issues[] = "sku_map vacío — importar mapeo.csv";
}
if ( $pages === 0 ) {
	$issues[] = "Sin páginas generadas — regenerar";
}
if ( empty( $prods_por_nivel ) || $prods_por_nivel[0]->level !== 4 ) {
	$issues[] = "Productos no están todos en nivel 4";
}

if ( empty( $issues ) ) {
	echo "✓ TODO CORRECTO — Listo para producción\n";
} else {
	echo "⚠  Pendiente:\n";
	foreach ( $issues as $issue ) {
		echo "  - $issue\n";
	}
}
