<?php

namespace AOE\CatalogEngine\Database;

class ProductRepository {

	public static function save( array $data ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'aoe_catalog_products';

		// Check if product with SKU already exists for this manufacturer
		$id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM $table WHERE manufacturer_id = %d AND sku = %s",
			$data['manufacturer_id'],
			$data['sku']
		) );

		// images can be either a plain string URL or an array of URLs
		$images_value = is_array( $data['images'] )
			? json_encode( $data['images'] )
			: (string) $data['images'];

		$db_data = [
			'manufacturer_id' => $data['manufacturer_id'],
			'category_id'     => $data['category_id'],
			'sku'             => $data['sku'],
			'name'            => $data['name'],
			'description'     => $data['description'],
			'urls_images'     => $images_value,
			'url_pdf'         => json_encode( $data['pdf'] ),
			'additional_data' => json_encode( $data['additional_data'] ),
		];

		if ( $id ) {
			// Update
			$wpdb->update(
				$table,
				$db_data,
				[ 'id' => $id ],
				[ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ],
				[ '%d' ]
			);
			return (int) $id;
		} else {
			// Insert
			$wpdb->insert(
				$table,
				$db_data,
				[ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
			);
			return (int) $wpdb->insert_id;
		}
	}

	public static function clear_by_manufacturer( int $manufacturer_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'aoe_catalog_products';
		$wpdb->delete( $table, [ 'manufacturer_id' => $manufacturer_id ], [ '%d' ] );
	}
}
