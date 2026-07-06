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
		$this->register_wpo_hooks();
	}

	private function register_wpo_hooks() {
		$mark = function () { update_option( 'aoe_last_asset_update', time() ); };

		add_action( 'upgrader_process_complete', function ( $upgrader, $options ) use ( $mark ) {
			if ( in_array( $options['type'] ?? '', [ 'plugin', 'theme', 'core' ], true ) ) {
				$mark();
			}
		}, 10, 2 );
		add_action( 'activated_plugin', $mark );
		add_action( 'deactivated_plugin', $mark );
		add_action( 'switch_theme', $mark );

		// Set flag when WPO purge happens and assets changed
		add_action( 'wpo_cache_flush', function () {
			$last_update = (int) get_option( 'aoe_last_asset_update', 0 );
			$last_regen  = (int) get_option( 'aoe_last_template_regen', 0 );

			if ( $last_update > $last_regen ) {
				update_option( 'aoe_needs_regen', home_url( '/__gen-template/all/' ) );
			}
		} );

		// AJAX endpoint for client-side flag check
		add_action( 'wp_ajax_aoe_check_regen', function () {
			$url = get_option( 'aoe_needs_regen', '' );
			if ( $url ) {
				delete_option( 'aoe_needs_regen' );
			}
			wp_send_json( [ 'url' => $url ?: '' ] );
		} );

		// Inject window.open on any admin page load when flag is set (catches admin bar purge → page reload)
		add_action( 'admin_footer', function () {
			if ( ! current_user_can( 'manage_options' ) ) return;
			if ( wp_doing_ajax() ) return;

			$url = get_option( 'aoe_needs_regen', '' );
			if ( ! $url ) return;

			delete_option( 'aoe_needs_regen' );
			$url_js = esc_js( $url );
			echo "<script>window.open('$url_js','_blank');</script>";
		}, 100 );

		// Also inject on WPO settings page to catch AJAX purge button
		add_action( 'admin_footer', function () {
			if ( ! current_user_can( 'manage_options' ) ) return;
			if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'wpo_cache' ) return;

			$ajaxurl = esc_js( admin_url( 'admin-ajax.php' ) );
			echo <<<JS
<script>
jQuery('#wp-optimize-purge-cache').on('click', function() {
	var check = setInterval(function() {
		jQuery.get('{$ajaxurl}', { action: 'aoe_check_regen' }, function(r) {
			if (r.url) {
				clearInterval(check);
				window.open(r.url, '_blank');
			}
		});
	}, 500);
	setTimeout(function() { clearInterval(check); }, 30000);
});
</script>
JS;
		}, 100 );

		// Show admin notice when background regeneration completes
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
