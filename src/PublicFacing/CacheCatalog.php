<?php

namespace AOE\CatalogEngine\PublicFacing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CacheCatalog {

	private static function base_dir(): string {
		$upload = wp_upload_dir();
		return $upload['basedir'] . '/aoe-cache-catalog';
	}

	private static function file_path( string $manufacturer_slug, string $page_slug ): string {
		$safe_key = str_replace( '/', '_', $page_slug ) . '.html';
		return self::base_dir() . '/' . $manufacturer_slug . '/' . $safe_key;
	}

	public static function get( string $manufacturer_slug, string $page_slug ): ?string {
		$path = self::file_path( $manufacturer_slug, $page_slug );
		if ( ! file_exists( $path ) ) {
			return null;
		}
		$contents = file_get_contents( $path );
		if ( $contents === false || $contents === '' ) {
			unlink( $path );
			return null;
		}
		return self::refresh_avada_assets( $contents, $manufacturer_slug, $page_slug );
	}

	private static function refresh_avada_assets( string $html, string $manufacturer_slug, string $page_slug ): ?string {
		$content_dir = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : dirname( wp_upload_dir()['basedir'] );
		$upload_dir = wp_upload_dir()['basedir'];
		$patterns = [
			$upload_dir . '/fusion-styles' => [
				'regex' => '/\/fusion-styles\/(fusion-[a-f0-9]+\.min\.css)/',
				'prefix' => 'fusion-',
			],
			$upload_dir . '/fusion-scripts' => [
				'regex' => '/\/fusion-scripts\/(fusion-[a-f0-9]+\.min\.js)/',
				'prefix' => 'fusion-',
			],
			$content_dir . '/cache/wpo-minify' => [
				'regex' => '/\/cache\/wpo-minify\/([^\/]+\/assets\/wpo-minify-[^\/]+\.min\.(css|js))/',
				'prefix' => 'wpo-minify-',
			],
		];
		$changed = false;
		$plugin_path = parse_url( plugin_dir_url( dirname( __DIR__, 2 ) . '/aoe-catalog-engine.php' ), PHP_URL_PATH );

		foreach ( $patterns as $base_dir => $cfg ) {
			if ( ! preg_match_all( $cfg['regex'], $html, $matches ) ) {
				continue;
			}
			foreach ( $matches[1] as $i => $old_path ) {
				$full = $base_dir . '/' . $old_path;
				if ( file_exists( $full ) ) {
					continue;
				}
				if ( $base_dir === $content_dir . '/cache/wpo-minify' ) {
					$old_subdir = substr( $old_path, 0, strpos( $old_path, '/' ) );
					$subdirs = glob( $base_dir . '/*', GLOB_ONLYDIR );
					$new_subdir = $old_subdir;
					if ( ! empty( $subdirs ) ) {
						usort( $subdirs, function( $a, $b ) {
							return filemtime( $b ) - filemtime( $a );
						} );
						foreach ( $subdirs as $sd ) {
							$name = basename( $sd );
							if ( $name !== 'tmp' && is_dir( $sd . '/assets' ) ) {
								$new_subdir = $name;
								break;
							}
						}
					}
					if ( strpos( $old_path, 'aoe-catalog-render' ) !== false ) {
						$new_path = str_replace( $old_subdir, $new_subdir, $old_path );
						if ( file_exists( $base_dir . '/' . $new_path ) ) {
							$html = str_replace( $old_path, $new_path, $html );
						} else {
							$ver = filemtime( dirname( __DIR__, 2 ) . '/assets/css/catalog-render.css' );
							$html = str_replace(
								'/wp-content' . $matches[0][$i],
								$plugin_path . 'assets/css/catalog-render.css?ver=' . $ver,
								$html
							);
						}
						$changed = true;
						continue;
					}
					if ( strpos( $old_path, 'aoe-catalog-js' ) !== false ) {
						$new_path = str_replace( $old_subdir, $new_subdir, $old_path );
						if ( file_exists( $base_dir . '/' . $new_path ) ) {
							$html = str_replace( $old_path, $new_path, $html );
						} else {
							$ver = filemtime( dirname( __DIR__, 2 ) . '/assets/js/catalog.js' );
							$html = str_replace(
								'/wp-content' . $matches[0][$i],
								$plugin_path . 'assets/js/catalog.js?ver=' . $ver,
								$html
							);
						}
						$changed = true;
						continue;
					}
					if ( $old_subdir !== $new_subdir ) {
						$html = str_replace( '/' . $old_subdir . '/', '/' . $new_subdir . '/', $html );
						$changed = true;
					}
					continue;
				}
				$new = self::latest_in_dir( $base_dir, $old_path, $cfg['prefix'] );
				if ( $new === null ) {
					return null;
				}
				$html = str_replace( $old_path, $new, $html );
				$changed = true;
			}
		}

		// Update version param for our plugin assets when source file changes
		$plugin_dir = dirname( __DIR__, 2 );
		$css_src = $plugin_dir . '/assets/css/catalog-render.css';
		$js_src = $plugin_dir . '/assets/js/catalog.js';
		$css_ver = file_exists( $css_src ) ? filemtime( $css_src ) : 0;
		$js_ver = file_exists( $js_src ) ? filemtime( $js_src ) : 0;

		if ( preg_match( '/aoe-catalog-engine\/assets\/css\/catalog-render\.css\?ver=(\d+)/', $html, $m )
			&& (int) $m[1] !== $css_ver ) {
			$html = str_replace( '?ver=' . $m[1], '?ver=' . $css_ver, $html );
			$changed = true;
		}
		if ( preg_match( '/aoe-catalog-engine\/assets\/js\/catalog\.js\?ver=(\d+)/', $html, $m )
			&& (int) $m[1] !== $js_ver ) {
			$html = str_replace( '?ver=' . $m[1], '?ver=' . $js_ver, $html );
			$changed = true;
		}

		if ( $changed ) {
			self::set( $manufacturer_slug, $page_slug, $html );
		}
		return $html;
	}

	private static function latest_in_dir( string $base_dir, string $old_path, string $prefix ): ?string {
		$ext = pathinfo( $old_path, PATHINFO_EXTENSION );
		$searches = [
			$base_dir . '/' . $prefix . '*.' . $ext,
			$base_dir . '/*/' . $prefix . '*.' . $ext,
			$base_dir . '/*/*/' . $prefix . '*.' . $ext,
		];
		foreach ( $searches as $glob ) {
			$files = glob( $glob );
			if ( ! empty( $files ) ) {
				usort( $files, function( $a, $b ) {
					return filemtime( $b ) - filemtime( $a );
				} );
				return str_replace( $base_dir . '/', '', $files[0] );
			}
		}
		return null;
	}

	public static function set( string $manufacturer_slug, string $page_slug, string $html ): void {
		$dir = dirname( self::file_path( $manufacturer_slug, $page_slug ) );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		file_put_contents( $dir . '/index.php', '<?php // Silence is golden.' );
		file_put_contents( self::file_path( $manufacturer_slug, $page_slug ), $html );
	}

	public static function invalidate( string $manufacturer_slug ): void {
		$dir = self::base_dir() . '/' . $manufacturer_slug;
		if ( is_dir( $dir ) ) {
			$files = array_diff( scandir( $dir ), [ '.', '..', 'index.php' ] );
			foreach ( $files as $file ) {
				$path = $dir . '/' . $file;
				if ( is_file( $path ) ) {
					unlink( $path );
				}
			}
		}
	}
}
