<?php

namespace AOE\CatalogEngine\PublicFacing;

use RankMath\Sitemap\Providers\Provider;
use RankMath\Sitemap\Router;

class CatalogSitemapProvider implements Provider {

	public function handles_type( $type ) {
		return 0 === strpos( $type, 'catalogo-' );
	}

	public function get_index_links( $max_entries ) {
		global $wpdb;

		$manufacturers = $wpdb->get_results(
			"SELECT m.slug AS manufacturer_slug
			 FROM {$wpdb->prefix}aoe_catalog_manufacturers m
			 JOIN {$wpdb->prefix}aoe_catalog_pregenerated_pages p ON m.id = p.manufacturer_id
			 WHERE p.type IN ('category', 'grouped', 'tree')
			 GROUP BY m.id, m.slug"
		);

		$links = [];
		foreach ( $manufacturers as $m ) {
			$links[] = [
				'loc'     => Router::get_base_url( 'catalogo-' . $m->manufacturer_slug . '-sitemap.xml' ),
				'lastmod' => gmdate( 'c' ),
			];
		}

		return $links;
	}

	public function get_sitemap_links( $type, $max_entries, $current_page ) {
		global $wpdb;

		$manufacturer_slug = substr( $type, 9 );
		if ( empty( $manufacturer_slug ) ) {
			return [];
		}

		$offset = ( $current_page > 1 ) ? ( ( $current_page - 1 ) * $max_entries ) : 0;

		$pages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT p.slug
				 FROM {$wpdb->prefix}aoe_catalog_pregenerated_pages p
				 JOIN {$wpdb->prefix}aoe_catalog_manufacturers m ON p.manufacturer_id = m.id
				 WHERE p.type IN ('category', 'grouped', 'tree')
				 AND m.slug = %s
				 ORDER BY 
					CASE p.type 
						WHEN 'tree' THEN 1
						WHEN 'grouped' THEN 2
						WHEN 'category' THEN 3
						ELSE 4
					END,
					p.page_number ASC
				 LIMIT %d OFFSET %d",
				$manufacturer_slug,
				$max_entries,
				$offset
			)
		);

		$links = [];
		$added = [];
		foreach ( $pages as $page ) {
			$url = home_url( '/catalogo/' . $page->slug . '/' );
			if ( isset( $added[ $url ] ) ) {
				continue;
			}
			$added[ $url ] = true;
			$links[] = [
				'loc' => $url,
				'mod' => gmdate( 'c' ),
			];
		}

		return $links;
	}
}
