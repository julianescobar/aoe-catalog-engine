<?php

namespace AOE\CatalogEngine\Import\Processors;

class AmphenolLtwProcessor extends BaseProcessor {

	public static function get_manufacturer_slug(): string {
		return 'amphenol-ltw';
	}

	public function has_separate_categories(): bool {
		return true;
	}

	public function get_page_threshold(): int {
		return 1;
	}

	public function get_supported_columns(): array {
		return [
			'part_number',
			'name',
			'category_l1',
			'category_l2',
			'url',
			'image_url',
			'pdf_url',
			'cad_url',
			'air_permeability',
			'assembly_style',
			'backshell',
			'cable_o_d',
			'coding',
			'connector_gender',
			'connector_type',
			'contact_gender',
			'fasten_style',
			'ip_rating',
			'jacket_material',
			'mating_style',
			'net_weight_g',
			'no_of_contacts',
			'nominal_current',
			'operating_temperature',
			'operating_voltage',
			'panel_thickness',
			'receptacle_nut_thread',
			'salt_spray',
			'shielded',
			'transmission_speed',
			'ul_certificate',
			'wire_awg',
			'wire_cable_length',
		];
	}

	/**
	 * Spec column -> human readable label (excluding fields used elsewhere).
	 */
	private function get_spec_label_map(): array {
		return [
			'air_permeability'         => 'Air Permeability',
			'assembly_style'           => 'Assembly Style',
			'backshell'                => 'Backshell',
			'cable_o_d'                => 'Cable O.D.',
			'coding'                   => 'Coding',
			'connector_gender'         => 'Connector Gender',
			'connector_type'           => 'Connector Type',
			'contact_gender'           => 'Contact Gender',
			'fasten_style'             => 'Fasten Style',
			'ip_rating'                => 'IP Rating',
			'jacket_material'          => 'Jacket Material',
			'mating_style'             => 'Mating Style',
			'net_weight_g'             => 'Net Weight (g)',
			'no_of_contacts'           => 'No. of Contacts',
			'nominal_current'          => 'Nominal Current',
			'operating_temperature'    => 'Operating Temperature',
			'operating_voltage'        => 'Operating Voltage',
			'panel_thickness'          => 'Panel Thickness',
			'receptacle_nut_thread'    => 'Receptacle Nut Thread',
			'salt_spray'               => 'Salt Spray',
			'shielded'                 => 'Shielded',
			'transmission_speed'       => 'Transmission Speed',
			'ul_certificate'           => 'UL Certificate',
			'wire_awg'                 => 'Wire AWG',
			'wire_cable_length'        => 'Wire/Cable Length',
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

		$data['sku'] = $sku;

		$name = isset( $row['name'] ) ? $this->normalize_text( (string) $row['name'] ) : '';
		$data['name'] = '' !== $name ? $name : $sku;

		// Resolve category path from DB structure by ltw_id (works on incremental
		// imports; on --mode=replace categories are rebuilt by import-ltw-structure.php)
		$l1 = isset( $row['category_l1'] ) ? trim( (string) $row['category_l1'] ) : '';
		$l2 = isset( $row['category_l2'] ) ? trim( (string) $row['category_l2'] ) : '';

		$cat_info = null;
		if ( '' !== $l2 ) {
			$cat_info = $this->get_category_path_by_ltw_id( $l2 );
		}
		if ( null === $cat_info && '' !== $l1 ) {
			$cat_info = $this->get_category_path_by_ltw_id( $l1 );
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

		// PDFs (datasheet + 3D CAD, like Anytek)
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
		$product_url = isset( $row['url'] ) ? trim( (string) $row['url'] ) : '';
		if ( '' !== $product_url ) {
			$additional['product_url'] = $this->normalize_text( $product_url );
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

	private function get_category_path_by_ltw_id( string $ltw_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'aoe_catalog_categories';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id FROM $table WHERE type = 'category' AND metadata_json LIKE %s LIMIT 1",
			'%"ltw_id":"' . $wpdb->esc_like( $ltw_id ) . '"%'
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
