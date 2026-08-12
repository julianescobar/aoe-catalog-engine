<?php
/**
 * Generate the .htaccess snippet for Bulgin category slugs that no longer
 * exist in the new catalog (410 Gone) and for merged slugs (301 redirects).
 *
 * Inputs:
 *   tools/bulgin-cat-map.json       (slug_map, redirect_map, gone_slugs, fallback_slugs)
 *   tools/categoriasbulginold.csv   (current DB categories; garbage = slug > 60 chars)
 *
 * Output: tools/bulgin-htaccess.txt
 *
 * The base URL is /catalogo/<slug>/ (flat pages). Adjust CATALOG_BASE if the
 * site runs in a subdirectory (e.g. /tienda/catalogo/).
 */

$tools = __DIR__;
$mapFile    = "$tools/bulgin-cat-map.json";
$newFile    = "$tools/categoriasbulginv2.csv";
$oldFile    = "$tools/categoriasbulginold.csv";
$outFile    = "$tools/bulgin-htaccess.txt";
$docFile    = "$tools/bulgin-slug-map.txt";

$map = json_decode(file_get_contents($mapFile), true);
if (!$map) die("No se pudo leer $mapFile\n");

// new category names (categoriasbulginv2.csv)
$newName = [];
if (file_exists($newFile)) {
	$fh = fopen($newFile, 'r');
	$h = fgetcsv($fh, 0, ';');
	$h[0] = preg_replace('/^\xEF\xBB\xBF/', '', $h[0]);
	$ni = array_flip($h);
	while (($r = fgetcsv($fh, 0, ';')) !== false) {
		if (count($r) < count($h)) continue;
		$row = array_combine($h, array_map('trim', $r));
		$newName[$row['category_id']] = $row['name'];
	}
	fclose($fh);
}

// new category names (current DB old export)
$oldName = [];
if (file_exists($oldFile)) {
	$fh = fopen($oldFile, 'r');
	fgetcsv($fh);
	while (($r = fgetcsv($fh)) !== false) {
		if (count($r) < 4) continue;
		$oldName[trim($r[2])] = trim($r[3]);
	}
	fclose($fh);
}

// URL prefix for catalog category pages (flat pages under the manufacturer slug)
$base = '/catalogo/bulgin/';
if (isset($argv[1]) && $argv[1] !== '') {
	$base = rtrim($argv[1], '/') . '/';
}

// ---- load old slugs ----
$oldSlugs = [];
$oldGarbage = [];
$fh = fopen($oldFile, 'r');
fgetcsv($fh);
while (($r = fgetcsv($fh)) !== false) {
	if (count($r) < 3) continue;
	$slug = trim($r[2]);
	if ($slug === '') continue;
	if (strlen($slug) > 60) {
		$oldGarbage[] = $slug;
	} else {
		$oldSlugs[$slug] = trim($r[3]);
	}
}
fclose($fh);

$slugMap     = $map['slug_map'];       // new category_id => final slug (kept)
$renameMap   = $map['rename_map'];     // old slug => new category_id (kept slugs)
$redirectMap = $map['redirect_map'];   // old slug => final slug (301)
$goneReal    = $map['gone_slugs'];     // old real slugs with no match (410)
$fallback    = $map['fallback_slugs']; // preserved, NOT 410

// garbage slugs (from product names): they never had real pages (verified
// against bulginurlsold.csv), so no 410 rules are needed for them.
$garbageGone = array_values(array_diff($oldGarbage, array_keys($redirectMap)));

// ---- compare against REAL current URLs (bulginurlsold.csv) ----
$urlsFile = "$tools/bulginurlsold.csv";
$current = [];
if (file_exists($urlsFile)) {
	$fh = fopen($urlsFile, 'r');
	$h = fgetcsv($fh);
	$h[0] = preg_replace('/^\xEF\xBB\xBF/', '', $h[0]);
	while (($r = fgetcsv($fh)) !== false) {
		if (count($r) < 1) continue;
		$s = trim($r[0]);
		if ($s === '') continue;
		if ($s === 'bulgin') continue; // root, regenerated
		$current[] = preg_replace('#^bulgin/#', '', $s);
	}
	fclose($fh);
}

$goneSet     = array_flip($goneReal);
$renameSet   = array_flip(array_keys($renameMap));
$redirectSet = array_flip(array_keys($redirectMap));
$fallbackSet = array_flip($fallback);

// Only emit rules for slugs that ACTUALLY exist as current URLs (dead URLs out).
$currentSet  = array_flip($current);
$redirectLive = array_intersect_key($redirectMap, $currentSet);
$goneLive     = array_values(array_intersect($goneReal, $current));

$goneExtra    = []; // pagination (-N) of a gone category -> also 410
$collision301 = []; // slug-<bigid> collision artifact -> 301 to base
$unmapped     = [];
foreach ($current as $s) {
	if (isset($goneSet[$s]) || isset($renameSet[$s]) || isset($redirectSet[$s]) || isset($fallbackSet[$s])) {
		continue;
	}
	if (preg_match('/^(.*)-(\d+)$/', $s, $m)) {
		$base2 = $m[1];
		$n     = (int)$m[2];
		if (isset($goneSet[$base2])) {
			$goneExtra[$s] = true;
			continue;
		}
		if (isset($renameSet[$base2])) {
			if ($n > 100) $collision301[$s] = $base2; // collision artifact (category id)
			continue;
		}
		if (isset($redirectSet[$base2])) {
			$collision301[$s] = $redirectMap[$base2];
			continue;
		}
	}
	$unmapped[] = $s;
}
ksort($goneExtra);
ksort($collision301);

