<?php

namespace AOE\CatalogEngine\Import\Processors;

interface ProcessorInterface {
	/**
	 * Process a single row from the CSV
	 *
	 * @param array $row The row data
	 * @return array Normalized product data
	 */
	public function process_row( array $row ): array;

	/**
	 * Get the manufacturer slug this processor handles
	 *
	 * @return string
	 */
	public static function get_manufacturer_slug(): string;

	/**
	 * Get the list of columns this processor supports/needs from the CSV
	 *
	 * @return array
	 */
	public function get_supported_columns(): array;

	/**
	 * Minimum product count for a category to get its own page.
	 * Categories below this threshold are packed into grouped pages.
	 *
	 * @return int
	 */
	public function get_page_threshold(): int;

	/**
	 * Whether this manufacturer manages categories via a separate import (Step 0).
	 * If true, product import in replace mode will NOT delete categories.
	 *
	 * @return bool
	 */
	public function has_separate_categories(): bool;
}
