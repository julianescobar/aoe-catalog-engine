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
			unlink( $path );
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

		$plugin_dir = dirname( __DIR__, 2 );
		$plugin_url = plugin_dir_url( $plugin_dir . '/aoe-catalog-engine.php' );
		$catalog_css_path = $plugin_dir . '/assets/css/catalog-render.css';
		$catalog_js_path = $plugin_dir . '/assets/js/catalog.js';
		$css_ver = file_exists( $catalog_css_path ) ? filemtime( $catalog_css_path ) : '1.0.0';
		$js_ver = file_exists( $catalog_js_path ) ? filemtime( $catalog_js_path ) : '1.0.0';

		wp_enqueue_style( 'aoe-catalog-render', $plugin_url . 'assets/css/catalog-render.css', [], $css_ver );
		wp_enqueue_script( 'aoe-catalog-js', $plugin_url . 'assets/js/catalog.js', [ 'jquery' ], $js_ver, true );
		wp_localize_script( 'aoe-catalog-js', 'aoeCatalog', [
			'manufacturerName' => $manufacturer->name ?? '',
		] );

		if ( class_exists( 'Fusion_Dynamic_JS' ) ) {
			\Fusion_Dynamic_JS::enqueue_script( 'bootstrap-modal' );
		}

		$content = apply_filters( 'the_content', $template_post->post_content );

		ob_start();
		get_header();
		$header_html = ob_get_clean();

		ob_start();
		get_footer();
		$footer_html = ob_get_clean();

		wp_reset_postdata();

		// Force direct URL for our plugin assets (bypass WPO)
		$css_url = $plugin_url . 'assets/css/catalog-render.css?ver=' . $css_ver;
		$js_url  = $plugin_url . 'assets/js/catalog.js?ver=' . $js_ver;
		$header_html = preg_replace(
			'~https?://[^"\'<>]*wpo-minify[^"\'<>]*aoe-catalog-render[^"\'<>]*\.min\.css[^"\'<>]*~',
			$css_url, $header_html
		);
		$header_html = preg_replace(
			'~https?://[^"\'<>]*wpo-minify[^"\'<>]*aoe-catalog-js[^"\'<>]*\.min\.js[^"\'<>]*~',
			$js_url, $header_html
		);
		$footer_html = preg_replace(
			'~https?://[^"\'<>]*wpo-minify[^"\'<>]*aoe-catalog-js[^"\'<>]*\.min\.js[^"\'<>]*~',
			$js_url, $footer_html
		);

		// Copy other WPO assets to a stable location so they survive WPO purges
		$assets_dir = self::base_dir() . '/assets';
		wp_mkdir_p( $assets_dir );

		$combined = $header_html . "\n" . $footer_html;
		preg_match_all( '~/wp-content/cache/wpo-minify/([^"\'<>]+\.(?:css|js))~', $combined, $matches, PREG_SET_ORDER );

		foreach ( $matches as $m ) {
			$rel_path = $m[1];
			$filename = basename( $rel_path );
			$src = WP_CONTENT_DIR . '/cache/wpo-minify/' . $rel_path;
			$dst = $assets_dir . '/' . $filename;

			if ( file_exists( $src ) && ! file_exists( $dst ) ) {
				@copy( $src, $dst );
			}

			$old_fragment = '/wp-content/cache/wpo-minify/' . $rel_path;
			$new_fragment = '/wp-content/uploads/aoe-cache-templates/assets/' . $filename;
			$header_html = str_replace( $old_fragment, $new_fragment, $header_html );
			$footer_html = str_replace( $old_fragment, $new_fragment, $footer_html );
		}

		$html = $header_html . "\n" . $content . "\n" . $footer_html;

		$dir = self::base_dir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$ok = false !== file_put_contents( self::file_path( $manufacturer_slug ), $html );

		// Clean up old 3-file format if it exists
		$base = self::base_dir() . '/' . $manufacturer_slug;
		foreach ( [ '-head.html', '-body.html', '-foot.html' ] as $suffix ) {
			$old = $base . $suffix;
			if ( file_exists( $old ) ) {
				unlink( $old );
			}
		}

		return $ok;
	}

	public static function delete( string $manufacturer_slug ): bool {
		$path = self::file_path( $manufacturer_slug );
		if ( file_exists( $path ) ) {
			return unlink( $path );
		}
		return true;
	}
}