function esc_regex(string $s): string {
	return preg_quote($s, '~');
}

$lines = [];
$lines[] = '# === Bulgin: merged category slugs -> 301 redirects ===';
foreach ($redirectLive as $old => $new) {
	$lines[] = sprintf('RedirectMatch 301 "~^%s%s(/.*)?$~" "%s%s/"', $base, esc_regex($old), $base, $new);
}
foreach ($collision301 as $old => $new) {
	$lines[] = sprintf('# collision artifact (pack-catalog -id suffix), %s no se regenera', $old);
	$lines[] = sprintf('RedirectMatch 301 "~^%s%s(/.*)?$~" "%s%s/"', $base, esc_regex($old), $base, $new);
}

$lines[] = '';
$lines[] = '# === Bulgin: disappeared categories -> 410 Gone ===';
foreach ($goneLive as $slug) {
	$lines[] = sprintf('RedirectMatch 410 "~^%s%s(/.*)?$~"', $base, esc_regex($slug));
}
foreach ($goneExtra as $slug => $v) {
	$lines[] = sprintf('# pagination of gone category, %s no se regenera', $slug);
	$lines[] = sprintf('RedirectMatch 410 "~^%s%s(/.*)?$~"', $base, esc_regex($slug));
}

$out = implode("\n", $lines) . "\n";
file_put_contents($outFile, $out);

// ---- documentation txt (survives the re-import) ----
$doc = [];
$doc[] = 'BULGIN - Mapa de slugs de categorías (generado antes del re-import)';
$doc[] = 'Base URL: ' . $base;
$doc[] = '';

$doc[] = '=== A) CATEGORÍAS QUE SE MANTIENEN (' . count($slugMap) . ') ===';
$renameIds = array_values($renameMap ?? []);
ksort($slugMap, SORT_NUMERIC);
foreach ($slugMap as $nid => $slug) {
	$tag = in_array((int)$nid, $renameIds, true) ? '' : ' [NUEVA slug]';
	$doc[] = sprintf("  %-5s %-42s -> %s%s%s", $nid, $newName[$nid] ?? '', $base, $slug, $tag);
}

$doc[] = '';
$doc[] = '=== B) SLUGS VIEJOS QUE YA NO EXISTEN -> 410 Gone (' . (count($goneLive) + count($goneExtra)) . ') ===';
foreach ($goneLive as $slug) {
	$doc[] = sprintf("  %-52s [%s]", $slug, $oldName[$slug] ?? '');
}
foreach ($goneExtra as $slug => $v) {
	$doc[] = sprintf("  %-52s (paginación de categoría gone)", $slug);
}

$doc[] = '';
$doc[] = '=== C) SLUGS VIEJOS FUSIONADOS -> 301 (' . (count($redirectLive) + count($collision301)) . ') ===';
foreach ($redirectLive as $old => $new) {
	$doc[] = sprintf("  %-52s -> %s%s  [%s]", $old, $base, $new, $oldName[$old] ?? '');
}
foreach ($collision301 as $old => $new) {
	$doc[] = sprintf("  %-52s -> %s%s  (artefacto de colisión)", $old, $base, $new);
}

$doc[] = '';
$doc[] = '=== D) SLUGS EN MAPA SIN URL ACTUAL (no generan reglas) ===';
$dead = array_diff_key($redirectMap, $currentSet);
foreach ($dead as $old => $new) {
	$doc[] = sprintf("  %-52s -> %s%s  (nunca fue URL real)", $old, $base, $new);
}

$doc[] = '';
$doc[] = '=== D) SLUGS BASURA (product-name) ===';
$doc[] = '  Las 52 categorías basura NUNCA tuvieron URL real (verificado contra bulginurlsold.csv): sin reglas .htaccess.';
$doc[] = '  Totales: ' . count($garbageGone) . ' slugs basura en BD vieja.';

$doc[] = '';
$doc[] = '=== E) FALLBACK (se mantienen, NO 410) ===';
foreach ($fallback as $slug) {
	$doc[] = "  $slug";
}

$doc[] = '';
$doc[] = '=== F) VERIFICACIÓN vs URLs actuales (bulginurlsold.csv) ===';
$doc[] = '  Total URLs actuales (sin root): ' . count($current);
$doc[] = '  NO mapeadas: ' . count($unmapped);
foreach ($unmapped as $u) $doc[] = "    $u";

file_put_contents($docFile, implode("\n", $doc) . "\n");

echo "301 redirects: " . (count($redirectLive) + count($collision301)) . "  (" . count($redirectLive) . " fusiones vivas + " . count($collision301) . " colisiones)\n";
echo "410 gone: " . (count($goneLive) + count($goneExtra)) . "  (" . count($goneLive) . " + " . count($goneExtra) . " paginaciones)\n";
echo "Muertas (sin regla): " . count($dead) . "\n";
echo "Basura sin URL (sin reglas): " . count($garbageGone) . "\n";
echo "Categorías mantenidas: " . count($slugMap) . "\n";
echo "URLs actuales comparadas: " . count($current) . " | no mapeadas: " . count($unmapped) . "\n";
echo "Base URL: $base\n";
echo "Snippet .htaccess: $outFile\n";
echo "Documentación: $docFile\n";
