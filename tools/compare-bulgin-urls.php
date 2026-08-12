<?php
/**
 * Compare current Bulgin catalog URLs (from wp_aoe_catalog_pregenerated_pages)
 * against bulgin-cat-map.json to classify each: KEPT / REDIRECT / GONE / UNMAPPED.
 *
 * Inputs:
 *   tools/bulginurlsold.csv    (slug column, e.g. "bulgin/6000-series-buccaneer")
 *   tools/bulgin-cat-map.json
 */

$tools = __DIR__;
$urlsFile = "$tools/bulginurlsold.csv";
$mapFile  = "$tools/bulgin-cat-map.json";

$map = json_decode(file_get_contents($mapFile), true);
if (!$map) die("No se pudo leer $mapFile\n");

$renameMap   = $map['rename_map'];     // old slug => new id (kept)
$slugMap     = $map['slug_map'];       // new id => final slug
$redirectMap = $map['redirect_map'];   // old slug => final slug (301)
$gone        = array_flip($map['gone_slugs']);
$fallback    = array_flip($map['fallback_slugs']);

$keptIds = array_values($renameMap);   // slugs whose id exists in new catalog

// load current slugs
$slugs = [];
$fh = fopen($urlsFile, 'r');
$h = fgetcsv($fh);
$h[0] = preg_replace('/^\xEF\xBB\xBF/', '', $h[0]);
while (($r = fgetcsv($fh)) !== false) {
	if (count($r) < 1) continue;
	$s = trim($r[0]);
	if ($s !== '') $slugs[] = $s;
}
fclose($fh);

function classify(string $base, array $renameMap, array $redirectMap, array $gone, array $fallback, array $slugMap): string {
	if (isset($gone[$base])) return 'GONE';
	if (isset($fallback[$base])) return 'FALLBACK';
	if (isset($redirectMap[$base])) return 'REDIRECT';
	if (isset($renameMap[$base])) return 'KEPT';
	return 'UNMAPPED';
}

$stats = ['KEPT' => 0, 'REDIRECT' => 0, 'GONE' => 0, 'FALLBACK' => 0, 'UNMAPPED' => 0, 'ROOT' => 0];
$lines = [];
$unmapped = [];
$pagOfGone = [];
foreach ($slugs as $s) {
	if ($s === 'bulgin') {
		$stats['ROOT']++;
		$lines[] = "$s  => ROOT (se regenera)";
		continue;
	}
	$base = preg_replace('#^bulgin/#', '', $s);
	$cls = classify($base, $renameMap, $redirectMap, $gone, $fallback, $slugMap);
	$suffix = '';

	if ($cls === 'UNMAPPED') {
		// pagination/collision variant? strip trailing -<digits>
		if (preg_match('/^(.*)-\d+$/', $base, $m)) {
			$base2 = $m[1];
			$cls2  = classify($base2, $renameMap, $redirectMap, $gone, $fallback, $slugMap);
			if ($cls2 !== 'UNMAPPED') {
				$suffix = "  (paginación/variante de '$base2' => $cls2)";
				if ($cls2 === 'GONE') $pagOfGone[] = $s;
				$cls = $cls2;
			}
		}
	}

	if ($cls === 'UNMAPPED') {
		$unmapped[] = $s;
		$lines[] = "$s  => *** UNMAPPED ***";
	} else {
		$stats[$cls]++;
		$extra = '';
		if ($cls === 'REDIRECT') $extra = "  -> " . $redirectMap[$base];
		if ($cls === 'KEPT')     $extra = "  (se mantiene)";
		if ($cls === 'GONE')     $extra = "  (410)";
		if ($cls === 'FALLBACK') $extra = "  (fallback, se mantiene)";
		$lines[] = "$s  => $cls$extra$suffix";
	}
}

echo "=== CLASIFICACIÓN DE URLs ACTUALES ===\n\n";
echo implode("\n", $lines) . "\n\n";
echo "=== RESUMEN ===\n";
foreach ($stats as $k => $v) echo str_pad($k, 10) . " $v\n";
echo "Total: " . count($slugs) . "\n";
if ($pagOfGone) echo "\nPaginaciones de categorías GONE (también 410):\n  " . implode("\n  ", $pagOfGone) . "\n";
if ($unmapped) {
	echo "\n*** NO MAPEADAS (habría que decidir) ***\n";
	foreach ($unmapped as $u) echo "  $u\n";
}
