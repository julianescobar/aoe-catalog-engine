<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( 'No tienes permisos para generar plantillas.', 'Acceso denegado', [ 'response' => 403 ] );
}

$manufacturer_slug = get_query_var( 'aoe_catalog_generate_template' );

if ( empty( $manufacturer_slug ) ) {
	wp_die( 'Parámetro no especificado.', 'Parámetros inválidos', [ 'response' => 400 ] );
}

if ( 'all' === $manufacturer_slug ) {
	$items = [];

	// Root
	$root_template_id = get_option( 'aoe_catalog_root_template_post_id', 0 );
	if ( $root_template_id ) {
		$items[] = [ 'slug' => 'root', 'label' => 'Página raíz', 'post_id' => $root_template_id ];
	}

	// Manufacturers
	global $wpdb;
	$table = $wpdb->prefix . 'aoe_catalog_manufacturers';
	$manufacturers = $wpdb->get_results( "SELECT slug, name, wp_post_id FROM $table ORDER BY name ASC" );
	foreach ( $manufacturers as $m ) {
		if ( $m->wp_post_id ) {
			$items[] = [ 'slug' => $m->slug, 'label' => $m->name, 'post_id' => (int) $m->wp_post_id ];
		}
	}

	echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Regenerando plantillas</title>';
	echo '<style>body{font-family:monospace;background:#111;color:#0f0;padding:1em;white-space:pre-wrap;font-size:14px;}</style></head><body>';
	echo "=== Limpiando assets anteriores... ";
	$assets_dir = \AOE\CatalogEngine\PublicFacing\TemplateCache::base_dir() . '/assets';
	if ( is_dir( $assets_dir ) ) {
		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $assets_dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $files as $file ) {
			$file->isDir() ? @rmdir( $file->getPathname() ) : @unlink( $file->getPathname() );
		}
	}
	echo "OK\n\n";

	echo '=== Regenerando todas las plantillas ===' . "\n\n";
	ob_flush(); flush();

	foreach ( $items as $item ) {
		$slug = $item['slug'];
		$label = $item['label'];
		echo "{$label} ({$slug})... ";
		ob_flush(); flush();

		$ok = \AOE\CatalogEngine\PublicFacing\TemplateCache::generate( $slug, $item['post_id'] );
		echo $ok ? "OK" : "ERROR";
		if ( $ok && 'root' !== $slug ) {
			\AOE\CatalogEngine\PublicFacing\CacheCatalog::invalidate( $slug );
			echo " + cache invalidado";
		}
		echo "\n";
		ob_flush(); flush();
	}

	update_option( 'aoe_regen_just_completed', time() );

	echo "\n=== Regeneración completada ===\n";
	echo '<p><a href="' . esc_url( home_url( '/catalogo/' ) ) . '" style="color:#0f0;">Ir al catálogo</a></p>';
	echo '</body></html>';
	exit;
}

if ( 'root' === $manufacturer_slug ) {
	$template_post_id = get_option( 'aoe_catalog_root_template_post_id', 0 );
	$result = $template_post_id ? \AOE\CatalogEngine\PublicFacing\TemplateCache::generate( 'root', $template_post_id ) : false;
} else {
	$result = \AOE\CatalogEngine\PublicFacing\TemplateCache::generate( $manufacturer_slug );
}

if ( $result ) {
	if ( 'root' !== $manufacturer_slug ) {
		\AOE\CatalogEngine\PublicFacing\CacheCatalog::invalidate( $manufacturer_slug );
	}
	$label = 'root' === $manufacturer_slug ? 'Página raíz' : esc_html( $manufacturer_slug );
	$back_url = 'root' === $manufacturer_slug ? home_url( '/catalogo/' ) : home_url( '/catalogo/' . $manufacturer_slug . '/' );
	echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Plantilla generada</title>';
	echo '<style>body{font-family:sans-serif;padding:2em;text-align:center;margin-top:15vh}' .
		'h1{color:#090}.success{border:2px solid #090;border-radius:8px;padding:2em;display:inline-block}' .
		'a{color:#069}</style></head><body>';
	echo '<div class="success"><h1>Plantilla generada correctamente</h1>';
	echo '<p><strong>' . $label . '</strong></p>';
	echo '<p>El template cache se ha generado con todos los estilos de Avada.</p>';
	echo '<p><a href="' . esc_url( $back_url ) . '">Ir al catálogo</a></p>';
	echo '</div></body></html>';
} else {
	$label = 'root' === $manufacturer_slug ? 'Página raíz' : esc_html( $manufacturer_slug );
	echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Error al generar plantilla</title>';
	echo '<style>body{font-family:sans-serif;padding:2em;text-align:center;margin-top:15vh}' .
		'h1{color:#c00}.error{border:2px solid #c00;border-radius:8px;padding:2em;display:inline-block}' .
		'a{color:#069}</style></head><body>';
	echo '<div class="error"><h1>Error al generar la plantilla</h1>';
	echo '<p>No se pudo generar el template cache para <strong>' . $label . '</strong>.</p>';
	echo '<p>Verifica que la plantilla esté seleccionada y exista.</p>';
	echo '<p><a href="javascript:history.back()">Volver</a></p>';
	echo '</div></body></html>';
}
exit;