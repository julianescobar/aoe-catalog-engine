<?php
/**
 * CLI: Validate Samtec category structure after import.
 * Usage: php tools/check-samtec-cats.php
 */
if ( ! in_array( PHP_SAPI, [ 'cli', 'cgi-fcgi' ], true ) ) {
	die( 'CLI only' );
}

$wp_load = __DIR__ . '/../../../../../wp-load.php';
if ( ! file_exists( $wp_load ) ) {
	$wp_load = __DIR__ . '/../../../../wp-load.php';
}
if ( ! file_exists( $wp_load ) ) {
	$wp_load = __DIR__ . '/../../../wp-load.php';
}
require_once $wp_load;

global $wpdb;
$table_c = $wpdb->prefix . 'aoe_catalog_categories';
$table_m = $wpdb->prefix . 'aoe_catalog_manufacturers';

$mfr = $wpdb->get_row( "SELECT * FROM $table_m WHERE slug = 'samtec'" );
if ( ! $mfr ) { die( "Samtec not found\n" ); }
echo "Manufacturer: ID={$mfr->id}, Name={$mfr->name}\n";

$mfr_id = (int) $mfr->id;

// Total by level
echo "\n=== Categories by level ===\n";
$by_level = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT level, type, COUNT(1) as cnt FROM $table_c WHERE manufacturer_id = %d GROUP BY level, type ORDER BY level",
		$mfr_id
	)
);
$total = 0;
foreach ( $by_level as $r ) {
	echo "  level={$r->level} type={$r->type}: {$r->cnt}\n";
	$total += (int) $r->cnt;
}
echo "  Total: $total\n";

// Level 0 orphans
echo "\n=== Level 0 orphans (unassigned) ===\n";
$orphan_count = $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(1) FROM $table_c WHERE manufacturer_id = %d AND level = 0",
		$mfr_id
	)
);
echo "  Total: $orphan_count\n";
if ( $orphan_count > 0 ) {
	$orphans = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, name, slug, products_count FROM $table_c WHERE manufacturer_id = %d AND level = 0 ORDER BY products_count DESC LIMIT 10",
			$mfr_id
		)
	);
	foreach ( $orphans as $o ) {
		echo "  ID={$o->id} name={$o->name} slug={$o->slug} products={$o->products_count}\n";
	}
}

// Sin clasificar
echo "\n=== Sin clasificar ===\n";
$uncat = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT id, level, name, slug, parent_id, products_count FROM $table_c WHERE manufacturer_id = %d AND slug = 'sin-clasificar'",
		$mfr_id
	)
);
if ( empty( $uncat ) ) {
	echo "  (none)\n";
} else {
	foreach ( $uncat as $u ) {
		$parent_name = $u->parent_id ? $wpdb->get_var( "SELECT name FROM $table_c WHERE id = $u->parent_id" ) : 'NULL';
		echo "  ID={$u->id} level={$u->level} name={$u->name} parent_id={$u->parent_id} parent_name=$parent_name products={$u->products_count}\n";
	}
}

// Level 1 categories
echo "\n=== Level 1 categories (top-level) ===\n";
$l1 = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT id, name, slug, products_count FROM $table_c WHERE manufacturer_id = %d AND level = 1 AND slug != 'sin-clasificar' ORDER BY name",
		$mfr_id
	)
);
foreach ( $l1 as $c ) {
	$subs = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(1) FROM $table_c WHERE manufacturer_id = %d AND parent_id = %d",
			$mfr_id, $c->id
		)
	);
	echo "  ID={$c->id} name={$c->name} slug={$c->slug} products={$c->products_count} children=$subs\n";
}
echo "  Total level 1: " . count( $l1 ) . "\n";

echo "\nDone.\n";
