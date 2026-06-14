<?php
/**
 * Rich transfer progress snapshots for wizard UI.
 *
 * @package TheExporter
 */

namespace TheExporter\Transfer;

use TheExporter\Jobs\JobRepository;
use TheExporter\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class TransferProgress
 */
class TransferProgress {

	const OPTION_RECEIVE_LOG      = 'te_transfer_receive_log';
	const OPTION_PUSH_LOG         = 'te_transfer_push_log';
	const OPTION_RECEIVE_COUNTERS = 'te_transfer_receive_counters';
	const OPTION_RECEIVE_INFLIGHT = 'te_transfer_receive_inflight';
	const OPTION_PUSH_HEARTBEAT   = 'te_transfer_push_heartbeat';
	const OPTION_IMPORT_PUSH_STATE = 'te_transfer_import_push_state';
	const LOG_MAX                 = 8;
	const STALE_SECONDS           = 30;

	/**
	 * Append a receive event.
	 *
	 * @param string $migration_id Migration ID.
	 * @param string $path         Relative path.
	 * @param string $component    Component slug.
	 * @param int    $size         File size bytes.
	 */
	public static function log_receive( $migration_id, $path, $component, $size = 0 ) {
		if ( self::receive_path_logged( $migration_id, $path ) ) {
			return;
		}
		self::append_log( self::OPTION_RECEIVE_LOG, $migration_id, $path, $component, $size );
		self::bump_receive_counter( $migration_id, $path, $component, $size );
		self::mark_receive_path_logged( $migration_id, $path );
	}

	/**
	 * Roll back a receive log entry (corrupt file repair).
	 *
	 * @param string $migration_id Migration ID.
	 * @param string $path         Relative path.
	 */
	public static function unlog_receive( $migration_id, $path ) {
		$migration_id = sanitize_text_field( $migration_id );
		$path         = sanitize_text_field( $path );
		if ( '' === $migration_id || '' === $path ) {
			return;
		}

		$size = self::unbump_receive_counter( $migration_id, $path );
		self::unmark_receive_path_logged( $migration_id, $path );

		$all = get_option( self::OPTION_RECEIVE_LOG, array() );
		if ( ! is_array( $all ) || empty( $all[ $migration_id ] ) ) {
			return;
		}
		foreach ( $all[ $migration_id ] as $i => $entry ) {
			if ( isset( $entry['path'] ) && $entry['path'] === $path ) {
				unset( $all[ $migration_id ][ $i ] );
				$all[ $migration_id ] = array_values( $all[ $migration_id ] );
				update_option( self::OPTION_RECEIVE_LOG, $all, false );
				return;
			}
		}
		unset( $size );
	}

	/**
	 * Clear receive counters for a migration.
	 *
	 * @param string $migration_id Migration ID.
	 */
	public static function clear_receive_counters( $migration_id = '' ) {
		if ( '' === $migration_id ) {
			delete_option( self::OPTION_RECEIVE_COUNTERS );
			return;
		}
		$all = get_option( self::OPTION_RECEIVE_COUNTERS, array() );
		if ( is_array( $all ) ) {
			unset( $all[ $migration_id ] );
			update_option( self::OPTION_RECEIVE_COUNTERS, $all, false );
		}
	}

	/**
	 * Clear all import-side receive tracking for a migration.
	 *
	 * @param string $migration_id Migration ID.
	 */
	public static function clear_receive_state( $migration_id ) {
		$migration_id = sanitize_text_field( $migration_id );
		if ( '' === $migration_id ) {
			return;
		}
		self::clear_receive_counters( $migration_id );
		self::clear_receive_inflight( $migration_id );

		$log = get_option( self::OPTION_RECEIVE_LOG, array() );
		if ( is_array( $log ) && isset( $log[ $migration_id ] ) ) {
			unset( $log[ $migration_id ] );
			update_option( self::OPTION_RECEIVE_LOG, $log, false );
		}

		$push = get_option( self::OPTION_IMPORT_PUSH_STATE, array() );
		if ( is_array( $push ) && isset( $push[ $migration_id ] ) ) {
			unset( $push[ $migration_id ] );
			update_option( self::OPTION_IMPORT_PUSH_STATE, $push, false );
		}

		VerifyQueue::clear_migration( $migration_id );
	}

	/**
	 * Stale detection window (seconds).
	 *
	 * @return int
	 */
	public static function stale_seconds() {
		return self::STALE_SECONDS;
	}

	/**
	 * Append a push event.
	 *
	 * @param string $migration_id Migration ID.
	 * @param string $path         Relative path.
	 * @param string $component    Component slug.
	 * @param int    $size         File size bytes.
	 */
	public static function log_push( $migration_id, $path, $component, $size = 0 ) {
		self::append_log( self::OPTION_PUSH_LOG, $migration_id, $path, $component, $size );
	}

