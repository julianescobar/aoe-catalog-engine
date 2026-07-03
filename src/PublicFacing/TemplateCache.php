<?php

namespace AOE\CatalogEngine\PublicFacing;

class TemplateCache {

	private static function base_dir(): string {
		$upload = wp_upload_dir();
		return $upload['basedir'] . '/aoe-cache-templates';
	}

	private static function file_path( string $manufacturer_slug ): string {
		return self::base_dir() . '/' . $manufacturer_slug . '.html';
	}

	public static function exists( string $manufacturer_slug ): bool {
		return file_exists( self::file_path( $manufacturer_slug ) );
	}

	public static function get( string $manufacturer_slug ): ?string {
		$path = self::file_path( $manufacturer_slug );
		if ( ! file_exists( $path ) ) {
			return null;
		}
		$contents = file_get_contents( $path );
		if ( $contents === false || $contents === '' ) {
			return null;
		}
		return $contents;
	}

	public static function generate( string $manufacturer_slug ): bool {
		global $wpdb, $wp_query, $post;

		$manufacturer = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}aoe_catalog_manufacturers WHERE slug = %s",
			$manufacturer_slug
		) );
		if ( ! $manufacturer || ! $manufacturer->wp_post_id ) {
			return false;
		}

		$template_post = get_post( (int) $manufacturer->wp_post_id );
		if ( ! $template_post ) {
			return false;
		}

		add_filter( 'show_admin_bar', '__return_false' );

		if ( ! is_a( $wp_query, 'WP_Query' ) ) {
			$wp_query = new \WP_Query();
		}
		$wp_query->is_singular = true;
		$wp_query->is_page     = true;
		$wp_query->is_home     = false;
		$wp_query->is_archive  = false;
		$wp_query->queried_object    = $template_post;
		$wp_query->queried_object_id = $template_post->ID;
		$wp_query->query_vars['page_id'] = $template_post->ID;

		$post = $template_post;
		setup_postdata( $post );

		// Enqueue our plugin assets so they get printed in header/footer
		$plugin_dir = dirname( __DIR__, 2 );
		$catalog_css_path = $plugin_dir . '/assets/css/catalog-render.css';
		wp_enqueue_style(
			'aoe-catalog-render',
			plugin_dir_url( $plugin_dir . '/aoe-catalog-engine.php' ) . 'assets/css/catalog-render.css',
			[],
			file_exists( $catalog_css_path ) ? filemtime( $catalog_css_path ) : '1.0.0'
		);
		$catalog_js_path = $plugin_dir . '/assets/js/catalog.js';
		wp_enqueue_script(
			'aoe-catalog-js',
			plugin_dir_url( $plugin_dir . '/aoe-catalog-engine.php' ) . 'assets/js/catalog.js',
			[ 'jquery' ],
			file_exists( $catalog_js_path ) ? filemtime( $catalog_js_path ) : '1.0.0',
			true
		);
		wp_localize_script( 'aoe-catalog-js', 'aoeCatalog', [
			'manufacturerName' => $manufacturer->name ?? '',
		] );

		// Enqueue bootstrap-modal so Avada's JS compiler includes it in the combined file
		if ( class_exists( 'Fusion_Dynamic_JS' ) ) {
			\Fusion_Dynamic_JS::enqueue_script( 'bootstrap-modal' );
		}

		// Process content BEFORE header/footer so Avada Fusion Forms JS gets enqueued in time
		$content = apply_filters( 'the_content', $template_post->post_content );

		ob_start();
		get_header();
		$header = ob_get_clean();

		ob_start();
		get_footer();
		$footer = ob_get_clean();

		wp_reset_postdata();

		$html = $header . "\n" . $content . "\n" . $footer;

		$dir = self::base_dir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		return false !== file_put_contents( self::file_path( $manufacturer_slug ), $html );
	}

	public static function delete( string $manufacturer_slug ): bool {
		$path = self::file_path( $manufacturer_slug );
		if ( file_exists( $path ) ) {
			return unlink( $path );
		}
		return true;
	}
}
