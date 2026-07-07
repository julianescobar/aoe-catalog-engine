<?php
/**
 * Asigna los nivel 4 huérfanos (parent_id = NULL) de Samtec a un nivel 3 "Sin clasificar".
 * Uso: php tools/fix-orphaned-level4.php
 */

if ( ! in_array( PHP_SAPI, [ 'cli', 'cgi-fcgi' ], true ) ) {
	die( 'CLI only' );
}

$wp_load = __DIR__ . '/../../../../../wp-load.php';
foreach ( [ '../../../../', '../../../' ] as $rel ) {
	$path = __DIR__ . '/' . $rel . 'wp-load.php';
	if ( file_exists( $path ) ) { $wp_load = $path; break; }
}
require_once $wp_load;

global $wpdb;

$mfr_id = (int) $wpdb->get_var(
	"SELECT id FROM {$wpdb->prefix}aoe_catalog_manufacturers WHERE slug = 'samtec'"
);

if ( ! $mfr_id ) {
	die( "Error: No se encontró el fabricante Samtec\n" );
}

echo "Fabricante ID: $mfr_id\n";

// 1. Verificar/Crear "Sin clasificar" nivel 3
$sin_clasificar = $wpdb->get_row( $wpdb->prepare(
	"SELECT id FROM {$wpdb->prefix}aoe_catalog_categories 
	 WHERE manufacturer_id = %d AND name = 'Sin clasificar' AND level = 3",
	$mfr_id
) );

if ( $sin_clasificar ) {
	$sin_id = (int) $sin_clasificar->id;
	echo "Categoría 'Sin clasificar' ya existe: ID $sin_id\n";
} else {
	$wpdb->insert( "{$wpdb->prefix}aoe_catalog_categories", [
		'manufacturer_id' => $mfr_id,
		'parent_id'       => null,
		'name'            => 'Sin clasificar',
		'slug'            => 'sin-clasificar',
		'type'            => 'category',
		'description'     => 'Categorías nivel 4 sin clasificación específica',
		'image'           => '',
		'level'           => 3,
		'products_count'  => 0,
		'metadata_json'   => json_encode( [] ),
	], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ] );
	$sin_id = (int) $wpdb->insert_id;
	echo "Categoría 'Sin clasificar' creada: ID $sin_id\n";
}

// 2. Contar huérfanos actuales
$orphans_before = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$wpdb->prefix}aoe_catalog_categories 
	 WHERE manufacturer_id = %d AND level = 4 AND parent_id IS NULL",
	$mfr_id
) );
echo "Huérfanos nivel 4 antes: $orphans_before\n";

// 3. Asignarlos a "Sin clasificar"
$updated = $wpdb->update(
	"{$wpdb->prefix}aoe_catalog_categories",
	[ 'parent_id' => $sin_id ],
	[ 'manufacturer_id' => $mfr_id, 'level' => 4, 'parent_id' => null ],
	[ '%d' ],
	[ '%d', '%d', '%d' ]
);

echo "Asignados a 'Sin clasificar': $updated\n";

// 4. Verificar que no queden huérfanos
$orphans_after = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$wpdb->prefix}aoe_catalog_categories 
	 WHERE manufacturer_id = %d AND level = 4 AND parent_id IS NULL",
	$mfr_id
) );
echo "Huérfanos nivel 4 después: $orphans_after\n";

if ( $orphans_after === 0 ) {
	echo "\n✓ Todos los nivel 4 tienen padre ahora. Regenera páginas desde admin o ejecuta:\n";
	echo "  php tools/regenerate-pages.php\n";
} else {
	echo "\n⚠ Quedan $orphans_after huérfanos sin asignar\n";
}
