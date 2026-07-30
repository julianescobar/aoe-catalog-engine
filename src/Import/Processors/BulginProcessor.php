<?php

namespace AOE\CatalogEngine\Import\Processors;

class BulginProcessor extends BaseProcessor {

	public static function get_manufacturer_slug(): string {
		return 'bulgin';
	}

	public function get_page_threshold(): int {
		return 0;
	}

	public function get_supported_columns(): array {
		return [
			'sku', 'product_display_title', 'product_family', 'product_series',
			'short_description', 'description', 'product_datasheet', 'image',
		];
	}

	protected function extract_technical_specs( array $row ): array {
		$specs    = [];
		$capture  = false;
		$skip_map = array_flip( [
			'sku', 'category', 'name', 'product_display_title',
			'product_display_subtitle', 'product_family', 'product_series',
			'image_label', 'small_image_label', 'cad_links', 'created_at',
			'grouped_cad', 'updated_at', 'certificate_links',
			'product_datasheet', 'pdf_links', 'pdfbuilder_enabled',
			'change_notes', 'downloads', 'country_of_manufacture',
			'short_description', 'description', 'max_insertion_loss',
			'avg_insertion_loss', 'product_technical_image', 'is_vitalis',
			'custom_layout_update_file', 'custom_layout', 'angle',
			'design', 'package_id', 'poa', 'poa_text', 'image',
			'additional_images', 'meta_title', 'meta_keyword',
			'meta_description',
		] );

		foreach ( $row as $key => $value ) {
			$clean_key = ltrim( trim( $key ), "\xEF\xBB\xBF" );
			if ( '' === $clean_key ) {
				continue;
			}

			if ( 'actuator_colour' === strtolower( $clean_key ) ) {
				$capture = true;
			}

			if ( $capture ) {
				$lower = strtolower( $clean_key );
				if ( 'waterproof_housing' === $lower ) {
					if ( '' !== trim( $value ) ) {
						$specs[ $clean_key ] = $this->normalize_text( (string) $value );
					}
					break;
				}
				if ( isset( $skip_map[ $lower ] ) ) {
					continue;
				}
				if ( '' !== trim( $value ) ) {
					$specs[ $clean_key ] = $this->normalize_text( (string) $value );
				}
			}
		}

		return $specs;
	}

	public function process_row( array $row ): array {
		$data = $this->get_default_structure();

		$row = array_combine(
			array_map( function ( $key ) { return ltrim( $key, "\xEF\xBB\xBF" ); }, array_keys( $row ) ),
			$row
		);

		$data['sku']  = isset( $row['sku'] ) ? $this->normalize_text( (string) $row['sku'] ) : '';
		$data['name'] = isset( $row['name'] ) ? $this->normalize_text( (string) $row['name'] ) : $data['sku'];

		$path = $this->extract_category_path( $row );
		$data['category_path'] = $path;
		$data['category'] = ! empty( $path ) ? end( $path ) : 'Uncategorized';

		$image_url = isset( $row['image'] ) ? $this->normalize_text( (string) $row['image'] ) : '';
		$data['images'] = ! empty( $image_url ) ? [ $image_url ] : [];

		$datasheet = isset( $row['product_datasheet'] ) ? $this->normalize_text( (string) $row['product_datasheet'] ) : '';
		$data['pdf'] = [
			'datasheet' => $datasheet,
		];

		$data['description'] = isset( $row['short_description'] ) ? $this->normalize_text( (string) $row['short_description'] ) : '';
		if ( empty( $data['description'] ) && isset( $row['description'] ) ) {
			$data['description'] = $this->normalize_text( (string) $row['description'] );
		}

		$specs = $this->extract_technical_specs( $row );
		if ( ! empty( $specs ) ) {
			$data['additional_data']['specs'] = $specs;
		}

		return $data;
	}

	private function extract_category_path( array $row ): array {
		$path = [];

		if ( isset( $row['product_family'] ) && '' !== trim( $row['product_family'] ) ) {
			$path[] = $this->normalize_text( (string) $row['product_family'] );
		}

		if ( isset( $row['product_series'] ) && '' !== trim( $row['product_series'] ) ) {
			$path[] = $this->normalize_text( (string) $row['product_series'] );
		}

		return $path;
	}
}
