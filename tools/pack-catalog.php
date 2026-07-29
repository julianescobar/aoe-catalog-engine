<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('memory_limit', '1024M');
set_time_limit(0);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cgi-fcgi') die('CLI only');

$slug = $argv[1] ?? '';
if (!$slug) die("Usage: php tools/pack-catalog.php <slug>\n");

$w = __DIR__ . '/../../../../../wp-load.php';
if (!file_exists($w)) { $w = __DIR__ . '/../../../../wp-load.php'; }
if (!file_exists($w)) { $w = __DIR__ . '/../../../wp-load.php'; }
require_once $w;

global $wpdb;
$mfr = $wpdb->get_row($wpdb->prepare(
	"SELECT * FROM {$wpdb->prefix}aoe_catalog_manufacturers WHERE slug = %s", $slug
));
if (!$mfr) die("Not found\n");
$mfr_id = (int)$mfr->id;
$mfr_name = $mfr->name;
$tp = $wpdb->prefix . 'aoe_catalog_pregenerated_pages';
$ts = $wpdb->prefix . 'aoe_catalog_page_segments';
$tc = $wpdb->prefix . 'aoe_catalog_categories';
$tpr = $wpdb->prefix . 'aoe_catalog_products';
$per = 200;

echo "=== Regenerating $mfr_name ===\n\n";

echo "1. Clearing old pages...\n";
$wpdb->delete($tp, ['manufacturer_id' => $mfr_id], ['%d']);
$wpdb->delete($ts, ['manufacturer_id' => $mfr_id], ['%d']);

echo "2. Updating products_count...\n";
$wpdb->query("UPDATE $tc c LEFT JOIN(SELECT category_id,COUNT(*)cnt FROM $tpr WHERE manufacturer_id=$mfr_id GROUP BY category_id)p ON p.category_id=c.id SET c.products_count=COALESCE(p.cnt,0) WHERE c.manufacturer_id=$mfr_id");

echo "3. Fetching categories...\n";
$cats = $wpdb->get_results("SELECT id,name,slug,level,products_count,description,metadata_json,image FROM $tc WHERE manufacturer_id=$mfr_id AND (products_count>0 OR description!='' OR (metadata_json NOT IN('[]','{}',''))) ORDER BY id ASC");
$total = count($cats);
echo "   Total: $total\n";
if (!$total) { echo "Nothing to do.\n"; exit; }

echo "4. Separating large/small...\n";
$large = []; $small = [];
foreach ($cats as $c) {
	$m2 = !empty($c->metadata_json) && $c->metadata_json !== '[]' ? json_decode($c->metadata_json, true) : [];
	$hc = !empty($c->description) || !empty($c->image) || (is_array($m2) && (!empty($m2['features'])||!empty($m2['highlights'])||!empty($m2['image_url'])));
	if ((int)$c->products_count >= 190 || $hc || $slug === 'camdenboss') $large[] = $c; else $small[] = $c;
}
echo "   Large: " . count($large) . ", Small: " . count($small) . "\n";

$sb = [];
function fs(&$b) {
	if (!$b) return; global $wpdb, $ts;
	$v = []; $p = [];
	foreach ($b as $s) { $p[] = '(%d,%d,%d,"category",%d,%d,%d)'; $v = array_merge($v, [$s['page_id'],$s['manufacturer_id'],$s['category_id'],$s['products_from'],$s['products_to'],$s['sort_order']]); }
	$sql = "INSERT INTO $ts(page_id,manufacturer_id,category_id,segment_type,products_from,products_to,sort_order) VALUES " . implode(',', $p);
	$wpdb->query($wpdb->prepare($sql, $v));
	$b = [];
}

echo "5. Creating category pages...\n";
$cnt = 0; $used = [];
foreach ($large as $c) {
	if ($slug === 'samtec' && (int)$c->level === 1) continue;
	$cm = !empty($c->metadata_json) ? json_decode($c->metadata_json, true) : [];
	$has_post = !empty($cm['wp_post_id']);
	$total_p = (int)$c->products_count;
	$pages = max(1, ceil($total_p / $per));
	$start = $has_post ? 2 : 1;
	for ($p = $start; $p <= $pages; $p++) {
		$ps = $slug . '/' . $c->slug . ($p > 1 ? '-' . $p : '');
		if (isset($used[$ps])) $ps .= '-' . $c->id;
		$used[$ps] = true;
		$from = ($p-1)*$per; $to = min($p*$per, $total_p);
		$wpdb->insert($tp, ['manufacturer_id'=>$mfr_id,'type'=>'category','slug'=>$ps,'page_number'=>$p,'link_count'=>$to-$from], ['%d','%s','%s','%d','%d']);
		$sb[] = ['page_id'=>(int)$wpdb->insert_id,'manufacturer_id'=>$mfr_id,'category_id'=>$c->id,'products_from'=>$from,'products_to'=>$to,'sort_order'=>1];
		$cnt++;
		if (count($sb) >= 300) { fs($sb); echo "  $cnt cat pages\n"; }
	}
}
fs($sb);
echo "   Done: $cnt category pages\n";

