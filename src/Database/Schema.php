<?php

namespace AOE\CatalogEngine\Database;

class Schema {

	public static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$table_manufacturers = $wpdb->prefix . 'aoe_catalog_manufacturers';
		$table_categories = $wpdb->prefix . 'aoe_catalog_categories';
		$table_products = $wpdb->prefix . 'aoe_catalog_products';

		$sql_manufacturers = "CREATE TABLE $table_manufacturers (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			slug varchar(255) NOT NULL,
			wp_post_id bigint(20) unsigned NULL,
			config_json longtext NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug)
		) $charset_collate;";

		$sql_categories = "CREATE TABLE $table_categories (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			manufacturer_id bigint(20) unsigned NOT NULL,
			parent_id bigint(20) unsigned NULL,
			name varchar(255) NOT NULL,
			slug varchar(255) NOT NULL,
			type varchar(50) NOT NULL DEFAULT 'category',
			description longtext NULL,
			image varchar(255) NULL,
			level int(11) NOT NULL DEFAULT 0,
			sort_order int(11) NOT NULL DEFAULT 0,
			is_hidden tinyint(1) NOT NULL DEFAULT 0,
			products_count int(11) NOT NULL DEFAULT 0,
			metadata_json longtext NULL,
			PRIMARY KEY  (id),
			KEY manufacturer_id (manufacturer_id),
			KEY parent_id (parent_id),
			KEY slug (slug),
			KEY idx_manufacturer_slug (manufacturer_id, slug)
		) $charset_collate;";

		$sql_products = "CREATE TABLE $table_products (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			manufacturer_id bigint(20) unsigned NOT NULL,
			category_id bigint(20) unsigned NOT NULL,
			sku varchar(255) NOT NULL,
			name varchar(255) NOT NULL,
			description longtext NULL,
			urls_images longtext NULL,
			url_pdf longtext NULL,
			additional_data longtext NULL,
			PRIMARY KEY  (id),
			KEY manufacturer_id (manufacturer_id),
			KEY category_id (category_id),
			KEY sku (sku),
			KEY name (name),
			KEY idx_manufacturer_category (manufacturer_id, category_id),
			KEY idx_manufacturer_sku (manufacturer_id, sku)
		) $charset_collate;";

		$table_pages = $wpdb->prefix . 'aoe_catalog_pregenerated_pages';
		$sql_pages = "CREATE TABLE $table_pages (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			manufacturer_id bigint(20) unsigned NOT NULL,
			type varchar(50) NOT NULL DEFAULT 'category',
			slug varchar(255) NOT NULL,
			page_number int(11) NOT NULL DEFAULT 1,
			link_count int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY manufacturer_id (manufacturer_id),
			KEY slug (slug),
			UNIQUE KEY manufacturer_slug (manufacturer_id, slug)
		) $charset_collate;";

		$table_segments = $wpdb->prefix . 'aoe_catalog_page_segments';
		$sql_segments = "CREATE TABLE $table_segments (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			page_id bigint(20) unsigned NOT NULL,
			manufacturer_id bigint(20) unsigned NOT NULL,
			category_id bigint(20) unsigned NOT NULL,
			segment_type varchar(50) NOT NULL DEFAULT 'category',
			products_from int(11) NOT NULL DEFAULT 0,
			products_to int(11) NOT NULL DEFAULT 0,
			sort_order int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY page_id (page_id),
			KEY manufacturer_id (manufacturer_id),
			KEY category_id (category_id)
		) $charset_collate;";

		$table_sku_map = $wpdb->prefix . 'aoe_catalog_sku_map';
		$sql_sku_map = "CREATE TABLE $table_sku_map (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			manufacturer_id bigint(20) unsigned NOT NULL,
			sku varchar(255) NOT NULL,
			codigo_serie varchar(255) NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY manufacturer_sku (manufacturer_id, sku),
			KEY codigo_serie (codigo_serie)
		) $charset_collate;";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		
		dbDelta( $sql_manufacturers );
		dbDelta( $sql_categories );
		dbDelta( $sql_products );
		dbDelta( $sql_pages );
		dbDelta( $sql_segments );
		dbDelta( $sql_sku_map );
	}
}
