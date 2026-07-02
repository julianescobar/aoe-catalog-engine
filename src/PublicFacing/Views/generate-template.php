<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( 'No tienes permisos para generar plantillas.', 'Acceso denegado', [ 'response' => 403 ] );
}

$manufacturer_slug = get_query_var( 'aoe_catalog_generate_template' );

if ( empty( $manufacturer_slug ) ) {
	wp_die( 'Fabricante no especificado.', 'Parámetros inválidos', [ 'response' => 400 ] );
}

$result = \AOE\CatalogEngine\PublicFacing\TemplateCache::generate( $manufacturer_slug );

if ( $result ) {
	echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Plantilla generada</title>';
	echo '<style>body{font-family:sans-serif;padding:2em;text-align:center;margin-top:15vh}' .
		'h1{color:#090}.success{border:2px solid #090;border-radius:8px;padding:2em;display:inline-block}' .
		'a{color:#069}</style></head><body>';
	echo '<div class="success"><h1>Plantilla generada correctamente</h1>';
	echo '<p>Fabricante: <strong>' . esc_html( $manufacturer_slug ) . '</strong></p>';
	echo '<p>El template cache se ha generado con todos los estilos de Avada.</p>';
	echo '<p><a href="' . esc_url( home_url( '/catalogo/' . $manufacturer_slug . '/' ) ) . '">Ir al catálogo</a></p>';
	echo '</div></body></html>';
} else {
	echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Error al generar plantilla</title>';
	echo '<style>body{font-family:sans-serif;padding:2em;text-align:center;margin-top:15vh}' .
		'h1{color:#c00}.error{border:2px solid #c00;border-radius:8px;padding:2em;display:inline-block}' .
		'a{color:#069}</style></head><body>';
	echo '<div class="error"><h1>Error al generar la plantilla</h1>';
	echo '<p>No se pudo generar el template cache para <strong>' . esc_html( $manufacturer_slug ) . '</strong>.</p>';
	echo '<p>Verifica que el fabricante exista y tenga un post de plantilla asignado.</p>';
	echo '<p><a href="javascript:history.back()">Volver</a></p>';
	echo '</div></body></html>';
}
exit;