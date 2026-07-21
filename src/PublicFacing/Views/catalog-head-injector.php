<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve SEO context for the current catalog page.
 */
function aoe_get_catalog_seo_context( array $extra = [] ): array {
	global $wpdb, $wp;

	$site_name    = get_bloginfo( 'name' );
	$og_image     = content_url( 'uploads/tc-componentes-vr.webp' );

	// --- Resolve manufacturer ---
	$manufacturer_slug = $extra['manufacturer_slug'] ?? get_query_var( 'aoe_catalog_manufacturer' );
	$manufacturer_name = $extra['manufacturer_name'] ?? '';
	$mfr_id            = 0;
	$mfr_config        = [];

	if ( $manufacturer_slug ) {
		if ( ! $manufacturer_name ) {
			$table_m = $wpdb->prefix . 'aoe_catalog_manufacturers';
			$mfr = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, name, config_json FROM $table_m WHERE slug = %s",
				$manufacturer_slug
			) );
			if ( $mfr ) {
				$manufacturer_name = $mfr->name;
				$mfr_id            = (int) $mfr->id;
				$mfr_config        = json_decode( $mfr->config_json ?? '', true ) ?: [];
			}
		} else {
			$table_m = $wpdb->prefix . 'aoe_catalog_manufacturers';
			$mfr_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM $table_m WHERE slug = %s", $manufacturer_slug
			) );
			$mfr_config = [];
		}
	}

	// --- Resolve page type, category, page number ---
	$category_slug = $extra['category_slug'] ?? get_query_var( 'aoe_catalog_category' );
	$page_type     = $extra['page_type'] ?? get_query_var( 'aoe_catalog_type' );
	$page_num      = $extra['page_num'] ?? (int) get_query_var( 'aoe_catalog_page', 1 );

	if ( ! $page_type ) {
		$page_type = $category_slug ? 'category' : 'tree';
	}

	// Resolve category name
	$category_name = $extra['category_name'] ?? '';
	if ( ! $category_name && $category_slug && $mfr_id ) {
		$table_c = $wpdb->prefix . 'aoe_catalog_categories';
		$cat_row = $wpdb->get_row( $wpdb->prepare(
			"SELECT name FROM $table_c WHERE slug = %s AND manufacturer_id = %d LIMIT 1",
			$category_slug, $mfr_id
		) );
		$category_name = $cat_row ? $cat_row->name : str_replace( '-', ' ', ucwords( $category_slug, '-' ) );
	}

	// --- Resolve breadcrumbs for JSON-LD ---
	$breadcrumb_path = $extra['breadcrumb_path'] ?? [];
	if ( empty( $breadcrumb_path ) && $category_slug && $mfr_id ) {
		$table_c = $wpdb->prefix . 'aoe_catalog_categories';
		$cat = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, parent_id, name FROM $table_c WHERE slug = %s AND manufacturer_id = %d LIMIT 1",
			$category_slug, $mfr_id
		) );
		if ( $cat ) {
			$all_cats = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, name, parent_id FROM $table_c WHERE manufacturer_id = %d",
				$mfr_id
			) );
			$parent_of = [];
			$name_of   = [];
			foreach ( $all_cats as $c ) {
				$parent_of[ (int) $c->id ] = (int) $c->parent_id;
				$name_of[ (int) $c->id ]   = $c->name;
			}
			$ancestors = [];
			$cur = (int) $cat->parent_id;
			while ( $cur && isset( $name_of[ $cur ] ) ) {
				array_unshift( $ancestors, $name_of[ $cur ] );
				$cur = $parent_of[ $cur ] ?? 0;
			}
			$breadcrumb_path = array_merge( [ $manufacturer_name ], $ancestors );
		}
	}

	// --- Resolve product count for description ---
	$products_count = 0;
	if ( $mfr_id && $category_slug && 'category' === $page_type ) {
		$products_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT products_count FROM {$wpdb->prefix}aoe_catalog_categories WHERE manufacturer_id = %d AND slug = %s LIMIT 1",
			$mfr_id, $category_slug
		) );
	} elseif ( $mfr_id && $category_slug && 'tree' === $page_type ) {
		$root = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}aoe_catalog_categories WHERE manufacturer_id = %d AND slug = %s LIMIT 1",
			$mfr_id, $category_slug
		) );
		if ( $root ) {
			$cat_ids = [ $root ];
			$collect = [ $root ];
			while ( ! empty( $collect ) ) {
				$ids = $wpdb->get_col( $wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}aoe_catalog_categories WHERE manufacturer_id = %d AND parent_id IN (" .
					implode( ',', array_fill( 0, count( $collect ), '%d' ) ) . ")",
					array_merge( [ $mfr_id ], $collect )
				) );
				if ( ! empty( $ids ) ) {
					$cat_ids = array_merge( $cat_ids, $ids );
					$collect = array_map( 'intval', $ids );
				} else {
					$collect = [];
				}
			}
			$placeholders = implode( ',', array_fill( 0, count( $cat_ids ), '%d' ) );
			$products_count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT SUM(products_count) FROM {$wpdb->prefix}aoe_catalog_categories WHERE id IN ($placeholders)",
				$cat_ids
			) );
		}
	} elseif ( $mfr_id ) {
		$products_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT SUM(products_count) FROM {$wpdb->prefix}aoe_catalog_categories WHERE manufacturer_id = %d",
			$mfr_id
		) );
	}

	// --- Resolve total pages for prev/next & page suffix ---
	if ( 'grouped' === $page_type ) {
		$pagination_base = $manufacturer_slug . '/productos';
	} elseif ( $category_slug ) {
		$pagination_base = $manufacturer_slug . '/' . $category_slug;
	} else {
		$pagination_base = $manufacturer_slug;
	}

	$pagination_total = 1;
	if ( $mfr_id && $pagination_base ) {
		$pagination_total = max( 1, (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT MAX(page_number) FROM {$wpdb->prefix}aoe_catalog_pregenerated_pages
			 WHERE manufacturer_id = %d AND type = %s
			 AND (slug = %s OR slug LIKE %s)",
			$mfr_id, $page_type, $pagination_base,
			$wpdb->esc_like( $pagination_base ) . '-%'
		) ) );
	}

	// --- Build title & description ---
	$page_suffix = '';
	if ( $pagination_total > 1 && $page_num > 1 ) {
		$page_suffix = ' (p. ' . $page_num . ')';
	}

	if ( 'category' === $page_type ) {
		$title = 'Catálogo ' . $manufacturer_name . ' ' . $category_name . $page_suffix . ' | ' . $site_name;
		$description = 'Catálogo de ' . $category_name . ' de ' . $manufacturer_name;
		if ( $products_count > 0 ) {
			$description .= '. ' . number_format( $products_count ) . ' productos disponibles';
		}
		$description .= '. Somos distribuidores en España.';
	} elseif ( 'grouped' === $page_type ) {
		$title = 'Catálogo ' . $manufacturer_name . $page_suffix . ' | ' . $site_name;
		$description = 'Catálogo completo de productos de ' . $manufacturer_name;
		if ( $products_count > 0 ) {
			$description .= ', ' . number_format( $products_count ) . ' productos disponibles';
		}
		$description .= '. Somos distribuidores en España.';
	} else {
		// Tree / navigation page
		$category_part = '';
		if ( $manufacturer_slug === 'samtec' && $category_name ) {
			$category_part = ' ' . $category_name;
		}
		$title = 'Catálogo ' . $manufacturer_name . $category_part . $page_suffix . ' | ' . $site_name;
		$description = 'Catálogo completo de productos' . ( $category_part ? ' de ' . $category_name . ' de ' . $manufacturer_name : ' de ' . $manufacturer_name );
		if ( $products_count > 0 ) {
			$description .= ', ' . number_format( $products_count ) . ' productos disponibles';
		}
		$description .= '. Somos distribuidores en España.';
	}

	// --- Resolve numberOfItems for ItemList ---
	$number_of_items = 0;
	if ( $mfr_id && $manufacturer_slug ) {
		if ( 'grouped' === $page_type ) {
			$itemlist_slug = $manufacturer_slug . '/productos';
		} elseif ( 'category' === $page_type && $category_slug ) {
			$itemlist_slug = $manufacturer_slug . '/' . $category_slug;
		} else {
			$itemlist_slug = $manufacturer_slug;
		}
		if ( $page_num > 1 ) {
			$itemlist_slug .= '-' . $page_num;
		}
		$number_of_items = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT link_count FROM {$wpdb->prefix}aoe_catalog_pregenerated_pages WHERE slug = %s",
			$itemlist_slug
		) );
	}

	// --- Build canonical URL ---
	$canonical_slug = $manufacturer_slug;
	if ( $category_slug ) {
		$canonical_slug .= '/' . $category_slug;
	} elseif ( 'grouped' === $page_type ) {
		$canonical_slug .= '/productos';
	}
	if ( $page_num > 1 ) {
		$canonical_slug .= '-' . $page_num;
	}
	$canonical_url = home_url( '/catalogo/' . $canonical_slug . '/' );

	// --- Build JSON-LD @graph ---
	$json_ld_graph = aoe_build_jsonld_graph( [
		'canonical_url'     => $canonical_url,
		'title'             => $title,
		'description'       => $description,
		'manufacturer_slug' => $manufacturer_slug,
		'manufacturer_name' => $manufacturer_name,
		'manufacturer_url'  => $manufacturer_url,
		'page_type'         => $page_type,
		'breadcrumb_path'   => $breadcrumb_path,
		'number_of_items'   => $number_of_items,
	] );

	return [
		'title'             => $title,
		'description'       => $description,
		'canonical_url'     => $canonical_url,
		'body_class'        => '',
		'site_name'         => $site_name,
		'og_image'          => $og_image,
		'json_ld_graph'     => $json_ld_graph,
		'manufacturer_name' => $manufacturer_name,
		'current_page'      => $page_num,
		'total_pages'       => $pagination_total,
		'pagination_base'   => $pagination_base,
	];
}

