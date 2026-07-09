<?php

namespace AOE\CatalogEngine\PublicFacing;

class TemplateCache {

	/**
	 * Per-request cache for get_header/get_footer output.
	 * Once captured from the first successful generate() call,
	 * subsequent calls reuse this frame instead of calling get_header/footer
	 * again (which produce empty output after wp_head/footer have fired once).
	 */
	private static ?string $frame_header = null;
	private static ?string $frame_footer = null;

	public static function base_dir(): string {
		$upload = wp_upload_dir();
		return $upload['basedir'] . '/aoe-cache-templates';
	}

	public static function file_path( string $slug ): string {
		return self::base_dir() . '/' . $slug . '.html';
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

	public static function generate( string $slug, ?int $template_post_id = null ): bool {
		global $wpdb, $wp_query, $post;

		$manufacturer_name = '';
		if ( null === $template_post_id ) {
			$manufacturer = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}aoe_catalog_manufacturers WHERE slug = %s",
				$slug
			) );
			if ( ! $manufacturer || ! $manufacturer->wp_post_id ) {
				return false;
			}
			$template_post_id = (int) $manufacturer->wp_post_id;
			$manufacturer_name = $manufacturer->name ?? '';
		}

		$template_post = get_post( $template_post_id );
		if ( ! $template_post ) {
			return false;
		}

		add_filter( 'show_admin_bar', '__return_false' );
		add_action( 'wp_enqueue_scripts', function() {
			wp_dequeue_style( 'admin-bar' );
			remove_action( 'wp_head', '_admin_bar_bump_cb' );
		}, 99999 );

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

		if ( class_exists( 'Fusion_Dynamic_JS' ) ) {
			\Fusion_Dynamic_JS::enqueue_script( 'bootstrap-modal' );

			// Fusion Forms JS must be explicitly enqueued since forms are rendered
			// inside modals that are always present in the cached HTML.
			if ( class_exists( 'FusionBuilder' ) && defined( 'FUSION_BUILDER_VERSION' ) ) {
				\Fusion_Dynamic_JS::enqueue_script(
					'fusion-form-js',
					\FusionBuilder::$js_folder_url . '/general/fusion-form.js',
					\FusionBuilder::$js_folder_path . '/general/fusion-form.js',
					[ 'jquery' ],
					FUSION_BUILDER_VERSION,
					true
				);
				\Fusion_Dynamic_JS::enqueue_script(
					'fusion-form-logics',
					\FusionBuilder::$js_folder_url . '/general/fusion-form-logics.js',
					\FusionBuilder::$js_folder_path . '/general/fusion-form-logics.js',
					[ 'jquery', 'fusion-form-js' ],
					FUSION_BUILDER_VERSION,
					true
				);
				\Fusion_Dynamic_JS::localize_script(
					'fusion-form-js',
					'formCreatorConfig',
					[
						'ajaxurl'             => admin_url( 'admin-ajax.php' ),
						'invalid_email'       => __( 'The supplied email address is invalid.', 'fusion-builder' ),
						'max_value_error'     => __( 'Max allowed value is: 2.', 'fusion-builder' ),
						'min_value_error'     => __( 'Min allowed value is: 1.', 'fusion-builder' ),
						'max_min_value_error' => __( 'Value out of bounds, limits are: 1-2.', 'fusion-builder' ),
						'file_size_error'     => __( 'Your file size exceeds max allowed limit of ', 'fusion-builder' ),
						'file_ext_error'      => __( 'This file extension is not allowed. Please upload file having these extensions: ', 'fusion-builder' ),
						'must_match'          => __( 'The value entered does not match the value for %s.', 'fusion-builder' ),
					]
				);
			}
		}

		// Force-load reCAPTCHA API (form shortcode may not enqueue it in this context)
		if ( class_exists( 'AWB_Google_Recaptcha' ) && method_exists( 'AWB_Google_Recaptcha', 'get_instance' ) ) {
			$recaptcha = \AWB_Google_Recaptcha::get_instance();
			if ( method_exists( $recaptcha, 'enqueue_scripts' ) ) {
				$recaptcha->enqueue_scripts();
			}
		}

		$content = apply_filters( 'the_content', $template_post->post_content );

		// Capture header/footer only once per request.
		// Subsequent calls reuse the cached frame because wp_head()/wp_footer()
		// actions only fire fully on the first invocation.
		if ( self::$frame_header === null || self::$frame_footer === null ) {
			ob_start();
			get_header();
			self::$frame_header = ob_get_clean();

			ob_start();
			get_footer();
			self::$frame_footer = ob_get_clean();
		}

		$header_html = self::$frame_header;
		$footer_html = self::$frame_footer;

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

		$ok = false !== file_put_contents( self::file_path( $slug ), $html );

		if ( $ok ) {
			update_option( 'aoe_last_template_regen', time() );
		}

		// Clean up old 3-file format if it exists
		$base = self::base_dir() . '/' . $slug;
		foreach ( [ '-head.html', '-body.html', '-foot.html' ] as $suffix ) {
			$old = $base . $suffix;
			if ( file_exists( $old ) ) {
				unlink( $old );
			}
		}

		return $ok;
	}

	public static function delete( string $slug ): bool {
		$path = self::file_path( $slug );
		if ( file_exists( $path ) ) {
			return unlink( $path );
		}
		return true;
	}

}
