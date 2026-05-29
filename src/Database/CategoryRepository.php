<?php

namespace AOE\CatalogEngine\Database;

class CategoryRepository {

	public static function find_or_create( int $manufacturer_id, string $name, string $type = 'category' ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'aoe_catalog_categories';
		$slug = sanitize_title( $name );

		// Check if exists
		$id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM $table WHERE manufacturer_id = %d AND slug = %s AND type = %s",
			$manufacturer_id,
			$slug,
			$type
		) );

		if ( $id ) {
			return (int) $id;
		}

		// Insert new category
		$wpdb->insert(
			$table,
			[
				'manufacturer_id' => $manufacturer_id,
				'parent_id'       => null,
				'name'            => $name,
				'slug'            => $slug,
				'type'            => $type,
				'level'           => 0,
				'products_count'  => 0,
				'metadata_json'   => json_encode( [] )
			],
			[ '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s' ]
		);

		return (int) $wpdb->insert_id;
	}

	public static function increment_count( int $category_id, int $count = 1 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'aoe_catalog_categories';
		$wpdb->query( $wpdb->prepare(
			"UPDATE $table SET products_count = products_count + %d WHERE id = %d",
			$count,
			$category_id
		) );
	}

	public static function clear_by_manufacturer( int $manufacturer_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'aoe_catalog_categories';
		$wpdb->delete( $table, [ 'manufacturer_id' => $manufacturer_id ], [ '%d' ] );
	}
}
