<?php

namespace AOE\CatalogEngine\Database;

class PageSegmentRepository {

	public static function get_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'aoe_catalog_page_segments';
	}

	public static function clear_by_manufacturer( int $manufacturer_id ) {
		global $wpdb;
		$wpdb->delete( self::get_table(), [ 'manufacturer_id' => $manufacturer_id ], [ '%d' ] );
	}

	public static function insert( array $data ): int {
		global $wpdb;
		$wpdb->insert( self::get_table(), [
			'page_id'        => $data['page_id'],
			'manufacturer_id' => $data['manufacturer_id'],
			'category_id'    => $data['category_id'],
			'segment_type'   => $data['segment_type'] ?? 'category',
			'products_from'  => $data['products_from'] ?? 0,
			'products_to'    => $data['products_to'] ?? 0,
			'sort_order'     => $data['sort_order'] ?? 0,
		], [ '%d', '%d', '%d', '%s', '%d', '%d', '%d' ] );
		return (int) $wpdb->insert_id;
	}

	public static function find_by_page_id( int $page_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM " . self::get_table() . " WHERE page_id = %d ORDER BY sort_order ASC",
			$page_id
		) );
		return $rows ?: [];
	}
}
