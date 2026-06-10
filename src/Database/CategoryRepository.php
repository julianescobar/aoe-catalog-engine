<?php

namespace AOE\CatalogEngine\Database;

class CategoryRepository {

	public static function find_or_create( int $manufacturer_id, string $name, string $type = 'category', ?int $parent_id = null ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'aoe_catalog_categories';
		$slug = sanitize_title( $name );

		$existing_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM $table WHERE manufacturer_id = %d AND slug = %s AND type = %s",
			$manufacturer_id,
			$slug,
			$type
		) );

		if ( $existing_id ) {
			return (int) $existing_id;
		}

		$level = 0;
		if ( null !== $parent_id ) {
			$parent_level = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT level FROM $table WHERE id = %d", $parent_id
			) );
			$level = $parent_level + 1;
		}

		$wpdb->insert(
			$table,
			[
				'manufacturer_id' => $manufacturer_id,
				'parent_id'       => $parent_id,
				'name'            => $name,
				'slug'            => $slug,
				'type'            => $type,
				'level'           => $level,
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
