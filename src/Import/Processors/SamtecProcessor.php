<?php

namespace AOE\CatalogEngine\Import\Processors;

class SamtecProcessor extends BaseProcessor {

	public static function get_manufacturer_slug(): string {
		return 'samtec';
	}

	public function has_separate_categories(): bool {
		return true;
	}

	public function get_supported_columns(): array {
		return [ 'Part', 'Description', 'ImageUrl', 'Print', 'Footprint', 'CatalogPage', 'SpecSheet' ];
	}

	public function process_row( array $row ): array {
		$data = $this->get_default_structure();

		$part = isset( $row['Part'] ) ? $this->normalize_text( (string) $row['Part'] ) : '';

		$data['sku']  = $part;
		$data['name'] = isset( $row['Description'] ) ? $this->normalize_text( (string) $row['Description'] ) : '';

		if ( ! empty( $part ) ) {
			$hyphen_pos = strpos( $part, '-' );
			$data['category'] = ( $hyphen_pos !== false )
				? substr( $part, 0, $hyphen_pos )
				: $part;
		}

		$image_url = isset( $row['ImageUrl'] ) ? $this->normalize_text( (string) $row['ImageUrl'] ) : '';
		$data['images'] = ! empty( $image_url ) ? [ $image_url ] : [];

		$data['pdf'] = [
			'print'        => isset( $row['Print'] ) ? $this->normalize_text( (string) $row['Print'] ) : '',
			'footprint'    => isset( $row['Footprint'] ) ? $this->normalize_text( (string) $row['Footprint'] ) : '',
			'catalog_page' => isset( $row['CatalogPage'] ) ? $this->normalize_text( (string) $row['CatalogPage'] ) : '',
			'spec_sheet'   => isset( $row['SpecSheet'] ) ? $this->normalize_text( (string) $row['SpecSheet'] ) : '',
		];

		return $data;
	}
}
