<?php
/**
 * Server-side transfer worker (Action Scheduler + loopback chain + CLI).
 *
 * @package TheExporter
 */

namespace TheExporter\Transfer;

use TheExporter\Runtime;
use TheExporter\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class TransferWorker
 */
class TransferWorker {

	const HOOK_TICK      = 'te_transfer_worker_tick';
	const OPTION_TOKEN   = 'te_transfer_worker_token';
	const OPTION_META    = 'te_transfer_worker_meta';
	const AS_GROUP       = 'the-exporter';
	const LOCK_SECONDS   = 60;

	/**
	 * @return int
	 */
	public static function budget_seconds() {
		return Settings::is_localhost_peer() ? 120 : 90;
	}

	/**
	 * @return int
	 */
	public static function budget_bytes() {
		return Settings::is_localhost_peer() ? 268435456 : 134217728;
	}

	/**
	 * @param string $migration_id Migration ID.
	 * @return bool
	 */
	public static function try_acquire( $migration_id ) {
		$key = 'te_push_worker_' . sanitize_key( $migration_id );
		if ( get_transient( $key ) ) {
			return false;
		}
		set_transient( $key, time(), self::LOCK_SECONDS );
		return true;
	}

	/**
	 * Refresh worker lock during long operations.
	 *
	 * @param string $migration_id Migration ID.
	 */
	public static function refresh_lock( $migration_id ) {
		set_transient( 'te_push_worker_' . sanitize_key( $migration_id ), time(), self::LOCK_SECONDS );
	}

	/**
	 * @param string $migration_id Migration ID.
	 */
	public static function release( $migration_id ) {
		delete_transient( 'te_push_worker_' . sanitize_key( $migration_id ) );
	}

	/**
	 * Register worker hooks.
	 */
	public static function init() {
		add_action( self::HOOK_TICK, array( __CLASS__, 'run_tick' ), 10, 1 );
	}

	/**
	 * Ensure background worker is running for a migration.
	 *
	 * @param string $migration_id Migration ID.
	 */
	public static function ensure_running( $migration_id ) {
		$migration_id = sanitize_text_field( $migration_id );
		if ( '' === $migration_id ) {
			return;
		}
		self::schedule_recurring( $migration_id );
		self::touch_meta(
			$migration_id,
			array(
				'active'     => true,
				'started_at' => gmdate( 'c' ),
				'source'     => 'ensure',
			)
		);
		self::schedule_once( $migration_id, 1 );
		self::chain_loopback( $migration_id );
		self::chain_loopback( $migration_id );
	}

	/**
	 * Run one worker tick (AS, loopback, or CLI).
	 *
	 * @param array|string $args Migration ID or AS args.
	 */
	public static function run_tick( $args ) {
		$migration_id = is_array( $args ) ? sanitize_text_field( $args['migration_id'] ?? '' ) : sanitize_text_field( $args );
		if ( '' === $migration_id ) {
			return;
		}

		if ( ! self::try_acquire( $migration_id ) ) {
			self::schedule_once( $migration_id, 3 );
			self::chain_loopback( $migration_id );
			return;
		}

		Runtime::prepare_job();

		$status = RemotePusher::push_status( $migration_id );
		if ( ! empty( $status['done'] ) ) {
			self::stop( $migration_id );
			self::release( $migration_id );
			return;
		}
		if ( empty( $status['active'] ) && empty( $status['job_id'] ) ) {
			self::stop( $migration_id );
			self::release( $migration_id );
			return;
		}

		RemotePusher::relay_worker_state( $migration_id, true );

		$GLOBALS['te_push_worker_driven'] = true;
		$source                             = defined( 'TE_WORKER_SOURCE' ) ? TE_WORKER_SOURCE : 'worker';
		$result                             = array( 'success' => false, 'error' => 'Worker tick failed' );
		try {
			$result = RemotePusher::process_tick_budget( $migration_id, self::budget_seconds(), self::budget_bytes() );
			self::touch_meta(
				$migration_id,
				array(
					'active'         => empty( $result['done'] ),
					'last_tick_at'   => gmdate( 'c' ),
					'last_source'    => $source,
					'last_error'     => isset( $result['error'] ) ? $result['error'] : '',
					'budget_bytes'   => (int) ( $result['budget_bytes'] ?? 0 ),
					'budget_seconds' => (float) ( $result['budget_seconds'] ?? 0 ),
					'retrying'       => ! empty( $result['retrying'] ),
				)
			);
		} finally {
			unset( $GLOBALS['te_push_worker_driven'] );
			self::release( $migration_id );
		}

		if ( ! empty( $result['done'] ) ) {
			RemotePusher::relay_worker_state( $migration_id, false );
			self::stop( $migration_id );
			return;
		}

		$delay = ! empty( $result['retrying'] ) ? (int) ( $result['retry_delay'] ?? 5 ) : 1;
		self::schedule_once( $migration_id, $delay );
		self::chain_loopback( $migration_id );
	}

