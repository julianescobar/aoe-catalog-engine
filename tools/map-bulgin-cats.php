<?php
/**
 * Build the definitive Bulgin category slug map (curated).
 *
 * Inputs: categoriasbulginold.csv (current DB), categoriasbulginv2.csv (new Magento export).
 *
 * Rules:
 *  - GROUPING ids (level 4 nodes that only group level-5 series) are skipped: their
 *    level-5 children are the real product categories.
 *  - old slugs matching a new category by exact slug are kept as-is.
 *  - CURATED: old slug -> new category_id for renames (the new category keeps the OLD slug).
 *  - new categories not covered get sanitize_title(name).
 *  - old real slugs with no match => GONE (410 candidates).
 *  - fallback slugs (uncategorized / sin-clasificar) are preserved, not 410.
 *
 * Outputs report to stdout and tools/bulgin-cat-map.json.
 */

$oldFile = __DIR__ . '/categoriasbulginold.csv';
$newFile = __DIR__ . '/categoriasbulginv2.csv';
$outJson = __DIR__ . '/bulgin-cat-map.json';

$fallbackSlugs = ['uncategorized', 'sin-clasificar'];

// Grouping nodes (level 4) that only contain level-5 series: skip as categories.
$skipIds = [696, 1121, 1122, 1123, 1124, 1125, 1126, 1127, 1128, 1129, 1130, 1131, 1132, 1133, 1134, 1135, 1137, 1138, 1139];

// Curated renames: old slug => new category_id (new category keeps the OLD slug).
$curated = [
    'circular-power-connectors' => 439,
    'circular-data-connectors' => 444,
    'switch-range' => 457,
    'indicators' => 468,
    'circular-automation-connectors' => 652,
    'circular-fiber-connectors' => 848,
    'rectangular-connectors' => 853,
    'radio-frequency-connectors' => 863,
    'battery-holders' => 461,
    'iec-connectors' => 462,
    'power-entry-modules' => 465,
    'photoelectric-sensors' => 842,
    '5000-series-expanded-beam' => 875,
    'standard-rectangular-connectors' => 854,
    'miniature-rectangular-connectors' => 904,
    'power-rectangular-connectors' => 905,
    'automotive-rectangular-connectors' => 906,
    '1-85mm-series' => 864,
    '2-40mm-series' => 865,
    '2-92mm-series' => 866,
    'n-type-series' => 867,
    'sma-series' => 868,
    'ssma-series' => 869,
    'tnca-series' => 870,
    'rf-adapter-series' => 871,
    'rf-termination-series' => 872,
    'm-series-distribution-units' => 658,
    'm5-series' => 660,
    'm8-series' => 661,
    'm12-series' => 662,
    'm16-series' => 663,
    'm23-series' => 664,
    '7-8-series' => 909,
    'standard-vitalis-buccaneer' => 1116,
    'rocker-switches' => 600,
    'refrigerator-switches' => 604,
    'voltage-selector' => 459,
    'vandal-resistant-switches' => 921,
    'ao-series-active-optical-hdmi' => 923,
    '900-series-buccaneer' => 447,
    '7000-series-buccaneer' => 443,
    '6000-series-buccaneer' => 442,
    '400-series-buccaneer' => 453,
    '4000-series-buccaneer' => 572,
    '9000-series-high-power-buccaneer' => 862,
    'standard-buccaneer' => 450,
    'mini-buccaneer' => 441,
    'explora' => 440,
    '600-series-ethernet-buccaneer-connectors' => 908,
    'standard-buccaneer-ethernet' => 451,
    'standard-buccaneer-usb' => 452,
    'standard-buccaneer-hdmi' => 910,
    '6000-series-usb-buccaneer' => 445,
    '6000-series-ethernet' => 446,
    '6000-series-hdmi-buccaneer' => 922,
    '20-series-usb' => 914,
    '30-series-hdmi' => 915,
    '4000-series-micro-usb-buccaneer' => 573,
    '4000-series-c-type-usb-buccaneer' => 840,
    '4000-series-spe' => 913,
    '400-series-smb-buccaneer' => 454,
    '400-series-mini-usb-buccaneer' => 455,
    'ao-series-usb' => 920,
    '4000-series-simplex-lc-fiber-buccaneer' => 849,
    '6000-series-duplex-lc-fiber-buccaneer' => 852,
    'push-pull-connectors-x-series' => 873,
    'push-pull-connectors-y-series' => 874,
    'push-button' => 458,
    'fuseholders' => 724,
    'be-enclosures' => 543,
    'be-enclosure-accessories' => 456,
    'mains-filters' => 466,
    'iec-distribution-units' => 464,
    'sensors' => 841,
];

