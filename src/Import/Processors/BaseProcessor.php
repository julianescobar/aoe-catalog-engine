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
			'description'     => '',
			'images'          => [],
			'pdf'             => [],
			'additional_data' => []
		];
	}
}