echo "6. Creating tree pages...\n";
$all = $wpdb->get_results("SELECT id,slug,parent_id,level,products_count FROM $tc WHERE manufacturer_id=$mfr_id ORDER BY COALESCE(parent_id,0) ASC,level ASC,id ASC");
$tcnt = 0;
if ($all) {
	$pl = []; $cb = [];
	foreach ($all as $c) { $pl[(int)$c->id] = (int)$c->parent_id; $cb[(int)$c->id] = $c; }
	$l4 = []; foreach ($all as $c) { if ((int)$c->level === 4) $l4[] = $c; }

	if ($slug === 'samtec') {
		echo "   Samtec mode\n";
		$l4b1 = [];
		foreach ($l4 as $it) {
			$cur = (int)$it->parent_id; $f = true;
			$iter = 0;
			while ($cur && $iter < 100) { $iter++;
				$cc = $cb[$cur] ?? null;
				if ($cc) {
					if ((int)$cc->level === 1) { $l4b1[$cur][] = $it; break; }
					if ($f && $cc->slug === 'sin-clasificar') { $l4b1[$cur][] = $it; break; }
				}
				$f = false; $cur = $pl[$cur] ?? 0;
			}
			if ($iter >= 100) echo "   WARN: infinite loop protection for item #$it->id\n";
		}

		$l1 = [];
		foreach ($all as $c) { if ((int)$c->level === 1 || $c->slug === 'sin-clasificar') $l1[] = $c; }
		$fid = null; foreach ($l1 as $c) { if ($c->slug === 'sin-clasificar') continue; $fid = (int)$c->id; break; }

		echo "   Level-1: " . count($l1) . ", first: $fid\n";

		build_and_save_tree($slug, $tp, $ts, $mfr_id, $l4b1, $l1, $fid, $all, $pl, $cb, $per, $sb, $tcnt);

		echo "   Done: $tcnt tree pages\n";
	} else {
		echo "   Non-samtec mode\n";
		$sc = null; foreach ($all as $c) { if ($c->slug === 'sin-clasificar') { $sc = $c; break; } }
		if ($sc) {
			$sid = (int)$sc->id; $us = []; $ot = [];
			foreach ($l4 as $it) { if ((int)$it->parent_id === $sid) $us[] = $it; else $ot[] = $it; }
			$l4 = array_merge($ot, $us);
		}
		if ($l4) {
			$chs = array_chunk($l4, $per); $ti = 1;
			foreach ($chs as $ch) {
				$aid = []; foreach ($ch as $it) { $cur = (int)$it->parent_id; while ($cur && count($aid) < 1000) { $aid[$cur] = true; $cur = $pl[$cur] ?? 0; } }
				$an = []; foreach(array_keys($aid) as $k) { if(isset($cb[$k])) $an[] = $cb[$k]; }
				usort($an, function($a,$b) {
					if(($a->slug??'')==='sin-clasificar'&&($b->slug??'')!=='sin-clasificar') return 1;
					if(($b->slug??'')==='sin-clasificar'&&($a->slug??'')!=='sin-clasificar') return -1;
					$c = (int)$a->level-(int)$b->level; if($c!==0) return $c;
					$pa=(int)($a->parent_id?:0); $pb=(int)($b->parent_id?:0); $c=$pa-$pb;
					return $c!==0?$c:(int)$a->id-(int)$b->id;
				});
				$segs = []; foreach($an as $a){$segs[]=['manufacturer_id'=>$mfr_id,'category_id'=>$a->id,'segment_type'=>'category','products_from'=>0,'products_to'=>(int)$a->products_count];}
				foreach($ch as $it){$segs[]=['manufacturer_id'=>$mfr_id,'category_id'=>$it->id,'segment_type'=>'category','products_from'=>0,'products_to'=>(int)$it->products_count];}
				$tslug = $slug . ($ti > 1 ? '-' . $ti : '');
				$wpdb->insert($tp, ['manufacturer_id'=>$mfr_id,'type'=>'tree','slug'=>$tslug,'page_number'=>$ti,'link_count'=>count($ch)], ['%d','%s','%s','%d','%d']);
				$pid = (int)$wpdb->insert_id;
				foreach ($segs as $i=>$s) { $sb[] = $s + ['page_id'=>$pid, 'sort_order'=>$i+1]; }
				fs($sb); $tcnt++; $ti++;
			}
		}
	}
}
fs($sb);
echo "   Tree pages: $tcnt\n";

