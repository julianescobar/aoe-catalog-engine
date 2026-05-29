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
			products_count int(11) NOT NULL DEFAULT 0,
			metadata_json longtext NULL,
			PRIMARY KEY  (id),
			KEY manufacturer_id (manufacturer_id),
			KEY parent_id (parent_id)
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

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		
		dbDelta( $sql_manufacturers );
		dbDelta( $sql_categories );
		dbDelta( $sql_products );
	}
}
