<?php

namespace AOE\CatalogEngine\Import\Processors;

class BivarProcessor extends BaseProcessor {

	public static function get_manufacturer_slug(): string {
		return 'bivar';
	}

	public function has_separate_categories(): bool {
		return true;
	}

	public function has_product_descriptions(): bool {
		return true;
	}

	public function get_supported_columns(): array {
		return [
			'Part Number', 'Category', 'Subcategory', 'Grouping', 'Series',
			'Description', 'Link', 'Image', 'ImageURL',
			'DataSheet', 'DataSheetURL', 'Drawing3D', 'Drawing3DURL',
			'Packagingtype', 'MOQ', 'OrderMultiple', 'Tariff',
			'COO', 'ECCN', 'InternationalHarmonize', 'LeadFree', 'ROHS', 'StaticSensitive',
			'LeadTime', 'MSL', 'Registerable',
		];
	}

	protected function get_technical_spec_columns(): array {
		return [
			'TapeMaterial', 'TapeWidth', 'Housing Material', 'Material',
			'Termination', 'Voltage', 'Voltage Rating', 'Operating Voltage',
			'LED Size', 'Lens Size', 'Lens Profile', 'Panel Cutout',
			'Adapter LED Color/Wavelength', 'Color/Wavelength', 'Lens Color',
			'Luminous Intensity', 'Mounting Type', 'Configuration',
			'Length', 'Height', 'Width', 'Slot Width', 'PCB Width',
			'Body Height in.(mm)', 'Body Length in.(mm)', 'Package/Case Size',
			'Current', 'Viewing Angle', 'Leads',
			'Retention', 'Centerline', 'IP Rated Seal Color',
			'Optical Fiber Diameter', 'Optical Fiber Length', 'Optical Fiber Color',
			'Screw Size', 'Outer Diameter', 'Inner Diameter', 'Shape',
			'Component', 'Thickness', 'Lever', 'Mounting Hole', 'Offset',
			'Wire Length', 'Color', 'Panel Thickness', 'Panel Mounting Type',
			'Board Mounting Type', 'IP Rating', 'Light Pipe Material',
			'Adapter Material', 'Adapter', 'Adapter Diameter', 'Light Pipe Diameter',
			'Bezel Material', 'Mounting Tab Material', 'Mounting Tabs',
			'Light Pipe Series', 'Pitch-Row', 'Pitch-Column', 'LED',
			'Operating Temperature', 'Storage Temperature', 'Power Dissipation',
		];
	}

	public function process_row( array $row ): array {
		$data = $this->get_default_structure();

		$part_number = isset( $row['Part Number'] ) ? $this->normalize_text( (string) $row['Part Number'] ) : '';
		if ( empty( $part_number ) ) {
			return $data;
		}

		$data['sku']  = $part_number;
		$name_val = isset( $row['Description'] ) ? $this->normalize_not_null( $row['Description'] ) : '';
		$data['name'] = ! empty( $name_val ) ? $name_val : $part_number;

		$path = $this->extract_category_path( $row );
		$data['category_path'] = $path;
		$data['category'] = ! empty( $path ) ? end( $path ) : 'Uncategorized';

		$data['description'] = $data['name'];

		$image = isset( $row['ImageURL'] ) ? trim( (string) $row['ImageURL'] ) : '';
		if ( empty( $image ) ) {
			$image = isset( $row['Image'] ) ? trim( (string) $row['Image'] ) : '';
		}
		$data['images'] = ! empty( $image ) ? [ $image ] : [];

		$datasheet = isset( $row['DataSheetURL'] ) ? trim( (string) $row['DataSheetURL'] ) : ( isset( $row['DataSheet'] ) ? trim( (string) $row['DataSheet'] ) : '' );
		if ( ! empty( $datasheet ) ) {
			$data['pdf'] = [ 'datasheet' => $datasheet ];
		}

		$additional = [];

		if ( ! empty( $row['Link'] ) ) {
			$additional['link'] = trim( (string) $row['Link'] );
		}

		if ( ! empty( $row['Drawing3DURL'] ) ) {
			$additional['drawing_3d'] = trim( (string) $row['Drawing3DURL'] );
		}

		// Packaging
		$packaging = [];
		foreach ( [ 'Packagingtype' => 'type', 'MOQ' => 'moq', 'OrderMultiple' => 'order_multiple' ] as $col => $key ) {
			$val = isset( $row[ $col ] ) ? trim( (string) $row[ $col ] ) : '';
			if ( $val !== '' && strtolower( $val ) !== 'null' ) {
				$packaging[ $key ] = $val;
			}
		}
		if ( ! empty( $packaging ) ) {
			$additional['packaging'] = $packaging;
		}

		// Compliance
		$compliance = [];
		foreach ( [
			'COO' => 'coo', 'ECCN' => 'eccn', 'InternationalHarmonize' => 'intl_harmonize',
			'LeadFree' => 'lead_free', 'ROHS' => 'rohs', 'StaticSensitive' => 'static_sensitive',
		] as $col => $key ) {
			$val = isset( $row[ $col ] ) ? trim( (string) $row[ $col ] ) : '';
			if ( $val !== '' && strtolower( $val ) !== 'null' ) {
				$compliance[ $key ] = $val;
			}
		}
		if ( ! empty( $compliance ) ) {
			$additional['compliance'] = $compliance;
		}

		// Meta fields
		foreach ( [ 'LeadTime' => 'lead_time', 'MSL' => 'msl', 'Registerable' => 'registerable', 'Tariff' => 'tariff' ] as $col => $key ) {
			$val = isset( $row[ $col ] ) ? trim( (string) $row[ $col ] ) : '';
			if ( $val !== '' && strtolower( $val ) !== 'null' ) {
				$additional[ $key ] = $val;
			}
		}

		// Pricing
		$pricing = [];
		foreach ( [ 'One', 'OneHundred', 'FiveHundred', 'OneK', 'FiveK', 'TenK', 'TwentyFiveK' ] as $col ) {
			$val = isset( $row[ $col ] ) ? trim( (string) $row[ $col ] ) : '';
			if ( $val !== '' && strtolower( $val ) !== 'null' ) {
				$pricing[ $col ] = $val;
			}
		}
		if ( ! empty( $pricing ) ) {
			$additional['pricing'] = $pricing;
		}

		// Technical specs (filter out literal "null" values)
		$specs = $this->extract_technical_specs( $row );
		$specs = array_filter( $specs, function ( $v ) {
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

	private function normalize_not_null( $value ): string {
		$val = $this->normalize_text( (string) $value );
		return ( $val !== '' && strtolower( $val ) !== 'null' ) ? $val : '';
	}

	private function extract_category_path( array $row ): array {
		$path = [];

		$category = isset( $row['Category'] ) ? $this->normalize_not_null( $row['Category'] ) : '';
		if ( empty( $category ) ) {
			return $path;
		}
		$path[] = $category;

		$subcategory = isset( $row['Subcategory'] ) ? $this->normalize_not_null( $row['Subcategory'] ) : '';
		if ( ! empty( $subcategory ) ) {
			$path[] = $subcategory;
		}

		// Level 3: Grouping takes precedence, fallback to Series
		$grouping = isset( $row['Grouping'] ) ? $this->normalize_not_null( $row['Grouping'] ) : '';
		$series   = isset( $row['Series'] ) ? $this->normalize_not_null( $row['Series'] ) : '';
		if ( ! empty( $grouping ) ) {
			$path[] = $grouping;
		} elseif ( ! empty( $series ) ) {
			$path[] = $series;
		}

		return $path;
	}
}
