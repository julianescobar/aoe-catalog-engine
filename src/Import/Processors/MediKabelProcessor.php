<?php

namespace AOE\CatalogEngine\Import\Processors;

class MediKabelProcessor extends BaseProcessor {

	public static function get_manufacturer_slug(): string {
		return 'medi-kabel';
	}

	public function has_separate_categories(): bool {
		return true;
	}

	public function get_page_threshold(): int {
		return 190;
	}

	public function get_supported_columns(): array {
		return [
			'article_number',
			'series_id',
			'series_name',
			'series_group',
			'series_url',
			'category_id',
			'subcategory_id',
			'image_url',
			'properties_badges',
			'construction',
			'technical_data',
			'properties',
		];
	}

	protected function get_technical_spec_columns(): array {
		return [
			'awg',
			'conductor',
			'core_dia_mm',
			'core_insulation',
			'cross_sectional_area_m',
			'cu_index',
			'inside_diameter_contracted_dia_mm',
			'inside_diameter_expanded_dia_mm',
			'no_of_cores',
			'nominal_size_mm',
			'norm',
			'operating_voltage_u',
			'outer_sheath',
			'packaging_unit',
			'shielding',
			'stranding',
			'temperature_degc',
			'test_voltage',
			'test_voltage_ac',
			'test_voltage_vac',
			'total_weight_kg_km',
			'u_ac',
			'u_dc',
			'u_vac',
			'uo_ac',
			'uo_dc',
			'uo_vac',
		];
	}

	public function process_row( array $row ): array {
		$data = $this->get_default_structure();

		// Strip BOM from all keys
		$row = array_combine(
			array_map( static function ( $key ) { return ltrim( $key, "\xEF\xBB\xBF" ); }, array_keys( $row ) ),
			$row
		);

		$sku = isset( $row['article_number'] ) ? $this->normalize_text( (string) $row['article_number'] ) : '';
		if ( '' === $sku ) {
			return $data;
		}

		$data['sku']  = $sku;
		$data['name'] = $sku;

		// Resolve category path: series_id -> subcategory -> category (from DB structure)
		$series_id      = isset( $row['series_id'] ) ? trim( (string) $row['series_id'] ) : '';
		$subcategory_id = isset( $row['subcategory_id'] ) ? trim( (string) $row['subcategory_id'] ) : '';
		$category_id    = isset( $row['category_id'] ) ? trim( (string) $row['category_id'] ) : '';

		$cat_info = null;
		if ( '' !== $series_id ) {
			$cat_info = $this->get_series_category_info( $series_id );
		}
		if ( null === $cat_info && '' !== $subcategory_id ) {
			$cat_info = $this->get_category_info_by_medikabel_id( $subcategory_id, 2 );
		}
		if ( null === $cat_info && '' !== $category_id ) {
			$cat_info = $this->get_category_info_by_medikabel_id( $category_id, 1 );
		}

		if ( null !== $cat_info && ! empty( $cat_info['path'] ) ) {
			$data['category_path'] = $cat_info['path'];
			$data['category']      = end( $cat_info['path'] );
		}
		if ( empty( $data['category'] ) ) {
			$data['category'] = 'Uncategorized';
		}

		$image_url = isset( $row['image_url'] ) ? $this->normalize_text( (string) $row['image_url'] ) : '';
		$data['images'] = ! empty( $image_url ) ? [ $image_url ] : [];

		// Description: construction + technical data (literal \n -> newline)
		$parts = [];
		foreach ( [ 'construction', 'technical_data' ] as $col ) {
			$val = isset( $row[ $col ] ) ? trim( (string) $row[ $col ] ) : '';
			if ( '' !== $val ) {
				$parts[] = str_replace( '\n', "\n", $val );
			}
		}
		$data['description'] = implode( "\n\n", $parts );

		$additional = [];

		$badges = isset( $row['properties_badges'] ) ? trim( (string) $row['properties_badges'] ) : '';
		if ( '' !== $badges ) {
			$additional['badges'] = array_values( array_filter( array_map( 'trim', explode( '|', $badges ) ) ) );
		}

		foreach ( [ 'construction', 'technical_data', 'properties' ] as $col ) {
			$val = isset( $row[ $col ] ) ? trim( (string) $row[ $col ] ) : '';
			if ( '' !== $val ) {
				$additional[ $col ] = str_replace( '\n', "\n", $val );
			}
		}

		$series = [];
		foreach ( [ 'series_id' => 'series_id', 'series_name' => 'name', 'series_group' => 'group', 'series_url' => 'url' ] as $col => $key ) {
			$val = isset( $row[ $col ] ) ? trim( (string) $row[ $col ] ) : '';
			if ( '' !== $val ) {
				$series[ $key ] = $val;
			}
		}
		if ( ! empty( $series ) ) {
			$additional['series'] = $series;
		}

		$specs = $this->extract_technical_specs( $row );
		$specs = array_filter( $specs, static function ( $v ) {
			return strtolower( trim( $v ) ) !== 'null';
		} );
		if ( ! empty( $specs ) ) {
			$additional['specs'] = $specs;
		}

		if ( ! empty( $additional ) ) {
			$data['additional_data'] = $additional;
		}

		return $data;
	}

	/**
	 * Look up a series category by series_id from the DB (created by structure import).
	 *
	 * @return array{path: string[]}|null
	 */
	private function get_series_category_info( string $series_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'aoe_catalog_categories';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, name, parent_id FROM $table WHERE type = 'series' AND metadata_json LIKE %s LIMIT 1",
			'%"series_id":"' . $wpdb->esc_like( $series_id ) . '"%'
		) );
		if ( empty( $rows ) ) {
			return null;
		}
		return $this->build_path( (int) $rows[0]->id, $table );
	}

	/**
	 * Look up a category (level 1 or 2) by its original Medi Kabel id stored in metadata.
	 */
	private function get_category_info_by_medikabel_id( string $medikabel_id, int $level ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'aoe_catalog_categories';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id FROM $table WHERE type = 'category' AND level = %d AND metadata_json LIKE %s LIMIT 1",
			$level,
			'%"medikabel_id":"' . $wpdb->esc_like( $medikabel_id ) . '"%'
		) );
		if ( empty( $rows ) ) {
			return null;
		}
		return $this->build_path( (int) $rows[0]->id, $table );
	}

	private function build_path( int $cat_id, string $table ): array {
		global $wpdb;
		$path    = [];
		$current = $cat_id;
		while ( $current ) {
			$cat = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, name, parent_id FROM $table WHERE id = %d", $current
			) );
			if ( ! $cat ) {
				break;
			}
			array_unshift( $path, $cat->name );
			$current = (int) $cat->parent_id;
		}
		return [ 'path' => $path ];
	}
}
