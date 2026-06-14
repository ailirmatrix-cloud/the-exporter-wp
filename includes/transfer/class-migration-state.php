<?php
/**
 * Single completion authority per migration phase.
 *
 * @package TheExporter
 */

namespace TheExporter\Transfer;

use TheExporter\Jobs\ExportOrchestrator;

defined( 'ABSPATH' ) || exit;

/**
 * Class MigrationState
 */
class MigrationState {

	/**
	 * Receive completion: all catalog files on import disk + verify queue drained.
	 *
	 * @param string $migration_id Migration ID.
	 * @return array
	 */
	public static function receive_state( $migration_id ) {
		$migration_id = sanitize_text_field( $migration_id );
		$upload       = FileUploader::migration_upload_status( $migration_id, array( 'lightweight' => true ) );
		$components   = TransferProgress::components_from_upload( $upload );
		$bytes        = TransferProgress::bytes_from_upload_public( $upload );

		return array(
			'migration_id'      => $migration_id,
			'needs_manifest'    => ! empty( $upload['needs_manifest'] ),
			'expected'          => (int) ( $upload['expected'] ?? 0 ),
			'uploaded'          => (int) ( $upload['uploaded'] ?? 0 ),
			'ready'             => ! empty( $upload['ready_to_validate'] ),
			'bytes_done'        => (int) ( $bytes['done'] ?? 0 ),
			'bytes_total'       => (int) ( $bytes['total'] ?? 0 ),
			'components'        => $components,
			'missing'           => isset( $upload['missing'] ) ? (array) $upload['missing'] : array(),
			'checksum_failures' => isset( $upload['checksum_failures'] ) ? (array) $upload['checksum_failures'] : array(),
		);
	}

	/**
	 * Push completion: job row complete or fully sent per queue.
	 *
	 * @param string $migration_id Migration ID.
	 * @return array
	 */
	public static function push_state( $migration_id ) {
		$migration_id = sanitize_text_field( $migration_id );
		$push         = RemotePusher::push_status( $migration_id );
		$total        = (int) ( $push['total'] ?? 0 );
		$sent         = (int) ( $push['sent'] ?? 0 );
		$complete     = ! empty( $push['done'] ) || ( $total > 0 && $sent >= $total );

		return array(
			'migration_id' => $migration_id,
			'sent'         => $sent,
			'total'        => $total,
			'active'       => ! empty( $push['active'] ) && ! $complete,
			'done'         => $complete,
			'failed'       => ! empty( $push['failed'] ),
			'raw'          => $push,
		);
	}

	/**
	 * Export completion: manifest components finalized on export disk.
	 *
	 * @param string $migration_id Migration ID.
	 * @return array
	 */
	public static function export_state( $migration_id ) {
		$migration_id = sanitize_text_field( $migration_id );
		$gate         = ExportOrchestrator::can_finalize( $migration_id );
		$job          = \TheExporter\Jobs\JobRepository::get_job_by_migration( $migration_id, 'export' );

		return array(
			'migration_id' => $migration_id,
			'ready'        => ! empty( $gate['ready'] ),
			'errors'       => isset( $gate['errors'] ) ? (array) $gate['errors'] : array(),
			'job_status'   => $job ? $job['status'] : '',
		);
	}

	/**
	 * Verify completion state for receive gate.
	 *
	 * @param string $migration_id Migration ID.
	 * @return array
	 */
	public static function verify_state( $migration_id ) {
		$migration_id = sanitize_text_field( $migration_id );
		$stats        = VerifyQueue::pending_stats( $migration_id );
		$worker       = VerifyWorker::status( $migration_id );

		return array(
			'migration_id' => $migration_id,
			'pending'      => (int) ( $stats['pending'] ?? 0 ),
			'verified'     => (int) ( $stats['verified'] ?? 0 ),
			'failed'       => (int) ( $stats['failed'] ?? 0 ),
			'total'        => (int) ( $stats['total'] ?? 0 ),
			'ready'        => VerifyQueue::migration_ready( $migration_id ),
			'worker'       => $worker,
		);
	}