// Merged/duplicate old slugs that should 301-redirect to a kept new category (NOT 410).
$redirects = [
    'circular-power-connector' => 439,
    'data-connectors' => 444,
    'switches' => 457,
    'vandal-resistant' => 921,
    '6000-series-ethernet-buccaneer' => 446,
    '4mm-led-indicators' => 468,
    '5mm-led-indicators' => 468,
    '6mm-vandal-resistant-indicator-dx-range' => 468,
    '8mm-vandal-resistant-indicator-dx-range' => 468,
    'indicator-lights' => 468,
    'low-voltage-lampholder' => 468,
    'low-voltage-lampholders' => 468,
    'vandal-resistant-led-indicators' => 468,
    'automotive-rectangular-connectors-contact-bandoliers' => 906,
    'automotive-rectangular-connectors-seal' => 906,
    'miniature-rectangular-connectors-wedgelocks' => 904,
    'minituare-rectangular-connectors-contact-bandoliers' => 904,
    'power-rectangular-connectors-contact-bandoliers' => 905,
    'power-rectangular-connectors-wedgelocks' => 905,
    'standard-rectangular-connectors-contacts' => 854,
];

function loadOld($file)
{
    $rows = [];
    $fh = fopen($file, 'r');
    if ($fh === false) {
        fwrite(STDERR, "Can't open $file\n");
        exit(1);
    }
    fgetcsv($fh);
    while (($r = fgetcsv($fh)) !== false) {
        if (count($r) < 4) continue;
        $rows[] = [
            'level'     => trim($r[0]),
            'parent_id' => trim($r[1]),
            'slug'      => trim($r[2]),
            'name'      => trim($r[3]),
        ];
    }
    fclose($fh);
    return $rows;
}

function loadNew($file)
{
    $rows = [];
    $fh = fopen($file, 'r');
    if ($fh === false) {
        fwrite(STDERR, "Can't open $file\n");
        exit(1);
    }
    $header = fgetcsv($fh, 0, ';');
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
    $cols = array_map('trim', $header);
    while (($r = fgetcsv($fh, 0, ';')) !== false) {
        if (count($r) < count($cols)) continue;
        $row = array_combine($cols, array_map('trim', $r));
        if (isset($row['is_active']) && $row['is_active'] !== '1') continue;
        $rows[] = $row;
    }
    fclose($fh);
    return $rows;
}

function sanitize($s)
{
    $s = preg_replace('/[^\x00-\x7F]/', ' ', (string)$s);
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    return preg_replace('/-+/', '-', $s);
}

// ---- load ----
$oldAll = loadOld($oldFile);
$newAll = loadNew($newFile);

$oldReal = [];
$oldGarbage = [];
foreach ($oldAll as $o) {
    if (strlen($o['slug']) > 60) {
        $oldGarbage[] = $o;
    } else {
        $oldReal[] = $o;
    }
}

$newById = [];
foreach ($newAll as $n) {
    $n['slug'] = sanitize($n['name']);
    $newById[$n['category_id']] = $n;
}

$skipSet = array_flip($skipIds);
$curatedSet = array_fill_keys(array_keys($curated), true);
$redirectSet = array_fill_keys(array_keys($redirects), true);

// ---- validation: every curated old slug exists, every curated id is a real new id ----
$oldSlugs = [];
foreach ($oldReal as $o) {
    $oldSlugs[$o['slug']] = true;
}
$missing = [];
foreach ($curated as $os => $nid) {
    if (isset($redirectSet[$os])) $missing[] = "old slug '$os' está en curated y en redirects (conflicto)";
    if (!isset($oldSlugs[$os])) $missing[] = "old slug '$os' no existe en BD";
    if (!isset($newById[$nid])) $missing[] = "id nuevo $nid (para '$os') no existe en CSV";
    if (isset($skipSet[$nid])) $missing[] = "id nuevo $nid (para '$os') está en skip";
}
foreach ($redirects as $os => $nid) {
    if (!isset($oldSlugs[$os])) $missing[] = "redirect: old slug '$os' no existe en BD";
    if (!isset($newById[$nid])) $missing[] = "redirect: id nuevo $nid no existe en CSV";
}
if ($missing) {
    echo "ERRORES DE VALIDACIÓN:\n";
    foreach ($missing as $m) echo "  - $m\n";
    exit(1);
}

