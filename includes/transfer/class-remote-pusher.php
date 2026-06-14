<?php
/**
 * Push migration packages to a connected import site over HTTPS.
 *
 * @package TheExporter
 */

namespace TheExporter\Transfer;

use TheExporter\Jobs\JobRepository;
use TheExporter\Jobs\Scheduler;
use TheExporter\Logging\AuditLogger;
use TheExporter\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class RemotePusher
 */
class RemotePusher {

	const STEP_COMPONENT       = 'transfer';
	const PARALLEL_MAX         = 2;
	const PARALLEL_MAX_BYTES   = 2097152; // 2 MB.
	const CONNECTED_PARALLEL_MAX       = 3;
	const CONNECTED_PARALLEL_MAX_BYTES = 33554432; // 32 MB.
	const WORKER_PARALLEL_MAX          = 4;
	const WORKER_PARALLEL_MAX_BYTES    = 8388608; // 8 MB.
	const CHUNK_SIZE                = 33554432; // 32 MB.
	const CHUNK_THRESHOLD           = 67108864; // 64 MB.
	const WORKER_CHUNK_THRESHOLD    = 16777216; // 16 MB.
	const PUSH_RETRY_MAX            = 3;
	const MAX_CONSECUTIVE_ERRORS    = 10;

	/**
	 * Cached push URL for current request/job.
	 *
	 * @var string|null
	 */
	private static $cached_push_url = null;

	/**
	 * Throttle reconcile_sent_index during worker ticks.
	 *
	 * @var int
	 */
	private static $reconcile_tick_counter = 0;

	/**
	 * Queue background push job.
	 *
	 * @param string $migration_id Migration ID.
	 * @return array
	 */
	public static function queue_push( $migration_id ) {
		if ( ! Settings::is_connected_transfer() ) {
			return array( 'success' => false, 'error' => 'Connected site mode is not enabled.' );
		}

		$remote_url = Settings::remote_site_url();
		$token      = Settings::get( 'remote_pairing_token', '' );
		if ( ! $remote_url || '' === trim( (string) $token ) ) {
			return array( 'success' => false, 'error' => 'Import site URL and pairing code are required in Settings.' );
		}

		$verify = RemoteAuth::verify_remote_site( $remote_url, $token );
		if ( empty( $verify['success'] ) ) {
			return array( 'success' => false, 'error' => isset( $verify['error'] ) ? $verify['error'] : 'Could not verify import site.' );
		}

		$path = Settings::migration_path( $migration_id, 'export' );
		if ( ! is_dir( $path ) || ! file_exists( $path . '/manifest.json' ) ) {
			return array( 'success' => false, 'error' => 'Export package not found. Finalize export first.' );
		}

		$existing = JobRepository::get_job_by_migration( $migration_id, 'push' );
		if ( $existing && in_array( $existing['status'], array( JobRepository::STATUS_PENDING, JobRepository::STATUS_RUNNING ), true ) ) {
			TransferWorker::ensure_running( $migration_id );
			TransferWorker::chain_loopback( $migration_id );
			return array(
				'success' => true,
				'job_id'  => (int) $existing['id'],
				'queued'  => true,
				'message' => 'Push already in progress.',
			);
		}
		if ( $existing && JobRepository::STATUS_FAILED === $existing['status'] ) {
			self::resume_push_job( $existing );
			TransferWorker::ensure_running( $migration_id );
			TransferWorker::chain_loopback( $migration_id );
			return array(
				'success'  => true,
				'job_id'   => (int) $existing['id'],
				'resumed'  => true,
				'message'  => 'Resumed failed push job.',
			);
		}

		$queue = self::build_file_queue( $migration_id );
		if ( empty( $queue ) ) {
			return array( 'success' => false, 'error' => 'No files to push.' );
		}

		$job_id = JobRepository::create_job( $migration_id, 'push', array(
			'remote_url' => $remote_url,
			'total'      => count( $queue ),
		) );
		if ( ! $job_id ) {
			return array( 'success' => false, 'error' => 'Could not create push job.' );
		}

		$step_id = JobRepository::create_step( $job_id, self::STEP_COMPONENT );
		JobRepository::update_step( $step_id, array(
			'status'       => JobRepository::STATUS_RUNNING,
			'total_chunks' => count( $queue ),
			'meta'         => array(
				'sent_index' => 0,
			),
		) );
		JobRepository::update_job_status( $job_id, JobRepository::STATUS_RUNNING );
		Scheduler::schedule( $job_id, self::STEP_COMPONENT, 0 );

		AuditLogger::log( 'remote_push_queued', 'Queued site-to-site transfer', array(
			'migration_id' => $migration_id,
			'files'        => count( $queue ),
		), 'success' );

		TransferWorker::ensure_running( $migration_id );
		TransferWorker::chain_loopback( $migration_id );

		return array(
			'success' => true,
			'job_id'  => $job_id,
			'total'   => count( $queue ),
		);
	}

	/**
	 * Process one file push tick.
	 *
	 * @param int $job_id Job ID.
	 * @return array
	 */
	public static function process_tick( $job_id ) {
		$job = JobRepository::get_job( $job_id );
		if ( ! $job || 'push' !== $job['type'] ) {
			return array( 'success' => false, 'error' => 'Push job not found' );
		}

		$step = JobRepository::get_step( $job_id, self::STEP_COMPONENT );
		if ( ! $step ) {
			return array( 'success' => false, 'error' => 'Push step missing' );
		}

		if ( JobRepository::STATUS_COMPLETED === $step['status'] ) {
			return array( 'success' => true, 'done' => true );
		}

		$meta  = is_array( $step['meta'] ) ? $step['meta'] : array();
		$queue = self::resolve_queue( $job['migration_id'], $meta );
		$index = isset( $meta['sent_index'] ) ? (int) $meta['sent_index'] : 0;
		$index = max( $index, (int) ( $step['completed_chunks'] ?? 0 ) );

		$meta['max_chunks_per_call'] = self::is_worker_driven() ? 8 : 4;
		$index = self::advance_past_received_on_import( $job['migration_id'], $index, $queue, $meta, $step, $job_id );

		if ( $index >= count( $queue ) ) {
			if ( ! self::import_uploads_complete( $job['migration_id'], count( $queue ) ) ) {
				self::maybe_reopen_incomplete_push( $job['migration_id'], $queue );
				$step = JobRepository::get_step( $job_id, self::STEP_COMPONENT );
				$meta = $step && is_array( $step['meta'] ) ? $step['meta'] : $meta;
				$index = isset( $meta['sent_index'] ) ? (int) $meta['sent_index'] : $index;
				if ( $index >= count( $queue ) ) {
					return array( 'success' => true, 'done' => false, 'sent' => $index, 'total' => count( $queue ), 'waiting_import' => true );
				}
			} else {
				JobRepository::update_step( (int) $step['id'], array(
					'status'           => JobRepository::STATUS_COMPLETED,
					'completed_chunks' => count( $queue ),
				) );
				JobRepository::update_job_status( $job_id, JobRepository::STATUS_COMPLETED );
				return array( 'success' => true, 'done' => true, 'sent' => count( $queue ) );
			}
		}

		$file_entry = $queue[ $index ];
		self::touch_heartbeat( $job['migration_id'], $file_entry, 0, 'sending' );

		$parallel = self::collect_parallel_batch( $job['migration_id'], $queue, $index );
		if ( count( $parallel ) > 1 ) {
			return self::process_parallel_entries( $job, $step, $meta, $queue, $index, $parallel );
		}

		$result = self::push_file_with_retry( $job['migration_id'], $file_entry, $meta );

		if ( ! empty( $result['success'] ) && ! empty( $result['partial'] ) ) {
			JobRepository::update_step(
				(int) $step['id'],
				array(
					'status'           => JobRepository::STATUS_RUNNING,
					'completed_chunks' => $index,
					'total_chunks'     => count( $queue ),
					'meta'             => $meta,
				)
			);
			self::touch_heartbeat( $job['migration_id'], $file_entry, (int) ( $meta['chunk_offset'] ?? 0 ), 'sending' );
			self::relay_push_state( $job['migration_id'], array( 'active' => true, 'sent' => $index ) );
			return array(
				'success' => true,
				'partial' => true,
				'done'    => false,
				'sent'    => $index,
				'total'   => count( $queue ),
			);
		}

		if ( empty( $result['success'] ) ) {
			return self::handle_push_failure( $job, $step, $meta, $queue, $index, $file_entry, $result );
		}

		unset( $meta['chunk_path'], $meta['chunk_offset'], $meta['consecutive_errors'], $meta['last_error'], $meta['chunk_error_path'], $meta['chunk_error_count'] );

		$local_path = trailingslashit( Settings::migration_path( $job['migration_id'], 'export' ) ) . $file_entry['path'];
		$file_size  = file_exists( $local_path ) ? (int) filesize( $local_path ) : 0;
		if ( empty( $result['skipped'] ) ) {
			TransferProgress::log_push( $job['migration_id'], $file_entry['path'], $file_entry['component'], $file_size );
		}

		$index++;
		$meta['sent_index'] = $index;
		JobRepository::update_step( (int) $step['id'], array(
			'completed_chunks' => $index,
			'total_chunks'     => count( $queue ),
			'meta'             => $meta,
		) );

		self::touch_heartbeat( $job['migration_id'], null, $index, 'active' );
		self::relay_push_state( $job['migration_id'], array( 'active' => true, 'sent' => $index ) );

		if ( $index < count( $queue ) ) {
			self::schedule_next_tick( $job_id );
			return array(
				'success' => true,
				'done'    => false,
				'sent'    => $index,
				'total'   => count( $queue ),
			);
		}

		if ( ! self::import_uploads_complete( $job['migration_id'], count( $queue ) ) ) {
			self::maybe_reopen_incomplete_push( $job['migration_id'], $queue );
			self::relay_push_state( $job['migration_id'], array( 'active' => true, 'sent' => $index, 'done' => false ) );
			TransferWorker::ensure_running( $job['migration_id'] );
			return array(
				'success' => true,
				'done'    => false,
				'sent'    => $index,
				'total'   => count( $queue ),
				'waiting_import' => true,
			);
		}

		JobRepository::update_step( (int) $step['id'], array( 'status' => JobRepository::STATUS_COMPLETED ) );
		JobRepository::update_job_status( $job_id, JobRepository::STATUS_COMPLETED );
		self::touch_heartbeat( $job['migration_id'], null, $index, 'done' );
		self::relay_push_state( $job['migration_id'], array( 'active' => false, 'done' => true ) );
		AuditLogger::log( 'remote_push_complete', 'Site-to-site transfer complete', array(
			'migration_id' => $job['migration_id'],
			'files'        => count( $queue ),
		), 'success' );

		return array( 'success' => true, 'done' => true, 'sent' => $index );
	}

