<?php

namespace AOE\CatalogEngine\Import\Processors;

use AOE\CatalogEngine\Database\CategoryRepository;

class EdacProcessor extends BaseProcessor {

	public static function get_manufacturer_slug(): string {
		return 'edac';
	}

	public function get_page_threshold(): int {
		return 0;
	}

	public function get_supported_columns(): array {
		return [
			'series_id',
			'part_number',
			'part_image_url',
			'datasheet_url',
		];
	}

	protected function get_technical_spec_columns(): array {
		return []; // Override extract_technical_specs instead
	}

	protected function extract_technical_specs( array $row ): array {
		$skip = [ 'series_id', 'part_number', 'part_image_url', 'datasheet_url' ];
		$specs = [];
		foreach ( $row as $key => $value ) {
			$clean_key = trim( $key );
			if ( '' === $clean_key ) {
				continue;
			}
			if ( in_array( strtolower( $clean_key ), $skip, true ) ) {
				continue;
			}
			if ( '' !== trim( $value ) ) {
				$specs[ $clean_key ] = $this->normalize_text( (string) $value );
			}
		}
		return $specs;
	}

	public function process_row( array $row ): array {
		$data = $this->get_default_structure();

		// Strip BOM from all keys
		$row = array_combine(
			array_map( function ( $key ) { return ltrim( $key, "\xEF\xBB\xBF" ); }, array_keys( $row ) ),
			$row
		);

		$data['sku']  = isset( $row['part_number'] ) ? $this->normalize_text( (string) $row['part_number'] ) : '';
		$data['name'] = $data['sku'];

		// Resolve series_id → category path from DB (imported via catalog.csv structure import)
		$series_id = isset( $row['series_id'] ) ? trim( $row['series_id'] ) : '';
		if ( '' !== $series_id ) {
			$cat_info = $this->get_series_category_info( $series_id );
			if ( null !== $cat_info ) {
				$data['category_path'] = $cat_info['path'];
				$data['category']      = end( $cat_info['path'] );
			}
		}

		if ( empty( $data['category'] ) ) {
			$data['category'] = 'Uncategorized';
		}

		$image_url = isset( $row['part_image_url'] ) ? $this->normalize_text( (string) $row['part_image_url'] ) : '';
		$data['images'] = ! empty( $image_url ) ? [ $image_url ] : [];

		$datasheet = isset( $row['datasheet_url'] ) ? $this->normalize_text( (string) $row['datasheet_url'] ) : '';
		$data['pdf'] = [
			'datasheet' => $datasheet,
		];

		$specs = $this->extract_technical_specs( $row );
		if ( ! empty( $specs ) ) {
			$data['additional_data']['specs'] = $specs;
		}

		return $data;
	}

	/**
	 * Look up a series category by series_id from the DB (created by structure import).
	 *
	 * @return array{path: string[], category_id: int, metadata: array}|null
	 */
	private function get_series_category_info( string $series_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'aoe_catalog_categories';

		// Find series by metadata_json containing the series_id
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, name, metadata_json FROM $table WHERE type = 'series' AND metadata_json LIKE %s",
			'%"series_id":"' . $wpdb->esc_like( $series_id ) . '"%'
		) );

		if ( empty( $rows ) ) {
			return null;
		}

		$cat = $rows[0];

		// Build full path by traversing parent_id chain
		$path     = [ $cat->name ];
		$parentId = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT parent_id FROM $table WHERE id = %d", $cat->id
		) );

		while ( $parentId ) {
			$parent = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, name, parent_id FROM $table WHERE id = %d", $parentId
			) );
			if ( ! $parent ) {
				break;
			}
			array_unshift( $path, $parent->name );
			$parentId = (int) $parent->parent_id;
		}

		return [
			'path'        => $path,
			'category_id' => (int) $cat->id,
			'metadata'    => json_decode( $cat->metadata_json, true ) ?: [],
		];
	}
}
