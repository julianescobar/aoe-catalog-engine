<?php

namespace AOE\CatalogEngine\Import\Processors;

/**
 * Amphenol Audio: CSV with 2-level category hierarchy (L1|L2 by name),
 * remote images/PDFs/CAD from amphenolaudio.com. No per-product specs —
 * the rich features/options live at category level and are imported by
 * import-audio-structure.php.
 */
class AmphenolAudioProcessor extends BaseProcessor {

	public static function get_manufacturer_slug(): string {
		return 'amphenol-audio';
	}

	public function has_separate_categories(): bool {
		return true;
	}

	public function has_product_descriptions(): bool {
		return false;
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
			'pdf_extra',
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

		// Category resolution is handled by import-audio-structure.php (pasada 2),
		// which rebuilds the L1|L2 hierarchy and re-attributes products by SKU.
		// On --mode=replace full-import clears categories, so products land in
		// "Uncategorized" here until the structure script runs.
		$data['category'] = 'Uncategorized';

		// Image: remote amphenolaudio.com URL.
		$image_url = isset( $row['image_url'] ) ? $this->normalize_text( (string) $row['image_url'] ) : '';
		$data['images'] = ! empty( $image_url ) ? [ $image_url ] : [];

		// Documents: datasheet + extra downloads + 3D CAD.
		$data['pdf'] = $this->parse_documents( $row );

		$product_url = isset( $row['url'] ) ? trim( (string) $row['url'] ) : '';
		if ( '' !== $product_url ) {
			$data['additional_data']['product_url'] = $this->normalize_text( $product_url );
		}

		// Note: 'features'/'options' columns in the products CSV are repeated
		// per category and are imported at category level by the structure
		// script, so they are intentionally ignored here.

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
}