<?php
/**
 * Lightweight receive request optimizations.
 *
 * @package TheExporter
 */

namespace TheExporter\Transfer;

defined( 'ABSPATH' ) || exit;

/**
 * Class LeanReceive
 */
class LeanReceive {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ), 5 );
	}

	/**
	 * Activate lean mode for transfer receive routes.
	 */
	public static function register() {
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'maybe_activate' ), 10, 3 );
	}

	/**
	 * @param mixed            $result  Response.
	 * @param \WP_REST_Server  $server  Server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function maybe_activate( $result, $server, $request ) {
		$route = (string) $request->get_route();
		if ( false === strpos( $route, '/the-exporter/v1/transfer/receive' ) ) {
			return $result;
		}

		if ( function_exists( 'wp_suspend_cache_addition' ) ) {
			wp_suspend_cache_addition( true );
		}
		if ( function_exists( 'wp_suspend_cache_invalidation' ) ) {
			wp_suspend_cache_invalidation( true );
		}

		@ini_set( 'zlib.output_compression', 'Off' );

		return $result;
	}
}
