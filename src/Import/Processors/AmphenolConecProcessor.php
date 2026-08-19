<?php

namespace AOE\CatalogEngine\Import\Processors;

class AmphenolConecProcessor extends BaseProcessor {

	public static function get_manufacturer_slug(): string {
		return 'amphenol-conec';
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
			'sku_id',
			'name',
			'description',
			'category_l1',
			'category_l2',
			'category_l3',
			'url',
			'image_url',
			'pdf_url',
			'cad_url',
			'pdf_extra',
			'attrs',
		];
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

		// Description: CSV carries a JSON array of short feature lines; join
		// them into a single readable subtitle line for the catalog row.
		$desc = isset( $row['description'] ) ? trim( (string) $row['description'] ) : '';
		$decoded = json_decode( $desc, true );
		if ( is_array( $decoded ) ) {
			$lines = array_values( array_filter( array_map( 'trim', $decoded ), static function ( $l ) { return '' !== $l; } ) );
			$desc  = implode( ', ', $lines );
		}
		$data['description'] = $this->normalize_text( $desc );

		// Resolve category path from DB structure by conec category_id (the
		// composite "L1|L2" path that equals the CSV category_id; L1 fallback).
		// Works on incremental imports; on --mode=replace categories are rebuilt
		// by import-conec-structure.php.
		$l1 = isset( $row['category_l1'] ) ? trim( (string) $row['category_l1'] ) : '';
		$l2 = isset( $row['category_l2'] ) ? trim( (string) $row['category_l2'] ) : '';

		$cat_info = null;
		if ( '' !== $l1 ) {
			$candidates = [];
			if ( '' !== $l2 ) {
				$candidates[] = $l1 . '|' . $l2;
			}
			$candidates[] = $l1;

			foreach ( $candidates as $cid ) {
				$cat_info = $this->get_category_path_by_conec_id( $cid );
				if ( null !== $cat_info && ! empty( $cat_info['path'] ) ) {
					break;
				}
				$cat_info = null;
			}
		}

		if ( null !== $cat_info && ! empty( $cat_info['path'] ) ) {
			$data['category_path'] = $cat_info['path'];
			$data['category']      = end( $cat_info['path'] );
		}
		if ( empty( $data['category'] ) ) {
			$data['category'] = 'Uncategorized';
		}

		// Image: real conec.com URLs; skip the site-wide Magento placeholder
		// used for parts without a real photo (those rows render without image).
		$image_url = isset( $row['image_url'] ) ? $this->normalize_text( (string) $row['image_url'] ) : '';
		$data['images'] = ( '' !== $image_url && false === strpos( $image_url, '/placeholder/' ) ) ? [ $image_url ] : [];

		// Documents: datasheet + extra downloads + 3D CAD (STEP zip)
		$data['pdf'] = $this->parse_documents( $row );

		// Specs: attrs JSON (label/value rows in English). All labels are
		// technical specs; no commercial noise to filter out.
		$specs = $this->extract_attr_specs( $row );
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

	private function parse_documents( array $row ): array {
		$pdfs = [];

		$pdf_url = isset( $row['pdf_url'] ) ? trim( (string) $row['pdf_url'] ) : '';
		if ( '' !== $pdf_url ) {
			$pdfs['datasheet'][] = [ 'url' => $pdf_url, 'name' => 'Datasheet' ];
		}

		$extra = isset( $row['pdf_extra'] ) ? trim( (string) $row['pdf_extra'] ) : '';
		if ( '' !== $extra ) {
			$urls = preg_split( '/\s*\|\s*/', $extra );
			$urls = array_values( array_filter( array_map( 'trim', $urls ), static function ( $u ) { return '' !== $u; } ) );
			foreach ( $urls as $i => $u ) {
				$pdfs['datasheet'][] = [ 'url' => $u, 'name' => 'Documento ' . ( $i + 1 ) ];
			}
		}

		$cad_url = isset( $row['cad_url'] ) ? trim( (string) $row['cad_url'] ) : '';
		if ( '' !== $cad_url ) {
			$pdfs['3D CAD'][] = [ 'url' => $cad_url, 'name' => '3D CAD' ];
		}

		return $pdfs;
	}

	private function extract_attr_specs( array $row ): array {
		$raw = isset( $row['attrs'] ) ? trim( (string) $row['attrs'] ) : '';
		if ( '' === $raw ) {
			return [];
		}

		$attrs = json_decode( $raw, true );
		if ( ! is_array( $attrs ) ) {
			return [];
		}

		$specs = [];
		foreach ( $attrs as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$label = trim( (string) ( $item['label'] ?? '' ) );
			$value = trim( (string) ( $item['value'] ?? '' ) );
			if ( '' === $label || '' === $value ) {
				continue;
			}
			$specs[ $label ] = $this->normalize_text( $value );
		}

		return $specs;
	}

	private function get_category_path_by_conec_id( string $conec_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'aoe_catalog_categories';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id FROM $table WHERE type = 'category' AND metadata_json LIKE %s LIMIT 1",
			'%"conec_id":"' . $wpdb->esc_like( $conec_id ) . '"%'
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
