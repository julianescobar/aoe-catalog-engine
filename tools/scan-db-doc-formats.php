<?php
/**
 * CLI scan: recorre la BD (aoe_catalog_products) y lista las extensiones de
 * archivo de todos los documentos guardados en url_pdf, normalizando ambos
 * formatos de almacenamiento:
 *   - Panduit:   {"drawing":[{"url":"...","name":"..."}], ...}
 *   - Otros:     {"datasheet":"https://...", "brochure":["..."]}
 * No escribe en BD.
 *
 * Usage:
 *   php tools/scan-db-doc-formats.php [--manufacturer=bulgin|panduit|...]
 */

namespace AOE\CatalogEngine\Tools;

if ( PHP_SAPI !== 'cli' ) {
	die( "CLI only.\n" );
}

ob_implicit_flush( true );
while ( ob_get_level() > 0 ) {
	ob_end_flush();
}

$args = getopt( '', [
	'manufacturer::',
	'db-host::',
	'db-user::',
	'db-pass::',
	'db-name::',
	'db-port::',
	'prefix::',
] );
$manufacturer_slug = $args['manufacturer'] ?? '';

$table_products     = 'aoe_catalog_products';
$table_manufacturer = 'aoe_catalog_manufacturers';

// Conexión directa por TCP (para correrlo local con el PHP CLI de Local).
$direct = new \mysqli( '127.0.0.1', 'root', 'root', 'local', 3306 );
if ( $direct->connect_errno ) {
	$direct = null;
}

$wpdb = null;
if ( null === $direct ) {
	// Bootstrap WordPress (fallback: se usa la conexión de WP).
	$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
	if ( ! file_exists( $wp_load ) ) {
		$wp_load = dirname( __DIR__, 5 ) . '/wp-load.php';
	}
	if ( ! file_exists( $wp_load ) ) {
		die( "wp-load.php no encontrado. Ajustá la ruta.\n" );
	}
	require_once $wp_load;
	global $wpdb;
	$table_products     = $wpdb->prefix . $table_products;
	$table_manufacturer = $wpdb->prefix . $table_manufacturer;
}

function aoe_db_results( string $sql, array $prepare = [] ): array {
	global $wpdb, $direct;
	if ( $direct ) {
		$stmt = $direct->prepare( $sql );
		if ( $prepare ) {
			$types = '';
			$vals  = [];
			foreach ( $prepare as $v ) {
				if ( is_int( $v ) ) {
					$types .= 'i';
				} else {
					$types .= 's';
				}
				$vals[] = $v;
			}
			$stmt->bind_param( $types, ...$vals );
		}
		$stmt->execute();
		$result = $stmt->get_result();
		$rows   = [];
		while ( $row = $result->fetch_assoc() ) {
			$rows[] = $row;
		}
		$stmt->close();
		return $rows;
	}
	return $wpdb->get_results( $prepare ? $wpdb->prepare( $sql, $prepare ) : $sql, ARRAY_A );
}

$manufacturers = aoe_db_results( "SELECT id, slug, name FROM {$table_manufacturer} ORDER BY id ASC" );
if ( empty( $manufacturers ) ) {
	die( "No hay fabricantes en la tabla {$table_manufacturer}.\n" );
}

$slug_to_id = [];
foreach ( $manufacturers as $m ) {
	$slug_to_id[ $m['slug'] ] = (int) $m['id'];
	echo "Fabricante: {$m['slug']} (id {$m['id']})\n";
}
echo "\n";

if ( '' !== $manufacturer_slug ) {
	if ( ! isset( $slug_to_id[ $manufacturer_slug ] ) ) {
		die( "Fabricante '{$manufacturer_slug}' no existe. Opciones: " . implode( ', ', array_keys( $slug_to_id ) ) . "\n" );
	}
	$manufacturers = array_filter( $manufacturers, function ( $m ) use ( $manufacturer_slug ) {
		return $m['slug'] === $manufacturer_slug;
	} );
	echo "Filtrando por fabricante: {$manufacturer_slug}\n\n";
}

