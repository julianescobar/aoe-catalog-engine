<?php

namespace AOE\CatalogEngine\Import\Processors;

/**
 * Amphenol Energy: CSV with 2-level category hierarchy (L1|L2 by name,
 * Neptune has no L2), remote images from amphenolenergy.com, specs from attrs JSON.
 */
class AmphenolEnergyProcessor extends BaseProcessor {

	public static function get_manufacturer_slug(): string {
		return 'amphenol-energy';
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
			'url',
			'image_url',
			'pdf_url',
			'attrs',
		];
	}

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
		$desc = str_replace( [ '\r\n', '\n' ], "\n", $desc );
		$data['description'] = $desc;

		$data['category'] = 'Uncategorized';

		$image_url = isset( $row['image_url'] ) ? $this->normalize_text( (string) $row['image_url'] ) : '';
		$data['images'] = ! empty( $image_url ) ? [ $image_url ] : [];

		$pdf_url = isset( $row['pdf_url'] ) ? trim( (string) $row['pdf_url'] ) : '';
		if ( '' !== $pdf_url ) {
			$data['pdf'] = [ 'datasheet' => [ [ 'url' => $pdf_url, 'name' => 'Datasheet' ] ] ];
		}

		$additional = [];

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