/**
 * Build the full @graph JSON-LD matching production (RankMath-style).
 */
function aoe_build_jsonld_graph( array $ctx ): array {
	$canonical_url     = $ctx['canonical_url'] ?? '';
	$title             = $ctx['title'] ?? '';
	$description       = $ctx['description'] ?? '';
	$manufacturer_slug = $ctx['manufacturer_slug'] ?? '';
	$manufacturer_name = $ctx['manufacturer_name'] ?? '';
	$manufacturer_url  = $ctx['manufacturer_url'] ?? '';
	$page_type         = $ctx['page_type'] ?? 'tree';
	$breadcrumb_path   = $ctx['breadcrumb_path'] ?? [];
	$number_of_items   = (int) $ctx['number_of_items'];

	$home_url = home_url( '/' );
	$catalogo_url = home_url( '/catalogo/' );

	// --- Global entities from RankMath settings ---
	$rm      = function_exists( 'get_option' ) ? get_option( 'rank-math-options-titles', [] ) : [];
	$org_name = ! empty( $rm['knowledgegraph_name'] ) ? $rm['knowledgegraph_name'] : get_bloginfo( 'name' );
	$logo_url = ! empty( $rm['knowledgegraph_logo'] ) ? $rm['knowledgegraph_logo'] : content_url( 'uploads/tc-componentes-vr.webp' );
	$site_name  = ! empty( $rm['website_name'] ) ? $rm['website_name'] : get_bloginfo( 'name' );
	$alt_name   = ! empty( $rm['website_alternate_name'] ) ? $rm['website_alternate_name'] : $site_name;
	$org_url    = ! empty( $rm['url'] ) ? $rm['url'] : $home_url;
	$email      = ! empty( $rm['email'] ) ? $rm['email'] : 'infoweb@tc-componentes.es';
	$phone      = ! empty( $rm['phone'] ) ? $rm['phone'] : '+34 93 590 2830';
	$addr       = ! empty( $rm['local_address'] ) && is_array( $rm['local_address'] ) ? $rm['local_address'] : [];
	$org_desc   = ! empty( $rm['organization_description'] ) ? $rm['organization_description'] : '';
	$language   = 'es';

	$graph = [];

	// 1. Place
	$place = [
		'@type' => 'Place',
		'@id'   => $home_url . '#place',
	];
	if ( ! empty( $addr ) ) {
		$postal = [
			'@type' => 'PostalAddress',
		];
		if ( ! empty( $addr['streetAddress'] ) ) $postal['streetAddress'] = $addr['streetAddress'];
		if ( ! empty( $addr['addressLocality'] ) ) $postal['addressLocality'] = $addr['addressLocality'];
		if ( ! empty( $addr['addressRegion'] ) ) $postal['addressRegion'] = $addr['addressRegion'];
		if ( ! empty( $addr['postalCode'] ) ) $postal['postalCode'] = $addr['postalCode'];
		if ( ! empty( $addr['addressCountry'] ) ) $postal['addressCountry'] = $addr['addressCountry'];
		$place['address'] = $postal;
	}
	$graph[] = $place;

	// 2. Organization (TC Componentes)
	$org = [
		'@type' => 'Organization',
		'@id'   => $home_url . '#organization',
		'name'  => $org_name,
		'url'   => $org_url,
	];
	// sameAs
	$same_as = [];
	if ( ! empty( $rm['social_url_facebook'] ) ) $same_as[] = $rm['social_url_facebook'];
	if ( ! empty( $rm['twitter_author_names'] ) ) $same_as[] = 'https://twitter.com/' . $rm['twitter_author_names'];
	if ( ! empty( $rm['social_additional_profiles'] ) ) {
		$lines = explode( "\n", $rm['social_additional_profiles'] );
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( $line ) $same_as[] = $line;
		}
	}
	if ( ! empty( $same_as ) ) $org['sameAs'] = $same_as;
	if ( $email ) $org['email'] = $email;
	if ( ! empty( $addr ) ) {
		$postal_full = [ '@type' => 'PostalAddress' ];
		if ( ! empty( $addr['streetAddress'] ) ) $postal_full['streetAddress'] = $addr['streetAddress'];
		if ( ! empty( $addr['addressLocality'] ) ) $postal_full['addressLocality'] = $addr['addressLocality'];
		if ( ! empty( $addr['addressRegion'] ) ) $postal_full['addressRegion'] = $addr['addressRegion'];
		if ( ! empty( $addr['postalCode'] ) ) $postal_full['postalCode'] = $addr['postalCode'];
		if ( ! empty( $addr['addressCountry'] ) ) $postal_full['addressCountry'] = $addr['addressCountry'];
		$org['address'] = $postal_full;
	}

	// Logo
	$logo_id = ! empty( $rm['knowledgegraph_logo_id'] ) ? (int) $rm['knowledgegraph_logo_id'] : 0;
	if ( $logo_url ) {
		$logo = [
			'@type'      => 'ImageObject',
			'@id'        => $home_url . '#logo',
			'url'        => $logo_url,
			'contentUrl' => $logo_url,
			'caption'    => $site_name,
			'inLanguage' => $language,
		];
		if ( $logo_id ) {
			$meta = wp_get_attachment_metadata( $logo_id );
			if ( ! empty( $meta['width'] ) ) $logo['width'] = $meta['width'];
			if ( ! empty( $meta['height'] ) ) $logo['height'] = $meta['height'];
		}
		$org['logo'] = $logo;
	}

	// ContactPoint
	if ( $phone ) {
		$org['contactPoint'] = [
			[
				'@type'       => 'ContactPoint',
				'telephone'   => $phone,
				'contactType' => 'customer support',
			],
		];
	}
	if ( $org_desc ) $org['description'] = $org_desc;
	if ( ! empty( $addr ) ) $org['location'] = [ '@id' => $home_url . '#place' ];
	$graph[] = $org;

	// 3. WebSite
	$graph[] = [
		'@type'        => 'WebSite',
		'@id'          => $home_url . '#website',
		'url'          => $home_url,
		'name'         => $site_name,
		'alternateName' => $alt_name,
		'publisher'    => [ '@id' => $home_url . '#organization' ],
		'inLanguage'   => $language,
	];

	// 4. BreadcrumbList
	$items = [];
	$pos   = 1;
	$items[] = [
		'@type'    => 'ListItem',
		'position' => (string) $pos++,
		'item'     => [ '@id' => $home_url, 'name' => 'Inicio' ],
	];
	if ( $manufacturer_slug ) {
		$items[] = [
			'@type'    => 'ListItem',
			'position' => (string) $pos++,
			'item'     => [ '@id' => $catalogo_url, 'name' => 'Catálogo' ],
		];
		$items[] = [
			'@type'    => 'ListItem',
			'position' => (string) $pos++,
			'item'     => [ '@id' => $manufacturer_url, 'name' => $manufacturer_name ],
		];
		foreach ( $breadcrumb_path as $bc_name ) {
			if ( $bc_name === $manufacturer_name ) continue;
			$items[] = [
				'@type'    => 'ListItem',
				'position' => (string) $pos++,
				'item'     => [ 'name' => $bc_name ],
			];
		}
		// Last item: "Productos" for grouped pages
		if ( 'grouped' === $page_type ) {
			$items[] = [
				'@type'    => 'ListItem',
				'position' => (string) $pos++,
				'item'     => [ '@id' => $canonical_url, 'name' => 'Productos' ],
			];
		}
	}
	if ( count( $items ) > 1 ) {
		$graph[] = [
			'@type'           => 'BreadcrumbList',
			'@id'             => '#breadcrumb',
			'itemListElement' => $items,
		];
	}

	// 5. WebPage
	$webpage = [
		'@type'     => 'WebPage',
		'@id'       => '#webpage',
		'url'       => $canonical_url,
		'isPartOf'  => [ '@id' => $home_url . '#website' ],
		'inLanguage' => $language,
	];
	if ( count( $items ) > 1 ) {
		$webpage['breadcrumb'] = [ '@id' => '#breadcrumb' ];
	}
	$itemlist_id = $canonical_url . '#itemlist';
	$webpage['mainEntity'] = [ '@id' => $itemlist_id ];
	$graph[] = $webpage;

	// 6. Organization (manufacturer)
	if ( $manufacturer_slug && $manufacturer_url ) {
		$graph[] = [
			'@type' => 'Organization',
			'@id'   => $manufacturer_url . '#manufacturer',
			'name'  => $manufacturer_name,
			'url'   => $manufacturer_url,
		];
	}

	// 7. ItemList
	$itemlist = [
		'@type' => 'ItemList',
		'@id'   => $itemlist_id,
		'url'   => $canonical_url,
	];
	if ( $number_of_items > 0 ) {
		$itemlist['numberOfItems'] = (string) $number_of_items;
	}
	$graph[] = $itemlist;

	return $graph;
}

