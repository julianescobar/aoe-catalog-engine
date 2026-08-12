<?php
/**
 * Enrich the new Bulgin products CSV with the resolved series slug.
 *
 * Inputs:
 *   tools/productosbulginv2.csv   (new Magento export, 164 cols, ';')
 *   tools/bulgin-cat-map.json     (curated slug map + skip_ids + rename_map)
 *   tools/old-product-cats.csv    (OPTIONAL: prod dump sku -> old category)
 *
 * Output: tools/productosbulginv2-serie.csv (same rows + appended "series_slug")
 *
 * Resolution order:
 *   1. Leaf category from product's category_ids (deepest id present in slug_map).
 *   2. (if old dump provided) sku lookup -> translate old slug via rename_map /
 *      redirect_map to the final kept slug.
 *   3. Otherwise series_slug is empty -> import will use "sin-clasificar".
 */

$tools = __DIR__;
$mapFile    = "$tools/bulgin-cat-map.json";
$newFile    = "$tools/productosbulginv2.csv";
$oldFile    = "$tools/old-product-cats.csv";
$outFile    = "$tools/productosbulginv2-serie.csv";

$map = json_decode(file_get_contents($mapFile), true);
if (!$map) die("No se pudo leer $mapFile\n");

$slugMap     = $map['slug_map'];      // new category_id => final slug
$renameMap   = $map['rename_map'];    // old slug => new category_id
$redirectMap = $map['redirect_map'];  // old slug => final slug (301)
$skipIds     = array_flip($map['skip_ids']);

// load real category levels (from categoriasbulginv2.csv) for leaf resolution
$levelFile = "$tools/categoriasbulginv2.csv";
$catLevel = [];   // category_id => level
$catName  = [];   // category_id => name
if (file_exists($levelFile)) {
	$fh = fopen($levelFile, 'r');
	$h = fgetcsv($fh, 0, ';');
	$h[0] = preg_replace('/^\xEF\xBB\xBF/', '', $h[0]);
	$li = array_flip($h);
	while (($r = fgetcsv($fh, 0, ';')) !== false) {
		if (count($r) < count($h)) continue;
		$row = array_combine($h, array_map('trim', $r));
		$catLevel[$row['category_id']] = (int)$row['level'];
		$catName[$row['category_id']]  = $row['name'];
	}
	fclose($fh);
	echo "Niveles de categorías cargados: " . count($catLevel) . "\n";
} else {
	echo "AVISO: no existe $levelFile -> resolverá por heurística de id.\n";
}

// leaf resolver: deepest category of the product that exists in slug_map (and not skipped)
$tieCount = 0;
$tieSamples = [];
function resolve_leaf(array $ids, array $slugMap, array $skipIds, array $catLevel): ?int {
	global $tieCount, $tieSamples;
	$best = null;
	$bestLevel = -1;
	$tie = false;
	foreach ($ids as $id) {
		if ($id <= 0) continue;
		if (isset($skipIds[$id])) continue;
		if (isset($slugMap[$id])) {
			$level = isset($catLevel[$id]) ? $catLevel[$id] : 1;
			if ($level > $bestLevel) {
				$bestLevel = $level;
				$best = $id;
				$tie = false;
			} elseif ($level === $bestLevel) {
				$tie = true;
			}
		}
	}
	if ($tie) {
		$tieCount++;
		if (count($tieSamples) < 5) $tieSamples[] = implode(',', $ids);
	}
	return $best;
}

// load old dump if present
$oldBySku = [];
if (file_exists($oldFile)) {
	$fh = fopen($oldFile, 'r');
	$h = fgetcsv($fh, 0, ';');
	$h[0] = preg_replace('/^\xEF\xBB\xBF/', '', $h[0]);
	$oi = array_flip($h);
	while (($r = fgetcsv($fh, 0, ';')) !== false) {
		if (count($r) < 2) continue;
		$sku = $r[$oi['sku']];
		$oldBySku[$sku] = [
			'slug'  => $r[$oi['old_slug']] ?? '',
			'name'  => $r[$oi['old_name']] ?? '',
			'level' => $r[$oi['old_level']] ?? '',
		];
	}
	fclose($fh);
	echo "Dump viejo cargado: " . count($oldBySku) . " SKUs\n";
} else {
	echo "AVISO: no existe $oldFile -> los productos sin category_ids irán a sin-clasificar.\n";
}

function old_to_slug(string $oldSlug, array $renameMap, array $slugMap, array $redirectMap): string {
	if (isset($renameMap[$oldSlug]) && isset($slugMap[$renameMap[$oldSlug]])) {
		return $slugMap[$renameMap[$oldSlug]];
	}
	if (isset($redirectMap[$oldSlug])) {
		return $redirectMap[$oldSlug];
	}
	return '';
}

// process products
$fh = fopen($newFile, 'r');
$h  = fgetcsv($fh, 0, ';');
$h[0] = preg_replace('/^\xEF\xBB\xBF/', '', $h[0]);
$pi = array_flip($h);

$out = fopen($outFile, 'w');
fputcsv($out, array_merge($h, ['series_slug']), ';');

$stats = ['leaf' => 0, 'old' => 0, 'fallback' => 0];
$line = 0;
while (($r = fgetcsv($fh, 0, ';')) !== false) {
	if (count($r) < count($h)) continue;
	$line++;
	$row = array_combine($h, array_map('trim', $r));
	$sku = $row['sku'];
	$slug = '';

	$ids = array_map('intval', array_filter(explode('|', $row['category_ids'] ?? '')));
	$leaf = resolve_leaf($ids, $slugMap, $skipIds, $catLevel);
	if ($leaf !== null) {
		$slug = $slugMap[$leaf];
		$stats['leaf']++;
	} elseif (isset($oldBySku[$sku])) {
		$slug = old_to_slug($oldBySku[$sku]['slug'], $renameMap, $slugMap, $redirectMap);
		$stats[$slug !== '' ? 'old' : 'fallback']++;
	} else {
		$stats['fallback']++;
	}

	fputcsv($out, array_merge($r, [$slug]), ';');
}
fclose($fh);
fclose($out);

echo "\nProcesados: $line\n";
printf("  con hoja (category_ids): %d\n", $stats['leaf']);
printf("  traducidos del dump viejo: %d\n", $stats['old']);
printf("  sin clasificar: %d\n", $stats['fallback']);
if ($tieCount) {
	echo "  AVISO: $tieCount productos con empate de nivel (2+ hojas al mismo nivel), ej: " . implode(' | ', $tieSamples) . "\n";
}
echo "\nCSV enriquecido: $outFile\n";
