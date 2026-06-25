<?php
/**
 * Usage: php tools/convert-images-to-webp.php <slug>
 *
 * Scans uploads/catalogo/{slug}/images/ for jpg/png/gif,
 * converts each to .webp (quality 75), moves original to originals/.
 */

if ( ! in_array( PHP_SAPI, [ 'cli', 'cgi-fcgi' ], true ) ) {
	die( 'CLI only' );
}
header_remove( 'Content-type' );

if ( $argc < 2 ) {
	echo "Usage: php tools/convert-images-to-webp.php <slug>\n";
	exit( 1 );
}

$slug = $argv[1];
$imageDir = __DIR__ . '/../../../uploads/catalogo/' . $slug . '/images/';

if ( ! is_dir( $imageDir ) ) {
	echo "Directory not found: $imageDir\n";
	exit( 1 );
}

if ( ! function_exists( 'imagecreatefromstring' ) || ! function_exists( 'imagewebp' ) ) {
	echo "PHP GD extension required.\n";
	exit( 1 );
}

$originalsDir = $imageDir . 'originals/';
if ( ! is_dir( $originalsDir ) ) {
	mkdir( $originalsDir, 0755, true );
	echo "Created: $originalsDir\n";
}

$extensions = [ 'jpg', 'jpeg', 'png', 'gif' ];
$files = [];
foreach ( scandir( $imageDir ) as $f ) {
	if ( $f === '.' || $f === '..' || $f === 'originals' ) continue;
	$ext = strtolower( pathinfo( $f, PATHINFO_EXTENSION ) );
	if ( ! in_array( $ext, $extensions, true ) ) continue;
	$files[] = $f;
}

if ( empty( $files ) ) {
	echo "No images found to convert in $imageDir\n";
	exit( 0 );
}

$total   = count( $files );
$ok      = 0;
$skipped = 0;
$errors  = 0;
$start   = microtime( true );

echo "Found $total images to process in $imageDir\n";
echo "---\n";

foreach ( $files as $file ) {
	$srcPath     = $imageDir . $file;
	$nameNoExt   = pathinfo( $file, PATHINFO_FILENAME );
	$webpPath    = $imageDir . $nameNoExt . '.webp';

	if ( file_exists( $webpPath ) ) {
		echo "  [SKIP] $webpPath already exists\n";
		$skipped++;
		continue;
	}

	$data = file_get_contents( $srcPath );
	if ( $data === false ) {
		echo "  [ERR] Could not read: $file\n";
		$errors++;
		continue;
	}

	$img = @imagecreatefromstring( $data );
	if ( $img === false ) {
		echo "  [ERR] Not a valid image: $file\n";
		$errors++;
		continue;
	}

	$saved = imagewebp( $img, $webpPath, 75 );
	imagedestroy( $img );

	if ( ! $saved ) {
		echo "  [ERR] Could not write webp: $webpPath\n";
		$errors++;
		continue;
	}

	if ( rename( $srcPath, $originalsDir . $file ) ) {
		echo "  [OK] $file -> $nameNoExt.webp\n";
		$ok++;
	} else {
		echo "  [WARN] Converted but could not move original: $file\n";
		$ok++;
	}
}

$elapsed = round( microtime( true ) - $start, 2 );
echo "---\n";
echo "Converted: $ok\n";
echo "Skipped:   $skipped\n";
echo "Errors:    $errors\n";
echo "Time:      {$elapsed}s\n";