/**
 * Replace dynamic head elements in cached template HTML.
 */
function aoe_inject_dynamic_head( string $html, array $context ): string {
	$title            = $context['title'] ?? '';
	$description      = $context['description'] ?? '';
	$canonical_url    = $context['canonical_url'] ?? '';
	$body_class       = $context['body_class'] ?? '';
	$site_name        = $context['site_name'] ?: get_bloginfo( 'name' );
	$og_image         = $context['og_image'] ?: content_url( 'uploads/tc-componentes-vr.webp' );
	$json_ld_graph    = $context['json_ld_graph'] ?? [];
	$manufacturer_name = $context['manufacturer_name'] ?? '';

	// 1. <title>
	if ( $title ) {
		$html = preg_replace(
			'/<title>[^<]*<\/title>/i',
			'<title>' . esc_html( $title ) . '</title>',
			$html, 1, $count
		);
		if ( ! $count ) {
			$html = str_replace( '</head>', '<title>' . esc_html( $title ) . '</title>' . "\n" . '</head>', $html );
		}
	}

	// 2. <meta name="description">
	if ( $description ) {
		$html = preg_replace(
			'/<meta\s+name=["\']description["\'][^>]*\/?>/i',
			'<meta name="description" content="' . esc_attr( $description ) . '" />',
			$html, 1, $count
		);
		if ( ! $count ) {
			$html = preg_replace(
				'/(<\/title>\s*)/i',
				'$1' . "\n" . '<meta name="description" content="' . esc_attr( $description ) . '" />',
				$html, 1
			);
		}
	}

	// 3. <link rel="canonical">
	if ( $canonical_url ) {
		$html = preg_replace(
			'/<link\s+rel=["\']canonical["\'][^>]*\/?>/i',
			'<link rel="canonical" href="' . esc_url( $canonical_url ) . '" />',
			$html, 1, $count
		);
		if ( ! $count ) {
			$html = preg_replace(
				'/(<meta\s+name=["\']description["\'][^>]*\/?>)/i',
				'$1' . "\n" . '<link rel="canonical" href="' . esc_url( $canonical_url ) . '" />',
				$html, 1
			);
		}
	}

	// 4. <link rel="alternate" hreflang> — strip wrong ones from RankMath, inject with canonical URL
	if ( $canonical_url ) {
		// Remove any existing hreflang (likely with __gen-template URL from template cache generation)
		$html = preg_replace(
			'/<link\s+rel=["\']alternate["\'][^>]*\bhreflang\b[^>]*\/?>\s*\n?/i',
			'',
			$html
		);
		// Inject correct hreflang
		$hreflang_es = "\n" . '<link rel="alternate" hreflang="es" href="' . esc_url( $canonical_url ) . '" />';
		$hreflang_xd = "\n" . '<link rel="alternate" hreflang="x-default" href="' . esc_url( $canonical_url ) . '" />';
		$html = preg_replace(
			'/(<\/head>)/i',
			$hreflang_es . $hreflang_xd . "\n" . '</head>',
			$html, 1
		);
	}

	// 5. <link rel="prev"> / <link rel="next">
	$current_page = $context['current_page'] ?? 0;
	$total_pages  = $context['total_pages'] ?? 0;
	$base_slug    = $context['pagination_base'] ?? '';

	if ( $total_pages > 1 && $base_slug ) {
		$catalogo_url = home_url( '/catalogo/' );
		$prev_tag = '';
		$next_tag = '';

		if ( $current_page > 1 ) {
			$prev_slug = $current_page === 2 ? $base_slug : $base_slug . '-' . ( $current_page - 1 );
			$prev_tag = "\n" . '<link rel="prev" href="' . esc_url( $catalogo_url . $prev_slug . '/' ) . '" />';
		}
		if ( $current_page < $total_pages ) {
			$next_slug = $base_slug . '-' . ( $current_page + 1 );
			$next_tag = "\n" . '<link rel="next" href="' . esc_url( $catalogo_url . $next_slug . '/' ) . '" />';
		}

		if ( $prev_tag || $next_tag ) {
			$html = str_replace(
				'</head>',
				$prev_tag . $next_tag . "\n" . '</head>',
				$html
			);
		}
	}

	// 6. <meta name="robots"> — force follow, index (template may have noindex from RankMath)
	$html = preg_replace(
		'/<meta\s+name=["\']robots["\'][^>]*\/?>\s*\n?/i',
		'',
		$html, 1
	);
	$html = preg_replace(
		'/(<\/title>\s*)/i',
		'$1' . "\n" . '<meta name="robots" content="follow, index" />',
		$html, 1
	);

	// 5. OG tags — replace existing or inject before </head>
	$og_map = [
		'og:locale'       => 'es_ES',
		'og:type'         => 'website',
		'og:title'        => $title,
		'og:description'  => $description,
		'og:url'          => $canonical_url,
		'og:site_name'    => $site_name,
	];
	foreach ( $og_map as $prop => $value ) {
		if ( ! $value ) {
			continue;
		}
		$replaced = preg_replace(
			'/<meta\s+property=["\']' . preg_quote( $prop, '/' ) . '["\'][^>]*\/?>/i',
			'<meta property="' . esc_attr( $prop ) . '" content="' . esc_attr( $value ) . '" />',
			$html, -1, $count
		);
		if ( $count ) {
			$html = $replaced;
		} else {
			$html = str_replace( '</head>',
				'<meta property="' . esc_attr( $prop ) . '" content="' . esc_attr( $value ) . '" />' . "\n" . '</head>',
				$html
			);
		}
	}
	// og:image
	$replaced = preg_replace(
		'/<meta\s+property=["\']og:image["\'][^>]*\/?>/i',
		'<meta property="og:image" content="' . esc_url( $og_image ) . '" />',
		$html, -1, $count
	);
	if ( $count ) {
		$html = $replaced;
	} else {
		$html = str_replace( '</head>',
			'<meta property="og:image" content="' . esc_url( $og_image ) . '" />' . "\n" . '</head>',
			$html
		);
	}
	// og:image:secure_url
	$replaced = preg_replace(
		'/<meta\s+property=["\']og:image:secure_url["\'][^>]*\/?>/i',
		'<meta property="og:image:secure_url" content="' . esc_url( $og_image ) . '" />',
		$html, -1, $count
	);
	if ( $count ) {
		$html = $replaced;
	} else {
		$html = str_replace( '</head>',
			'<meta property="og:image:secure_url" content="' . esc_url( $og_image ) . '" />' . "\n" . '</head>',
			$html
		);
	}

	// 5. Twitter cards — inject card + image; leave title/description absent (null) as in production
	$html = preg_replace(
		'/<meta\s+name=["\']twitter:card["\'][^>]*\/?>\s*\n?/i',
		'',
		$html, 1
	);
	$html = preg_replace(
		'/(<meta\s+property=["\']og:image["\'][^>]*\/?>)/i',
		'$1' . "\n" . '<meta name="twitter:card" content="summary_large_image" />',
		$html, 1
	);
	// twitter:image = same as og:image
	$html = preg_replace(
		'/<meta\s+name=["\']twitter:image["\'][^>]*\/?>\s*\n?/i',
		'',
		$html, 1
	);
	$html = preg_replace(
		'/(<meta\s+name=["\']twitter:card["\'][^>]*\/?>)/i',
		'$1' . "\n" . '<meta name="twitter:image" content="' . esc_url( $og_image ) . '" />',
		$html, 1
	);

	// 6. <body class="">
	if ( $body_class ) {
		$html = preg_replace(
			'/(<body\s[^>]*)class=["\'][^"\']*["\']/i',
			'$1class="' . esc_attr( $body_class ) . '"',
			$html, 1
		);
	}

	// 7. JSON-LD — remove existing, inject single @graph before </head>
	$html = preg_replace(
		'/<script\s+type=["\']application\/ld\+json["\'][^>]*>.*?<\/script>\s*\n?/is',
		'',
		$html
	);
	if ( ! empty( $json_ld_graph ) ) {
		$json_ld_html = '<script type="application/ld+json" class="rank-math-schema-pro">'
			. wp_json_encode( [
				'@context' => 'https://schema.org',
				'@graph'   => $json_ld_graph,
			], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT )
			. '</script>' . "\n";
		$html = str_replace( '</head>', $json_ld_html . '</head>', $html );
	}

	// 8. Inline aoeCatalog data for JS
	$html = preg_replace(
		'/<script[^>]*id=["\']aoe-catalog-data["\'][^>]*>.*?<\/script>\s*\n?/is',
		'',
		$html
	);
	$html = preg_replace(
		'/<script[^>]*>\s*\/\* <!\[CDATA\[ \*\/\s*var aoeCatalog\s*=.*?\/\* \]\]> \*\/\s*<\/script>\s*\n?/is',
		'',
		$html
	);
	$catalog_script = '<script id="aoe-catalog-data" type="text/javascript">/* <![CDATA[ */' .
		'var aoeCatalog=' . wp_json_encode( [
			'manufacturerName' => $manufacturer_name,
		] ) . ';/* ]]> */</script>' . "\n";
	$html = str_replace( '</head>', $catalog_script . '</head>', $html );

	return $html;
}
