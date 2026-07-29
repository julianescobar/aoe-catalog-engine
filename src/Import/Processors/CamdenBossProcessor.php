<?php

namespace AOE\CatalogEngine\Import\Processors;

class CamdenBossProcessor extends BaseProcessor {

	public static function get_manufacturer_slug(): string {
		return 'camdenboss';
	}

	public function get_page_threshold(): int {
		return 0;
	}

	protected function get_technical_spec_columns(): array {
		return [
			'External length (mm)',
			'External width (mm)',
			'External height (mm)',
			'Internal length (mm)',
			'Internal width (mm)',
			'Internal height (mm)',
			'Wall thickness (mm)',
			'U Height',
			'Weight (g)',
			'Diameter (mm)',
			'Base Material',
			'Material Type',
			'Colour',
			'IK Rating',
			'UL Rating',
			'IP Rating',
			'EMC Shielded',
			'Glow Wire Test',
			'General Current Rating (A)',
			'UL Current Rating (A)',
			'UL Voltage Rating (V)',
			'VDE Current Rating (A)',
			'VDE Voltage Rating (V)',
			'Nominal Voltage (V)',
			'Input Voltage (V)',
			'Output Voltage (V)',
			'LED Voltage (V)',
			'LED Colours',
			'Wattage (W)',
			'Capacity (Ah)',
			'Primary Winding',
			'Connection',
			'Clamping Method',
			'Sub D Type',
			'USB Type',
			'RJ45 Type',
			'Cable Entry',
			'Cable Size (AWG)',
			'Cable Size Solid Wire (mm2)',
			'Cable Size Stranded Wire (mm2)',
			'Terminal Type',
			'Contacts',
			'No. of Poles',
			'No. of Decks',
			'Max Wire (mm)',
			'Pitch (mm)',
			'Mounting Type',
			'DIN Rail Mounting',
			'Wall Mount',
			'Panel Fitting',
			'Pole mount',
			'Actuator',
			'Actuator Height over PCB (mm)',
			'Operation',
			'Foot Switch Type',
			'Illuminated',
			'No. of Buttons',
			'Orientation',
			'Blow Speed',
			'Operating Temperature',
			'Application',
			'Country of origin',
			'Dominant Wavelength (nm)',
			'Luminous Intensity (mcd)',
			'Kinked Pin',
			'Fuse Size (mm)',
			'Special Feature',
		];
	}

	public function get_supported_columns(): array {
		return [ 'Code', 'Name', 'Level 1', 'Level 2', 'Level 3', 'Image Name', 'Datasheets', 'Web Description' ];
	}

	public function process_row( array $row ): array {
		$data = $this->get_default_structure();

		$data['sku']  = isset( $row['Code'] ) ? $this->normalize_text( (string) $row['Code'] ) : '';
		$data['name'] = isset( $row['Name'] ) ? $this->normalize_text( (string) $row['Name'] ) : '';

		$path = $this->extract_category_path( $row );
		$data['category_path'] = $path;
		$data['category'] = ! empty( $path ) ? end( $path ) : 'Uncategorized';

		$image_name = isset( $row['Image Name'] ) ? $this->normalize_text( (string) $row['Image Name'] ) : '';
		$data['images'] = ! empty( $image_name ) ? [ $image_name ] : [];

		$datasheet = isset( $row['Datasheets'] ) ? $this->normalize_text( (string) $row['Datasheets'] ) : '';
		if ( ! empty( $datasheet ) && ! str_ends_with( $datasheet, '.pdf' ) ) {
			$datasheet .= '.pdf';
		}
		$data['pdf'] = [
			'datasheet' => $datasheet,
		];

		$data['description'] = isset( $row['Web Description'] ) ? $this->normalize_text( (string) $row['Web Description'] ) : '';

		$specs = $this->extract_technical_specs( $row );
		if ( ! empty( $specs ) ) {
			$data['additional_data']['specs'] = $specs;
		}

		return $data;
	}

	private function extract_category_path( array $row ): array {
		$path = [];
		$keys = [ 'Level 1', 'Level 2', 'Level 3' ];
		foreach ( $keys as $key ) {
			if ( isset( $row[ $key ] ) && '' !== trim( $row[ $key ] ) ) {
				$path[] = $this->normalize_text( (string) $row[ $key ] );
			}
		}
		return $path;
	}
}
