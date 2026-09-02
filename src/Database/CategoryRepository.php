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

	public static function update( int $id, array $data ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'aoe_catalog_categories';
		$formats = [];
		foreach ( $data as $key => $value ) {
			if ( in_array( $key, [ 'name', 'slug', 'description', 'image', 'sort_order', 'is_hidden', 'metadata_json' ], true ) ) {
				$formats[] = is_int( $value ) ? '%d' : '%s';
			}
		}
		return (bool) $wpdb->update( $table, $data, [ 'id' => $id ], $formats, [ '%d' ] );
	}

	public static function find_all( int $manufacturer_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'aoe_catalog_categories';
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $table WHERE manufacturer_id = %d ORDER BY sort_order ASC, level ASC, id ASC",
			$manufacturer_id
		) );
	}

	/**
	 * Reorder categories. sort_order is assigned per sibling group (same
	 * parent_id) based on the position each id holds within its own group in
	 * $ordered_ids, so a flat list from the UI can never scramble separate
	 * branches.
	 */
	public static function reorder( array $ordered_ids ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'aoe_catalog_categories';
		if ( empty( $ordered_ids ) ) {
			return;
		}
		$ids = array_map( 'intval', $ordered_ids );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, parent_id FROM $table WHERE id IN ($placeholders)", $ids
		) );
		$parent_of = [];
		foreach ( $rows as $r ) {
			$parent_of[ (int) $r->id ] = (int) $r->parent_id;
		}
		// Assign position within each sibling group.
		$rank  = [];
		$final_order = [];
		foreach ( $ordered_ids as $id ) {
			$id  = (int) $id;
			$pid = $parent_of[ $id ] ?? 0;
			$key = 'p' . $pid;
			$rank[ $key ] = ( $rank[ $key ] ?? 0 ) + 1;
			$final_order[ $id ] = $rank[ $key ];
		}
		foreach ( $final_order as $id => $order ) {
			$wpdb->update( $table, [ 'sort_order' => $order ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );
		}
	}

	public static function toggle_hidden( int $id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'aoe_catalog_categories';
		$current = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT is_hidden FROM $table WHERE id = %d", $id
		) );
		$new_value = $current ? 0 : 1;
		return (bool) $wpdb->update( $table, [ 'is_hidden' => $new_value ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );
	}
}
