<?php

namespace AOE\CatalogEngine\Import\Processors;

class YokowoProcessor extends BaseProcessor {

	public static function get_manufacturer_slug(): string {
		return 'yokowo';
	}

	public function has_separate_categories(): bool {
		return true;
	}

	public function get_page_threshold(): int {
		return 190;
	}

	public function get_supported_columns(): array {
		return [
			'part_number',
			'category_id',
			'subcategory_id',
			'image_url',
			'drawing_url',
			'pdf_2d_url',
			'pdf_guide_url',
			'cad_3d_url',
			'rohs',
			'note',
		];
	}

	protected function get_technical_spec_columns(): array {
		return [
			'a', 'b', 'boss', 'cap', 'compatible_product', 'contact_resistance',
			'cycle_durability', 'd', 'e', 'f', 'floating_amount', 'g',
			'initial_height_mating_condition_height', 'insertion_force',
			'number_of_pins', 'operation_temp_range', 'packaging', 'packaging_quantity',
			'pitch', 'plug_rece', 'rated_current', 'rated_voltage', 'row',
			'soldering_method', 'spring_force', 'technology', 'working_height', 'working_range',
		];
	}

	public function process_row( array $row ): array {
		$data = $this->get_default_structure();

		$row = array_combine(
			array_map( static function ( $key ) { return ltrim( $key, "\xEF\xBB\xBF" ); }, array_keys( $row ) ),
			$row
		);

		$sku = isset( $row['part_number'] ) ? $this->normalize_text( (string) $row['part_number'] ) : '';
		if ( '' === $sku ) {
			return $data;
		}

		$data['sku']  = $sku;
		$data['name'] = $sku;

		// Resolve category path from DB structure
		$cat_id = isset( $row['category_id'] ) ? trim( (string) $row['category_id'] ) : '';
		$sub_id = isset( $row['subcategory_id'] ) ? trim( (string) $row['subcategory_id'] ) : '';

		$cat_info = null;
		if ( '' !== $sub_id ) {
			$cat_info = $this->get_category_info_by_yokowo_id( $sub_id, 2 );
		}
		if ( null === $cat_info && '' !== $cat_id ) {
			$cat_info = $this->get_category_info_by_yokowo_id( $cat_id, 1 );
		}

		if ( null !== $cat_info && ! empty( $cat_info['path'] ) ) {
			$data['category_path'] = $cat_info['path'];
			$data['category']      = end( $cat_info['path'] );
		}
		if ( empty( $data['category'] ) ) {
			$data['category'] = 'Uncategorized';
		}

		// Image
		$image_url = isset( $row['image_url'] ) ? $this->normalize_text( (string) $row['image_url'] ) : '';
		$data['images'] = ! empty( $image_url ) ? [ $image_url ] : [];

		// PDFs (multiple docs like Panduit)
		$data['pdf'] = $this->parse_pdfs( $row );

		// Specs
		$specs = $this->extract_technical_specs( $row );
		$specs = array_filter( $specs, static function ( $v ) {
			return strtolower( trim( $v ) ) !== 'null';
		} );
		$additional = [];
		if ( ! empty( $specs ) ) {
			$additional['specs'] = $specs;
		}
		$rohs = isset( $row['rohs'] ) ? trim( (string) $row['rohs'] ) : '';
		if ( '' !== $rohs ) {
			$additional['rohs'] = $rohs;
		}
		$note = isset( $row['note'] ) ? trim( (string) $row['note'] ) : '';
		if ( '' !== $note ) {
			$additional['note'] = $note;
		}
		if ( ! empty( $additional ) ) {
			$data['additional_data'] = $additional;
		}

		return $data;
	}

	private function parse_pdfs( array $row ): array {
		$pdfs = [];
		$map  = [
			'pdf_guide_url'  => 'Datasheet',
			'pdf_2d_url'     => 'Drawing 2D',
			'drawing_url'    => 'Drawing',
			'cad_3d_url'     => '3D CAD',
		];
		foreach ( $map as $col => $label ) {
			$url = isset( $row[ $col ] ) ? trim( (string) $row[ $col ] ) : '';
			if ( '' !== $url ) {
				$pdfs[ $label ][] = [
					'url'  => $url,
					'name' => $label,
				];
			}
		}
		return $pdfs;
	}

	private function get_category_info_by_yokowo_id( string $yokowo_id, int $level ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'aoe_catalog_categories';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id FROM $table WHERE type = 'category' AND level = %d AND metadata_json LIKE %s LIMIT 1",
			$level,
			'%"yokowo_id":"' . $wpdb->esc_like( $yokowo_id ) . '"%'
		) );
		if ( empty( $rows ) ) {
			return null;
		}

		$path    = [];
		$current = (int) $rows[0]->id;
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
