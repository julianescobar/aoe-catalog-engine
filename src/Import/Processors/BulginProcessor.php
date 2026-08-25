<?php

namespace AOE\CatalogEngine\Import\Processors;

class BulginProcessor extends BaseProcessor {

	public static function get_manufacturer_slug(): string {
		return 'bulgin';
	}

	public function get_page_threshold(): int {
		return 0;
	}

	public function has_product_descriptions(): bool {
		return true;
	}

	public function get_supported_columns(): array {
		return [
			'sku', 'name', 'url', 'image_url', 'pdf_url', 'short_description', 'series_slug',
		];
	}

	protected function get_technical_spec_columns(): array {
		return []; // Override extract_technical_specs instead
	}

	protected function extract_technical_specs( array $row ): array {
		$skip = array_flip( [
			'sku', 'name', 'url', 'entity_id', 'category_ids',
			'image_url', 'pdf_url', 'cad_url', 'cad_viewer_url',
			'short_description', 'series_slug',
		] );
		$specs = [];
		foreach ( $row as $key => $value ) {
			$clean_key = ltrim( trim( $key ), "\xEF\xBB\xBF" );
			if ( '' === $clean_key ) {
				continue;
			}
			if ( isset( $skip[ strtolower( $clean_key ) ] ) ) {
				continue;
			}
			if ( '' !== trim( $value ) ) {
				$specs[ $clean_key ] = $this->normalize_text( (string) $value );
			}
		}
		return $specs;
	}

	public function process_row( array $row ): array {
		$data = $this->get_default_structure();

		// Strip BOM from all keys
		$row = array_combine(
			array_map( function ( $key ) { return ltrim( $key, "\xEF\xBB\xBF" ); }, array_keys( $row ) ),
			$row
		);

		$data['sku']  = isset( $row['sku'] ) ? $this->normalize_text( (string) $row['sku'] ) : '';
		$data['name'] = isset( $row['name'] ) ? $this->normalize_text( (string) $row['name'] ) : $data['sku'];

		// series_slug is the final curated slug (bulgin-cat-map.json). find_or_create
		// turns it into the same slug, so the categories import can re-attribute by slug.
		$slug = isset( $row['series_slug'] ) ? trim( (string) $row['series_slug'] ) : '';
		if ( '' !== $slug ) {
			$data['category'] = $slug;
		}

		$image_url = isset( $row['image_url'] ) ? trim( (string) $row['image_url'] ) : '';
		$data['images'] = ! empty( $image_url ) ? [ $this->strip_image_cache( $image_url ) ] : [];

		$datasheet = isset( $row['pdf_url'] ) ? $this->normalize_text( (string) $row['pdf_url'] ) : '';
		$data['pdf'] = [
			'datasheet' => $datasheet,
		];

		$data['description'] = isset( $row['short_description'] ) ? $this->normalize_text( (string) $row['short_description'] ) : '';

		$product_url = isset( $row['url'] ) ? $this->normalize_text( (string) $row['url'] ) : '';
		if ( '' !== $product_url ) {
			$data['additional_data']['url'] = $product_url;
		}

		$specs = $this->extract_technical_specs( $row );
		if ( ! empty( $specs ) ) {
			$data['additional_data']['specs'] = $specs;
		}

		return $data;
	}

	private function strip_image_cache( string $url ): string {
		return preg_replace( '#/media/catalog/product/cache/[0-9a-f]+/#i', '/media/catalog/product/', $url ) ?? $url;
	}
}
