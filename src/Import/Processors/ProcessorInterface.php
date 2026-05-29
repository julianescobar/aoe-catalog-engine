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
}