// ---- assign final slug per new id ----
$idSlug = [];      // new category_id => final slug
$slugOld = [];     // old slug => new category_id (kept slug)
foreach ($curated as $os => $nid) {
    $idSlug[$nid] = $os;
    $slugOld[$os] = $nid;
}

// exact slug matches for the rest
foreach ($newById as $nid => $n) {
    if (isset($skipSet[$nid])) continue;
    if (isset($idSlug[$nid])) continue;
    if (isset($oldSlugs[$n['slug']]) && !isset($slugOld[$n['slug']])) {
        $idSlug[$nid] = $n['slug'];
        $slugOld[$n['slug']] = $nid;
    }
}

// remaining new ids => sanitized name
$newSlugs = [];
foreach ($newById as $nid => $n) {
    if (isset($skipSet[$nid])) continue;
    if (!isset($idSlug[$nid])) {
        $idSlug[$nid] = $n['slug'];
        $newSlugs[$nid] = $n['slug'];
    }
}

// gone = old real slugs not matched and not redirected
$gone = [];
foreach ($oldReal as $o) {
    if (isset($slugOld[$o['slug']])) continue;
    if (isset($redirectSet[$o['slug']])) continue;
    if (in_array($o['slug'], $fallbackSlugs, true)) continue;
    $gone[] = $o;
}

// ---- collision check ----
$bySlug = [];
foreach ($idSlug as $nid => $slug) {
    $bySlug[$slug][] = $nid;
}
$collisions = 0;
foreach ($bySlug as $slug => $ids) {
    if (count($ids) > 1) $collisions++;
}

// ---- report ----
echo "OLD totales: " . count($oldAll) . " (reales: " . count($oldReal) . ", basura: " . count($oldGarbage) . ")\n";
echo "NEW activas: " . count($newAll) . " | skip (agrupaciones): " . count($skipIds) . "\n\n";

echo "=== A) Slug conservado por renombre (categoría nueva usa slug ACTUAL) ===\n";
$renames = array_keys($curated);
sort($renames);
foreach ($renames as $os) {
    printf("  %-52s -> %-5s %s\n", $os, $curated[$os], $newById[$curated[$os]]['name']);
}
echo "Total: " . count($renames) . "\n\n";

echo "=== B) NUEVAS con slug por defecto (sanitize nombre) ===\n";
foreach ($newSlugs as $nid => $slug) {
    printf("  %-5s %-42s -> %s\n", $nid, $newById[$nid]['name'], $slug);
}
echo "Total: " . count($newSlugs) . "\n\n";

echo "=== C) REDIRECTS 301 (slug viejo fusionado -> categoría nueva) ===\n";
foreach ($redirects as $os => $nid) {
    printf("  %-52s -> %-5s %s  (url final: %s)\n", $os, $nid, $newById[$nid]['name'], $idSlug[$nid]);
}
echo "Total: " . count($redirects) . "\n\n";

echo "=== D) DESAPARECIDAS (candidatas a 410) ===\n";
foreach ($gone as $o) {
    printf("  %-52s [%s]\n", $o['slug'], $o['name']);
}
echo "Total: " . count($gone) . "\n\n";

echo "=== E) Colisiones de slug (deben ser 0) ===\n";
foreach ($bySlug as $slug => $ids) {
    if (count($ids) > 1) {
        echo "  $slug: " . implode(', ', $ids) . "\n";
    }
}
if ($collisions === 0) echo "  (ninguna)\n\n";

echo "=== F) Fallback ===\n";
echo "  uncategorized / sin-clasificar se conservan (no 410)\n\n";

// ---- JSON ----
$redirectMap = [];
foreach ($redirects as $os => $nid) {
    $redirectMap[$os] = $idSlug[$nid]; // old slug => final kept slug
}
$renameMap = [];
foreach ($slugOld as $os => $nid) {
    $renameMap[$os] = $nid;                // old slug => new category_id (kept slug / renamed)
}
$json = [
    'slug_map' => $idSlug,                 // new category_id => final slug
    'rename_map' => $renameMap,            // old slug => new category_id (reverse of kept slugs)
    'gone_slugs' => array_column($gone, 'slug'),
    'redirect_map' => $redirectMap,        // old slug => final slug (301)
    'fallback_slugs' => $fallbackSlugs,
    'skip_ids' => $skipIds,                // grouping ids to ignore
];
file_put_contents($outJson, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "JSON escrito: $outJson\n";
echo "Colisiones: $collisions | Renombres: " . count($renames) . " | Nuevas default: " . count($newSlugs) . " | Redirects: " . count($redirects) . " | Gone: " . count($gone) . "\n";