	/**
	 * Track in-flight chunked receive on import site.
	 *
	 * @param string $migration_id Migration ID.
	 * @param string $path         Relative path.
	 * @param string $component    Component.
	 * @param int    $bytes_done   Bytes received.
	 * @param int    $bytes_total  Total file size.
	 */
	public static function set_receive_inflight( $migration_id, $path, $component, $bytes_done, $bytes_total ) {
		$all = get_option( self::OPTION_RECEIVE_INFLIGHT, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		$all[ $migration_id ] = array(
			'path'        => $path,
			'component'   => $component,
			'bytes_done'  => (int) $bytes_done,
			'bytes_total' => (int) $bytes_total,
			'updated_at'  => gmdate( 'c' ),
		);
		update_option( self::OPTION_RECEIVE_INFLIGHT, $all, false );
	}

	/**
	 * @param string $migration_id Migration ID.
	 */
	public static function clear_receive_inflight( $migration_id ) {
		$all = get_option( self::OPTION_RECEIVE_INFLIGHT, array() );
		if ( is_array( $all ) && isset( $all[ $migration_id ] ) ) {
			unset( $all[ $migration_id ] );
			update_option( self::OPTION_RECEIVE_INFLIGHT, $all, false );
		}
	}

	/**
	 * @param string $migration_id Migration ID.
	 * @return array|null
	 */
	public static function get_receive_inflight( $migration_id ) {
		$all = get_option( self::OPTION_RECEIVE_INFLIGHT, array() );
		return ( is_array( $all ) && ! empty( $all[ $migration_id ] ) ) ? $all[ $migration_id ] : null;
	}

	/**
	 * Update export-side push heartbeat.
	 *
	 * @param string $migration_id Migration ID.
	 * @param array  $data         Heartbeat payload.
	 */
	public static function update_push_heartbeat( $migration_id, array $data ) {
		$all = get_option( self::OPTION_PUSH_HEARTBEAT, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		$data['updated_at'] = gmdate( 'c' );
		$all[ $migration_id ] = $data;
		update_option( self::OPTION_PUSH_HEARTBEAT, $all, false );
	}

	/**
	 * Import-side push state relayed from export site.
	 *
	 * @param string $migration_id Migration ID.
	 * @param array  $state        State payload.
	 */
	public static function set_import_push_state( $migration_id, array $state ) {
		$all = get_option( self::OPTION_IMPORT_PUSH_STATE, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		$prev = isset( $all[ $migration_id ] ) && is_array( $all[ $migration_id ] ) ? $all[ $migration_id ] : array();
		$state['updated_at'] = gmdate( 'c' );
		$all[ $migration_id ] = array_merge( $prev, $state );
		update_option( self::OPTION_IMPORT_PUSH_STATE, $all, false );
	}

	/**
	 * @param string $migration_id Migration ID.
	 * @return array|null
	 */
	public static function get_import_push_state( $migration_id ) {
		$all = get_option( self::OPTION_IMPORT_PUSH_STATE, array() );
		return ( is_array( $all ) && ! empty( $all[ $migration_id ] ) ) ? $all[ $migration_id ] : null;
	}

	/**
	 * Receive progress snapshot for import wizard.
	 *
	 * @param string $migration_id Migration ID.
	 * @return array
	 */
	public static function receive_snapshot( $migration_id ) {
		$migration_id = sanitize_text_field( $migration_id );
		self::sync_counters_from_disk( $migration_id );

		$pre_receive = MigrationState::receive_state( $migration_id );
		$expected    = (int) ( $pre_receive['expected'] ?? 0 );
		$uploaded    = (int) ( $pre_receive['uploaded'] ?? 0 );
		$reconciling = $expected > 0 && $uploaded >= $expected && empty( $pre_receive['ready'] );
		$verify      = VerifyWorker::snapshot_verify( $migration_id, $reconciling );
		$receive     = MigrationState::receive_state( $migration_id );
		$upload     = array(
			'needs_manifest'    => $receive['needs_manifest'],
			'expected'          => $receive['expected'],
			'uploaded'          => $receive['uploaded'],
			'ready_to_validate' => $receive['ready'],
			'bytes_done'        => $receive['bytes_done'],
			'bytes_total'       => $receive['bytes_total'],
		);
		$components = $receive['components'];

		$recent     = self::get_recent( self::OPTION_RECEIVE_LOG, $migration_id );
		$expected   = (int) $receive['expected'];
		$uploaded   = (int) $receive['uploaded'];
		$pct        = $expected > 0 ? (int) floor( ( $uploaded / $expected ) * 100 ) : 0;
		$last_at    = ! empty( $recent[0]['received_at'] ) ? $recent[0]['received_at'] : null;
		$inflight   = self::reconcile_receive_inflight( $migration_id );
		$push_state = self::get_import_push_state( $migration_id );
		$stale      = MigrationState::receive_is_stale( $migration_id, $receive, $last_at, $inflight, $push_state );
		$stall_diag = MigrationState::receive_stall_diag( $receive, $last_at, $inflight, $push_state, $stale );

		// #region agent log
		$debug_log = dirname( TE_PLUGIN_DIR ) . '/debug-303160.log';
		if ( $stale || ( is_array( $stall_diag ) && ! empty( $stall_diag['reasons'] ) ) ) {
			$payload = wp_json_encode( array(
				'sessionId'    => '303160',
				'location'     => 'class-transfer-progress.php:receive_snapshot',
				'message'      => 'receive_stall_snapshot',
				'hypothesisId' => 'H1-H4',
				'timestamp'    => (int) round( microtime( true ) * 1000 ),
				'data'         => array(
					'migration_id' => $migration_id,
					'stale'        => $stale,
					'stall_diag'   => $stall_diag,
					'uploaded'     => $uploaded,
					'expected'     => $expected,
				),
			) ) . "\n";
			@file_put_contents( $debug_log, $payload, FILE_APPEND | LOCK_EX ); // phpcs:ignore
		}
		// #endregion

		$bytes_done = (int) $receive['bytes_done'];
		if ( ! empty( $inflight['bytes_done'] ) && $bytes_done < (int) $inflight['bytes_done'] ) {
			$bytes_done = (int) $inflight['bytes_done'];
		}

		return array(
			'migration_id'    => $migration_id,
			'phase'           => ! empty( $upload['needs_manifest'] ) ? 'waiting' : 'upload',
			'overall_percent' => $pct,
			'current_action'  => self::receive_action( $upload, $components, $inflight, $push_state, $verify ),
			'upload'          => array(
				'expected'    => $expected,
				'uploaded'    => $uploaded,
				'ready'       => ! empty( $receive['ready'] ),
				'bytes_done'  => $bytes_done,
				'bytes_total' => (int) $receive['bytes_total'],
			),
			'verify'              => array(
				'pending'  => (int) ( $verify['pending'] ?? 0 ),
				'verified' => (int) ( $verify['verified'] ?? 0 ),
				'total'    => (int) ( $verify['total'] ?? 0 ),
				'done'     => ! empty( $verify['done'] ),
			),
			'components'        => $components,
			'recent'              => $recent,
			'last_received_at'    => $last_at,
			'inflight'            => $inflight,
			'push_state'          => $push_state,
			'stale'               => $stale,
			'stale_message'       => self::stale_message( $stale, $push_state, $reconciling, $inflight, $migration_id, $receive ),
			'stall_diag'          => $stall_diag,
			'reconciling'         => $reconciling,
			'needs_manifest'      => ! empty( $upload['needs_manifest'] ),
			'php_limits'          => Settings::php_upload_limits(),
			'peer_php_limits'     => Settings::get( 'peer_php_limits', array() ),
		);
	}

	/**
	 * Sync inflight chunk progress with bytes actually on disk.
	 *
	 * @param string $migration_id Migration ID.
	 * @return array|null
	 */
	public static function reconcile_receive_inflight( $migration_id ) {
		$inflight = self::get_receive_inflight( $migration_id );
		if ( empty( $inflight['path'] ) ) {
			return $inflight;
		}

		$disk_bytes = ChunkReceiver::bytes_on_disk( $migration_id, $inflight['path'] );
		$stored     = (int) ( $inflight['bytes_done'] ?? 0 );
		if ( $disk_bytes === $stored ) {
			return $inflight;
		}

		$total     = (int) ( $inflight['bytes_total'] ?? 0 );
		$component = isset( $inflight['component'] ) ? sanitize_key( $inflight['component'] ) : '';
		$done      = $total > 0 ? min( $total, max( 0, $disk_bytes ) ) : max( 0, $disk_bytes );
		self::set_receive_inflight( $migration_id, $inflight['path'], $component, $done, $total );
		$inflight['bytes_done'] = $done;
		return $inflight;
	}

	/**
	 * Push progress snapshot for export wizard.
	 *
	 * @param string $migration_id Migration ID.
	 * @return array
	 */
	public static function push_snapshot( $migration_id ) {
		$migration_id = sanitize_text_field( $migration_id );
		$push         = RemotePusher::push_status( $migration_id );
		$total        = (int) ( $push['total'] ?? 0 );
		$sent         = (int) ( $push['sent'] ?? 0 );
		$pct          = $total > 0 ? (int) floor( ( $sent / $total ) * 100 ) : 0;
		$recent       = self::get_recent( self::OPTION_PUSH_LOG, $migration_id );
		$components   = isset( $push['components'] ) ? $push['components'] : array();
		$heartbeat    = get_option( self::OPTION_PUSH_HEARTBEAT, array() );
		$hb           = is_array( $heartbeat ) && ! empty( $heartbeat[ $migration_id ] ) ? $heartbeat[ $migration_id ] : null;

		return array(
			'migration_id'    => $migration_id,
			'phase'           => ! empty( $push['done'] ) ? 'done' : ( ! empty( $push['active'] ) ? 'push' : 'idle' ),
			'overall_percent' => $pct,
			'current_action'  => self::push_action( $push, $hb ),
			'push'            => array(
				'sent'          => $sent,
				'total'         => $total,
				'active'        => ! empty( $push['active'] ),
				'done'          => ! empty( $push['done'] ),
				'failed'        => ! empty( $push['failed'] ),
				'bytes_sent'    => (int) ( $push['bytes_sent'] ?? 0 ),
				'bytes_total'   => (int) ( $push['bytes_total'] ?? 0 ),
				'current_file'  => isset( $push['current_file'] ) ? $push['current_file'] : null,
				'heartbeat'     => $hb,
				'worker_active' => ! empty( $push['worker_active'] ),
				'worker_source' => isset( $push['worker_source'] ) ? $push['worker_source'] : null,
				'last_error'    => isset( $push['last_error'] ) ? $push['last_error'] : '',
				'retrying'      => ! empty( $push['retrying'] ),
			),
			'components' => $components,
			'recent'     => $recent,
		);
	}

	/**
	 * Seed receive progress after import-side local copy.
	 *
	 * @param string $migration_id Migration ID.
	 * @param int    $files        Files copied.
	 * @param int    $bytes        Bytes copied.
	 */
	public static function seed_from_local_copy( $migration_id, $files, $bytes ) {
		$migration_id = sanitize_text_field( $migration_id );
		foreach ( PackageIndex::get_components( $migration_id, 'import' ) as $comp ) {
			foreach ( $comp['files'] as $file ) {
				self::log_receive(
					$migration_id,
					$file['path'],
					$comp['name'],
					isset( $file['size'] ) ? (int) $file['size'] : 0
				);
			}
		}
		foreach ( PackageIndex::get_global_files( $migration_id, 'import' ) as $file ) {
			self::log_receive(
				$migration_id,
				$file['path'],
				'manifest',
				isset( $file['size'] ) ? (int) $file['size'] : 0
			);
		}
	}

	/**
	 * Group upload file rows by component for checklist UI.
	 *
	 * @param array $upload Upload status from FileUploader.
	 * @return array
	 */
	public static function components_from_upload( array $upload ) {
		$by_component = array();
		foreach ( (array) ( $upload['files'] ?? array() ) as $file ) {
			$comp = $file['component'] ?? 'unknown';
			if ( ! isset( $by_component[ $comp ] ) ) {
				$by_component[ $comp ] = array(
					'done'        => 0,
					'total'       => 0,
					'bytes_done'  => 0,
					'bytes_total' => 0,
				);
			}
			$size = (int) ( $file['size'] ?? 0 );
			$by_component[ $comp ]['total']++;
			$by_component[ $comp ]['bytes_total'] += $size;
			if ( ! empty( $file['uploaded'] ) ) {
				$by_component[ $comp ]['done']++;
				$by_component[ $comp ]['bytes_done'] += $size;
			}
		}

		$out = array();
		$out[] = array(
			'name'         => 'manifest',
			'label'        => PackageIndex::component_label( 'manifest' ),
			'status'       => empty( $upload['needs_manifest'] ) ? JobRepository::STATUS_COMPLETED : JobRepository::STATUS_PENDING,
			'chunks_done'  => empty( $upload['needs_manifest'] ) ? 1 : 0,
			'chunks_total' => 1,
			'bytes_done'   => empty( $upload['needs_manifest'] ) ? 1 : 0,
			'bytes_total'  => 1,
		);

		foreach ( PackageIndex::component_order() as $name ) {
			if ( ! isset( $by_component[ $name ] ) ) {
				continue;
			}
			$row = $by_component[ $name ];
			$status = JobRepository::STATUS_PENDING;
			if ( $row['done'] >= $row['total'] && $row['total'] > 0 ) {
				$status = JobRepository::STATUS_COMPLETED;
			} elseif ( $row['done'] > 0 ) {
				$status = JobRepository::STATUS_RUNNING;
			}
			$out[] = array(
				'name'         => $name,
				'label'        => PackageIndex::component_label( $name ),
				'status'       => $status,
				'chunks_done'  => $row['done'],
				'chunks_total' => $row['total'],
				'bytes_done'   => $row['bytes_done'],
				'bytes_total'  => $row['bytes_total'],
			);
		}

		return $out;
	}

	/**
	 * Group push queue by component for export checklist UI.
	 *
	 * @param array $queue File queue.
	 * @param int   $sent  Sent index.
	 * @return array
	 */
	public static function components_from_queue( array $queue, $sent ) {
		$by_component = array();
		$sent         = (int) $sent;
		foreach ( $queue as $i => $entry ) {
			$comp = $entry['component'] ?? 'unknown';
			if ( ! isset( $by_component[ $comp ] ) ) {
				$by_component[ $comp ] = array(
					'done'        => 0,
					'total'       => 0,
					'bytes_done'  => 0,
					'bytes_total' => 0,
				);
			}
			$size = (int) ( $entry['size'] ?? 0 );
			$by_component[ $comp ]['total']++;
			$by_component[ $comp ]['bytes_total'] += $size;
			if ( $i < $sent ) {
				$by_component[ $comp ]['done']++;
				$by_component[ $comp ]['bytes_done'] += $size;
			}
		}

		$out = array();
		if ( isset( $by_component['manifest'] ) ) {
			$row = $by_component['manifest'];
			$out[] = self::queue_component_row( 'manifest', $row );
			unset( $by_component['manifest'] );
		}
		foreach ( PackageIndex::component_order() as $name ) {
			if ( ! isset( $by_component[ $name ] ) ) {
				continue;
			}
			$out[] = self::queue_component_row( $name, $by_component[ $name ] );
		}
		return $out;
	}

	/**
	 * @param string $name Component slug.
	 * @param array  $row  Counts.
	 * @return array
	 */
	private static function queue_component_row( $name, array $row ) {
		$status = JobRepository::STATUS_PENDING;
		if ( $row['done'] >= $row['total'] && $row['total'] > 0 ) {
			$status = JobRepository::STATUS_COMPLETED;
		} elseif ( $row['done'] > 0 ) {
			$status = JobRepository::STATUS_RUNNING;
		}
		return array(
			'name'         => $name,
			'label'        => PackageIndex::component_label( $name ),
			'status'       => $status,
			'chunks_done'  => $row['done'],
			'chunks_total' => $row['total'],
			'bytes_done'   => $row['bytes_done'],
			'bytes_total'  => $row['bytes_total'],
		);
	}

	/**
	 * @param array $upload Upload status.
	 * @return array{done:int,total:int}
	 */
	public static function bytes_from_upload_public( array $upload ) {
		return self::bytes_from_upload( $upload );
	}

	private static function bytes_from_upload( array $upload ) {
		$done  = 0;
		$total = 0;
		foreach ( (array) ( $upload['files'] ?? array() ) as $file ) {
			$size = (int) ( $file['size'] ?? 0 );
			$total += $size;
			if ( ! empty( $file['uploaded'] ) ) {
				$done += $size;
			}
		}
		if ( empty( $upload['needs_manifest'] ) ) {
			$total += 1;
			$done  += 1;
		}
		return array( 'done' => $done, 'total' => $total );
	}

	/**
	 * @param array $upload     Upload status.
	 * @param array $components Component rows.
	 * @return string
	 */
	private static function receive_action( array $upload, array $components, $inflight = null, $push_state = null, $verify = null ) {
		if ( ! empty( $upload['needs_manifest'] ) ) {
			return __( 'Waiting for manifest from export site…', 'the-exporter' );
		}
		if ( MigrationState::push_error_visible( $inflight, $push_state ) ) {
			return sprintf(
				/* translators: %s: error message */
				__( 'Export push failed: %s', 'the-exporter' ),
				$push_state['error']
			);
		}
		if ( ! empty( $inflight['path'] ) && ! empty( $inflight['bytes_total'] ) ) {
			$done_mb = round( (int) $inflight['bytes_done'] / 1048576, 1 );
			$total_mb = round( (int) $inflight['bytes_total'] / 1048576, 1 );
			return sprintf(
				/* translators: 1: file path, 2: megabytes done, 3: megabytes total */
				__( 'Receiving %1$s (%2$s / %3$s MB)…', 'the-exporter' ),
				$inflight['path'],
				$done_mb,
				$total_mb
			);
		}
		foreach ( $components as $c ) {
			if ( JobRepository::STATUS_RUNNING === $c['status'] ) {
				return sprintf(
					/* translators: 1: component label, 2: done count, 3: total count */
					__( 'Receiving %1$s (%2$d / %3$d files)', 'the-exporter' ),
					$c['label'],
					(int) $c['chunks_done'],
					(int) $c['chunks_total']
				);
			}
		}
		if ( ! empty( $upload['ready_to_validate'] ) ) {
			return __( 'All packages received — ready to validate.', 'the-exporter' );
		}
		$expected = (int) ( $upload['expected'] ?? 0 );
		$uploaded = (int) ( $upload['uploaded'] ?? 0 );
		if ( $expected > 0 && $uploaded >= $expected ) {
			$verify_total    = is_array( $verify ) ? (int) ( $verify['total'] ?? 0 ) : 0;
			$verify_pending  = is_array( $verify ) ? (int) ( $verify['pending'] ?? 0 ) : 0;
			$verify_verified = is_array( $verify ) ? (int) ( $verify['verified'] ?? 0 ) : 0;
			if ( $verify_total > 0 ) {
				$done_count = max( 0, $verify_verified );
				return sprintf(
					/* translators: 1: verified count, 2: total count */
					__( 'Verifying checksums (%1$d / %2$d files)…', 'the-exporter' ),
					$done_count,
					$verify_total
				);
			}
			if ( $verify_pending > 0 ) {
				return sprintf(
					/* translators: %d: pending file count */
					__( 'Verifying checksums (%d files remaining)…', 'the-exporter' ),
					$verify_pending
				);
			}
			return __( 'Finishing checksum verification…', 'the-exporter' );
		}
		return __( 'Receiving packages…', 'the-exporter' );
	}

	/**
	 * @param array $push Push status.
	 * @return string
	 */
	private static function push_action( array $push, $heartbeat = null ) {
		if ( ! empty( $push['done'] ) ) {
			return __( 'All files sent to import site.', 'the-exporter' );
		}
		if ( ! empty( $push['retrying'] ) && ! empty( $push['last_error'] ) ) {
			return sprintf(
				/* translators: %s: error message */
				__( 'Retrying after error: %s', 'the-exporter' ),
				$push['last_error']
			);
		}
		if ( ! empty( $push['failed'] ) || ( is_array( $heartbeat ) && ! empty( $heartbeat['error'] ) ) ) {
			return __( 'Push failed — server will retry automatically.', 'the-exporter' );
		}
		if ( ! empty( $push['worker_active'] ) ) {
			return sprintf(
				/* translators: 1: sent count, 2: total count */
				__( 'Server transfer running (%1$d / %2$d files)', 'the-exporter' ),
				(int) ( $push['sent'] ?? 0 ),
				(int) ( $push['total'] ?? 0 )
			);
		}
		if ( is_array( $heartbeat ) && ! empty( $heartbeat['current_file']['path'] ) && ! empty( $heartbeat['offset'] ) ) {
			$size = (int) ( $heartbeat['current_file']['size'] ?? 0 );
			if ( $size > 0 ) {
				$pct = (int) floor( ( (int) $heartbeat['offset'] / $size ) * 100 );
				return sprintf(
					/* translators: 1: file path, 2: percent */
					__( 'Sending %1$s (%2$d%%)…', 'the-exporter' ),
					$heartbeat['current_file']['path'],
					$pct
				);
			}
		}
		if ( ! empty( $push['current_file']['path'] ) ) {
			return sprintf(
				/* translators: %s: file path */
				__( 'Sending %s…', 'the-exporter' ),
				$push['current_file']['path']
			);
		}
		if ( ! empty( $push['active'] ) ) {
			return sprintf(
				/* translators: 1: sent count, 2: total count */
				__( 'Sending files (%1$d / %2$d)', 'the-exporter' ),
				(int) ( $push['sent'] ?? 0 ),
				(int) ( $push['total'] ?? 0 )
			);
		}
		return __( 'Starting site-to-site transfer…', 'the-exporter' );
	}

	/**
	 * @param string|null $last_at        ISO timestamp.
	 * @param int         $uploaded       Files uploaded.
	 * @param int         $expected       Files expected.
	 * @param bool        $needs_manifest Waiting for manifest.
	 * @param array|null  $inflight       In-flight chunk state.
	 * @param array|null  $push_state     Relayed export push state.
	 * @return bool
	 */
	private static function is_stale( $last_at, $uploaded, $expected, $needs_manifest, $inflight = null, $push_state = null ) {
		if ( $needs_manifest || $expected <= 0 || $uploaded >= $expected ) {
			return false;
		}
		if ( ! empty( $push_state['error'] ) ) {
			return true;
		}
		if ( ! empty( $inflight['updated_at'] ) && ! empty( $inflight['bytes_total'] ) ) {
			$ts = strtotime( $inflight['updated_at'] );
			if ( $ts ) {
				$file_mb     = max( 1, (int) $inflight['bytes_total'] / 1048576 );
				$stale_after = max( self::STALE_SECONDS, $file_mb * 3 );
				if ( ( time() - $ts ) <= $stale_after ) {
					return false;
				}
			}
		}
		if ( ! empty( $push_state['active'] ) && ! empty( $push_state['updated_at'] ) ) {
			$ts = strtotime( $push_state['updated_at'] );
			if ( $ts && ( time() - $ts ) <= 45 ) {
				return false;
			}
		}
		if ( ! empty( $push_state['worker_active'] ) ) {
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
		return ( time() - $ts ) > self::STALE_SECONDS;
	}

	/**
	 * @param bool       $stale      Whether stale.
	 * @param array|null $push_state Push state.
	 * @return string
	 */
	private static function stale_message( $stale, $push_state = null, $reconciling = false, $inflight = null, $migration_id = '', $receive = array() ) {
		if ( $reconciling ) {
			if ( $stale ) {
				return __( 'Checksum verification stalled — server worker will retry.', 'the-exporter' );
			}
			return __( 'Finishing checksum verification…', 'the-exporter' );
		}
		if ( ! $stale ) {
			return '';
		}
		if ( MigrationState::push_error_visible( $inflight, $push_state ) ) {
			return sprintf(
				/* translators: %s: error message */
				__( 'Export push failed: %s', 'the-exporter' ),
				$push_state['error']
			);
		}
		if ( is_array( $push_state ) && ! empty( $push_state['last_relay_code'] ) && (int) $push_state['last_relay_code'] >= 400 ) {
			return sprintf(
				/* translators: %d: HTTP status code */
				__( 'Export could not update import status (HTTP %d). Keep the Send tab open on the export site.', 'the-exporter' ),
				(int) $push_state['last_relay_code']
			);
		}
		$expected = is_array( $receive ) ? (int) ( $receive['expected'] ?? 0 ) : 0;
		$uploaded = is_array( $receive ) ? (int) ( $receive['uploaded'] ?? 0 ) : 0;
		if ( $expected > 0 && 0 === $uploaded ) {
			return __( 'Waiting for export site to send the first file — keep the Send tab open on the export site.', 'the-exporter' );
		}
		if ( '' !== $migration_id ) {
			return sprintf(
				/* translators: %s: migration UUID */
				__( 'No new files in 30s. On the export site, keep Send open or run: wp the-exporter transfer worker --migration-id=%s', 'the-exporter' ),
				$migration_id
			);
		}
		return __( 'No new files in 30s. On the export site, keep Send open or run: wp the-exporter transfer worker --migration-id=…', 'the-exporter' );
	}


	/**
	 * @param string $option_key   Option key.
	 * @param string $migration_id Migration ID.
	 * @return array
	 */
	private static function get_recent( $option_key, $migration_id ) {
		$all = get_option( $option_key, array() );
		if ( ! is_array( $all ) || empty( $all[ $migration_id ] ) ) {
			return array();
		}
		return array_slice( $all[ $migration_id ], 0, self::LOG_MAX );
	}

	/**
	 * @param string $option_key   Option key.
	 * @param string $migration_id Migration ID.
	 * @param string $path         Path.
	 * @param string $component    Component.
	 * @param int    $size         Size.
	 */
	private static function append_log( $option_key, $migration_id, $path, $component, $size ) {
		$all = get_option( $option_key, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		if ( ! isset( $all[ $migration_id ] ) || ! is_array( $all[ $migration_id ] ) ) {
			$all[ $migration_id ] = array();
		}
		array_unshift(
			$all[ $migration_id ],
			array(
				'path'         => $path,
				'component'    => $component,
				'size'         => (int) $size,
				'received_at'  => gmdate( 'c' ),
			)
		);
		$all[ $migration_id ] = array_slice( $all[ $migration_id ], 0, self::LOG_MAX );
		update_option( $option_key, $all, false );
	}

	/**
	 * @param string $migration_id Migration ID.
	 * @return array
	 */
	private static function get_receive_counters( $migration_id ) {
		$all = get_option( self::OPTION_RECEIVE_COUNTERS, array() );
		if ( ! is_array( $all ) || empty( $all[ $migration_id ] ) ) {
			return array();
		}
		return $all[ $migration_id ];
	}

	/**
	 * @param string $migration_id Migration ID.
	 * @param string $path         Path.
	 * @param string $component    Component.
	 * @param int    $size         Size.
	 */
	private static function bump_receive_counter( $migration_id, $path, $component, $size ) {
		$all = get_option( self::OPTION_RECEIVE_COUNTERS, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		if ( empty( $all[ $migration_id ] ) ) {
			$all[ $migration_id ] = array(
				'uploaded'      => 0,
				'expected'      => 0,
				'bytes_done'    => 0,
				'bytes_total'   => 0,
				'manifest'      => false,
				'catalog_ready' => false,
				'by_component'  => array(),
				'paths'         => array(),
			);
		}
		$c = &$all[ $migration_id ];
		$size = (int) $size;

		if ( 'manifest' === $component || 'manifest.json' === $path ) {
			$c['manifest'] = true;
			self::init_catalog_counters( $migration_id, $c );
		} else {
			$c['uploaded']++;
			$c['bytes_done'] += $size;
			if ( ! isset( $c['by_component'][ $component ] ) ) {
				$c['by_component'][ $component ] = array(
					'done'        => 0,
					'total'       => 0,
					'bytes_done'  => 0,
					'bytes_total' => 0,
				);
			}
			$c['by_component'][ $component ]['done']++;
			$c['by_component'][ $component ]['bytes_done'] += $size;
		}

		update_option( self::OPTION_RECEIVE_COUNTERS, $all, false );
	}

	/**
	 * @param string $migration_id Migration ID.
	 * @param string $path         Path.
	 * @return bool
	 */
	private static function receive_path_logged( $migration_id, $path ) {
		$c = self::get_receive_counters( $migration_id );
		return ! empty( $c['paths'][ $path ] );
	}

	/**
	 * @param string $migration_id Migration ID.
	 * @param string $path         Path.
	 */
	private static function mark_receive_path_logged( $migration_id, $path ) {
		$all = get_option( self::OPTION_RECEIVE_COUNTERS, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		$defaults = array(
			'uploaded'      => 0,
			'expected'      => 0,
			'bytes_done'    => 0,
			'bytes_total'   => 0,
			'manifest'      => false,
			'catalog_ready' => false,
			'by_component'  => array(),
			'paths'         => array(),
		);
		if ( empty( $all[ $migration_id ] ) || ! is_array( $all[ $migration_id ] ) ) {
			$all[ $migration_id ] = $defaults;
		} else {
			$all[ $migration_id ] = array_merge( $defaults, $all[ $migration_id ] );
		}
		$all[ $migration_id ]['paths'][ $path ] = true;
		update_option( self::OPTION_RECEIVE_COUNTERS, $all, false );
	}

	/**
	 * Rebuild receive counter cache from disk catalog (authority = disk).
	 *
	 * @param string $migration_id Migration ID.
	 */
	public static function sync_counters_from_disk( $migration_id ) {
		$migration_id = sanitize_text_field( $migration_id );
		$upload       = FileUploader::migration_upload_status( $migration_id, array( 'lightweight' => true ) );
		if ( ! empty( $upload['needs_manifest'] ) ) {
			return;
		}

		$all = get_option( self::OPTION_RECEIVE_COUNTERS, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		$prev_paths = array();
		if ( ! empty( $all[ $migration_id ]['paths'] ) && is_array( $all[ $migration_id ]['paths'] ) ) {
			$prev_paths = $all[ $migration_id ]['paths'];
		}

		$c = array(
			'uploaded'      => (int) ( $upload['uploaded'] ?? 0 ),
			'expected'      => (int) ( $upload['expected'] ?? 0 ),
			'bytes_done'    => 0,
			'bytes_total'   => 0,
			'manifest'      => true,
			'catalog_ready' => true,
			'by_component'  => array(),
			'paths'         => array(),
		);

		$bytes = self::bytes_from_upload( $upload );
		$c['bytes_done']  = (int) $bytes['done'];
		$c['bytes_total'] = (int) $bytes['total'];

		foreach ( (array) ( $upload['files'] ?? array() ) as $file ) {
			$comp = $file['component'] ?? 'unknown';
			if ( ! isset( $c['by_component'][ $comp ] ) ) {
				$c['by_component'][ $comp ] = array(
					'done'        => 0,
					'total'       => 0,
					'bytes_done'  => 0,
					'bytes_total' => 0,
				);
			}
			$fs = (int) ( $file['size'] ?? 0 );
			$c['by_component'][ $comp ]['total']++;
			$c['by_component'][ $comp ]['bytes_total'] += $fs;
			if ( ! empty( $file['uploaded'] ) ) {
				$c['by_component'][ $comp ]['done']++;
				$c['by_component'][ $comp ]['bytes_done'] += $fs;
				if ( ! empty( $file['path'] ) ) {
					$c['paths'][ $file['path'] ] = true;
				}
			}
		}

		foreach ( $prev_paths as $path => $logged ) {
			if ( $logged && empty( $c['paths'][ $path ] ) ) {
				unset( $prev_paths[ $path ] );
			}
		}
		$c['paths'] = array_merge( $prev_paths, $c['paths'] );

		$all[ $migration_id ] = $c;
		update_option( self::OPTION_RECEIVE_COUNTERS, $all, false );
	}

	/**
	 * @param string $migration_id Migration ID.
	 * @param string $path         Path.
	 */
	private static function unmark_receive_path_logged( $migration_id, $path ) {
		$all = get_option( self::OPTION_RECEIVE_COUNTERS, array() );
		if ( is_array( $all ) && ! empty( $all[ $migration_id ]['paths'][ $path ] ) ) {
			unset( $all[ $migration_id ]['paths'][ $path ] );
			update_option( self::OPTION_RECEIVE_COUNTERS, $all, false );
		}
	}

	/**
	 * @param string $migration_id Migration ID.
	 * @param string $path         Path.
	 * @return int Size removed from counters.
	 */
	private static function unbump_receive_counter( $migration_id, $path ) {
		$all = get_option( self::OPTION_RECEIVE_COUNTERS, array() );
		if ( ! is_array( $all ) || empty( $all[ $migration_id ] ) || empty( $all[ $migration_id ]['paths'][ $path ] ) ) {
			return 0;
		}

		$c    = &$all[ $migration_id ];
		$size = 0;
		$base = Settings::migration_path( $migration_id, 'import' );
		$full = trailingslashit( $base ) . $path;
		if ( file_exists( $full ) ) {
			$size = (int) filesize( $full );
		}

		if ( 'manifest.json' === $path ) {
			$c['manifest'] = false;
		} else {
			$c['uploaded']   = max( 0, (int) ( $c['uploaded'] ?? 0 ) - 1 );
			$c['bytes_done'] = max( 0, (int) ( $c['bytes_done'] ?? 0 ) - $size );
			$parts           = explode( '/', $path );
			$component       = isset( $parts[0] ) ? sanitize_key( $parts[0] ) : '';
			if ( $component && ! empty( $c['by_component'][ $component ] ) ) {
				$c['by_component'][ $component ]['done']       = max( 0, (int) $c['by_component'][ $component ]['done'] - 1 );
				$c['by_component'][ $component ]['bytes_done'] = max( 0, (int) $c['by_component'][ $component ]['bytes_done'] - $size );
			}
		}

		unset( $c['paths'][ $path ] );
		update_option( self::OPTION_RECEIVE_COUNTERS, $all, false );
		return $size;
	}

	/**
	 * Seed expected totals from manifest catalog (once).
	 *
	 * @param string $migration_id Migration ID.
	 * @param array  $c            Counter ref.
	 */
	private static function init_catalog_counters( $migration_id, array &$c ) {
		$upload = FileUploader::migration_upload_status( $migration_id, array( 'lightweight' => true ) );
		if ( ! empty( $upload['needs_manifest'] ) ) {
			return;
		}
		$c['catalog_ready'] = true;
		$c['expected']      = (int) ( $upload['expected'] ?? 0 );
		$c['uploaded']      = max( (int) ( $upload['uploaded'] ?? 0 ), 1 );
		$bytes              = self::bytes_from_upload( $upload );
		$c['bytes_total']   = $bytes['total'];
		$c['bytes_done']    = max( $bytes['done'], $c['bytes_done'] );

		$c['by_component'] = array();
		foreach ( (array) ( $upload['files'] ?? array() ) as $file ) {
			$comp = $file['component'] ?? 'unknown';
			if ( ! isset( $c['by_component'][ $comp ] ) ) {
				$c['by_component'][ $comp ] = array(
					'done'        => 0,
					'total'       => 0,
					'bytes_done'  => 0,
					'bytes_total' => 0,
				);
			}
			$fs = (int) ( $file['size'] ?? 0 );
			$c['by_component'][ $comp ]['total']++;
			$c['by_component'][ $comp ]['bytes_total'] += $fs;
			if ( ! empty( $file['uploaded'] ) ) {
				$c['by_component'][ $comp ]['done']++;
				$c['by_component'][ $comp ]['bytes_done'] += $fs;
			}
		}
	}

	/**
	 * @param string $migration_id Migration ID.
	 * @param array  $c            Counters.
	 * @return array
	 */
	private static function upload_from_counters( $migration_id, array $c ) {
		$expected = (int) ( $c['expected'] ?? 0 );
		$uploaded = (int) ( $c['uploaded'] ?? 0 );
		$ready    = $expected > 0 && $uploaded >= $expected;
		if ( $ready ) {
			$ready = VerifyQueue::migration_ready( $migration_id );
		}
		return array(
			'migration_id'      => $migration_id,
			'needs_manifest'    => empty( $c['manifest'] ),
			'expected'          => $expected,
			'uploaded'          => $uploaded,
			'ready_to_validate' => $ready,
			'bytes_done'        => (int) ( $c['bytes_done'] ?? 0 ),
			'bytes_total'       => (int) ( $c['bytes_total'] ?? 0 ),
		);
	}

	/**
	 * @param array $c Counters.
	 * @return array
	 */
	private static function components_from_counters( array $c ) {
		$out   = array();
		$out[] = array(
			'name'         => 'manifest',
			'label'        => PackageIndex::component_label( 'manifest' ),
			'status'       => ! empty( $c['manifest'] ) ? JobRepository::STATUS_COMPLETED : JobRepository::STATUS_PENDING,
			'chunks_done'  => ! empty( $c['manifest'] ) ? 1 : 0,
			'chunks_total' => 1,
			'bytes_done'   => ! empty( $c['manifest'] ) ? 1 : 0,
			'bytes_total'  => 1,
		);
		foreach ( PackageIndex::component_order() as $name ) {
			if ( empty( $c['by_component'][ $name ] ) ) {
				continue;
			}
			$row = $c['by_component'][ $name ];
			$status = JobRepository::STATUS_PENDING;
			if ( $row['done'] >= $row['total'] && $row['total'] > 0 ) {
				$status = JobRepository::STATUS_COMPLETED;
			} elseif ( $row['done'] > 0 ) {
				$status = JobRepository::STATUS_RUNNING;
			}
			$out[] = array(
				'name'         => $name,
				'label'        => PackageIndex::component_label( $name ),
				'status'       => $status,
				'chunks_done'  => (int) $row['done'],
				'chunks_total' => (int) $row['total'],
				'bytes_done'   => (int) $row['bytes_done'],
				'bytes_total'  => (int) $row['bytes_total'],
			);
		}
		return $out;
	}
}