	/**
	 * Drive push forward one file (admin / Studio fallback when WP-Cron is idle).
	 *
	 * @param string $migration_id Migration ID.
	 * @return array
	 */
	public static function drive_push( $migration_id ) {
		$migration_id = sanitize_text_field( $migration_id );
		self::clear_push_url_cache();
		$job          = JobRepository::get_job_by_migration( $migration_id, 'push' );
		if ( ! $job ) {
			$queued = self::queue_push( $migration_id );
			if ( empty( $queued['success'] ) ) {
				return $queued;
			}
			$job = JobRepository::get_job_by_migration( $migration_id, 'push' );
			if ( ! $job ) {
				return array( 'success' => false, 'error' => 'Could not start push job.' );
			}
		} elseif ( JobRepository::STATUS_FAILED === $job['status'] ) {
			self::resume_push_job( $job );
			$job = JobRepository::get_job_by_migration( $migration_id, 'push' );
		}

		self::reconcile_sent_index( $migration_id );

		return self::process_tick( (int) $job['id'] );
	}

	/**
	 * Process ticks until time/byte budget is exhausted (server worker).
	 *
	 * @param string $migration_id Migration ID.
	 * @param int    $max_seconds  Time budget.
	 * @param int    $max_bytes    Byte budget (0 = unlimited).
	 * @return array
	 */
	public static function process_tick_budget( $migration_id, $max_seconds = 90, $max_bytes = 0 ) {
		\TheExporter\Runtime::prepare_job();
		self::clear_push_url_cache();
		$migration_id = sanitize_text_field( $migration_id );

		$job = JobRepository::get_job_by_migration( $migration_id, 'push' );
		if ( ! $job ) {
			return array( 'success' => false, 'error' => 'No push job found.' );
		}
		if ( JobRepository::STATUS_FAILED === $job['status'] ) {
			self::resume_push_job( $job );
			$job = JobRepository::get_job_by_migration( $migration_id, 'push' );
		}

		self::reconcile_sent_index( $migration_id );

		$started = microtime( true );
		$bytes   = 0;
		$last    = array( 'success' => true );
		$before  = self::push_status( $migration_id );
		$byte_budget = $max_bytes;
		if ( $byte_budget > 0 ) {
			$total = (int) ( $before['total'] ?? 0 );
			$sent  = (int) ( $before['sent'] ?? 0 );
			if ( $total > 0 && $sent >= max( 0, $total - 3 ) ) {
				$byte_budget = 0;
			}
		}

		while ( ( microtime( true ) - $started ) < $max_seconds ) {
			$before = self::push_status( $migration_id );
			if ( ! empty( $before['done'] ) ) {
				$last = array( 'success' => true, 'done' => true, 'sent' => (int) $before['sent'] );
				break;
			}

			if ( self::is_worker_driven() ) {
				TransferWorker::refresh_lock( $migration_id );
			}

			$last  = self::process_tick( (int) $job['id'] );
			$after = self::push_status( $migration_id );
			$bytes += max( 0, (int) ( $after['bytes_sent'] ?? 0 ) - (int) ( $before['bytes_sent'] ?? 0 ) );

			if ( ! empty( $last['done'] ) ) {
				break;
			}
			if ( empty( $last['success'] ) && empty( $last['retrying'] ) ) {
				break;
			}
			if ( ! empty( $last['retrying'] ) ) {
				if ( self::is_worker_driven() ) {
					usleep( 500000 );
					continue;
				}
				break;
			}
			if ( ! empty( $last['partial'] ) ) {
				continue;
			}
			if ( $byte_budget > 0 && $bytes >= $byte_budget ) {
				break;
			}
		}

		$last['budget_bytes']   = $bytes;
		$last['budget_seconds'] = round( microtime( true ) - $started, 2 );
		return $last;
	}

	/**
	 * Send multiple files in one HTTP request.
	 *
	 * @param string $migration_id Migration ID.
	 * @param int    $max_seconds  Time budget.
	 * @param int    $max_files    Max files per batch.
	 * @return array
	 */
	public static function drive_push_batch( $migration_id, $max_seconds = 25, $max_files = 20 ) {
		$GLOBALS['te_push_browser_driven'] = true;
		$started = microtime( true );
		$last    = array( 'success' => false, 'error' => 'No files sent' );

		try {
			if ( $max_files <= 0 ) {
				$last = self::process_tick_budget( $migration_id, $max_seconds, TransferWorker::budget_bytes() );
				$last['batch_files'] = 0;
			} else {
				$files = 0;
				while ( $files < $max_files && ( microtime( true ) - $started ) < $max_seconds ) {
					$last = self::drive_push( $migration_id );
					$files++;
					if ( empty( $last['success'] ) && empty( $last['retrying'] ) ) {
						break;
					}
					if ( ! empty( $last['done'] ) ) {
						break;
					}
					if ( ! empty( $last['retrying'] ) ) {
						break;
					}
				}
				$last['batch_files'] = $files;
			}
		} finally {
			unset( $GLOBALS['te_push_browser_driven'] );
		}

		$last['batch_seconds'] = round( microtime( true ) - $started, 2 );
		return $last;
	}