	/**
	 * Whether verify appears stalled after all files are on disk.
	 *
	 * @param string $migration_id Migration ID.
	 * @param array  $verify_state From verify_state().
	 * @return bool
	 */
	public static function verify_is_stale( $migration_id, array $verify_state ) {
		if ( ! empty( $verify_state['ready'] ) || (int) ( $verify_state['pending'] ?? 0 ) <= 0 ) {
			return false;
		}
		$worker = isset( $verify_state['worker'] ) ? $verify_state['worker'] : VerifyWorker::status( $migration_id );
		if ( ! empty( $worker['worker_last_at'] ) ) {
			$ts = strtotime( $worker['worker_last_at'] );
			if ( $ts && ( time() - $ts ) <= 90 ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether relayed export push error should surface in receive UI.
	 *
	 * @param array|null $inflight   In-flight chunk state.
	 * @param array|null $push_state Relayed push state.
	 * @return bool
	 */
	public static function push_error_visible( $inflight, $push_state ) {
		if ( empty( $push_state['error'] ) ) {
			return false;
		}
		if ( ! empty( $inflight['path'] ) && ! empty( $inflight['updated_at'] ) ) {
			$ts = strtotime( $inflight['updated_at'] );
			if ( $ts && ( time() - $ts ) <= 45 ) {
				return false;
			}
		}
		if ( ! empty( $push_state['active'] ) || ! empty( $push_state['worker_active'] ) ) {
			return false;
		}
		if ( ! empty( $push_state['worker_last_at'] ) ) {
			$ts = strtotime( $push_state['worker_last_at'] );
			if ( $ts && ( time() - $ts ) <= 45 ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether receive appears stalled (disk incomplete, no inflight, push idle).
	 *
	 * @param string      $migration_id Migration ID.
	 * @param array       $receive      Receive state from receive_state().
	 * @param string|null $last_at      Last receive log timestamp.
	 * @param array|null  $inflight     In-flight chunk.
	 * @param array|null  $push_state   Relayed push state.
	 * @return bool
	 */
	public static function receive_is_stale( $migration_id, array $receive, $last_at, $inflight = null, $push_state = null ) {
		if ( ! empty( $receive['needs_manifest'] ) || ! empty( $receive['ready'] ) ) {
			return false;
		}
		$expected = (int) ( $receive['expected'] ?? 0 );
		$uploaded = (int) ( $receive['uploaded'] ?? 0 );
		if ( $expected > 0 && $uploaded >= $expected ) {
			return self::verify_is_stale( $migration_id, self::verify_state( $migration_id ) );
		}
		if ( self::push_error_visible( $inflight, $push_state ) ) {
			return true;
		}
		if ( ! empty( $inflight['updated_at'] ) && ! empty( $inflight['bytes_total'] ) ) {
			$ts = strtotime( $inflight['updated_at'] );
			if ( $ts ) {
				$file_mb     = max( 1, (int) $inflight['bytes_total'] / 1048576 );
				$stale_after = max( TransferProgress::stale_seconds(), $file_mb * 3 );
				if ( ( time() - $ts ) <= $stale_after ) {
					return false;
				}
			}
		}
		if ( ! empty( $push_state['active'] ) || ! empty( $push_state['worker_active'] ) ) {
			return false;
		}
		if ( ! empty( $push_state['worker_last_at'] ) ) {
			$ts = strtotime( $push_state['worker_last_at'] );
			if ( $ts && ( time() - $ts ) <= 45 ) {
				return false;
			}
		}
		if ( ! $last_at ) {
			return false;
		}
		$ts = strtotime( $last_at );
		if ( ! $ts ) {
			return false;
		}
		return ( time() - $ts ) > TransferProgress::stale_seconds();
	}
}
