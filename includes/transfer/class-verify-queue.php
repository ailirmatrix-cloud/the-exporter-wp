<?php
/**
 * Background SHA256 verification for server-to-server transfers.
 *
 * @package TheExporter
 */

namespace TheExporter\Transfer;

use TheExporter\Logging\AuditLogger;
use TheExporter\Settings;
use TheExporter\Validation\ChecksumService;

defined( 'ABSPATH' ) || exit;

/**
 * Class VerifyQueue
 */
class VerifyQueue {

	const OPTION_QUEUE = 'te_transfer_verify_queue';
	const OPTION_STATE = 'te_transfer_verify_state';
	const HOOK_VERIFY  = 'te_verify_upload';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( self::HOOK_VERIFY, array( __CLASS__, 'process_item' ), 10, 1 );
	}

	/**
	 * Queue a file for background verification.
	 *
	 * @param string $migration_id      Migration ID.
	 * @param string $component         Component slug.
	 * @param string $relative_path     Relative path.
	 * @param string $dest_path         Absolute file path.
	 * @param string $expected_checksum Expected SHA256.
	 */
	public static function enqueue( $migration_id, $component, $relative_path, $dest_path, $expected_checksum ) {
		$migration_id      = sanitize_text_field( $migration_id );
		$component         = sanitize_key( $component );
		$relative_path     = sanitize_text_field( $relative_path );
		$expected_checksum = sanitize_text_field( $expected_checksum );

		if ( '' === $migration_id || '' === $relative_path || '' === $expected_checksum || ! file_exists( $dest_path ) ) {
			return;
		}

		$key = self::item_key( $relative_path );
		self::set_state(
			$migration_id,
			$key,
			array(
				'status'   => 'pending',
				'path'     => $relative_path,
				'component'=> $component,
				'checksum' => $expected_checksum,
			)
		);

		$queue = self::get_queue();
		$queue[ $migration_id ][ $key ] = array(
			'component'         => $component,
			'relative_path'     => $relative_path,
			'dest_path'         => $dest_path,
			'expected_checksum' => $expected_checksum,
			'enqueued_at'       => gmdate( 'c' ),
		);
		update_option( self::OPTION_QUEUE, $queue, false );

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time(), self::HOOK_VERIFY, array(
				'migration_id'  => $migration_id,
				'relative_path' => $relative_path,
			), 'the-exporter' );
		} else {
			wp_schedule_single_event( time(), self::HOOK_VERIFY, array( $migration_id, $relative_path ) );
		}
	}

	/**
	 * Process one queued verification.
	 *
	 * @param array|string $args Args or migration ID (WP-Cron).
	 * @param string       $path_arg Relative path (WP-Cron).
	 */
	public static function process_item( $args, $path_arg = '' ) {
		if ( is_array( $args ) ) {
			$migration_id  = sanitize_text_field( $args['migration_id'] ?? '' );
			$relative_path = sanitize_text_field( $args['relative_path'] ?? '' );
		} else {
			$migration_id  = sanitize_text_field( $args );
			$relative_path = sanitize_text_field( $path_arg );
		}

		if ( '' === $migration_id || '' === $relative_path ) {
			return;
		}

		$queue = self::get_queue();
		$key   = self::item_key( $relative_path );
		if ( empty( $queue[ $migration_id ][ $key ] ) ) {
			return;
		}

		$item      = $queue[ $migration_id ][ $key ];
		$dest_path = $item['dest_path'];
		$expected  = $item['expected_checksum'];

		if ( ! file_exists( $dest_path ) ) {
			self::mark_failed( $migration_id, $key, $relative_path, $item['component'], 'File missing before verify' );
			unset( $queue[ $migration_id ][ $key ] );
			update_option( self::OPTION_QUEUE, $queue, false );
			return;
		}

		$actual = ChecksumService::hash_file( $dest_path );
		if ( hash_equals( strtolower( $expected ), strtolower( (string) $actual ) ) ) {
			self::set_state(
				$migration_id,
				$key,
				array(
					'status'    => 'ok',
					'path'      => $relative_path,
					'component' => $item['component'],
					'checksum'  => $expected,
				)
			);
			unset( $queue[ $migration_id ][ $key ] );
			update_option( self::OPTION_QUEUE, $queue, false );
			return;
		}

		TransferRepair::purge_file( $migration_id, $relative_path, $item['component'] );
		self::mark_failed( $migration_id, $key, $relative_path, $item['component'], 'Checksum mismatch after verify' );
		unset( $queue[ $migration_id ][ $key ] );
		update_option( self::OPTION_QUEUE, $queue, false );

		AuditLogger::log(
			'transfer_checksum_fail',
			'Deferred verify failed: ' . $relative_path,
			array(
				'migration_id' => $migration_id,
				'component'    => $item['component'],
			),
			'error'
		);
	}

	/**
	 * Whether file on disk matches expected checksum (sync).
	 *
	 * @param string $path              Absolute path.
	 * @param string $expected_checksum Expected hash.
	 * @return bool
	 */
	public static function file_matches( $path, $expected_checksum ) {
		if ( ! $expected_checksum || ! file_exists( $path ) ) {
			return false;
		}
		return ChecksumService::verify_file( $path, $expected_checksum );
	}

	/**
	 * Whether file is verified or pending verify for skip logic.
	 *
	 * @param string $migration_id      Migration ID.
	 * @param string $relative_path     Path.
	 * @param string $dest_path         Absolute path.
	 * @param string $expected_checksum Expected hash.
	 * @return bool
	 */
	public static function accept_existing_file( $migration_id, $relative_path, $dest_path, $expected_checksum ) {
		$key   = self::item_key( $relative_path );
		$state = self::get_item_state( $migration_id, $key );

		if ( ! empty( $state['status'] ) && 'failed' === $state['status'] ) {
			TransferRepair::purge_file( $migration_id, $relative_path, $state['component'] ?? '' );
			return false;
		}

		if ( self::file_matches( $dest_path, $expected_checksum ) ) {
			self::set_state(
				$migration_id,
				$key,
				array(
					'status'    => 'ok',
					'path'      => $relative_path,
					'component' => $state['component'] ?? '',
					'checksum'  => $expected_checksum,
				)
			);
			return true;
		}

		if ( ! empty( $state['status'] ) && 'pending' === $state['status'] ) {
			return true;
		}

		TransferRepair::purge_file( $migration_id, $relative_path, $state['component'] ?? '' );
		return false;
	}

	/**
	 * Whether all catalog files for a component are verified.
	 *
	 * @param string $migration_id Migration ID.
	 * @param string $component    Component slug.
	 * @return bool
	 */
	public static function component_ready( $migration_id, $component ) {
		$queue = self::get_queue();
		if ( ! empty( $queue[ $migration_id ] ) ) {
			foreach ( $queue[ $migration_id ] as $item ) {
				if ( ( $item['component'] ?? '' ) === $component ) {
					return false;
				}
			}
		}

		$state_all = self::get_state( $migration_id );
		foreach ( $state_all as $entry ) {
			if ( ( $entry['component'] ?? '' ) === $component && 'pending' === ( $entry['status'] ?? '' ) ) {
				return false;
			}
			if ( ( $entry['component'] ?? '' ) === $component && 'failed' === ( $entry['status'] ?? '' ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether migration has pending or failed verifications.
	 *
	 * @param string $migration_id Migration ID.
	 * @return bool
	 */
	public static function migration_ready( $migration_id ) {
		$queue = self::get_queue();
		if ( ! empty( $queue[ $migration_id ] ) ) {
			return false;
		}

		foreach ( self::get_state( $migration_id ) as $entry ) {
			if ( in_array( $entry['status'] ?? '', array( 'pending', 'failed' ), true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Count verify queue/state entries for UI and nudge decisions.
	 *
	 * @param string $migration_id Migration ID.
	 * @return array{pending:int,verified:int,failed:int,total:int}
	 */
	public static function pending_stats( $migration_id ) {
		$migration_id = sanitize_text_field( $migration_id );
		$queue        = self::get_queue();
		$state        = self::get_state( $migration_id );
		$queued_keys  = array();

		$pending = 0;
		if ( ! empty( $queue[ $migration_id ] ) && is_array( $queue[ $migration_id ] ) ) {
			$pending += count( $queue[ $migration_id ] );
			$queued_keys = array_keys( $queue[ $migration_id ] );
		}

		$verified = 0;
		$failed   = 0;
		foreach ( $state as $key => $entry ) {
			$status = $entry['status'] ?? '';
			if ( 'ok' === $status ) {
				$verified++;
			} elseif ( 'failed' === $status ) {
				$failed++;
			} elseif ( 'pending' === $status && ! in_array( $key, $queued_keys, true ) ) {
				$pending++;
			}
		}

		return array(
			'pending'  => $pending,
			'verified' => $verified,
			'failed'   => $failed,
			'total'    => $pending + $verified + $failed,
		);
	}

	/**
	 * Drain pending verify jobs with a time/item budget (receive poll nudge).
	 *
	 * @param string $migration_id Migration ID.
	 * @param int    $max_seconds  Max wall time.
	 * @param int    $max_items    Max items per call.
	 * @return array{processed:int,remaining:int,done:bool}
	 */
	public static function flush_pending_budget( $migration_id, $max_seconds, $max_items ) {
		$migration_id = sanitize_text_field( $migration_id );
		$max_seconds  = max( 1, (int) $max_seconds );
		$max_items    = max( 1, (int) $max_items );
		$started      = microtime( true );
		$processed    = 0;

		while ( $processed < $max_items && ( microtime( true ) - $started ) < $max_seconds ) {
			$queue = self::get_queue();
			if ( empty( $queue[ $migration_id ] ) ) {
				break;
			}
			$items = $queue[ $migration_id ];
			$key   = (string) array_key_first( $items );
			if ( '' === $key || empty( $items[ $key ] ) ) {
				break;
			}
			$item = $items[ $key ];
			self::process_item(
				array(
					'migration_id'  => $migration_id,
					'relative_path' => $item['relative_path'],
				)
			);
			$processed++;
		}

		$remaining = 0;
		$queue     = self::get_queue();
		if ( ! empty( $queue[ $migration_id ] ) ) {
			$remaining = count( $queue[ $migration_id ] );
		}

		return array(
			'processed' => $processed,
			'remaining' => $remaining,
			'done'      => 0 === $remaining,
		);
	}

	/**
	 * Resolve orphan pending state rows (queue drained but state still pending).
	 *
	 * @param string $migration_id Migration ID.
	 * @return int Number reconciled.
	 */
	public static function reconcile_orphan_pending( $migration_id ) {
		$migration_id = sanitize_text_field( $migration_id );
		$queue        = self::get_queue();
		$queued_keys  = ! empty( $queue[ $migration_id ] ) ? array_keys( $queue[ $migration_id ] ) : array();
		$state        = self::get_state( $migration_id );
		$base         = Settings::migration_path( $migration_id, 'import' );
		$reconciled   = 0;

		if ( ! $base || ! is_dir( $base ) ) {
			return 0;
		}

		foreach ( $state as $key => $entry ) {
			if ( 'pending' !== ( $entry['status'] ?? '' ) || in_array( $key, $queued_keys, true ) ) {
				continue;
			}

			$relative_path = $entry['path'] ?? '';
			$expected      = $entry['checksum'] ?? '';
			$component     = $entry['component'] ?? '';
			if ( '' === $relative_path || '' === $expected ) {
				continue;
			}

			$dest_path = trailingslashit( $base ) . $relative_path;
			if ( ! file_exists( $dest_path ) ) {
				self::mark_failed( $migration_id, $key, $relative_path, $component, 'File missing during orphan reconcile' );
				$reconciled++;
				continue;
			}

			if ( self::file_matches( $dest_path, $expected ) ) {
				self::set_state(
					$migration_id,
					$key,
					array(
						'status'    => 'ok',
						'path'      => $relative_path,
						'component' => $component,
						'checksum'  => $expected,
					)
				);
				$reconciled++;
				continue;
			}

			TransferRepair::purge_file( $migration_id, $relative_path, $component );
			self::mark_failed( $migration_id, $key, $relative_path, $component, 'Checksum mismatch during orphan reconcile' );
			$reconciled++;
		}

		return $reconciled;
	}

	/**
	 * Advance deferred verify during receive polling (Studio cron often idle).
	 *
	 * @param string $migration_id Migration ID.
	 * @return array{pending:int,verified:int,total:int,done:bool,processed:int,remaining:int}
	 */
	public static function nudge_on_receive_poll( $migration_id ) {
		$migration_id = sanitize_text_field( $migration_id );
		$stats        = self::pending_stats( $migration_id );

		if ( self::migration_ready( $migration_id ) ) {
			return array_merge(
				$stats,
				array(
					'done'       => true,
					'processed'  => 0,
					'remaining'  => 0,
				)
			);
		}

		$upload = FileUploader::migration_upload_status( $migration_id, array( 'lightweight' => true ) );
		$expected = (int) ( $upload['expected'] ?? 0 );
		$uploaded = (int) ( $upload['uploaded'] ?? 0 );
		if ( $expected <= 0 || $uploaded < $expected || ! empty( $upload['needs_manifest'] ) ) {
			return array_merge(
				$stats,
				array(
					'done'       => false,
					'processed'  => 0,
					'remaining'  => $stats['pending'],
				)
			);
		}

		if ( Settings::is_localhost_studio() ) {
			$max_seconds = 25;
			$max_items   = 200;
		} else {
			$max_seconds = 8;
			$max_items   = 40;
		}

		$flush = self::flush_pending_budget( $migration_id, $max_seconds, $max_items );
		self::reconcile_orphan_pending( $migration_id );

		$stats = self::pending_stats( $migration_id );
		return array_merge(
			$stats,
			array(
				'done'      => self::migration_ready( $migration_id ),
				'processed' => (int) $flush['processed'],
				'remaining' => (int) $flush['remaining'],
			)
		);
	}

	/**
	 * Drain pending verify jobs synchronously (import validate gate).
	 *
	 * @param string $migration_id Migration ID.
	 */
	public static function flush_pending( $migration_id ) {
		$queue = self::get_queue();
		if ( empty( $queue[ $migration_id ] ) ) {
			return;
		}
		$items = $queue[ $migration_id ];
		foreach ( array_keys( $items ) as $key ) {
			$item = $items[ $key ];
			self::process_item(
				array(
					'migration_id'  => $migration_id,
					'relative_path' => $item['relative_path'],
				)
			);
		}
	}

	/**
	 * @param string $migration_id Migration ID.
	 */
	public static function clear_migration( $migration_id ) {
		$queue = self::get_queue();
		unset( $queue[ $migration_id ] );
		update_option( self::OPTION_QUEUE, $queue, false );

		$state = self::get_state_all();
		unset( $state[ $migration_id ] );
		update_option( self::OPTION_STATE, $state, false );
	}

	/**
	 * @param string $relative_path Path.
	 * @return string
	 */
	private static function item_key( $relative_path ) {
		return substr( hash( 'sha256', $relative_path ), 0, 16 );
	}

	/**
	 * @return array
	 */
	private static function get_queue() {
		$queue = get_option( self::OPTION_QUEUE, array() );
		return is_array( $queue ) ? $queue : array();
	}

	/**
	 * @param string $migration_id Migration ID.
	 * @return array
	 */
	private static function get_state( $migration_id ) {
		$all = self::get_state_all();
		return isset( $all[ $migration_id ] ) && is_array( $all[ $migration_id ] ) ? $all[ $migration_id ] : array();
	}

	/**
	 * @return array
	 */
	private static function get_state_all() {
		$all = get_option( self::OPTION_STATE, array() );
		return is_array( $all ) ? $all : array();
	}

	/**
	 * @param string $migration_id Migration ID.
	 * @param string $key          Item key.
	 * @return array
	 */
	private static function get_item_state( $migration_id, $key ) {
		$state = self::get_state( $migration_id );
		return isset( $state[ $key ] ) && is_array( $state[ $key ] ) ? $state[ $key ] : array();
	}

	/**
	 * @param string $migration_id Migration ID.
	 * @param string $key          Item key.
	 * @param array  $patch        State patch.
	 */
	private static function set_state( $migration_id, $key, array $patch ) {
		$all = self::get_state_all();
		if ( ! isset( $all[ $migration_id ] ) || ! is_array( $all[ $migration_id ] ) ) {
			$all[ $migration_id ] = array();
		}
		$prev = isset( $all[ $migration_id ][ $key ] ) ? $all[ $migration_id ][ $key ] : array();
		$all[ $migration_id ][ $key ] = array_merge( $prev, $patch );
		update_option( self::OPTION_STATE, $all, false );
	}

	/**
	 * @param string $migration_id  Migration ID.
	 * @param string $key           Item key.
	 * @param string $relative_path Path.
	 * @param string $component     Component.
	 * @param string $reason        Failure reason.
	 */
	private static function mark_failed( $migration_id, $key, $relative_path, $component, $reason ) {
		self::set_state(
			$migration_id,
			$key,
			array(
				'status'    => 'failed',
				'path'      => $relative_path,
				'component' => $component,
				'error'     => $reason,
			)
		);
	}
}
