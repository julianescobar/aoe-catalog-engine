<?php

namespace AOE\CatalogEngine\Import\Processors;

class PanduitProcessor extends BaseProcessor {

	public static function get_manufacturer_slug(): string {
		return 'panduit';
	}

	public function get_page_threshold(): int {
		return 999999;
	}

	public function has_product_descriptions(): bool {
		return true;
	}

	public function get_supported_columns(): array {
		return [
			'sku', 'name', 'breadcrumb', 'description', 'image_url', 'documents',
		];
	}

	protected function get_known_field_columns(): array {
		return [
			'url', 'product_id', 'breadcrumb', 'sku', 'name', 'subtitle',
			'description', 'image_url', 'documents',
		];
	}

	protected function extract_technical_specs( array $row ): array {
		$specs     = [];
		$skip_map  = array_flip( $this->get_known_field_columns() );

		foreach ( $row as $key => $value ) {
			$clean_key = ltrim( trim( $key ), "\xEF\xBB\xBF" );
			if ( '' === $clean_key ) {
				continue;
			}
			$lower = strtolower( $clean_key );
			if ( isset( $skip_map[ $lower ] ) ) {
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

		$row = array_combine(
			array_map( function ( $key ) { return ltrim( $key, "\xEF\xBB\xBF" ); }, array_keys( $row ) ),
			$row
		);

		$data['sku']  = isset( $row['sku'] ) ? $this->normalize_text( (string) $row['sku'] ) : '';
		$data['name'] = isset( $row['name'] ) ? $this->normalize_text( (string) $row['name'] ) : $data['sku'];

		$path = $this->extract_category_path( $row );
		$data['category_path'] = $path;
		$data['category'] = ! empty( $path ) ? end( $path ) : 'Uncategorized';

		$image_url = isset( $row['image_url'] ) ? $this->normalize_text( (string) $row['image_url'] ) : '';
		$data['images'] = ! empty( $image_url ) ? [ $image_url ] : [];

		$data['pdf'] = $this->parse_documents( $row );

		$data['description'] = isset( $row['description'] ) ? $this->normalize_text( (string) $row['description'] ) : '';

		$subtitle = isset( $row['subtitle'] ) ? $this->normalize_text( (string) $row['subtitle'] ) : '';
		if ( '' !== $subtitle ) {
			$data['additional_data']['subtitle'] = $subtitle;
		}

		$specs = $this->extract_technical_specs( $row );
		if ( ! empty( $specs ) ) {
			$data['additional_data']['specs'] = $specs;
		}

		return $data;
	}

	private function extract_category_path( array $row ): array {
		if ( ! isset( $row['breadcrumb'] ) || '' === trim( $row['breadcrumb'] ) ) {
			return [];
		}

		$parts = explode( ' > ', $this->normalize_text( (string) $row['breadcrumb'] ) );
		return array_map( 'trim', array_filter( $parts, function( $p ) { return '' !== trim( $p ); } ) );
	}

	private function parse_documents( array $row ): array {
		$pdfs = [];

		if ( ! isset( $row['documents'] ) || '' === trim( $row['documents'] ) ) {
			return $pdfs;
		}

		$entries = explode( '||', (string) $row['documents'] );
		$allowed_exts = [ 'pdf', 'stp', 'step', 'dwg', 'dxf' ];

		foreach ( $entries as $entry ) {
			$parts = explode( '|', trim( $entry ) );
			if ( count( $parts ) < 5 ) {
				continue;
			}

			$url = trim( $parts[4] ?? '' );
			if ( '' === $url ) {
				continue;
			}

			// Filter by extension: only PDF and 3D CAD (.stp, .step).
			$ext = strtolower( pathinfo( $url, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, $allowed_exts, true ) ) {
				continue;
			}

			$type       = trim( $parts[0] ?? '' );
			$name       = trim( $parts[1] ?? '' );
			$label      = 'document';
			$type_lower = strtolower( $type );

			if ( in_array( $ext, [ 'dwg', 'dxf', 'stp', 'step' ], true ) ) {
				$label = 'drawing';
			} elseif ( str_contains( $type_lower, 'specification' ) || str_contains( $type_lower, 'datasheet' ) ) {
				$label = 'datasheet';
			} elseif ( str_contains( $type_lower, 'installation' ) ) {
				$label = 'manual';
			} elseif ( str_contains( $type_lower, 'application' ) ) {
				$label = 'application_guide';
			} elseif ( str_contains( $type_lower, 'brochure' ) || str_contains( $type_lower, 'catalog' ) ) {
				$label = 'brochure';
			}

			$display_name = ! empty( $name ) ? $name : $label;
			if ( 'drawing' === $label && ! empty( $name ) ) {
				$display_name = $type . ' ' . $name;
			}

			$pdfs[ $label ][] = [ 'url' => $url, 'name' => $display_name ];
		}

		return $pdfs;
	}
}
