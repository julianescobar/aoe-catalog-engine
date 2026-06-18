<?php
/**
 * Usage: php tools/download-pdfs.php <slug> <csv_path> [parallel=15]
 *
 * Downloads PDFs from datasheet_url column to pdf/{slug}/originals/
 * Skips existing files.
 */

if ( PHP_SAPI !== 'cli' ) {
	die( 'CLI only' );
}

if ( $argc < 3 ) {
	echo "Usage: php tools/download-pdfs.php <slug> <csv_path> [parallel=15]\n";
	exit( 1 );
}

$slug     = $argv[1];
$csvPath  = $argv[2];
$parallel = isset( $argv[3] ) ? (int) $argv[3] : 15;

if ( ! file_exists( $csvPath ) ) {
	echo "CSV not found: $csvPath\n";
	exit( 1 );
}

$pdfDir = __DIR__ . '/../../../../pdf/' . $slug . '/originals/';
if ( ! is_dir( $pdfDir ) ) {
	mkdir( $pdfDir, 0755, true );
	echo "Created: $pdfDir\n";
}

$csv     = fopen( $csvPath, 'r' );
$headers = fgetcsv( $csv, 0, ';' );
if ( ! empty( $headers[0] ) ) {
	$headers[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $headers[0] );
}

$colIdx = array_search( 'datasheet_url', $headers );
if ( $colIdx === false ) {
	echo "No datasheet_url column found.\n";
	echo "Headers: " . implode( ', ', $headers ) . "\n";
	exit( 1 );
}

echo "Parallel:    $parallel\n";
echo "PDF column:  $colIdx\n";
echo "---\n";

$startTime = microtime( true );
$okPdfs    = 0;
$errors    = 0;
$skipped   = 0;
$errorLog  = [];

// Count total rows
$csvEstimate = fopen( $csvPath, 'r' );
fgetcsv( $csvEstimate, 0, ';' );
$rowCount = 0;
while ( fgetcsv( $csvEstimate, 0, ';' ) !== false ) {
	$rowCount++;
}
fclose( $csvEstimate );
echo "Total CSV rows: {$rowCount}\n";

// Re-open
fclose( $csv );
$csv  = fopen( $csvPath, 'r' );
fgetcsv( $csv, 0, ';' );

$batchSize = $parallel * 50;
$buffer    = [];
$rowNum    = 0;

while ( ( $row = fgetcsv( $csv, 0, ';' ) ) !== false ) {
	$rowNum++;

	$url = trim( $row[ $colIdx ] ?? '' );
	if ( '' === $url ) {
		continue;
	}

	$name = basename( urldecode( parse_url( $url, PHP_URL_PATH ) ) );
	$dest = $pdfDir . $name;

	if ( file_exists( $dest ) ) {
		$skipped++;
		continue;
	}

	$buffer[] = [ 'url' => $url, 'dest' => $dest ];

	if ( count( $buffer ) >= $batchSize ) {
		download_batch( $buffer, $parallel, $okPdfs, $errors, $errorLog );
		$buffer = [];
	}
}

if ( ! empty( $buffer ) ) {
	download_batch( $buffer, $parallel, $okPdfs, $errors, $errorLog );
}

fclose( $csv );

$elapsed = round( microtime( true ) - $startTime, 2 );
echo "---\n";
echo "Rows:       $rowNum\n";
echo "PDFs OK:    $okPdfs\n";
echo "Skipped:    $skipped\n";
echo "Errors:     $errors\n";
echo "Time:       {$elapsed}s\n";

if ( ! empty( $errorLog ) ) {
	$logPath = __DIR__ . '/pdf-errors-' . $slug . '-' . date( 'Ymd_His' ) . '.txt';
	file_put_contents( $logPath, implode( "\n", $errorLog ) );
	echo "Error log:  $logPath\n";
}

function download_batch( array $entries, int $parallel, int &$okPdfs, int &$errors, array &$errorLog ) {
	$total = count( $entries );
	if ( $total === 0 ) return;

	for ( $offset = 0; $offset < $total; $offset += $parallel ) {
		$batch = array_slice( $entries, $offset, $parallel );

		$mh = curl_multi_init();
		$handles = [];
		foreach ( $batch as $ti => $entry ) {
			$ch = curl_init();
			curl_setopt_array( $ch, [
				CURLOPT_URL            => $entry['url'],
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS      => 5,
				CURLOPT_TIMEOUT        => 60,
				CURLOPT_SSL_VERIFYPEER => false,
				CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
			] );
			curl_multi_add_handle( $mh, $ch );
			$handles[] = [ 'ch' => $ch, 'entry' => $entry ];
		}

		$running = 0;
		do {
			$status = curl_multi_exec( $mh, $running );
			if ( $running > 0 ) {
				curl_multi_select( $mh, 1 );
			}
		} while ( $running > 0 );

		foreach ( $handles as $h ) {
			$ch   = $h['ch'];
			$data = curl_multi_getcontent( $ch );
			$httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			curl_multi_remove_handle( $mh, $ch );
			curl_close( $ch );

			if ( $data === false || $data === '' || $httpCode >= 400 ) {
				echo "  [ERR] HTTP {$httpCode}: {$h['entry']['url']}\n";
				$errorLog[] = $h['entry']['url'];
				$errors++;
				continue;
			}

			$saved = file_put_contents( $h['entry']['dest'], $data );
			if ( $saved === false ) {
				echo "  [ERR] Could not write: {$h['entry']['dest']}\n";
				$errorLog[] = $h['entry']['url'];
				$errors++;
				continue;
			}
			echo "  [OK] {$h['entry']['dest']}\n";
			$okPdfs++;
		}
		curl_multi_close( $mh );
	}
}
