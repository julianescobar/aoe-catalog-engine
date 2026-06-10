<?php

namespace AOE\CatalogEngine\Import\Processors;

class CamdenBossProcessor extends BaseProcessor {

	public static function get_manufacturer_slug(): string {
		return 'camdenboss';
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
		if ( ! empty( $datasheet ) ) {
			$datasheet .= '.pdf';
		}
		$data['pdf'] = [
			'datasheet' => $datasheet,
		];

		$data['description'] = isset( $row['Web Description'] ) ? $this->normalize_text( (string) $row['Web Description'] ) : '';

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
