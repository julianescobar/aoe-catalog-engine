<?php

namespace AOE\CatalogEngine\Import\Processors;

/**
 * Amphenol PCD: CSV with 3-level category hierarchy (L1|L2|L3 by name),
 * remote images/PDFs from amphenolpcd.com.cn, specs from attrs JSON.
 */
class AmphenolPcdProcessor extends BaseProcessor {

	public static function get_manufacturer_slug(): string {
		return 'amphenol-pcd';
	}

	public function has_separate_categories(): bool {
		return true;
	}

	public function has_product_descriptions(): bool {
		return true;
	}

	public function get_page_threshold(): int {
		return 1;
	}

	public function get_supported_columns(): array {
		return [
			'part_number',
			'name',
			'description',
			'category_l1',
			'category_l2',
			'category_l3',
			'url',
			'image_url',
			'pdf_url',
			'pdf_extra',
			'attrs',
		];
	}

	/**
	 * attrs labels that are commercial/logistics noise and must never become
	 * technical specs.
	 */
	private function get_ignored_attr_labels(): array {
		return [
			'price',
			'min. order quantity',
			'packing unit',
			'moq',
			'lead time',
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

		$desc = isset( $row['description'] ) ? $this->normalize_text( (string) $row['description'] ) : '';
		// Convert literal "\n"/"\r\n" escape sequences to real newlines so the
		// render shows proper line breaks instead of visible "\n" text.
		$desc = str_replace( [ '\r\n', '\n' ], "\n", $desc );
		$data['description'] = $desc;

		// Category resolution is handled by import-pcd-structure.php (pasada 2),
		// which rebuilds the L1|L2|L3 hierarchy and re-attributes products by SKU.
		// On --mode=replace full-import clears categories, so products land in
		// "Uncategorized" here until the structure script runs.
		$data['category'] = 'Uncategorized';

		// Image: remote amphenolpcd.com.cn URL.
		$image_url = isset( $row['image_url'] ) ? $this->normalize_text( (string) $row['image_url'] ) : '';
		$data['images'] = ! empty( $image_url ) ? [ $image_url ] : [];

		// Documents: datasheet + extra downloads.
		$data['pdf'] = $this->parse_documents( $row );

		// Specs from attrs JSON.
		$specs = $this->extract_attr_specs( $row );
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

	private function parse_documents( array $row ): array {
		$pdfs = [];

		$pdf_url = isset( $row['pdf_url'] ) ? trim( (string) $row['pdf_url'] ) : '';
		if ( '' !== $pdf_url ) {
			$pdfs['datasheet'][] = [ 'url' => $pdf_url, 'name' => 'Datasheet' ];
		}

		$extra = isset( $row['pdf_extra'] ) ? trim( (string) $row['pdf_extra'] ) : '';
		if ( '' !== $extra ) {
			$urls = preg_split( '/\s*\|\s*/', $extra );
			$urls = array_values( array_filter( array_map( 'trim', $urls ), static function ( $u ) { return '' !== $u; } ) );
			foreach ( $urls as $i => $u ) {
				$pdfs['datasheet'][] = [ 'url' => $u, 'name' => 'Documento ' . ( $i + 1 ) ];
			}
		}

		return $pdfs;
	}

	private function extract_attr_specs( array $row ): array {
		$raw = isset( $row['attrs'] ) ? trim( (string) $row['attrs'] ) : '';
		if ( '' === $raw ) {
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
			$label_lower = strtolower( $label );
			foreach ( $this->get_ignored_attr_labels() as $ignore ) {
				if ( str_contains( $label_lower, $ignore ) ) {
					continue 2;
				}
			}
			$specs[ $label ] = $this->normalize_text( $value );
		}

		return $specs;
	}
}