echo "7. Grouped pages...\n";
$gcnt = 0;
if ($small) {
	$gi = 1; $ga = 0; $gs = [];
	foreach ($small as $c) {
		if ($slug === 'samtec' && (int)$c->level === 1) continue;
		$cnt2 = (int)$c->products_count;
		if ($ga + $cnt2 > $per && $ga > 0) {
			$ps = $slug . '/productos' . ($gi > 1 ? '-' . $gi : '');
			$wpdb->insert($tp, ['manufacturer_id'=>$mfr_id,'type'=>'grouped','slug'=>$ps,'page_number'=>$gi,'link_count'=>$ga], ['%d','%s','%s','%d','%d']);
			$pid = (int)$wpdb->insert_id;
			foreach ($gs as $i=>$s) { $sb[] = $s + ['page_id'=>$pid, 'sort_order'=>$i+1]; }
			fs($sb); $gcnt++; $gi++; $ga = 0; $gs = [];
		}
		$gs[] = ['manufacturer_id'=>$mfr_id,'category_id'=>$c->id,'segment_type'=>'category','products_from'=>0,'products_to'=>$cnt2];
		$ga += $cnt2;
	}
	if ($gs) {
		$ps = $slug . '/productos' . ($gi > 1 ? '-' . $gi : '');
		$wpdb->insert($tp, ['manufacturer_id'=>$mfr_id,'type'=>'grouped','slug'=>$ps,'page_number'=>$gi,'link_count'=>$ga], ['%d','%s','%s','%d','%d']);
		$pid = (int)$wpdb->insert_id;
		foreach ($gs as $i=>$s) { $sb[] = $s + ['page_id'=>$pid, 'sort_order'=>$i+1]; }
		fs($sb); $gcnt++;
	}
}
echo "   Grouped: $gcnt\n";

echo "\n=== Done ===\n";
echo "  Cat pages: $cnt\n";
echo "  Tree pages: $tcnt\n";
echo "  Grouped: $gcnt\n";

function build_and_save_tree($slug, $tp, $ts, $mfr_id, &$l4b1, &$l1, $fid, &$all, &$pl, &$cb, $per, &$sb, &$tcnt) {
	// Move sin-clasificar to end within each level-1 group
	foreach ($l1 as $l) {
		if ($l->slug === 'sin-clasificar') continue;
		$lid = (int)$l->id;
		if (!empty($l4b1[$lid])) {
			$us = []; $ot = []; $sid = null;
			foreach ($all as $c) { if ($c->slug === 'sin-clasificar' && (int)$c->parent_id === $lid) { $sid = (int)$c->id; break; } }
			if ($sid) {
				foreach ($l4b1[$lid] as $it) { if ((int)$it->parent_id === $sid) $us[] = $it; else $ot[] = $it; }
				$l4b1[$lid] = array_merge($ot, $us);
			}
		}
	}

	global $wpdb;
	$bs = function($its) use ($mfr_id, $pl, $cb) {
		$aid = []; foreach ($its as $it) { $cur = (int)$it->parent_id; while ($cur) { $aid[$cur] = true; $cur = $pl[$cur] ?? 0; } }
		$an = []; foreach(array_keys($aid) as $k) { if(isset($cb[$k])) $an[] = $cb[$k]; }
		usort($an, function($a,$b) {
			if(($a->slug??'')==='sin-clasificar'&&($b->slug??'')!=='sin-clasificar') return 1;
			if(($b->slug??'')==='sin-clasificar'&&($a->slug??'')!=='sin-clasificar') return -1;
			$c = (int)$a->level-(int)$b->level; if($c!==0) return $c;
			$pa=(int)($a->parent_id?:0); $pb=(int)($b->parent_id?:0); $c=$pa-$pb;
			return $c!==0?$c:(int)$a->id-(int)$b->id;
		});
		$s = []; foreach($an as $a) { $s[] = ['manufacturer_id'=>$mfr_id,'category_id'=>$a->id,'segment_type'=>'category','products_from'=>0,'products_to'=>(int)$a->products_count]; }
		foreach($its as $it) { $s[] = ['manufacturer_id'=>$mfr_id,'category_id'=>$it->id,'segment_type'=>'category','products_from'=>0,'products_to'=>(int)$it->products_count]; }
		return $s;
	};

	if ($fid !== null) {
		$its = $l4b1[$fid] ?? [];
		$segs = $bs($its);
		$wpdb->insert($tp, ['manufacturer_id'=>$mfr_id,'type'=>'tree','slug'=>$slug,'page_number'=>1,'link_count'=>count($its)], ['%d','%s','%s','%d','%d']);
		$pid = (int)$wpdb->insert_id;
		foreach ($segs as $i=>$s) { $sb[] = $s + ['page_id'=>$pid, 'sort_order'=>$i+1]; }
		fs($sb); $tcnt++;
		echo "   Main tree page ($slug)\n";
	}

	foreach ($l1 as $l) {
		if ($fid !== null && (int)$l->id === $fid) continue;
		$lid = (int)$l->id;
		$chs = array_chunk($l4b1[$lid] ?? [], $per);
		$bi = 0;
		foreach ($chs as $ch) {
			$bi++;
			$segs = $bs($ch);
			$ps = $slug . '/' . $l->slug . ($bi > 1 ? '-' . $bi : '');
			$wpdb->insert($tp, ['manufacturer_id'=>$mfr_id,'type'=>'tree','slug'=>$ps,'page_number'=>$bi,'link_count'=>count($ch)], ['%d','%s','%s','%d','%d']);
			$pid = (int)$wpdb->insert_id;
			foreach ($segs as $i=>$s) { $sb[] = $s + ['page_id'=>$pid, 'sort_order'=>$i+1]; }
			fs($sb); $tcnt++;
			echo "   Subtree: $ps\n";
		}
	}
}
