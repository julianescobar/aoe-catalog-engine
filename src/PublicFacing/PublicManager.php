<?php

namespace AOE\CatalogEngine\PublicFacing;

class PublicManager {

	public function __construct() {
		add_action( 'init', [ $this, 'register_rewrite_rules' ] );
		add_filter( 'query_vars', [ $this, 'register_query_vars' ] );
		add_action( 'pre_get_posts', [ $this, 'override_catalog_query' ] );
		add_filter( 'template_include', [ $this, 'load_catalog_templates' ] );
		add_action( 'template_redirect', [ $this, 'intercept_catalog_request' ], 1 );
		add_filter( 'redirect_canonical', [ $this, 'disable_redirect_for_catalog' ], 10, 2 );
		// Sitemap provider desactivado durante pruebas. Activar al final:
		// add_filter( 'rank_math/sitemap/providers', [ $this, 'register_catalog_sitemap_provider' ] );
	}

	public function register_rewrite_rules() {
		// Test preview
		add_rewrite_rule( '^catalogo/(test-[^/]+)/([^/]+)-([0-9]+)/?', 'index.php?aoe_catalog_preview=$matches[1]&aoe_catalog_category=$matches[2]&aoe_catalog_page=$matches[3]', 'top' );
		add_rewrite_rule( '^catalogo/(test-[^/]+)/([^/]+)/?', 'index.php?aoe_catalog_preview=$matches[1]&aoe_catalog_category=$matches[2]', 'top' );

		// Production: grouped pages  /samtec/productos/ and /samtec/productos-2/
		add_rewrite_rule( '^catalogo/([^/]+)/productos(?:-([0-9]+))?/?', 'index.php?aoe_catalog_manufacturer=$matches[1]&aoe_catalog_page=$matches[2]&aoe_catalog_type=grouped', 'top' );

		// Production: category paginated  /samtec/erf8-2/
		add_rewrite_rule( '^catalogo/([^/]+)/([^/]+)-([0-9]+)/?', 'index.php?aoe_catalog_manufacturer=$matches[1]&aoe_catalog_category=$matches[2]&aoe_catalog_page=$matches[3]', 'top' );

		// Production: category single  /samtec/erf8/  or manufacturer index  /samtec/
		add_rewrite_rule( '^catalogo/([^/]+)/([^/]+)/?', 'index.php?aoe_catalog_manufacturer=$matches[1]&aoe_catalog_category=$matches[2]', 'top' );
		add_rewrite_rule( '^catalogo/([^/]+)/?', 'index.php?aoe_catalog_manufacturer=$matches[1]', 'top' );
	}

	public function register_query_vars( $vars ) {
		$vars[] = 'aoe_catalog';
		$vars[] = 'aoe_catalog_preview';
		$vars[] = 'aoe_catalog_manufacturer';
		$vars[] = 'aoe_catalog_category';
		$vars[] = 'aoe_catalog_page';
		$vars[] = 'aoe_catalog_type';
		return $vars;
	}

	public function override_catalog_query( $query ) {
		if ( ! is_admin() && $query->is_main_query() && (
			get_query_var( 'aoe_catalog' ) ||
			get_query_var( 'aoe_catalog_preview' ) ||
			get_query_var( 'aoe_catalog_manufacturer' )
		) ) {
			$query->is_404 = false;
			$query->is_home = false;
			status_header( 200 );
		}
	}

	public function load_catalog_templates( $template ) {
		$manufacturer_slug = get_query_var( 'aoe_catalog_manufacturer' );
		if ( ! empty( $manufacturer_slug ) ) {
			$view_path = __DIR__ . '/Views/single-catalog.php';
			if ( file_exists( $view_path ) ) {
				return $view_path;
			}
		}

		$preview_slug = get_query_var( 'aoe_catalog_preview' );
		if ( ! empty( $preview_slug ) ) {
			$view_path = __DIR__ . '/Views/preview-catalog.php';
			if ( file_exists( $view_path ) ) {
				return $view_path;
			}
		}

		$catalog_slug = get_query_var( 'aoe_catalog' );
		if ( ! empty( $catalog_slug ) ) {
			$view_path = __DIR__ . '/Views/single-catalog.php';
			if ( file_exists( $view_path ) ) {
				return $view_path;
			}
		}
		return $template;
	}

	public function intercept_catalog_request() {
		if ( get_query_var( 'aoe_catalog_manufacturer' ) || get_query_var( 'aoe_catalog_preview' ) || get_query_var( 'aoe_catalog' ) ) {
			status_header( 200 );
			nocache_headers();
		}
	}

	public function disable_redirect_for_catalog( $redirect_url, $requested_url ) {
		if ( get_query_var( 'aoe_catalog_manufacturer' ) || get_query_var( 'aoe_catalog_preview' ) || get_query_var( 'aoe_catalog' ) ) {
			return false;
		}

		// Also check the request path directly, in case rewrite rules didn't match
		$path = trim( parse_url( $requested_url, PHP_URL_PATH ), '/' );
		if ( preg_match( '#^catalogo/(test-[^/]+|[^/]+)#', $path ) ) {
			return false;
		}

		return $redirect_url;
	}

	public function register_catalog_sitemap_provider( $providers ) {
		$providers[] = new CatalogSitemapProvider();
		return $providers;
	}
}
