<?php
/**
 * Background verify worker (Action Scheduler + loopback chain).
 *
 * @package TheExporter
 */

namespace TheExporter\Transfer;

use TheExporter\Runtime;
use TheExporter\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class VerifyWorker
 */
class VerifyWorker {

	const HOOK_TICK    = 'te_verify_worker_tick';
	const OPTION_META  = 'te_verify_worker_meta';
	const AS_GROUP     = 'the-exporter';
	const LOCK_SECONDS = 60;
	const IDLE_FALLBACK_SECONDS = 30;

	/**
	 * @return int
	 */
	public static function budget_seconds() {
		return Settings::is_localhost_studio() ? 25 : 12;
	}

	/**
	 * @return int
	 */
	public static function budget_items() {
		return Settings::is_localhost_studio() ? 200 : 50;
	}

	/**
	 * @param string $migration_id Migration ID.
	 * @return bool
	 */
	public static function try_acquire( $migration_id ) {
		$key = 'te_verify_worker_' . sanitize_key( $migration_id );
		if ( get_transient( $key ) ) {
			return false;
		}
		set_transient( $key, time(), self::LOCK_SECONDS );
		return true;
	}

	/**
	 * @param string $migration_id Migration ID.
	 */
	public static function release( $migration_id ) {
		delete_transient( 'te_verify_worker_' . sanitize_key( $migration_id ) );
	}

	/**
	 * Register worker hooks.
	 */
	public static function init() {
		add_action( self::HOOK_TICK, array( __CLASS__, 'run_tick' ), 10, 1 );
	}

	/**
	 * Ensure verify worker is running for a migration.
	 *
	 * @param string $migration_id Migration ID.
	 */
	public static function ensure_running( $migration_id ) {
		$migration_id = sanitize_text_field( $migration_id );
		if ( '' === $migration_id || VerifyQueue::migration_ready( $migration_id ) ) {
			return;
		}
		self::schedule_recurring( $migration_id );
		self::touch_meta(
			$migration_id,
			array(
				'active'     => true,
				'started_at' => gmdate( 'c' ),
			)
		);
		self::schedule_once( $migration_id, 1 );
		self::chain_loopback( $migration_id );
	}

	/**
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

		if ( VerifyQueue::migration_ready( $migration_id ) ) {
			self::stop( $migration_id );
			self::release( $migration_id );
			return;
		}

		$stats_before = VerifyQueue::pending_stats( $migration_id );
		$flush        = VerifyQueue::flush_pending_budget( $migration_id, self::budget_seconds(), self::budget_items() );
		VerifyQueue::reconcile_orphan_pending( $migration_id );
		$stats_after  = VerifyQueue::pending_stats( $migration_id );
		$done         = VerifyQueue::migration_ready( $migration_id );

		self::touch_meta(
			$migration_id,
			array(
				'active'         => ! $done,
				'last_tick_at'   => gmdate( 'c' ),
				'last_pending'   => (int) ( $stats_after['pending'] ?? 0 ),
				'processed'      => (int) ( $flush['processed'] ?? 0 ),
				'remaining'      => (int) ( $flush['remaining'] ?? 0 ),
			)
		);

		self::release( $migration_id );

		if ( $done ) {
			self::stop( $migration_id );
			return;
		}

		$delay = ( (int) ( $stats_after['pending'] ?? 0 ) === (int) ( $stats_before['pending'] ?? 0 ) ) ? 5 : 1;
		self::schedule_once( $migration_id, $delay );
		self::chain_loopback( $migration_id );
	}

	/**
	 * @param string $migration_id Migration ID.
	 * @return array
	 */
	public static function status( $migration_id ) {
		$all  = get_option( self::OPTION_META, array() );
		$meta = is_array( $all ) && ! empty( $all[ $migration_id ] ) ? $all[ $migration_id ] : array();
		return array(
			'worker_active'  => ! empty( $meta['active'] ),
			'worker_last_at' => isset( $meta['last_tick_at'] ) ? $meta['last_tick_at'] : null,
			'last_pending'   => (int) ( $meta['last_pending'] ?? 0 ),
		);
	}

	/**
	 * Whether worker appears idle long enough for receive-poll fallback nudge.
	 *
	 * @param string $migration_id Migration ID.
	 * @return bool
	 */
	public static function should_fallback_nudge( $migration_id ) {
		$meta = self::status( $migration_id );
		if ( ! empty( $meta['worker_active'] ) && ! empty( $meta['worker_last_at'] ) ) {
			$ts = strtotime( $meta['worker_last_at'] );
			if ( $ts && ( time() - $ts ) <= self::IDLE_FALLBACK_SECONDS ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param string $migration_id Migration ID.
	 */
	public static function chain_loopback( $migration_id ) {
		$url = rest_url( 'the-exporter/v1/transfer/verify-tick' );
		wp_remote_post(
			$url,
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'headers'   => array(
					'Content-Type'        => 'application/json',
					'X-TE-Worker-Token'   => TransferWorker::get_token(),
				),
				'body'      => wp_json_encode( array( 'migration_id' => $migration_id ) ),
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			)
		);
	}

	/**
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
	 * Resolve verify progress for receive snapshot (light path + optional fallback).
	 *
	 * @param string $migration_id Migration ID.
	 * @param bool   $reconciling  Whether files on disk but not ready.
	 * @return array
	 */
	public static function snapshot_verify( $migration_id, $reconciling ) {
		$migration_id = sanitize_text_field( $migration_id );
		$stats        = VerifyQueue::pending_stats( $migration_id );

		if ( VerifyQueue::migration_ready( $migration_id ) ) {
			return array_merge(
				$stats,
				array(
					'done'       => true,
					'processed'  => 0,
					'remaining'  => 0,
				)
			);
		}

		if ( $reconciling ) {
			self::ensure_running( $migration_id );
			if ( self::should_fallback_nudge( $migration_id ) ) {
				return VerifyQueue::nudge_on_receive_poll( $migration_id );
			}
		}

		return array_merge(
			$stats,
			array(
				'done'      => false,
				'processed' => 0,
				'remaining' => (int) ( $stats['pending'] ?? 0 ),
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
			as_schedule_recurring_action( time() + 2, 5, self::HOOK_TICK, $args, self::AS_GROUP );
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
