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
		// Remove WordPress shortlink from <head>
		remove_action( 'wp_head', 'wp_shortlink_wp_head' );

		$this->register_admin_notices();
	}

	private function register_admin_notices() {
		// Show admin notice when a manual template regeneration completes
		add_action( 'admin_notices', function () {
			$completed = (int) get_option( 'aoe_regen_just_completed', 0 );
			if ( $completed > time() - 120 ) {
				delete_option( 'aoe_regen_just_completed' );
				$time = wp_date( 'H:i:s', $completed );
				echo '<div class="notice notice-success is-dismissible"><p>';
				echo '✅ Regeneración de templates del catálogo completada a las <strong>' . esc_html( $time ) . '</strong>.';
				echo '</p></div>';
			}
		} );
	}
}
