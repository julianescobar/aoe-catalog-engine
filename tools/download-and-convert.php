<?php
/**
 * Usage: php tools/download-and-convert.php <slug> <csv_path> [parallel=15]
 *
 * Reads CSV with columns: part_image_url, datasheet_url
 * Downloads images (converts to webp 75%) to uploads/catalogo/{slug}/images/
 * Downloads PDFs as-is to uploads/catalogo/{slug}/pdfs/
 * Files that already exist are skipped automatically.
 * Processes CSV in chunks to limit memory usage.
 */

if ( PHP_SAPI !== 'cli' ) {
	die( 'CLI only' );
}

if ( $argc < 3 ) {
	echo "Usage: php tools/download-and-convert.php <slug> <csv_path> [parallel=15]\n";
	exit( 1 );
}

$slug     = $argv[1];
$csvPath  = $argv[2];
$parallel = isset( $argv[3] ) ? (int) $argv[3] : 15;

if ( ! file_exists( $csvPath ) ) {
	echo "CSV not found: $csvPath\n";
	exit( 1 );
}

$hasGd = function_exists( 'imagecreatefromstring' ) && function_exists( 'imagewebp' );
if ( ! $hasGd ) {
	echo "[WARN] PHP GD extension not found. Images will stay as original format.\n";
}

$imageDir = __DIR__ . '/../../../uploads/catalogo/' . $slug . '/images/';
$pdfDir   = __DIR__ . '/../../../../pdf/' . $slug . '/originals/';

if ( ! is_dir( $imageDir ) ) {
	mkdir( $imageDir, 0755, true );
	echo "Created: $imageDir\n";
}
if ( ! is_dir( $pdfDir ) ) {
	mkdir( $pdfDir, 0755, true );
	echo "Created: $pdfDir\n";
}

$csv     = fopen( $csvPath, 'r' );
$headers = fgetcsv( $csv, 0, ';' );
if ( ! empty( $headers[0] ) ) {
	$headers[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $headers[0] );
}
$colIdxImg = array_search( 'part_image_url', $headers );
$colIdxPdf = array_search( 'datasheet_url', $headers );

if ( $colIdxImg === false && $colIdxPdf === false ) {
	echo "No part_image_url or datasheet_url columns found.\n";
	echo "Headers: " . implode( ', ', $headers ) . "\n";
	exit( 1 );
}

echo "Parallel:    $parallel\n";
echo "Image column: " . ( $colIdxImg !== false ? $colIdxImg : 'N/A' ) . "\n";
echo "PDF column:   " . ( $colIdxPdf !== false ? $colIdxPdf : 'N/A' ) . "\n";
echo "---\n";

$startTime = microtime( true );
$okImages  = 0;
$okPdfs    = 0;
$errors    = 0;
$errorLog  = [];
$done      = 0;

// First pass: count existing files to show total pending estimate
$csvEstimate = fopen( $csvPath, 'r' );
fgetcsv( $csvEstimate, 0, ';' );
$rowCount = 0;
while ( fgetcsv( $csvEstimate, 0, ';' ) !== false ) {
	$rowCount++;
}
fclose( $csvEstimate );
echo "Total CSV rows (excluding header): {$rowCount}\n";

// Re-open CSV from beginning
fclose( $csv );
$csv  = fopen( $csvPath, 'r' );
fgetcsv( $csv, 0, ';' ); // skip header

$batchSize = $parallel * 50; // collect up to 50 batches before each multi-download
$buffer    = [];
$rowNum    = 0;

while ( ( $row = fgetcsv( $csv, 0, ';' ) ) !== false ) {
	$rowNum++;

	$hasImg = $colIdxImg !== false && ! empty( trim( $row[ $colIdxImg ] ?? '' ) );
	$hasPdf = $colIdxPdf !== false && ! empty( trim( $row[ $colIdxPdf ] ?? '' ) );

	if ( ! $hasImg && ! $hasPdf ) {
		continue;
	}

	$entry = [ 'img' => null, 'pdf' => null ];

	if ( $hasImg ) {
		$url        = trim( $row[ $colIdxImg ] );
		$parsedPath = urldecode( parse_url( $url, PHP_URL_PATH ) );
		$ext        = strtolower( pathinfo( $parsedPath, PATHINFO_EXTENSION ) );
		$nameNoExt  = pathinfo( basename( $parsedPath ), PATHINFO_FILENAME );
		$dest       = $imageDir . $nameNoExt . ( $hasGd ? '.webp' : '.' . $ext );
		if ( ! file_exists( $dest ) ) {
			$entry['img'] = [ 'url' => $url, 'dest' => $dest ];
		}
	}

	if ( $hasPdf ) {
		$url   = trim( $row[ $colIdxPdf ] );
		$name  = basename( urldecode( parse_url( $url, PHP_URL_PATH ) ) );
		$dest  = $pdfDir . $name;
		if ( ! file_exists( $dest ) ) {
			$entry['pdf'] = [ 'url' => $url, 'dest' => $dest ];
		}
	}

	if ( $entry['img'] === null && $entry['pdf'] === null ) {
		continue;
	}

	$buffer[] = $entry;

	if ( count( $buffer ) >= $batchSize ) {
		$totalPending += download_batch( $buffer, $parallel, $hasGd, $done, $okImages, $okPdfs, $errors, $errorLog );
		$buffer = [];
	}
}

