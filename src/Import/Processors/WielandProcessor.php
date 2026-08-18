<?php

namespace AOE\CatalogEngine\Import\Processors;

class WielandProcessor extends BaseProcessor {

	public static function get_manufacturer_slug(): string {
		return 'wieland';
	}

	public function has_separate_categories(): bool {
		return true;
	}

	public function get_page_threshold(): int {
		return 1;
	}

	public function get_supported_columns(): array {
		return [
			'sku',
			'name',
			'url',
			'ean',
			'breadcrumb',
			'category',
			'image_url',
			'available',
			'successor_url',
			'pdf_urls',
			'cad_urls',
			'downloads_json',
			'attrs',
		];
	}

	public function process_row( array $row ): array {
		$data = $this->get_default_structure();

		$row = array_combine(
			array_map( static function ( $key ) { return ltrim( $key, "\xEF\xBB\xBF" ); }, array_keys( $row ) ),
			$row
		);

		$sku = isset( $row['sku'] ) ? $this->normalize_text( (string) $row['sku'] ) : '';
		if ( '' === $sku ) {
			return $data;
		}

		$data['sku'] = $sku;

		$name = isset( $row['name'] ) ? $this->normalize_text( (string) $row['name'] ) : '';
		$data['name'] = '' !== $name ? $name : $sku;

		$data['description'] = '';

		// Category: flat from the "category" column (no hierarchy).
		$category = isset( $row['category'] ) ? trim( (string) $row['category'] ) : '';
		$data['category'] = '' !== $category ? $category : 'Uncategorized';

		// Image: single remote URL from wieland-electric.com CDN.
		$image_url = isset( $row['image_url'] ) ? trim( (string) $row['image_url'] ) : '';
		$data['images'] = ( '' !== $image_url ) ? [ $image_url ] : [];

		// Documents: prefer downloads_json (structured) over pipe-separated columns.
		$data['pdf'] = $this->parse_documents( $row );

		// Specs: attrs JSON (label/value rows in Spanish).
		$specs = $this->extract_attr_specs( $row );
		$additional = [];
		if ( ! empty( $specs ) ) {
			$additional['specs'] = $specs;
		}
		$product_url = isset( $row['url'] ) ? trim( (string) $row['url'] ) : '';
		if ( '' !== $product_url ) {
			$additional['product_url'] = $this->normalize_text( $product_url );
		}
		$ean = isset( $row['ean'] ) ? trim( (string) $row['ean'] ) : '';
		if ( '' !== $ean ) {
			$additional['ean'] = $ean;
		}
		$available = isset( $row['available'] ) ? trim( (string) $row['available'] ) : '';
		if ( '' !== $available ) {
			$additional['available'] = (int) $available;
		}
		$successor = isset( $row['successor_url'] ) ? trim( (string) $row['successor_url'] ) : '';
		if ( '' !== $successor ) {
			$additional['successor_url'] = $this->normalize_text( $successor );
		}
		if ( ! empty( $additional ) ) {
			$data['additional_data'] = $additional;
		}

		return $data;
	}

	private function parse_documents( array $row ): array {
		$pdfs = [];

		// Prefer downloads_json (structured JSON with url/filename/ext).
		$dj_raw = isset( $row['downloads_json'] ) ? trim( (string) $row['downloads_json'] ) : '';
		if ( '' !== $dj_raw ) {
			$downloads = json_decode( $dj_raw, true );
			if ( is_array( $downloads ) ) {
				foreach ( $downloads as $item ) {
					if ( ! is_array( $item ) ) {
						continue;
					}
					$url  = $item['url'] ?? '';
					$ext  = strtolower( $item['ext'] ?? '' );
					$name = $item['filename'] ?? '';
					if ( '' === $url ) {
						continue;
					}
					if ( 'pdf' === $ext ) {
						$pdfs['datasheet'][] = [ 'url' => $url, 'name' => $name ];
					} elseif ( in_array( $ext, [ 'dxf', 'jt', 'stp', 'igs', 'zip', 'step' ], true ) ) {
						$pdfs['3D CAD'][] = [ 'url' => $url, 'name' => $name ];
					}
					// Skip tif and other non-PDF/non-CAD types.
				}
				if ( ! empty( $pdfs ) ) {
					return $pdfs;
				}
			}
		}

		// Fallback: parse pipe-separated pdf_urls and cad_urls.
		$pdf_urls = isset( $row['pdf_urls'] ) ? trim( (string) $row['pdf_urls'] ) : '';
		if ( '' !== $pdf_urls ) {
			$urls = array_values( array_filter( array_map( 'trim', explode( ' | ', $pdf_urls ) ) ) );
			foreach ( $urls as $u ) {
				$pdfs['datasheet'][] = [ 'url' => $u, 'name' => 'Datasheet' ];
			}
		}

		$cad_urls = isset( $row['cad_urls'] ) ? trim( (string) $row['cad_urls'] ) : '';
		if ( '' !== $cad_urls ) {
			$urls = array_values( array_filter( array_map( 'trim', explode( ' | ', $cad_urls ) ) ) );
			foreach ( $urls as $u ) {
				$pdfs['3D CAD'][] = [ 'url' => $u, 'name' => '3D CAD' ];
			}
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

		$ignore = $this->get_ignored_attr_labels();
		$specs  = [];
		foreach ( $attrs as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$label = trim( (string) ( $item['label'] ?? '' ) );
			$value = trim( (string) ( $item['value'] ?? '' ) );
			if ( '' === $label || '' === $value ) {
				continue;
			}
			if ( in_array( $label, $ignore, true ) ) {
				continue;
			}
			$specs[ $label ] = $this->normalize_text( $value );
		}

		return $specs;
	}

	private function get_ignored_attr_labels(): array {
		return [
			// Packaging / logistics
			'Altura embalada',
			'Anchura embalada',
			'Longitud empaquetada',
			'Peso bruto embalado',
			'Volumen de la caja de carton',
			'Empaquetado',
			'Unidad de embalaje',
			'Cantidad mínima de pedido',
			'Peso neto de una pieza',
			'Peso neto',
			'Peso',
			'País de origen',
			'Número del arancel aduanero',
			// Classification codes
			'Etim 6 0',
			'Etim 9 0',
			'Etim 5 0',
			'Etim 8 0',
			'Etim 7 0',
			'Etim 4 0',
			'Etim 3 0',
			'Eclass 8 1',
			'Eclass 11',
			// Compliance / regulatory
			'Reach svhc conformity status',
			'Rohs conformity status',
			'Excepciones rohs',
			'Declaracion reach svhc cas',
			'Substancias reach svhc',
			'Reach cas numbers',
			'Reach substance',
			'Rohs exceptions',
			'Certificacion rohs',
			'Certificacion ul',
			'Certificaciones homologaciones',
			'Homologacion culus',
			'Ce norm',
			// German labels (not translated)
			'Nennspannung dc',
			'Umgebungstemperatur ta min',
			'Leiterquerschnitt starr ein mehrdr htig max',
			'Reihenklemmen fur installationsverteiler',
			'Anzahl der klemmstellen je etage',
			'Erweiterbar',
			// Other noise
			'Noticia legal',
			'Hft',
			'Tm',
			'D',
			'Dd',
			'Du',
			'S',
			'Descripcion 1',
			'Descripcion 2',
		];
	}
}
