<?php

namespace AOE\CatalogEngine\Import\Processors;

class MHConnectorsProcessor extends BaseProcessor {

	public static function get_manufacturer_slug(): string {
		return 'mhconnectors';
	}

	public function has_separate_categories(): bool {
		return true;
	}

	public function get_page_threshold(): int {
		return 1;
	}

	public function get_supported_columns(): array {
		return [
			'series_id',
			'part_number',
			'part_image_url',
			'datasheet_url',
			'attrs',
		];
	}

	public function process_row( array $row ): array {
		$data = $this->get_default_structure();

		// Handle BOM in first column.
		$row = array_combine(
			array_map( static function ( $key ) { return ltrim( $key, "\xEF\xBB\xBF" ); }, array_keys( $row ) ),
			$row
		);

		$sku = isset( $row['part_number'] ) ? $this->normalize_text( (string) $row['part_number'] ) : '';
		if ( '' === $sku ) {
			return $data;
		}

		$data['sku'] = $sku;

		// Name = part_number (e.g. "MHDC09P").
		$data['name'] = $sku;
		$data['description'] = '';

		// Category will be set by the structure importer (series → subcategory → category).
		// On initial import, products go to Uncategorized.
		$data['category'] = 'Uncategorized';

		// Image: single remote URL from mhconnectors.com.
		$image_url = isset( $row['part_image_url'] ) ? trim( (string) $row['part_image_url'] ) : '';
		$data['images'] = ( '' !== $image_url ) ? [ $image_url ] : [];

		// Documents: single datasheet PDF.
		$pdfs = [];
		$datasheet = isset( $row['datasheet_url'] ) ? trim( (string) $row['datasheet_url'] ) : '';
		if ( '' !== $datasheet ) {
			$pdfs['datasheet'][] = [ 'url' => $datasheet, 'name' => 'Datasheet' ];
		}
		$data['pdf'] = $pdfs;

		// Specs: will be populated by the structure importer from series data.
		// Products have mostly empty attrs in the CSV.
		$specs = $this->extract_attr_specs( $row );
		$additional = [];
		if ( ! empty( $specs ) ) {
			$additional['specs'] = $specs;
		}
		$series_id = isset( $row['series_id'] ) ? trim( (string) $row['series_id'] ) : '';
		if ( '' !== $series_id ) {
			$additional['series_id'] = $series_id;
		}
		if ( ! empty( $additional ) ) {
			$data['additional_data'] = $additional;
		}

		return $data;
	}

	private function extract_attr_specs( array $row ): array {
		$raw = isset( $row['attrs'] ) ? trim( (string) $row['attrs'] ) : '';
		if ( '' === $raw || '[]' === $raw ) {
			return [];
		}

		$attrs = json_decode( $raw, true );
		if ( ! is_array( $attrs ) ) {
			return [];
		}

		$specs = [];
		foreach ( $attrs as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$label = trim( (string) ( $item['label'] ?? '' ) );
			$value = trim( (string) ( $item['value'] ?? '' ) );
			if ( '' === $label || '' === $value ) {
				continue;
			}
			$specs[ $label ] = $this->normalize_text( $value );
		}

		return $specs;
	}
}
