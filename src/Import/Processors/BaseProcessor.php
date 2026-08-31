<?php

namespace AOE\CatalogEngine\Import\Processors;

abstract class BaseProcessor implements ProcessorInterface {

	/**
	 * Normalizes a common text string and fixes UTF-8 mojibake
	 */
	protected function normalize_text( string $text ): string {
		$text = trim( wp_strip_all_tags( $text ) );
		return $this->fix_mojibake( $text );
	}

	/**
	 * Revert double/triple UTF-8 mojibake (â„¢ → ™, Â® → ®, Ã± → ñ, etc.)
	 */
	protected function fix_mojibake( string $str ): string {
		$to_w1252 = @mb_convert_encoding( $str, 'Windows-1252', 'UTF-8' );
		if ( $to_w1252 !== false && @mb_check_encoding( $to_w1252, 'UTF-8' ) ) {
			$str = $to_w1252;
		}
		$to_w1252 = @mb_convert_encoding( $str, 'Windows-1252', 'UTF-8' );
		if ( $to_w1252 !== false && @mb_check_encoding( $to_w1252, 'UTF-8' ) ) {
			$str = $to_w1252;
		}
		return $str;
	}

	/**
	 * Default mapping structure that processors should return
	 */
	protected function get_default_structure(): array {
		return [
			'sku'             => '',
			'name'            => '',
			'category'        => '',
			'category_path'   => [],
			'description'     => '',
			'images'          => [],
			'pdf'             => [],
			'additional_data' => []
		];
	}

	/**
	 * Default supported columns list
	 */
	public function get_supported_columns(): array {
		return [ 'SKU', 'Name', 'Category', 'Description' ];
	}

	/**
	 * Known technical specification column names.
	 * Override in subclasses to add manufacturer-specific columns.
	 */
	protected function get_technical_spec_columns(): array {
		return [];
	}

	/**
	 * Return normalized search docs (pdfs + 3dcad) for a product.
	 *
	 * Operators store their PDF/3D CAD in different fields (url_pdf subkeys,
	 * additional_data, dedicated columns...). Each processor knows where its
	 * data lives, so this method centralizes that knowledge for the search
	 * index. Subclasses should override only when they store docs elsewhere
	 * (e.g. Bivar drawing_3d, Bulgin cad_url).
	 *
	 * @param array $stored_pdf       Decoded url_pdf JSON (the 'pdf' field).
	 * @param array $additional_data  Decoded additional_data JSON.
	 * @return array ['pdfs' => [], '3dcad' => []]
	 */
	public function get_search_docs( array $stored_pdf = [], array $additional_data = [] ): array {
		return $this->classify_docs( $stored_pdf );
	}

	/**
	 * Classify a raw docs structure into ['pdfs' => [...], '3dcad' => [...]].
	 * CAD is detected by file extension OR strong keywords in the label.
	 */
	protected function classify_docs( array $raw_docs ): array {
		$CAD_EXT = [ 'dxf', 'dwg', 'stp', 'step', 'igs', 'iges', 'jt', 'stl', 'x_t', 'x_b' ];
		$CAD_KEYWORDS = '/\b(dxf|dwg|stp|step|iges?|jt|3d\s*model|3d\s*cad|cad\s*file|solidworks|autocad)\b/i';
		$result = [ 'pdfs' => [], '3dcad' => [] ];

		foreach ( $raw_docs as $label => $items ) {
			if ( ! is_array( $items ) ) {
				$items = [ $items ];
			}
			foreach ( $items as $item ) {
				if ( is_array( $item ) ) {
					$url  = (string) ( $item['url'] ?? '' );
					$name = (string) ( $item['name'] ?? $label );
				} else {
					$url  = (string) $item;
					$name = (string) $label;
				}
				if ( '' === $url ) {
					continue;
				}
				$ext = strtolower( pathinfo( parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
				$is_cad_ext  = in_array( $ext, $CAD_EXT, true );
				$is_cad_name = (bool) preg_match( $CAD_KEYWORDS, $name );

				if ( $is_cad_ext || $is_cad_name ) {
					$result['3dcad'][] = [ 'url' => $url, 'name' => $name, 'ext' => $ext ];
				} else {
					$result['pdfs'][] = [ 'url' => $url, 'name' => $name ];
				}
			}
		}
		return $result;
	}

	public function get_page_threshold(): int {
		return 200;
	}

	public function has_separate_categories(): bool {
		return false;
	}

	public function has_product_descriptions(): bool {
		return false;
	}

	public function has_custom_description_from_specs(): bool {
		return false;
	}

	/**
	 * Extract technical specifications from a CSV row (case-insensitive column matching).
	 * Returns a key-value array of non-empty specs using the canonical column names.
	 */
	protected function extract_technical_specs( array $row ): array {
		$specs = [];
		$columns = $this->get_technical_spec_columns();

		// Build case-insensitive lookup: lowercase CSV header → original CSV header
		$row_lower = [];
		foreach ( $row as $key => $value ) {
			$row_lower[ strtolower( trim( $key ) ) ] = $key;
		}

		foreach ( $columns as $col ) {
			$col_lower = strtolower( trim( $col ) );
			if ( isset( $row_lower[ $col_lower ] ) ) {
				$actual_key = $row_lower[ $col_lower ];
				$value = $row[ $actual_key ];
				if ( '' !== trim( $value ) ) {
					$specs[ $col ] = $this->normalize_text( (string) $value );
				}
			}
		}
		return $specs;
	}
}