	/**
	 * Push status for migration.
	 *
	 * @param string $migration_id Migration ID.
	 * @return array
	 */
	public static function push_status( $migration_id ) {
		$job = JobRepository::get_job_by_migration( $migration_id, 'push' );
		if ( ! $job ) {
			return array( 'active' => false );
		}
		$step = JobRepository::get_step( (int) $job['id'], self::STEP_COMPONENT );
		$meta = $step && is_array( $step['meta'] ) ? $step['meta'] : array();
		$queue = self::resolve_queue( $migration_id, $meta );
		$total = count( $queue ) ?: (int) ( $step['total_chunks'] ?? 0 );
		$sent  = isset( $meta['sent_index'] ) ? (int) $meta['sent_index'] : (int) ( $step['completed_chunks'] ?? 0 );
		$worker = TransferWorker::status( $migration_id );

		$bytes_sent  = 0;
		$bytes_total = 0;
		$export_base = trailingslashit( Settings::migration_path( $migration_id, 'export' ) );
		foreach ( $queue as $i => $entry ) {
			$size = isset( $entry['size'] ) ? (int) $entry['size'] : 0;
			if ( $size <= 0 ) {
				$local = $export_base . $entry['path'];
				$size  = file_exists( $local ) ? (int) filesize( $local ) : 0;
			}
			$bytes_total += $size;
			if ( $i < $sent ) {
				$bytes_sent += $size;
			}
		}

		$current_file = null;
		if ( $sent < count( $queue ) ) {
			$next = $queue[ $sent ];
			$size = self::file_entry_size( $migration_id, $next );
			$current_file = array(
				'path'      => $next['path'],
				'component' => $next['component'],
				'size'      => $size,
			);
		}

		return array_merge(
			array(
				'active'       => in_array( $job['status'], array( JobRepository::STATUS_PENDING, JobRepository::STATUS_RUNNING ), true ),
				'status'       => $job['status'],
				'sent'         => $sent,
				'total'        => $total,
				'job_id'       => (int) $job['id'],
				'done'         => JobRepository::STATUS_COMPLETED === $job['status'],
				'failed'       => JobRepository::STATUS_FAILED === $job['status'],
				'current_file' => $current_file,
				'bytes_sent'   => $bytes_sent,
				'bytes_total'  => $bytes_total,
				'components'   => TransferProgress::components_from_queue( $queue, $sent ),
				'last_error'   => isset( $meta['last_error'] ) ? $meta['last_error'] : '',
				'retrying'     => ! empty( $meta['consecutive_errors'] ) && JobRepository::STATUS_RUNNING === $job['status'],
			),
			$worker
		);
	}

	/**
	 * Push one file with retries.
	 *
	 * @param string $migration_id Migration ID.
	 * @param array  $file_entry   File queue entry.
	 * @return array
	 */
	public static function push_file_with_retry( $migration_id, array $file_entry, array &$meta = array() ) {
		$last = array( 'success' => false, 'error' => 'Push failed' );
		for ( $attempt = 0; $attempt < self::PUSH_RETRY_MAX; $attempt++ ) {
			$last = self::push_file( $migration_id, $file_entry, $meta );
			if ( ! empty( $last['reset_chunk'] ) ) {
				unset( $meta['chunk_path'], $meta['chunk_offset'] );
			}
			if ( ! empty( $last['success'] ) ) {
				return $last;
			}
			if ( self::is_permanent_error( $last['error'] ?? '', $last ) ) {
				return $last;
			}
			if ( $attempt < self::PUSH_RETRY_MAX - 1 ) {
				usleep( 500000 * ( $attempt + 1 ) );
			}
		}
		return $last;
	}

	/**
	 * Push one file to remote site.
	 *
	 * @param string $migration_id Migration ID.
	 * @param array  $file_entry   File queue entry.
	 * @return array
	 */
	public static function push_file( $migration_id, array $file_entry, array &$meta = array() ) {
		$rel_path = $file_entry['path'];
		$local    = trailingslashit( Settings::migration_path( $migration_id, 'export' ) ) . $rel_path;
		if ( ! file_exists( $local ) ) {
			return array( 'success' => false, 'error' => 'Local file missing: ' . $rel_path );
		}

		$size      = (int) filesize( $local );
		$threshold = self::effective_chunk_threshold();
		if ( $size > $threshold ) {
			return self::push_file_chunked( $migration_id, $file_entry, $local, $size, $meta );
		}

		return self::push_file_whole( $migration_id, $file_entry, $local );
	}

	/**
	 * Push entire file in one request.
	 *
	 * @param string $migration_id Migration ID.
	 * @param array  $file_entry   Entry.
	 * @param string $local        Local path.
	 * @return array
	 */
	private static function push_file_whole( $migration_id, array $file_entry, $local ) {
		$remote_url = self::get_push_url();
		$token      = Settings::get( 'remote_pairing_token', '' );
		$component  = sanitize_key( $file_entry['component'] );
		$rel_path   = $file_entry['path'];
		$checksum   = isset( $file_entry['checksum'] ) ? $file_entry['checksum'] : '';

		$endpoint = trailingslashit( $remote_url ) . 'wp-json/the-exporter/v1/transfer/receive/' . rawurlencode( $migration_id ) . '/' . rawurlencode( $component );

		if ( ! function_exists( 'curl_init' ) ) {
			return array( 'success' => false, 'error' => 'cURL is required for site-to-site transfer on this host.' );
		}

		$mime  = function_exists( 'mime_content_type' ) ? mime_content_type( $local ) : 'application/octet-stream';
		$cfile = curl_file_create( $local, $mime, basename( $local ) );
		$post  = array(
			'file'          => $cfile,
			'relative_path' => $rel_path,
			'checksum'      => $checksum,
		);

		$ch = curl_init( $endpoint );
		curl_setopt_array( $ch, array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $post,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 600,
			CURLOPT_HTTPHEADER     => array(
				'X-TE-Token: ' . $token,
				'Accept: application/json',
			),
		) );

		$body = curl_exec( $ch );
		$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$err  = curl_error( $ch );
		curl_close( $ch );

		if ( $body === false ) {
			return array( 'success' => false, 'error' => $err ?: 'Transfer request failed' );
		}

		$data = json_decode( $body, true );
		if ( 200 !== $code || empty( $data['success'] ) ) {
			$msg = is_array( $data ) && ! empty( $data['error'] ) ? $data['error'] : 'Remote rejected upload (HTTP ' . $code . ')';
			return array( 'success' => false, 'error' => $msg, 'path' => $rel_path );
		}

