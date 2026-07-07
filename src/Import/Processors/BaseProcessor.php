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

	public function get_page_threshold(): int {
		return 200;
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
