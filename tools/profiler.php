<?php
/**
 * Lightweight inline profiler for catalog pages.
 *
 * Enable by adding ?aoe_profile=1 to any catalog URL.
 * Writes timing log to wp-content/uploads/aoe-profile.log
 *
 * To enable: Open PublicManager.php and uncomment the line in __construct():
 *   add_action( 'plugins_loaded', [ $this, 'enable_profiler' ] );
 */

namespace AOE\CatalogEngine\PublicFacing;

class Profiler {

	private static array $marks = [];
	private static string $log_file = '';

	public static function init(): void {
		if ( empty( $_GET['aoe_profile'] ) ) {
			return;
		}
		$upload = wp_upload_dir();
		self::$log_file = $upload['basedir'] . '/aoe-profile.log';
		self::mark( 'init' );

		// Hook into key points
		add_action( 'wp', [ self::class, 'mark_wp' ], 1 );
		add_action( 'template_redirect', [ self::class, 'mark_template_redirect' ], 0 );
		add_filter( 'template_include', [ self::class, 'mark_template_include' ], 9999 );
		add_action( 'shutdown', [ self::class, 'flush' ], 9999 );
	}

	public static function mark_wp(): void { self::mark( 'wp' ); }
	public static function mark_template_redirect(): void { self::mark( 'template_redirect' ); }
	public static function mark_template_include( $template ) {
		self::mark( 'template_include: ' . basename( $template ) );
		return $template;
	}

	public static function mark( string $label ): void {
		if ( empty( self::$log_file ) ) return;
		self::$marks[] = [ microtime( true ), $label ];
	}

	public static function flush(): void {
		if ( empty( self::$marks ) ) return;

		$start = self::$marks[0][0];
		$lines = [];
		$lines[] = "\n=== " . date( 'Y-m-d H:i:s' ) . " | " . ( $_SERVER['REQUEST_URI'] ?? 'cli' ) . " ===\n";

		foreach ( self::$marks as $i => $mark ) {
			[ $time, $label ] = $mark;
			$elapsed = sprintf( '%0.4f', $time - $start );
			$diff = $i > 0 ? sprintf( '+%0.4f', $time - self::$marks[ $i - 1 ][0] ) : '-----';
			$lines[] = sprintf( "  %-40s %s (%s)", $label, $elapsed, $diff );
		}
		$lines[] = '';

		file_put_contents( self::$log_file, implode( "\n", $lines ), FILE_APPEND );
	}
}
