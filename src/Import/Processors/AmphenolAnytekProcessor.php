<?php

namespace AOE\CatalogEngine\Import\Processors;

class AmphenolAnytekProcessor extends BaseProcessor {

	public static function get_manufacturer_slug(): string {
		return 'amphenolanytek';
	}

	public function has_separate_categories(): bool {
		return true;
	}

	public function get_page_threshold(): int {
		return 1;
	}

	public function get_supported_columns(): array {
		return [
			'catalogue_pn',
			'web_name',
			'csv_description',
			'list_cate_id',
			'image_url',
			'pdf_url',
			'cad_url',
			'product_pitch',
			'poles',
			'current_rating',
			'insulation_resistance',
			'operating_temperature',
			'safety_approval',
			'screw',
			'soldering_temperature',
			'solid_wire',
			'stranded_wire',
			'tin_dipped_stranded_wire',
			'torque',
			'voltage_rating',
			'wire_range',
			'wire_strip_length',
			'withstanding_voltage',
		];
	}

	/**
	 * Spec column -> human readable label (excluding fields used elsewhere).
	 */
	private function get_spec_label_map(): array {
		return [
			'product_pitch'              => 'Pitch',
			'poles'                      => 'Poles',
			'current_rating'             => 'Current Rating',
			'insulation_resistance'      => 'Insulation Resistance',
			'operating_temperature'      => 'Operating Temperature',
			'screw'                      => 'Screw',
			'soldering_temperature'      => 'Soldering Temperature',
			'solid_wire'                 => 'Solid Wire',
			'stranded_wire'              => 'Stranded Wire',
			'tin_dipped_stranded_wire'   => 'Tin-Dipped Stranded Wire',
			'torque'                     => 'Torque',
			'voltage_rating'             => 'Voltage Rating',
			'wire_range'                 => 'Wire Range',
			'wire_strip_length'          => 'Wire Strip Length',
			'withstanding_voltage'       => 'Withstanding Voltage',
		];
	}

	public function process_row( array $row ): array {
		$data = $this->get_default_structure();

		$row = array_combine(
			array_map( static function ( $key ) { return ltrim( $key, "\xEF\xBB\xBF" ); }, array_keys( $row ) ),
			$row
		);

		$sku = isset( $row['catalogue_pn'] ) ? $this->normalize_text( (string) $row['catalogue_pn'] ) : '';
		if ( '' === $sku ) {
			return $data;
		}

		$data['sku'] = $sku;

		$web_name = isset( $row['web_name'] ) ? $this->normalize_text( (string) $row['web_name'] ) : '';
		$data['name'] = '' !== $web_name ? $web_name : $sku;

		// Description with literal \n converted to real newlines
		$desc = isset( $row['csv_description'] ) ? (string) $row['csv_description'] : '';
		$desc = str_replace( '\n', "\n", $desc );
		$data['description'] = '' !== trim( $desc ) ? $this->normalize_text( $desc ) : '';

		// Resolve category path from DB structure by anytek_id (works on incremental
		// imports; on --mode=replace categories are rebuilt by import-anytek-structure.php)
		$cat_id = isset( $row['list_cate_id'] ) ? trim( (string) $row['list_cate_id'] ) : '';
		$cat_info = null;
		if ( '' !== $cat_id ) {
			$cat_info = $this->get_category_path_by_anytek_id( $cat_id );
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

		// PDFs (datasheet + 3D CAD archive, like Yokowo)
		$data['pdf'] = $this->parse_pdfs( $row );

		// Specs
		$specs = [];
		foreach ( $this->get_spec_label_map() as $col => $label ) {
			$value = isset( $row[ $col ] ) ? trim( (string) $row[ $col ] ) : '';
			if ( '' === $value || 'null' === strtolower( $value ) ) {
				continue;
			}
			$specs[ $label ] = $this->normalize_text( $value );
		}
		$additional = [];
		if ( ! empty( $specs ) ) {
			$additional['specs'] = $specs;
		}
		if ( ! empty( $additional ) ) {
			$data['additional_data'] = $additional;
		}

		return $data;
	}

	private function parse_pdfs( array $row ): array {
		$pdfs = [];
		$map  = [
			'pdf_url' => 'Datasheet',
			'cad_url' => '3D CAD',
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

	private function get_category_path_by_anytek_id( string $anytek_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'aoe_catalog_categories';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id FROM $table WHERE type = 'category' AND metadata_json LIKE %s LIMIT 1",
			'%"anytek_id":"' . $wpdb->esc_like( $anytek_id ) . '"%'
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
