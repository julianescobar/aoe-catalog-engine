<?php

namespace AOE\CatalogEngine\Database;

class PageRepository {

	public static function get_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'aoe_catalog_pregenerated_pages';
	}

	public static function clear_by_manufacturer( int $manufacturer_id ) {
		global $wpdb;
		$wpdb->delete( self::get_table(), [ 'manufacturer_id' => $manufacturer_id ], [ '%d' ] );
	}

	public static function insert( array $data ): int {
		global $wpdb;
		$wpdb->insert( self::get_table(), [
			'manufacturer_id' => $data['manufacturer_id'],
			'type'            => $data['type'],
			'slug'            => $data['slug'],
			'page_number'     => $data['page_number'] ?? 1,
			'link_count'      => $data['link_count'] ?? 0,
		], [ '%d', '%s', '%s', '%d', '%d' ] );
		return (int) $wpdb->insert_id;
	}

	public static function find_by_manufacturer_slug( int $manufacturer_id, string $slug ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM " . self::get_table() . " WHERE manufacturer_id = %d AND slug = %s",
			$manufacturer_id,
			$slug
		) );
		return $row ?: null;
	}
}
