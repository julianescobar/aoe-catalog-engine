<?php

namespace AOE\CatalogEngine\Database;

class CategoryRepository {

	public static function find_or_create( int $manufacturer_id, string $name, string $type = 'category', ?int $parent_id = null ): int {
		$existing_id = self::find_existing( $manufacturer_id, $name, $type, $parent_id );
		if ( null !== $existing_id ) {
			return $existing_id;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'aoe_catalog_categories';
		$slug = sanitize_title( $name );

		$level = 1;
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

	/**
	 * Walk a category path (e.g. ["Light Pipes", "Rigid Panel Press-Fit", "GLP"])
	 * and return the leaf category ID, or null if any level is not found.
	 * Does NOT create missing categories.
	 */
	public static function find_by_path( int $manufacturer_id, array $path_names, string $type = 'category' ): ?int {
		$parent_id = null;
		foreach ( $path_names as $name ) {
			$id = self::find_existing( $manufacturer_id, $name, $type, $parent_id );
			if ( null === $id ) {
				return null;
			}
			$parent_id = $id;
		}
		return $parent_id;
	}

	private static function find_existing( int $manufacturer_id, string $name, string $type, ?int $parent_id ): ?int {
		global $wpdb;
		$table = $wpdb->prefix . 'aoe_catalog_categories';
		$slug  = sanitize_title( $name );

		if ( null !== $parent_id ) {
			$id = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM $table WHERE manufacturer_id = %d AND slug = %s AND parent_id = %d LIMIT 1",
				$manufacturer_id, $slug, $parent_id
			) );
		} else {
			$id = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM $table WHERE manufacturer_id = %d AND slug = %s AND type = %s LIMIT 1",
				$manufacturer_id, $slug, $type
			) );
		}
		return $id !== null ? (int) $id : null;
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
