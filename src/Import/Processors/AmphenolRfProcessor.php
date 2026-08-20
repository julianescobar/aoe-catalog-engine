<?php

namespace AOE\CatalogEngine\Import\Processors;

class AmphenolRfProcessor extends BaseProcessor {

	public static function get_manufacturer_slug(): string {
		return 'amphenol-rf';
	}

	public function has_separate_categories(): bool {
		return true;
	}

	public function get_page_threshold(): int {
		return 1;
	}

	public function get_supported_columns(): array {
		return [
			'part_number',
			'name',
			'category_l1',
			'category_l2',
			'url',
			'image_url',
			'pdf_url',
			'cad_url',
			'impedance_ohms',
			'frequency_max_ghz',
			'temp_min_degrees_celsius',
			'temp_max_degrees_celsius',
			'unit_weight_grams',
			'body_material',
			'termination_style',
			'gender',
			'ports',
			'coupling_mechanism',
			'mating_cycles_min',
			'cable_assembly_length',
			'cable_type_cable_assemblies',
			'applications',
		];
	}

	private function get_spec_label_map(): array {
		return [];
	}

	private function parse_attrs( array $row ): array {
		$raw = isset( $row['attrs'] ) ? trim( (string) $row['attrs'] ) : '';
		if ( '' === $raw || '[]' === $raw ) {
			return [];
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return [];
		}

		$noise_labels = [
			'country of origin', 'eccn', 'hts code', 'pfas',
			'rohs', 'reach', 'lead free', 'compliance',
		];
		$specs = [];
		foreach ( $decoded as $item ) {
			$label = trim( $item['label'] ?? '' );
			$value = trim( $item['value'] ?? '' );
			if ( '' === $label || '' === $value ) {
				continue;
			}
			$low = strtolower( $value );
			if ( 'not applicable' === $low || 'not rated' === $low || 'null' === $low ) {
				continue;
			}
			if ( in_array( strtolower( $label ), $noise_labels, true ) ) {
				continue;
			}
			$specs[ $label ] = $this->normalize_text( $value );
		}
		return $specs;
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

		// Resolve category path from DB structure by rf category_id (works on
		// incremental imports; on --mode=replace categories are rebuilt by
		// import-rf-structure.php)
		$l1 = isset( $row['category_l1'] ) ? trim( (string) $row['category_l1'] ) : '';
		$l2 = isset( $row['category_l2'] ) ? trim( (string) $row['category_l2'] ) : '';

		$cat_info = null;
		if ( '' !== $l2 ) {
			$cat_info = $this->get_category_path_by_rf_id( $l2 );
		}
		if ( null === $cat_info && '' !== $l1 ) {
			$cat_info = $this->get_category_path_by_rf_id( $l1 );
		}

		if ( null !== $cat_info && ! empty( $cat_info['path'] ) ) {
			$data['category_path'] = $cat_info['path'];
			$data['category']      = end( $cat_info['path'] );
		}
		if ( empty( $data['category'] ) ) {
			$data['category'] = 'Uncategorized';
		}

		// Image (skip the site-wide search-parts.svg placeholder used for parts
		// without a real photo; those rows render without image). CSV filenames
		// may contain HTML entities (&#x2B; = +) which must be decoded so the
		// local media resolver finds the matching downloaded file.
		$image_url = isset( $row['image_url'] ) ? $this->normalize_text( (string) $row['image_url'] ) : '';
		if ( '' !== $image_url ) {
			$image_url = html_entity_decode( $image_url, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		$data['images'] = ( '' !== $image_url && false === strpos( $image_url, 'search-parts' ) ) ? [ $image_url ] : [];

		// Documents: pdf_url (datasheet) and cad_url (3D CAD).
		$data['pdf'] = $this->parse_documents( $row );

		// Specs — from attrs JSON (the only source; no individual spec columns in CSV)
		$specs = $this->parse_attrs( $row );

		$additional = [];
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

	private function build_temperature_range( array $row ): string {
		$min = isset( $row['temp_min_degrees_celsius'] ) ? trim( (string) $row['temp_min_degrees_celsius'] ) : '';
		$max = isset( $row['temp_max_degrees_celsius'] ) ? trim( (string) $row['temp_max_degrees_celsius'] ) : '';

		$clean = static function ( string $v ): string {
			$v = preg_replace( '/[^0-9.\-]/', '', $v );
			return ( '' === $v || 'Not Applicable' === $v ) ? '' : $v;
		};

		$min = $clean( $min );
		$max = $clean( $max );

		if ( '' === $min && '' === $max ) {
			return '';
		}
		if ( '' === $min ) {
			return 'up to ' . $max . ' °C';
		}
		if ( '' === $max ) {
			return 'from ' . $min . ' °C';
		}
		return $min . ' to ' . $max . ' °C';
	}

	private function parse_documents( array $row ): array {
		$pdfs = [];

		$pdf_url = isset( $row['pdf_url'] ) ? trim( (string) $row['pdf_url'] ) : '';
		if ( '' !== $pdf_url ) {
			$pdfs['datasheet'][] = [ 'url' => $pdf_url, 'name' => 'Datasheet' ];
		}

		$cad_url = isset( $row['cad_url'] ) ? trim( (string) $row['cad_url'] ) : '';
		if ( '' !== $cad_url ) {
			$pdfs['3D CAD'][] = [ 'url' => $cad_url, 'name' => '3D CAD' ];
		}

		return $pdfs;
	}

	private function get_category_path_by_rf_id( string $rf_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'aoe_catalog_categories';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id FROM $table WHERE type = 'category' AND metadata_json LIKE %s LIMIT 1",
			'%"rf_id":"' . $wpdb->esc_like( $rf_id ) . '"%'
		) );
		if ( empty( $rows ) ) {
			return null;
		}

		$path    = [];
		$current = (int) $rows[0]->id;
		while ( $current ) {
			$cat = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, name, parent_id FROM $table WHERE id = %d", $current
			) );
			if ( ! $cat ) {
				break;
			}
			array_unshift( $path, $cat->name );
			$current = (int) $cat->parent_id;
		}
		return [ 'path' => $path ];
	}
}
