<?php

namespace AOE\CatalogEngine;

use AOE\CatalogEngine\Database\Schema;

class Activator {

	public static function activate() {
		Schema::create_tables();
		
		// Register rewrite rules and flush
		require_once plugin_dir_path( __DIR__ ) . 'src/PublicFacing/PublicManager.php';
		$public_manager = new \AOE\CatalogEngine\PublicFacing\PublicManager();
		$public_manager->register_rewrite_rules();
		flush_rewrite_rules();
	}
}