/**
 * Normaliza una entrada de url_pdf (string | string[] | {url,name} | {url,name}[]).
 */
function aoe_normalize_doc_entries( $value ): array {
	$entries = [];
	foreach ( (array) $value as $item ) {
		if ( is_array( $item ) ) {
			$url = isset( $item['url'] ) ? (string) $item['url'] : '';
			if ( '' === $url ) {
				continue;
			}
			$entries[] = $url;
		} else {
			$str = trim( (string) $item );
			if ( '' !== $str ) {
				$entries[] = $str;
			}
		}
	}
	return $entries;
}

// ext -> manufacturer -> type -> count
$stats     = [];
$rows      = 0;
$rows_docs = 0;
$total_doc = 0;

foreach ( $manufacturers as $m ) {
	$mid   = (int) $m['id'];
	$slug  = $m['slug'];
	$last  = 0;
	$batch = 1000;

	while ( true ) {
		$rows_db = aoe_db_results(
			"SELECT id, url_pdf FROM {$table_products}
			 WHERE manufacturer_id = ? AND id > ?
			 ORDER BY id ASC LIMIT ?",
			[ $mid, $last, $batch ]
		);

		if ( empty( $rows_db ) ) {
			break;
		}

		foreach ( $rows_db as $row ) {
			$rows++;
			$last = (int) $row['id'];

			$json = $row['url_pdf'] ?? '';
			if ( '' === $json || 'null' === $json ) {
				continue;
			}
			$decoded = json_decode( $json, true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}

			$has_docs = false;
			foreach ( $decoded as $type => $value ) {
				if ( empty( $value ) ) {
					continue;
				}
				foreach ( aoe_normalize_doc_entries( $value ) as $url ) {
					$has_docs = true;
					$total_doc++;

					$url_no_query = preg_replace( '/[?#].*$/', '', $url );
					$ext = strtolower( pathinfo( parse_url( $url_no_query, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
					if ( '' === $ext ) {
						$ext = '(sin extension)';
					}

					$stats[ $ext ][ $slug ][ $type ] = ( $stats[ $ext ][ $slug ][ $type ] ?? 0 ) + 1;
				}
			}
			if ( $has_docs ) {
				$rows_docs++;
			}
		}

		if ( count( $rows_db ) < $batch ) {
			break;
		}
		echo "  {$slug}: ...{$last}\n";
	}
	echo "  {$slug}: fin ({$rows} filas acumuladas)\n\n";
}

echo "\n========== RESUMEN ==========\n";
echo "Filas totales:         {$rows}\n";
echo "Filas con documentos:  {$rows_docs}\n";
echo "Documentos totales:    {$total_doc}\n\n";

echo "Extensiones por fabricante y tipo:\n";
uksort( $stats, function( $a, $b ) use ( $stats ) {
	$ta = 0;
	$tb = 0;
	foreach ( $stats[ $a ] as $by_type ) {
		$ta += array_sum( $by_type );
	}
	foreach ( $stats[ $b ] as $by_type ) {
		$tb += array_sum( $by_type );
	}
	return $tb <=> $ta;
} );
foreach ( $stats as $ext => $by_manu ) {
	$count = 0;
	$detail = '';
	foreach ( $by_manu as $slug => $by_type ) {
		$subtotal = array_sum( $by_type );
		$count   += $subtotal;
		$types = [];
		foreach ( $by_type as $t => $tc ) {
			$types[] = "{$t}:{$tc}";
		}
		$detail .= "\n    {$slug}: {$subtotal} [" . implode( ', ', $types ) . ']';
	}
	$pct = $total_doc > 0 ? round( 100 * $count / $total_doc, 1 ) : 0;
	echo "  .{$ext}: {$count} ({$pct}%){$detail}\n";
}

echo "\nListo.\n";
