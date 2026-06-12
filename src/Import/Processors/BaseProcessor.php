<?php

namespace AOE\CatalogEngine\Import\Processors;

abstract class BaseProcessor implements ProcessorInterface {

	/**
	 * Normalizes a common text string
	 */
	protected function normalize_text( string $text ): string {
		return trim( wp_strip_all_tags( $text ) );
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