		return array( 'success' => true, 'path' => $rel_path );
	}

	/**
	 * Query import disk for partial chunk bytes (disk-authoritative resume).
	 *
	 * @param string $migration_id Migration ID.
	 * @param string $rel_path     Relative catalog path.
	 * @param int    $total_size   Expected file size.
	 * @return array
	 */
	public static function fetch_import_chunk_status( $migration_id, $rel_path, $total_size = 0 ) {
		$migration_id = sanitize_text_field( $migration_id );
		$rel_path     = sanitize_text_field( $rel_path );
		$remote_url   = self::get_push_url();
		$token        = Settings::get( 'remote_pairing_token', '' );

		if ( ! $remote_url || '' === trim( (string) $token ) || ! function_exists( 'curl_init' ) || '' === $rel_path ) {
			return array( 'success' => false );
		}

		$query    = array(
			'path' => $rel_path,
		);
		if ( $total_size > 0 ) {
			$query['total_size'] = $total_size;
		}
		$endpoint = add_query_arg(
			$query,
			trailingslashit( $remote_url ) . 'wp-json/the-exporter/v1/transfer/chunk-status/' . rawurlencode( $migration_id )
		);

		$ch = curl_init( $endpoint );
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 30,
				CURLOPT_HTTPHEADER     => array(
					'X-TE-Token: ' . $token,
					'Accept: application/json',
				),
			)
		);

		$body = curl_exec( $ch );
		$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		if ( false === $body || 200 !== $code ) {
			return array( 'success' => false );
		}

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return array( 'success' => false );
		}

		return array_merge(
			array( 'success' => true ),
			$data
		);
	}

	/**
	 * Resolve next chunk offset from import disk (authority) with meta fallback.
	 *
	 * @param string $migration_id Migration ID.
	 * @param string $rel_path     Relative path.
	 * @param int    $size         File size.
	 * @param array  $meta         Push step meta.
	 * @return int
	 */
	private static function resolve_chunk_offset( $migration_id, $rel_path, $size, array $meta ) {
		$status = self::fetch_import_chunk_status( $migration_id, $rel_path, $size );
		if ( ! empty( $status['success'] ) ) {
			return min( $size, max( 0, (int) ( $status['bytes_on_disk'] ?? 0 ) ) );
		}
		if ( ! empty( $meta['chunk_path'] ) && $meta['chunk_path'] === $rel_path ) {
			return min( $size, max( 0, (int) ( $meta['chunk_offset'] ?? 0 ) ) );
		}
		return 0;
	}

	/**
	 * Push large file in fixed-size chunks.
	 *
	 * @param string $migration_id Migration ID.
	 * @param array  $file_entry   Entry.
	 * @param string $local        Local path.
	 * @param int    $size         File size.
	 * @return array
	 */
	private static function push_file_chunked( $migration_id, array $file_entry, $local, $size, array &$meta = array() ) {
		$remote_url = self::get_push_url();
		$token      = Settings::get( 'remote_pairing_token', '' );
		$component  = sanitize_key( $file_entry['component'] );
		$rel_path   = $file_entry['path'];
		$checksum   = isset( $file_entry['checksum'] ) ? $file_entry['checksum'] : '';

		if ( ! function_exists( 'curl_init' ) ) {
			return array( 'success' => false, 'error' => 'cURL is required for site-to-site transfer on this host.' );
		}

		$endpoint = trailingslashit( $remote_url ) . 'wp-json/the-exporter/v1/transfer/receive-chunk/' . rawurlencode( $migration_id ) . '/' . rawurlencode( $component );
		$handle   = fopen( $local, 'rb' );
		if ( ! $handle ) {
			return array( 'success' => false, 'error' => 'Could not read local file: ' . $rel_path );
		}

		$offset = self::resolve_chunk_offset( $migration_id, $rel_path, $size, $meta );
		if ( $offset > 0 ) {
			fseek( $handle, $offset );
		}

		$meta['chunk_path']   = $rel_path;
		$meta['chunk_offset'] = $offset;
		$chunk_size           = isset( $meta['chunk_size_override'] )
			? max( 1048576, (int) $meta['chunk_size_override'] )
			: Settings::effective_peer_chunk_size();
		$part_total           = (int) ceil( $size / max( 1, $chunk_size ) );
		$part_index           = (int) floor( $offset / max( 1, $chunk_size ) );
		$max_chunks           = isset( $meta['max_chunks_per_call'] ) ? max( 1, (int) $meta['max_chunks_per_call'] ) : 1;
		$chunks_sent          = 0;

		while ( $offset < $size ) {
			$chunk_len = (int) min( $chunk_size, $size - $offset );
			if ( $offset > 0 ) {
				fseek( $handle, $offset );
			}
			$chunk = fread( $handle, $chunk_len );
			if ( false === $chunk || '' === $chunk ) {
				fclose( $handle );
				$meta['chunk_offset'] = $offset;
				return array( 'success' => false, 'error' => 'Failed reading chunk from ' . $rel_path );
			}

			$is_final = ( $offset + strlen( $chunk ) ) >= $size;
			$tmp      = null;
			$post     = array(
				'relative_path' => $rel_path,
				'checksum'      => $checksum,
				'offset'        => $offset,
				'total_size'    => $size,
				'part_index'    => $part_index,
				'part_total'    => $part_total,
				'is_final'      => $is_final ? '1' : '0',
			);

			if ( class_exists( 'CURLStringFile' ) ) {
				$post['file'] = new \CURLStringFile( $chunk, 'chunk.bin', 'application/octet-stream' );
			} else {
				$tmp = wp_tempnam( 'te-chunk-' );
				if ( ! $tmp || false === file_put_contents( $tmp, $chunk ) ) {
					fclose( $handle );
					if ( $tmp ) {
						@unlink( $tmp );
					}
					$meta['chunk_offset'] = $offset;
					return array( 'success' => false, 'error' => 'Could not prepare chunk temp file' );
				}
				$post['file'] = curl_file_create( $tmp, 'application/octet-stream', 'chunk.bin' );
			}

			self::touch_heartbeat( $migration_id, $file_entry, $offset, 'sending' );

			$ch = curl_init( $endpoint );
			curl_setopt_array( $ch, array(
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $post,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 90,
				CURLOPT_HTTPHEADER     => array(
					'X-TE-Token: ' . $token,
					'Accept: application/json',
				),
			) );

			$body = curl_exec( $ch );
			$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			$err  = curl_error( $ch );
			curl_close( $ch );
			if ( $tmp ) {
				@unlink( $tmp );
			}

			if ( $body === false ) {
				fclose( $handle );
				$meta['chunk_offset'] = $offset;
				self::relay_push_state( $migration_id, array(
					'active' => true,
					'error'  => $err ?: 'Chunk transfer failed',
				) );
				return array( 'success' => false, 'error' => $err ?: 'Chunk transfer failed' );
			}

			$data = json_decode( $body, true );
			if ( 200 !== $code || empty( $data['success'] ) ) {
				$msg = is_array( $data ) && ! empty( $data['error'] ) ? $data['error'] : 'Remote rejected chunk (HTTP ' . $code . ')';

				if ( self::is_retriable_chunk_error( $code, $msg ) && $chunk_size > 1048576 ) {
					$chunk_size                   = max( 1048576, (int) floor( $chunk_size / 2 ) );
					$meta['chunk_size_override']  = $chunk_size;
					$part_total                   = (int) ceil( $size / $chunk_size );
					$part_index                   = (int) floor( $offset / $chunk_size );
					continue;
				}

				if ( ! empty( $data['resume_from'] ) && empty( $data['reset'] ) ) {
					$offset               = min( $size, max( 0, (int) $data['resume_from'] ) );
					$meta['chunk_offset'] = $offset;
					$part_index           = (int) floor( $offset / max( 1, $chunk_size ) );
					continue;
				}

				fclose( $handle );
				if ( ! empty( $data['reset'] ) ) {
					unset( $meta['chunk_path'], $meta['chunk_offset'] );
					$realign = self::fetch_import_chunk_status( $migration_id, $rel_path, $size );
					if ( ! empty( $realign['success'] ) ) {
						$meta['chunk_path']   = $rel_path;
						$meta['chunk_offset'] = (int) ( $realign['bytes_on_disk'] ?? 0 );
					}
				} else {
					$meta['chunk_offset'] = $offset;
				}
				self::relay_push_state( $migration_id, array(
					'active' => false,
					'error'  => $msg,
				) );
				return array( 'success' => false, 'error' => $msg, 'path' => $rel_path, 'reset_chunk' => ! empty( $data['reset'] ) );
			}

			if ( isset( $data['bytes_on_disk'] ) ) {
				$offset = min( $size, max( 0, (int) $data['bytes_on_disk'] ) );
			} else {
				$offset += strlen( $chunk );
			}
			$part_index++;
			$meta['chunk_offset'] = $offset;

			if ( ! $is_final ) {
				$chunks_sent++;
				if ( $chunks_sent < $max_chunks ) {
					continue;
				}
				fclose( $handle );
				$meta['chunk_path'] = $rel_path;
				return array(
					'success'  => true,
					'partial'  => true,
					'path'     => $rel_path,
					'offset'   => $offset,
					'chunked'  => true,
				);
			}
		}

		fclose( $handle );
		unset( $meta['chunk_path'], $meta['chunk_offset'], $meta['chunk_size_override'] );
		return array( 'success' => true, 'path' => $rel_path, 'chunked' => true );
	}

	/**
	 * Whether a failed chunk POST can be retried with a smaller payload.
	 *
	 * @param int    $http_code HTTP status.
	 * @param string $message   Error message.
	 * @return bool
	 */
	private static function is_retriable_chunk_error( $http_code, $message ) {
		if ( 413 === (int) $http_code ) {
			return true;
		}
		$lower = strtolower( (string) $message );
		$needles = array(
			'no chunk received',
			'no chunk data received',
			'failed writing chunk',
			'remote rejected chunk',
			'chunk transfer failed',
			'post_max_size',
			'upload_max_filesize',
			'entity too large',
		);
		foreach ( $needles as $needle ) {
			if ( false !== strpos( $lower, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Collect consecutive small files eligible for parallel push.
	 *
	 * @param string $migration_id Migration ID.
	 * @param array  $queue        File queue.
	 * @param int    $index        Start index.
	 * @return array
	 */
	private static function collect_parallel_batch( $migration_id, array $queue, $index ) {
		if ( self::is_worker_driven() ) {
			$max       = self::WORKER_PARALLEL_MAX;
			$max_bytes = self::WORKER_PARALLEL_MAX_BYTES;
		} else {
			$max       = Settings::is_connected_transfer() ? self::CONNECTED_PARALLEL_MAX : self::PARALLEL_MAX;
			$max_bytes = Settings::is_connected_transfer() ? self::CONNECTED_PARALLEL_MAX_BYTES : self::PARALLEL_MAX_BYTES;
		}
		$batch    = array();
		for ( $i = 0; $i < $max; $i++ ) {
			$pos = $index + $i;
			if ( ! isset( $queue[ $pos ] ) ) {
				break;
			}
			$size = self::file_entry_size( $migration_id, $queue[ $pos ] );
			if ( $size > $max_bytes || $size > self::effective_chunk_threshold() ) {
				break;
			}
			$batch[] = $queue[ $pos ];
		}
		return count( $batch ) > 1 ? $batch : array();
	}

	/**
	 * @param string $migration_id Migration ID.
	 * @param array  $entry        Queue entry.
	 * @return int
	 */
	private static function file_entry_size( $migration_id, array $entry ) {
		if ( ! empty( $entry['size'] ) ) {
			return (int) $entry['size'];
		}
		$local = trailingslashit( Settings::migration_path( $migration_id, 'export' ) ) . $entry['path'];
		return file_exists( $local ) ? (int) filesize( $local ) : 0;
	}

	/**
	 * Push multiple small files via curl_multi.
	 *
	 * @param array $job      Job row.
	 * @param array $step     Step row.
	 * @param array $meta     Step meta.
	 * @param array $queue    File queue.
	 * @param int   $index    Start index.
	 * @param array $entries  Entries to push.
	 * @return array
	 */
	private static function process_parallel_entries( array $job, array $step, array $meta, array $queue, $index, array $entries ) {
		$job_id = (int) $job['id'];
		$results = self::push_files_parallel( $job['migration_id'], $entries );
		foreach ( $results as $i => $result ) {
			if ( empty( $result['success'] ) ) {
				return self::handle_push_failure( $job, $step, $meta, $queue, $index, $entries[ $i ], $result );
			}
			$entry = $entries[ $i ];
			$size  = self::file_entry_size( $job['migration_id'], $entry );
			TransferProgress::log_push( $job['migration_id'], $entry['path'], $entry['component'], $size );
		}

		$index += count( $entries );
		$meta['sent_index'] = $index;
		unset( $meta['consecutive_errors'], $meta['last_error'], $meta['chunk_path'], $meta['chunk_offset'] );
		JobRepository::update_step( (int) $step['id'], array(
			'completed_chunks' => $index,
			'total_chunks'     => count( $queue ),
			'meta'             => $meta,
		) );

		if ( $index < count( $queue ) ) {
			self::schedule_next_tick( $job_id );
			return array(
				'success' => true,
				'done'    => false,
				'sent'    => $index,
				'total'   => count( $queue ),
				'parallel'=> count( $entries ),
			);
		}

		JobRepository::update_step( (int) $step['id'], array( 'status' => JobRepository::STATUS_COMPLETED ) );
		JobRepository::update_job_status( $job_id, JobRepository::STATUS_COMPLETED );
		AuditLogger::log( 'remote_push_complete', 'Site-to-site transfer complete', array(
			'migration_id' => $job['migration_id'],
			'files'        => count( $queue ),
		), 'success' );

		return array( 'success' => true, 'done' => true, 'sent' => $index );
	}

	/**
	 * Push up to N small files concurrently.
	 *
	 * @param string $migration_id Migration ID.
	 * @param array  $entries      File entries.
	 * @return array
	 */
	private static function push_files_parallel( $migration_id, array $entries ) {
		if ( ! function_exists( 'curl_multi_init' ) ) {
			$out = array();
			foreach ( $entries as $entry ) {
				$out[] = self::push_file( $migration_id, $entry );
			}
			return $out;
		}

		$remote_url = self::get_push_url();
		$token      = Settings::get( 'remote_pairing_token', '' );
		$mh         = curl_multi_init();
		$handles    = array();

		foreach ( $entries as $i => $entry ) {
			$local = trailingslashit( Settings::migration_path( $migration_id, 'export' ) ) . $entry['path'];
			if ( ! file_exists( $local ) ) {
				curl_multi_close( $mh );
				return array( array( 'success' => false, 'error' => 'Local file missing: ' . $entry['path'] ) );
			}
			$component = sanitize_key( $entry['component'] );
			$endpoint  = trailingslashit( $remote_url ) . 'wp-json/the-exporter/v1/transfer/receive/' . rawurlencode( $migration_id ) . '/' . rawurlencode( $component );
			$mime      = function_exists( 'mime_content_type' ) ? mime_content_type( $local ) : 'application/octet-stream';
			$cfile     = curl_file_create( $local, $mime, basename( $local ) );
			$ch        = curl_init( $endpoint );
			curl_setopt_array( $ch, array(
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => array(
					'file'          => $cfile,
					'relative_path' => $entry['path'],
					'checksum'      => isset( $entry['checksum'] ) ? $entry['checksum'] : '',
				),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 120,
				CURLOPT_HTTPHEADER     => array(
					'X-TE-Token: ' . $token,
					'Accept: application/json',
				),
			) );
			curl_multi_add_handle( $mh, $ch );
			$handles[ $i ] = $ch;
		}

		$running = null;
		do {
			curl_multi_exec( $mh, $running );
			curl_multi_select( $mh, 1 );
		} while ( $running > 0 );

		$out = array();
		foreach ( $handles as $i => $ch ) {
			$body = curl_multi_getcontent( $ch );
			$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			$err  = curl_error( $ch );
			curl_multi_remove_handle( $mh, $ch );
			curl_close( $ch );

			if ( false === $body || '' === $body ) {
				$out[] = array( 'success' => false, 'error' => $err ?: 'Transfer request failed', 'path' => $entries[ $i ]['path'] );
				continue;
			}
			$data = json_decode( $body, true );
			if ( 200 !== $code || empty( $data['success'] ) ) {
				$msg = is_array( $data ) && ! empty( $data['error'] ) ? $data['error'] : 'Remote rejected upload (HTTP ' . $code . ')';
				$out[] = array( 'success' => false, 'error' => $msg, 'path' => $entries[ $i ]['path'] );
				continue;
			}
			$out[] = array( 'success' => true, 'path' => $entries[ $i ]['path'] );
		}
		curl_multi_close( $mh );
		return $out;
	}

	/**
	 * Build ordered file queue (manifest first).
	 *
	 * @param string $migration_id Migration ID.
	 * @return array
	 */
	public static function build_file_queue( $migration_id ) {
		$queue = array();

		foreach ( PackageIndex::get_global_files( $migration_id, 'export' ) as $file ) {
			$queue[] = array(
				'component' => 'manifest',
				'path'      => $file['path'],
				'checksum'  => $file['checksum'],
				'size'      => isset( $file['size'] ) ? (int) $file['size'] : 0,
			);
		}

		foreach ( PackageIndex::get_components( $migration_id, 'export' ) as $comp ) {
			foreach ( $comp['files'] as $file ) {
				$queue[] = array(
					'component' => $comp['name'],
					'path'      => $file['path'],
					'checksum'  => isset( $file['checksum'] ) ? $file['checksum'] : '',
					'size'      => isset( $file['size'] ) ? (int) $file['size'] : 0,
				);
			}
		}

		return $queue;
	}

	/**
	 * Resolve file queue from slim meta or rebuild from disk.
	 *
	 * @param string $migration_id Migration ID.
	 * @param array  $meta         Step meta.
	 * @return array
	 */
	private static function resolve_queue( $migration_id, array $meta ) {
		if ( ! empty( $meta['file_queue'] ) && is_array( $meta['file_queue'] ) ) {
			return $meta['file_queue'];
		}
		return self::build_file_queue( $migration_id );
	}

	/**
	 * Cached remote push URL.
	 *
	 * @return string
	 */
	private static function get_push_url() {
		if ( null === self::$cached_push_url ) {
			self::$cached_push_url = Settings::effective_remote_push_url();
		}
		return self::$cached_push_url;
	}

	/**
	 * @param string     $migration_id Migration ID.
	 * @param array|null $file_entry   Current file entry.
	 * @param int        $offset       Byte offset for chunked send.
	 * @param string     $phase        Phase slug.
	 * @param string     $error        Optional error.
	 */
	private static function touch_heartbeat( $migration_id, $file_entry, $offset, $phase, $error = '' ) {
		$data = array(
			'phase'  => $phase,
			'offset' => (int) $offset,
		);
		if ( is_array( $file_entry ) ) {
			$data['current_file'] = array(
				'path'      => $file_entry['path'],
				'component' => $file_entry['component'],
				'size'      => self::file_entry_size( $migration_id, $file_entry ),
			);
		}
		if ( $error ) {
			$data['error'] = $error;
		}
		TransferProgress::update_push_heartbeat( $migration_id, $data );
	}

	/**
	 * Relay worker heartbeat to import site (receive UI stale detection).
	 *
	 * @param string $migration_id Migration ID.
	 * @param bool   $active       Whether worker is active.
	 */
	public static function relay_worker_state( $migration_id, $active ) {
		$push    = self::push_status( $migration_id );
		$worker  = TransferWorker::status( $migration_id );
		$state   = array(
			'active'         => $active && ! empty( $push['active'] ),
			'worker_active'  => (bool) $active,
			'worker_last_at' => gmdate( 'c' ),
			'updated_at'     => gmdate( 'c' ),
			'sent'           => (int) ( $push['sent'] ?? 0 ),
			'retrying'       => ! empty( $worker['worker_retrying'] ) || ! empty( $push['retrying'] ),
			'last_error'     => isset( $worker['worker_error'] ) ? $worker['worker_error'] : ( $push['last_error'] ?? '' ),
		);
		if ( ! empty( $push['current_file'] ) ) {
			$state['current_file'] = $push['current_file'];
		}
		if ( ! empty( $push['done'] ) ) {
			$state['done'] = true;
		}
		self::relay_push_state( $migration_id, $state );
	}

	/**
	 * Relay push state to import site for receive UI.
	 *
	 * @param string $migration_id Migration ID.
	 * @param array  $state        State payload.
	 */
	private static function relay_push_state( $migration_id, array $state ) {
		$remote_url = self::get_push_url();
		$token      = Settings::get( 'remote_pairing_token', '' );
		if ( ! $remote_url || ! $token ) {
			return;
		}

		$relay_log = get_option( 'te_transfer_relay_log', array() );
		if ( ! is_array( $relay_log ) ) {
			$relay_log = array();
		}
		if ( ! empty( $relay_log[ $migration_id ] ) && is_array( $relay_log[ $migration_id ] ) ) {
			$state = array_merge( $relay_log[ $migration_id ], $state );
		}

		$endpoint = trailingslashit( $remote_url ) . 'wp-json/the-exporter/v1/transfer/push-state/' . rawurlencode( $migration_id );
		$headers  = array(
			'X-TE-Token'   => $token,
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		);
		$body_json = wp_json_encode( $state );
		$code      = 0;
		$err       = '';

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout'     => 8,
				'headers'     => $headers,
				'body'        => $body_json,
				'redirection' => 5,
			)
		);

		if ( is_wp_error( $response ) ) {
			$err = $response->get_error_message();
		} else {
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 300 ) {
				$err = 'HTTP ' . $code;
			}
		}

		if ( ( $code < 200 || $code >= 300 ) && function_exists( 'curl_init' ) ) {
			$ch = curl_init( $endpoint );
			$curl_opts = array(
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $body_json,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 8,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_HTTPHEADER     => array(
					'X-TE-Token: ' . $token,
					'Content-Type: application/json',
					'Accept: application/json',
				),
			);
			if ( defined( 'CURLOPT_POSTREDIR' ) && defined( 'CURL_REDIR_POST_ALL' ) ) {
				$curl_opts[ CURLOPT_POSTREDIR ] = CURL_REDIR_POST_ALL;
			}
			curl_setopt_array( $ch, $curl_opts );
			$curl_body = curl_exec( $ch );
			$code      = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			$curl_err  = curl_error( $ch );
			curl_close( $ch );
			if ( false === $curl_body ) {
				$err = $curl_err ?: 'curl failed';
			} elseif ( $code < 200 || $code >= 300 ) {
				$err = 'HTTP ' . $code;
			} else {
				$err = '';
			}
		}

		$relay_log[ $migration_id ] = array(
			'last_relay_at'    => gmdate( 'c' ),
			'last_relay_code'  => $code,
			'last_relay_error' => $err,
		);
		update_option( 'te_transfer_relay_log', $relay_log, false );
	}

	/**
	 * Whether a push error looks transient (timeouts, gateway errors).
	 *
	 * @param string $error Error message.
	 * @return bool
	 */
	public static function is_transient_push_error( $error ) {
		$error = strtolower( (string) $error );
		if ( '' === $error ) {
			return false;
		}
		$needles = array(
			'timeout',
			'timed out',
			'502',
			'503',
			'504',
			'connection reset',
			'connection refused',
			'could not resolve',
			'empty reply',
			'chunk transfer failed',
			'remote rejected',
			'curl failed',
		);
		foreach ( $needles as $needle ) {
			if ( false !== strpos( $error, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resume a failed push job when the last error looks transient.
	 *
	 * @param string $migration_id Migration ID.
	 * @return bool Whether a job was resumed.
	 */
	public static function maybe_resume_failed_push( $migration_id ) {
		$job = JobRepository::get_job_by_migration( $migration_id, 'push' );
		if ( ! $job || JobRepository::STATUS_FAILED !== $job['status'] ) {
			return false;
		}
		$step = JobRepository::get_step( (int) $job['id'], self::STEP_COMPONENT );
		$meta = $step && is_array( $step['meta'] ) ? $step['meta'] : array();
		$error = isset( $meta['last_error'] ) ? (string) $meta['last_error'] : '';
		if ( ! self::is_transient_push_error( $error ) ) {
			return false;
		}
		self::resume_push_job( $job );
		TransferWorker::ensure_running( $migration_id );
		return true;
	}

	/**
	 * Run push until complete (CLI).
	 *
	 * @param string $migration_id Migration ID.
	 * @param int    $max_seconds  Time budget (0 = unlimited).
	 * @return array
	 */
	public static function drive_push_loop( $migration_id, $max_seconds = 0 ) {
		if ( ! defined( 'TE_WORKER_SOURCE' ) ) {
			define( 'TE_WORKER_SOURCE', 'cli' );
		}
		return TransferWorker::run_daemon( $migration_id, $max_seconds );
	}

	/**
	 * Resume a failed push job.
	 *
	 * @param array $job Job row.
	 */
	private static function resume_push_job( array $job ) {
		$step = JobRepository::get_step( (int) $job['id'], self::STEP_COMPONENT );
		if ( $step ) {
			JobRepository::update_step( (int) $step['id'], array(
				'status' => JobRepository::STATUS_RUNNING,
			) );
		}
		JobRepository::update_job_status( (int) $job['id'], JobRepository::STATUS_RUNNING );
	}

	/**
	 * Align sent_index with files already on the import site.
	 *
	 * @param string $migration_id Migration ID.
	 */
	public static function reconcile_sent_index( $migration_id ) {
		$job = JobRepository::get_job_by_migration( $migration_id, 'push' );
		if ( ! $job ) {
			return;
		}
		$step = JobRepository::get_step( (int) $job['id'], self::STEP_COMPONENT );
		if ( ! $step ) {
			return;
		}

		$received = self::fetch_import_progress( $migration_id );
		$paths    = $received['paths'] ?? array();
		// #region agent log
		$debug_log = dirname( TE_PLUGIN_DIR ) . '/debug-303160.log';
		@file_put_contents( $debug_log, wp_json_encode( array(
			'sessionId'    => '303160',
			'location'     => 'class-remote-pusher.php:reconcile_sent_index',
			'message'      => 'reconcile_paths',
			'hypothesisId' => 'H6-H8',
			'timestamp'    => (int) round( microtime( true ) * 1000 ),
			'data'         => array(
				'migration_id'   => $migration_id,
				'received_count' => count( $paths ),
				'uploaded'       => (int) ( $received['uploaded'] ?? 0 ),
				'expected'       => (int) ( $received['expected'] ?? 0 ),
				'job_status'     => $job['status'] ?? '',
			),
		) ) . "\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore
		// #endregion
		if ( empty( $paths ) && empty( $received['uploaded'] ) ) {
			return;
		}

		$queue  = self::resolve_queue( $migration_id, is_array( $step['meta'] ) ? $step['meta'] : array() );
		$sent   = 0;
		$lookup = array_flip( $paths );
		foreach ( $queue as $i => $entry ) {
			if ( ! isset( $lookup[ $entry['path'] ] ) ) {
				break;
			}
			$sent = $i + 1;
		}

		$meta = is_array( $step['meta'] ) ? $step['meta'] : array();
		$current = isset( $meta['sent_index'] ) ? (int) $meta['sent_index'] : (int) ( $step['completed_chunks'] ?? 0 );
		$queue_total = count( $queue );
		if ( $sent > $current ) {
			$meta['sent_index'] = $sent;
			JobRepository::update_step( (int) $step['id'], array(
				'completed_chunks' => $sent,
				'total_chunks'     => $queue_total,
				'meta'             => $meta,
				'status'           => JobRepository::STATUS_RUNNING,
			) );
			JobRepository::update_job_status( (int) $job['id'], JobRepository::STATUS_RUNNING );
		} elseif ( $sent < $current && $sent > 0 ) {
			$meta['sent_index'] = $sent;
			JobRepository::update_step( (int) $step['id'], array(
				'completed_chunks' => $sent,
				'total_chunks'     => $queue_total,
				'meta'             => $meta,
				'status'           => JobRepository::STATUS_RUNNING,
			) );
			JobRepository::update_job_status( (int) $job['id'], JobRepository::STATUS_RUNNING );
		}

		if ( JobRepository::STATUS_COMPLETED === $job['status'] && ! self::import_uploads_complete( $migration_id, $queue_total ) ) {
			self::maybe_reopen_incomplete_push( $migration_id, $queue );
			return;
		}

		if ( $queue_total > 0 && $sent >= $queue_total && self::import_uploads_complete( $migration_id, $queue_total ) && JobRepository::STATUS_COMPLETED !== $job['status'] ) {
			$meta['sent_index'] = $queue_total;
			JobRepository::update_step( (int) $step['id'], array(
				'status'           => JobRepository::STATUS_COMPLETED,
				'completed_chunks' => $queue_total,
				'total_chunks'     => $queue_total,
				'meta'             => $meta,
			) );
			JobRepository::update_job_status( (int) $job['id'], JobRepository::STATUS_COMPLETED );
			self::relay_push_state( $migration_id, array(
				'active' => false,
				'done'   => true,
				'sent'   => $queue_total,
			) );
		} elseif ( $queue_total > 0 && $sent < $queue_total && JobRepository::STATUS_COMPLETED === $job['status'] ) {
			self::maybe_reopen_incomplete_push( $migration_id, $queue );
		}
	}

	/**
	 * Skip queue entries already present on the import site (no HTTP upload).
	 *
	 * @param string $migration_id Migration ID.
	 * @param int    $index        Current queue index.
	 * @param array  $queue        File queue.
	 * @param array  $meta         Step meta (by ref).
	 * @param array  $step         Step row.
	 * @param int    $job_id       Job ID.
	 * @return int Updated index.
	 */
	private static function advance_past_received_on_import( $migration_id, $index, array $queue, array &$meta, array $step, $job_id ) {
		$max_skip = self::is_worker_driven() ? 40 : 20;
		$progress = self::fetch_import_progress( $migration_id );
		$lookup   = array_flip( $progress['paths'] ?? array() );
		if ( empty( $lookup ) ) {
			return $index;
		}

		$skipped = 0;
		while ( $index < count( $queue ) && $skipped < $max_skip ) {
			if ( ! isset( $lookup[ $queue[ $index ]['path'] ] ) ) {
				break;
			}
			$index++;
			$skipped++;
		}

		if ( $skipped > 0 ) {
			$meta['sent_index'] = $index;
			JobRepository::update_step( (int) $step['id'], array(
				'completed_chunks' => $index,
				'total_chunks'     => count( $queue ),
				'meta'             => $meta,
				'status'           => JobRepository::STATUS_RUNNING,
			) );
			self::relay_push_state( $migration_id, array( 'active' => true, 'sent' => $index ) );
			// #region agent log
			$debug_log = dirname( TE_PLUGIN_DIR ) . '/debug-303160.log';
			@file_put_contents( $debug_log, wp_json_encode( array(
				'sessionId'    => '303160',
				'location'     => 'class-remote-pusher.php:advance_past_received',
				'message'      => 'fast_skip',
				'hypothesisId' => 'H9',
				'timestamp'    => (int) round( microtime( true ) * 1000 ),
				'data'         => array(
					'migration_id' => $migration_id,
					'skipped'      => $skipped,
					'new_index'    => $index,
				),
			) ) . "\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore
			// #endregion
		}

		return $index;
	}

	/**
	 * Whether the import site reports all expected files received.
	 *
	 * @param string $migration_id Migration ID.
	 * @param int    $queue_total  Push queue size.
	 * @return bool
	 */
	private static function import_uploads_complete( $migration_id, $queue_total = 0 ) {
		$progress = self::fetch_import_progress( $migration_id );
		$expected = (int) ( $progress['expected'] ?? 0 );
		$uploaded = (int) ( $progress['uploaded'] ?? 0 );
		if ( $expected > 0 ) {
			return $uploaded >= $expected;
		}
		if ( $queue_total > 0 ) {
			$paths = $progress['paths'] ?? array();
			return count( $paths ) >= $queue_total;
		}
		return false;
	}

	/**
	 * Re-open a prematurely completed push job at the first missing file.
	 *
	 * @param string $migration_id Migration ID.
	 * @param array  $queue        File queue.
	 */
	private static function maybe_reopen_incomplete_push( $migration_id, array $queue ) {
		if ( self::import_uploads_complete( $migration_id, count( $queue ) ) ) {
			return;
		}

		$progress = self::fetch_import_progress( $migration_id );
		$lookup   = array_flip( $progress['paths'] ?? array() );
		$new_index = count( $queue );
		foreach ( $queue as $i => $entry ) {
			if ( ! isset( $lookup[ $entry['path'] ] ) ) {
				$new_index = $i;
				break;
			}
		}
		if ( $new_index >= count( $queue ) ) {
			return;
		}

		$job = JobRepository::get_job_by_migration( $migration_id, 'push' );
		if ( ! $job ) {
			return;
		}
		$step = JobRepository::get_step( (int) $job['id'], self::STEP_COMPONENT );
		if ( ! $step ) {
			return;
		}

		$meta = is_array( $step['meta'] ) ? $step['meta'] : array();
		unset( $meta['chunk_path'], $meta['chunk_offset'], $meta['last_error'] );
		$meta['sent_index'] = $new_index;

		JobRepository::update_step( (int) $step['id'], array(
			'status'           => JobRepository::STATUS_RUNNING,
			'completed_chunks' => $new_index,
			'total_chunks'     => count( $queue ),
			'meta'             => $meta,
		) );
		JobRepository::update_job_status( (int) $job['id'], JobRepository::STATUS_RUNNING );
		self::relay_push_state( $migration_id, array(
			'active' => true,
			'done'   => false,
			'sent'   => $new_index,
		) );
		TransferWorker::ensure_running( $migration_id );

		// #region agent log
		$debug_log = dirname( TE_PLUGIN_DIR ) . '/debug-303160.log';
		@file_put_contents( $debug_log, wp_json_encode( array(
			'sessionId'    => '303160',
			'location'     => 'class-remote-pusher.php:maybe_reopen_incomplete_push',
			'message'      => 'reopen_push',
			'hypothesisId' => 'H6-H8',
			'timestamp'    => (int) round( microtime( true ) * 1000 ),
			'data'         => array(
				'migration_id' => $migration_id,
				'new_index'    => $new_index,
				'uploaded'     => (int) ( $progress['uploaded'] ?? 0 ),
				'expected'     => (int) ( $progress['expected'] ?? 0 ),
			),
		) ) . "\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore
		// #endregion
	}

	/**
	 * Fetch import receive progress from the paired site.
	 *
	 * @param string $migration_id Migration ID.
	 * @return array{paths:array,uploaded:int,expected:int}
	 */
	private static function fetch_import_progress( $migration_id ) {
		$remote_url = self::get_push_url();
		$token      = Settings::get( 'remote_pairing_token', '' );
		if ( ! $remote_url || ! $token ) {
			return array( 'paths' => array(), 'uploaded' => 0, 'expected' => 0 );
		}

		$endpoint = trailingslashit( $remote_url ) . 'wp-json/the-exporter/v1/transfer/import-progress/' . rawurlencode( $migration_id );
		$body     = '';
		$code     = 0;

		$response = wp_remote_get(
			$endpoint,
			array(
				'timeout'     => 15,
				'headers'     => array(
					'X-TE-Token' => $token,
					'Accept'     => 'application/json',
				),
				'redirection' => 5,
			)
		);

		if ( is_wp_error( $response ) ) {
			$body = '';
		} else {
			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = (string) wp_remote_retrieve_body( $response );
		}

		if ( ( $code < 200 || $code >= 300 || '' === $body ) && function_exists( 'curl_init' ) ) {
			$ch = curl_init( $endpoint );
			curl_setopt_array( $ch, array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 15,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_HTTPHEADER     => array(
					'X-TE-Token: ' . $token,
					'Accept: application/json',
				),
			) );
			$body = curl_exec( $ch );
			curl_close( $ch );
		}

		if ( false === $body || '' === $body ) {
			return array( 'paths' => array(), 'uploaded' => 0, 'expected' => 0 );
		}

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return array( 'paths' => array(), 'uploaded' => 0, 'expected' => 0 );
		}

		return array(
			'paths'    => ! empty( $data['received_paths'] ) && is_array( $data['received_paths'] ) ? $data['received_paths'] : array(),
			'uploaded' => (int) ( $data['uploaded'] ?? 0 ),
			'expected' => (int) ( $data['expected'] ?? 0 ),
		);
	}

	/**
	 * @param string $migration_id Migration ID.
	 * @return array
	 */
	private static function fetch_import_received_paths( $migration_id ) {
		$progress = self::fetch_import_progress( $migration_id );
		return $progress['paths'];
	}

	/**
	 * Mark push job complete (e.g. after local filesystem copy).
	 *
	 * @param string $migration_id Migration ID.
	 * @return array
	 */
	public static function mark_push_complete( $migration_id ) {
		$migration_id = sanitize_text_field( $migration_id );
		$queue        = self::build_file_queue( $migration_id );
		$total        = count( $queue );

		$job = JobRepository::get_job_by_migration( $migration_id, 'push' );
		if ( ! $job ) {
			$job_id = JobRepository::create_job( $migration_id, 'push', array( 'total' => $total ) );
			if ( ! $job_id ) {
				return array( 'success' => false, 'error' => 'Could not create push job.' );
			}
			$step_id = JobRepository::create_step( $job_id, self::STEP_COMPONENT );
			JobRepository::update_step(
				$step_id,
				array(
					'status'           => JobRepository::STATUS_COMPLETED,
					'total_chunks'     => $total,
					'completed_chunks' => $total,
					'meta'             => array( 'sent_index' => $total, 'local_copy' => true ),
				)
			);
			JobRepository::update_job_status( $job_id, JobRepository::STATUS_COMPLETED );
			TransferWorker::stop( $migration_id );
			self::relay_push_state( $migration_id, array( 'active' => false, 'done' => true ) );
			return array( 'success' => true, 'done' => true, 'sent' => $total );
		}

		$step = JobRepository::get_step( (int) $job['id'], self::STEP_COMPONENT );
		if ( $step ) {
			JobRepository::update_step(
				(int) $step['id'],
				array(
					'status'           => JobRepository::STATUS_COMPLETED,
					'total_chunks'     => $total,
					'completed_chunks' => $total,
					'meta'             => array( 'sent_index' => $total, 'local_copy' => true ),
				)
			);
		}
		JobRepository::update_job_status( (int) $job['id'], JobRepository::STATUS_COMPLETED );
		TransferWorker::stop( $migration_id );
		self::relay_push_state( $migration_id, array( 'active' => false, 'done' => true ) );

		return array( 'success' => true, 'done' => true, 'sent' => $total );
	}

	/**
	 * Clear cached push URL.
	 */
	public static function clear_push_url_cache() {
		self::$cached_push_url = null;
	}

	/**
	 * Handle transient push errors with backoff instead of immediate hard failure.
	 *
	 * @param array      $job        Job row.
	 * @param array      $step       Step row.
	 * @param array      $meta       Step meta.
	 * @param array      $queue      File queue.
	 * @param int        $index      Current index.
	 * @param array      $file_entry File entry.
	 * @param array      $result     Push result.
	 * @return array
	 */
	private static function handle_push_failure( array $job, array $step, array $meta, array $queue, $index, array $file_entry, array $result ) {
		$error     = isset( $result['error'] ) ? $result['error'] : 'Push failed';
		$job_id    = (int) $job['id'];
		$permanent = self::is_permanent_error( $error, $result );

		if ( ! empty( $result['reset_chunk'] ) || false !== stripos( $error, 'offset mismatch' ) || false !== stripos( $error, 'failed writing chunk' ) ) {
			unset( $meta['chunk_path'], $meta['chunk_offset'] );
			if ( ! empty( $file_entry['path'] ) ) {
				$size    = self::file_entry_size( $job['migration_id'], $file_entry );
				$realign = self::fetch_import_chunk_status( $job['migration_id'], $file_entry['path'], $size );
				if ( ! empty( $realign['success'] ) ) {
					$meta['chunk_path']   = $file_entry['path'];
					$meta['chunk_offset'] = (int) ( $realign['bytes_on_disk'] ?? 0 );
				}
			}
		}

		$chunk_path = $file_entry['path'] ?? '';
		if ( $chunk_path && ( $meta['chunk_error_path'] ?? '' ) === $chunk_path ) {
			$meta['chunk_error_count'] = (int) ( $meta['chunk_error_count'] ?? 0 ) + 1;
		} else {
			$meta['chunk_error_path']  = $chunk_path;
			$meta['chunk_error_count'] = 1;
		}

		if ( (int) ( $meta['chunk_error_count'] ?? 0 ) >= 3 && $chunk_path ) {
			$size    = self::file_entry_size( $job['migration_id'], $file_entry );
			$realign = self::fetch_import_chunk_status( $job['migration_id'], $chunk_path, $size );
			if ( ! empty( $realign['success'] ) ) {
				$meta['chunk_path']        = $chunk_path;
				$meta['chunk_offset']      = (int) ( $realign['bytes_on_disk'] ?? 0 );
				$meta['chunk_error_count'] = 0;
			}
		}

		self::touch_heartbeat( $job['migration_id'], $file_entry, (int) ( $meta['chunk_offset'] ?? 0 ), 'failed', $error );

		$consecutive = (int) ( $meta['consecutive_errors'] ?? 0 ) + 1;
		$meta['consecutive_errors'] = $consecutive;
		$meta['last_error']         = $error;
		$meta['sent_index']         = $index;

		if ( $permanent || $consecutive >= self::MAX_CONSECUTIVE_ERRORS ) {
			self::relay_push_state( $job['migration_id'], array(
				'active' => false,
				'error'  => $error,
			) );
			JobRepository::update_step( (int) $step['id'], array(
				'status'           => JobRepository::STATUS_FAILED,
				'completed_chunks' => $index,
				'meta'             => $meta,
			) );
			JobRepository::update_job_status( $job_id, JobRepository::STATUS_FAILED );
			TransferWorker::stop( $job['migration_id'] );
			return $result;
		}

		$delay = min( 60, 5 * $consecutive );
		JobRepository::update_step( (int) $step['id'], array(
			'status'           => JobRepository::STATUS_RUNNING,
			'completed_chunks' => $index,
			'meta'             => $meta,
		) );
		JobRepository::update_job_status( $job_id, JobRepository::STATUS_RUNNING );
		self::relay_push_state( $job['migration_id'], array(
			'active' => true,
			'error'  => $error,
			'sent'   => $index,
		) );

		if ( self::is_worker_driven() || empty( $GLOBALS['te_push_browser_driven'] ) ) {
			TransferWorker::ensure_running( $job['migration_id'] );
		}

		return array(
			'success'     => false,
			'retrying'    => true,
			'retry_delay' => $delay,
			'error'       => $error,
			'sent'        => $index,
			'total'       => count( $queue ),
		);
	}

	/**
	 * @param int $job_id Job ID.
	 */
	private static function schedule_next_tick( $job_id ) {
		if ( ! empty( $GLOBALS['te_push_browser_driven'] ) || self::is_worker_driven() ) {
			return;
		}
		Scheduler::schedule( $job_id, self::STEP_COMPONENT, 1 );
	}

	/**
	 * @return bool
	 */
	private static function is_worker_driven() {
		return ! empty( $GLOBALS['te_push_worker_driven'] );
	}

	/**
	 * @return int
	 */
	private static function effective_chunk_threshold() {
		$chunk = Settings::transfer_chunk_size();
		if ( self::is_worker_driven() ) {
			return max( $chunk * 2, 16777216 );
		}
		return max( $chunk * 2, self::CHUNK_THRESHOLD );
	}

	/**
	 * @param string $error  Error message.
	 * @param array  $result Result payload.
	 * @return bool
	 */
	private static function is_permanent_error( $error, array $result ) {
		$error = strtolower( (string) $error );
		if ( false !== strpos( $error, 'local file missing' ) ) {
			return true;
		}
		if ( false !== strpos( $error, 'invalid or expired pairing' ) ) {
			return true;
		}
		if ( false !== strpos( $error, 'http 401' ) || false !== strpos( $error, 'http 403' ) || false !== strpos( $error, 'http 404' ) ) {
			return true;
		}
		if ( ! empty( $result['permanent'] ) ) {
			return true;
		}
		return false;
	}
}