// Flush remaining buffer
if ( ! empty( $buffer ) ) {
	$totalPending += download_batch( $buffer, $parallel, $hasGd, $done, $okImages, $okPdfs, $errors, $errorLog );
}

fclose( $csv );

$elapsed = round( microtime( true ) - $startTime, 2 );
echo "---\n";
echo "Rows processed: $rowNum\n";
echo "Images OK:      $okImages\n";
echo "PDFs OK:        $okPdfs\n";
echo "Errors:         $errors\n";
echo "Time:           {$elapsed}s\n";

if ( ! empty( $errorLog ) ) {
	$logPath = __DIR__ . '/download-errors-' . $slug . '-' . date( 'Ymd_His' ) . '.txt';
	file_put_contents( $logPath, implode( "\n", $errorLog ) );
	echo "Error log:      $logPath\n";
}

function download_batch( array $entries, int $parallel, bool $hasGd, int &$done, int &$okImages, int &$okPdfs, int &$errors, array &$errorLog ): int {
	// Build flat list of download tasks
	$tasks = [];
	$taskToEntry = [];
	foreach ( $entries as $ei => $entry ) {
		if ( $entry['img'] !== null ) {
			$tasks[] = [ 'type' => 'image', 'url' => $entry['img']['url'], 'dest' => $entry['img']['dest'] ];
			$taskToEntry[] = [ 'entryIdx' => $ei, 'sub' => 'img' ];
		}
		if ( $entry['pdf'] !== null ) {
			$tasks[] = [ 'type' => 'pdf', 'url' => $entry['pdf']['url'], 'dest' => $entry['pdf']['dest'] ];
			$taskToEntry[] = [ 'entryIdx' => $ei, 'sub' => 'pdf' ];
		}
	}

	$total = count( $tasks );
	if ( $total === 0 ) {
		return 0;
	}

	for ( $offset = 0; $offset < $total; $offset += $parallel ) {
		$batch = array_slice( $tasks, $offset, $parallel );

		$mh = curl_multi_init();
		$handles = [];
		foreach ( $batch as $ti => $task ) {
			$ch = curl_init();
			curl_setopt_array( $ch, [
				CURLOPT_URL            => $task['url'],
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS      => 5,
				CURLOPT_TIMEOUT        => 60,
				CURLOPT_SSL_VERIFYPEER => false,
				CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
			] );
			curl_multi_add_handle( $mh, $ch );
			$handles[] = [ 'ch' => $ch, 'task' => $task ];
		}

		$running = 0;
		do {
			$status = curl_multi_exec( $mh, $running );
			if ( $running > 0 ) {
				curl_multi_select( $mh, 1 );
			}
		} while ( $running > 0 );

		foreach ( $handles as $entry ) {
			$ch     = $entry['ch'];
			$task   = $entry['task'];
			$data   = curl_multi_getcontent( $ch );
			$httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			curl_multi_remove_handle( $mh, $ch );
			curl_close( $ch );

			$done++;

			if ( $data === false || $data === '' || $httpCode >= 400 ) {
				echo "  [ERR] ({$done}/?) HTTP {$httpCode}: {$task['url']}\n";
				$errorLog[] = $task['url'];
				$errors++;
				continue;
			}

			if ( $task['type'] === 'image' ) {
				if ( $hasGd ) {
					$img = @imagecreatefromstring( $data );
					if ( $img === false ) {
						echo "  [ERR] ({$done}/?) Not a valid image: {$task['url']}\n";
						$errorLog[] = $task['url'];
						$errors++;
						continue;
					}
					$saved = imagewebp( $img, $task['dest'], 75 );
					imagedestroy( $img );
					if ( ! $saved ) {
						echo "  [ERR] ({$done}/?) Could not write: {$task['dest']}\n";
						$errorLog[] = $task['url'];
						$errors++;
						continue;
					}
				} else {
					$saved = file_put_contents( $task['dest'], $data );
					if ( $saved === false ) {
						echo "  [ERR] ({$done}/?) Could not write: {$task['dest']}\n";
						$errorLog[] = $task['url'];
						$errors++;
						continue;
					}
				}
				echo "  [OK] ({$done}/?) {$task['dest']}\n";
				$okImages++;
			} else {
				$saved = file_put_contents( $task['dest'], $data );
				if ( $saved === false ) {
					echo "  [ERR] ({$done}/?) Could not write: {$task['dest']}\n";
					$errorLog[] = $task['url'];
					$errors++;
					continue;
				}
				echo "  [OK] ({$done}/?) {$task['dest']}\n";
				$okPdfs++;
			}
		}

		curl_multi_close( $mh );
	}

	return $total;
}
