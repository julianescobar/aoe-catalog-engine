<?php

namespace AOE\CatalogEngine\PublicFacing;

class PublicManager {

	public function __construct() {
		add_action( 'init', [ $this, 'register_rewrite_rules' ] );
		add_filter( 'query_vars', [ $this, 'register_query_vars' ] );
		add_action( 'pre_get_posts', [ $this, 'override_catalog_query' ] );
		add_filter( 'template_include', [ $this, 'load_catalog_templates' ] );
	}

	public function register_rewrite_rules() {
		add_rewrite_rule( '^catalogo/(test-[^/]+)/([^/]+)-([0-9]+)/?', 'index.php?aoe_catalog_preview=$matches[1]&aoe_catalog_category=$matches[2]&aoe_catalog_page=$matches[3]', 'top' );
		add_rewrite_rule( '^catalogo/(test-[^/]+)/([^/]+)/?', 'index.php?aoe_catalog_preview=$matches[1]&aoe_catalog_category=$matches[2]', 'top' );
		add_rewrite_rule( '^catalog/([^/]+)/?', 'index.php?aoe_catalog=$matches[1]', 'top' );
	}

	public function register_query_vars( $vars ) {
		$vars[] = 'aoe_catalog';
		$vars[] = 'aoe_catalog_preview';
		$vars[] = 'aoe_catalog_category';
		$vars[] = 'aoe_catalog_page';
		return $vars;
	}

	public function override_catalog_query( $query ) {
		if ( ! is_admin() && $query->is_main_query() && ( get_query_var( 'aoe_catalog' ) || get_query_var( 'aoe_catalog_preview' ) ) ) {
			$query->is_404 = false;
			status_header( 200 );
		}
	}

	public function load_catalog_templates( $template ) {
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
}
