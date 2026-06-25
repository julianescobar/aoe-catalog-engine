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
			"SELECT m.slug AS manufacturer_slug, COUNT(p.id) AS total_pages
			 FROM {$wpdb->prefix}aoe_catalog_manufacturers m
			 JOIN {$wpdb->prefix}aoe_catalog_pregenerated_pages p ON m.id = p.manufacturer_id
			 WHERE p.type IN ('category', 'grouped', 'tree')
			 GROUP BY m.id, m.slug"
		);

		$links = [];
		foreach ( $manufacturers as $m ) {
			$max_pages = (int) ceil( (int) $m->total_pages / $max_entries );
			for ( $page = 1; $page <= $max_pages; $page++ ) {
				$suffix = $page > 1 ? $page : '';
				$links[] = [
					'loc'     => Router::get_base_url( 'catalogo-' . $m->manufacturer_slug . '-sitemap' . $suffix . '.xml' ),
					'lastmod' => gmdate( 'c' ),
				];
			}
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
				"SELECT DISTINCT p.slug, p.type
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

		// Build lookup: slug -> category metadata_json (only for category pages)
		$redirect_map = [];
		$cat_pages = wp_list_filter( $pages, [ 'type' => 'category' ] );
		if ( ! empty( $cat_pages ) ) {
			$slugs = array_map( function( $p ) { return $p->slug; }, $cat_pages );
			$slugs_placeholder = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
			$cat_meta = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.slug, c.metadata_json
					 FROM {$wpdb->prefix}aoe_catalog_pregenerated_pages p
					 JOIN {$wpdb->prefix}aoe_catalog_page_segments s ON p.id = s.page_id
					 JOIN {$wpdb->prefix}aoe_catalog_categories c ON s.category_id = c.id
					 WHERE p.slug IN ($slugs_placeholder)
					 AND c.metadata_json LIKE '%wp_post_id%'",
					$slugs
				)
			);
			foreach ( $cat_meta as $cm ) {
				$meta = json_decode( $cm->metadata_json, true );
				if ( ! empty( $meta['wp_post_id'] ) ) {
					$redirect_map[ $cm->slug ] = (int) $meta['wp_post_id'];
				}
			}
		}

		$links = [];
		$added = [];
		foreach ( $pages as $page ) {
			if ( isset( $redirect_map[ $page->slug ] ) ) {
				$url = get_permalink( $redirect_map[ $page->slug ] );
				if ( $url && ! isset( $added[ $url ] ) ) {
					$added[ $url ] = true;
					$links[] = [
						'loc' => $url,
						'mod' => gmdate( 'c' ),
					];
				}
				continue;
			}
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