	/**
	 * Loop until push completes (CLI daemon).
	 *
	 * @param string $migration_id Migration ID.
	 * @param int    $max_seconds  Time budget (0 = unlimited).
	 * @return array
	 */
	public static function run_daemon( $migration_id, $max_seconds = 0 ) {
		if ( ! defined( 'TE_WORKER_SOURCE' ) ) {
			define( 'TE_WORKER_SOURCE', 'cli' );
		}

		Runtime::prepare_job();
		$migration_id = sanitize_text_field( $migration_id );
		self::ensure_running( $migration_id );

		$started = microtime( true );
		$last    = array( 'success' => false, 'error' => 'Worker not started' );

		do {
			if ( ! self::try_acquire( $migration_id ) ) {
				sleep( 2 );
				continue;
			}
			$GLOBALS['te_push_worker_driven'] = true;
			try {
				RemotePusher::relay_worker_state( $migration_id, true );
				$last = RemotePusher::process_tick_budget( $migration_id, self::budget_seconds(), self::budget_bytes() );
			} finally {
				unset( $GLOBALS['te_push_worker_driven'] );
				self::release( $migration_id );
			}

			if ( ! empty( $last['done'] ) ) {
				break;
			}
			if ( empty( $last['success'] ) && empty( $last['retrying'] ) ) {
				break;
			}
			if ( ! empty( $last['retrying'] ) ) {
				sleep( (int) ( $last['retry_delay'] ?? 5 ) );
			}
		} while ( 0 === $max_seconds || ( microtime( true ) - $started ) < $max_seconds );

		$last['daemon_seconds'] = round( microtime( true ) - $started, 2 );
		if ( ! empty( $last['done'] ) ) {
			RemotePusher::relay_worker_state( $migration_id, false );
			self::stop( $migration_id );
		}
		return $last;
	}

	/**
	 * Worker status for UI.
	 *
	 * @param string $migration_id Migration ID.
	 * @return array
	 */
	public static function status( $migration_id ) {
		$all  = get_option( self::OPTION_META, array() );
		$meta = is_array( $all ) && ! empty( $all[ $migration_id ] ) ? $all[ $migration_id ] : array();
		return array(
			'worker_active'   => ! empty( $meta['active'] ),
			'worker_last_at'  => isset( $meta['last_tick_at'] ) ? $meta['last_tick_at'] : null,
			'worker_source'   => isset( $meta['last_source'] ) ? $meta['last_source'] : null,
			'worker_error'    => isset( $meta['last_error'] ) ? $meta['last_error'] : '',
			'worker_retrying' => ! empty( $meta['retrying'] ),
		);
	}

	/**
	 * Verify loopback / internal worker token.
	 *
	 * @param string $token Token header value.
	 * @return bool
	 */
	public static function verify_token( $token ) {
		$token = (string) $token;
		return '' !== $token && hash_equals( self::get_token(), $token );
	}

	/**
	 * @return string
	 */
	public static function get_token() {
		$token = get_option( self::OPTION_TOKEN, '' );
		if ( ! is_string( $token ) || strlen( $token ) < 32 ) {
			$token = wp_generate_password( 48, false, false );
			update_option( self::OPTION_TOKEN, $token, false );
		}
		return $token;
	}

	/**
	 * Non-blocking loopback tick to keep worker alive without WP-Cron.
	 *
	 * @param string $migration_id Migration ID.
	 */
	public static function chain_loopback( $migration_id ) {
		$url = rest_url( 'the-exporter/v1/transfer/worker-tick' );
		wp_remote_post(
			$url,
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'headers'   => array(
					'Content-Type'      => 'application/json',
					'X-TE-Worker-Token' => self::get_token(),
				),
				'body'      => wp_json_encode( array( 'migration_id' => $migration_id ) ),
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			)
		);
	}

	/**
	 * Stop worker schedules for migration.
	 *
	 * @param string $migration_id Migration ID.
	 */
	public static function stop( $migration_id ) {
		$migration_id = sanitize_text_field( $migration_id );
		self::unschedule( $migration_id );
		self::touch_meta(
			$migration_id,
			array(
				'active'       => false,
				'stopped_at'   => gmdate( 'c' ),
				'last_tick_at' => gmdate( 'c' ),
			)
		);
	}

	/**
	 * @param string $migration_id Migration ID.
	 * @param int    $delay        Delay seconds.
	 */
	private static function schedule_once( $migration_id, $delay ) {
		$args = array( 'migration_id' => $migration_id );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, self::HOOK_TICK, $args, self::AS_GROUP );
		} else {
			wp_schedule_single_event( time() + $delay, self::HOOK_TICK, array( $args ) );
		}
	}

	/**
	 * @param string $migration_id Migration ID.
	 */
	private static function schedule_recurring( $migration_id ) {
		$args = array( 'migration_id' => $migration_id );
		if ( function_exists( 'as_next_scheduled_action' ) && as_next_scheduled_action( self::HOOK_TICK, $args, self::AS_GROUP ) ) {
			return;
		}
		if ( function_exists( 'as_schedule_recurring_action' ) ) {
			as_schedule_recurring_action( time() + 2, 3, self::HOOK_TICK, $args, self::AS_GROUP );
		}
	}

	/**
	 * @param string $migration_id Migration ID.
	 */
	private static function unschedule( $migration_id ) {
		$args = array( 'migration_id' => $migration_id );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_TICK, $args, self::AS_GROUP );
		}
		wp_clear_scheduled_hook( self::HOOK_TICK, array( $args ) );
	}

	/**
	 * @param string $migration_id Migration ID.
	 * @param array  $patch        Meta patch.
	 */
	private static function touch_meta( $migration_id, array $patch ) {
		$all = get_option( self::OPTION_META, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		$prev = isset( $all[ $migration_id ] ) && is_array( $all[ $migration_id ] ) ? $all[ $migration_id ] : array();
		$all[ $migration_id ] = array_merge( $prev, $patch );
		update_option( self::OPTION_META, $all, false );
	}
}
