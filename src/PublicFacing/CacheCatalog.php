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
		if ( file_exists( $path ) ) {
			$contents = file_get_contents( $path );
			if ( $contents !== false && $contents !== '' ) {
				return $contents;
			}
			unlink( $path );
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
