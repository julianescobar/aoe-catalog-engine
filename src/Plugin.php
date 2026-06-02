<?php

namespace AOE\CatalogEngine;

class Plugin {

	private $processor_manager;
	private $admin_manager;
	private $batch_processor;
	private $public_manager;

	public function __construct() {
		$this->load_dependencies();
	}

	private function load_dependencies() {
		$this->processor_manager = new \AOE\CatalogEngine\Import\ProcessorManager();
		$this->batch_processor   = new \AOE\CatalogEngine\Import\BatchProcessor( $this->processor_manager );
		$this->public_manager    = new \AOE\CatalogEngine\PublicFacing\PublicManager();

		if ( is_admin() ) {
			$this->admin_manager = new \AOE\CatalogEngine\Admin\AdminManager( $this->processor_manager );
		}
	}

	public function run() {
		// Initialization logic if necessary
	}
}
