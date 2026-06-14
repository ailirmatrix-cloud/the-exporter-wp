<?php
/**
 * Action Scheduler integration for chunked processing.
 *
 * @package TheExporter
 */

namespace TheExporter\Jobs;

use TheExporter\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * Class Scheduler
 */
class Scheduler {

	const HOOK_PROCESS = 'te_process_chunk';

	/**
	 * Init scheduler hooks.
	 */
	public static function init() {
		add_action( self::HOOK_PROCESS, array( __CLASS__, 'process_chunk' ), 10, 1 );
	}

	/**
	 * Schedule next chunk processing.
	 *
	 * @param int    $job_id    Job ID.
	 * @param string $component Component.
	 * @param int    $delay     Delay in seconds.
	 */
	public static function schedule( $job_id, $component, $delay = 0 ) {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, self::HOOK_PROCESS, array(
				'job_id'    => $job_id,
				'component' => $component,
			), 'the-exporter' );
		} else {
			wp_schedule_single_event( time() + $delay, self::HOOK_PROCESS, array( $job_id, $component ) );
		}
	}

	/**
	 * Process one chunk via admin fallback engine.
	 *
	 * WP-Cron passes ($job_id, $component) as separate args.
	 * Action Scheduler passes a single associative array.
	 *
	 * @param int|array $job_id_or_args Job ID or AS args array.
	 * @param string    $component_arg  Component (WP-Cron second arg).
	 */
	public static function process_chunk( $job_id_or_args, $component_arg = '' ) {
		Runtime::prepare_job();

		if ( is_array( $job_id_or_args ) ) {
			$job_id    = absint( $job_id_or_args['job_id'] ?? 0 );
			$component = sanitize_key( $job_id_or_args['component'] ?? '' );
		} else {
			$job_id    = absint( $job_id_or_args );
			$component = sanitize_key( $component_arg );
		}

		$job = JobRepository::get_job( $job_id );

		if ( ! $job || JobRepository::STATUS_PAUSED === $job['status'] ) {
			return;
		}

		if ( '' === $component ) {
			return;
		}

		JobRepository::update_job_status( $job_id, JobRepository::STATUS_RUNNING );

		if ( 'push' === $job['type'] ) {
			\TheExporter\Transfer\RemotePusher::process_tick( $job_id );
			return;
		}

		if ( 'export' === $job['type'] ) {
			ExportOrchestrator::process_next_chunk( $job_id, $component );
		} else {
			ImportOrchestrator::process_next_chunk( $job_id, $component );
		}
	}
}
