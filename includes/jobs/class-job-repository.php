<?php
/**
 * Job persistence repository.
 *
 * @package TheExporter
 */

namespace TheExporter\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Class JobRepository
 */
class JobRepository {

	/**
	 * Seconds without lock heartbeat before lock is considered stale.
	 */
	const LOCK_STALE_SECONDS = 180;

	const STATUS_PENDING   = 'pending';
	const STATUS_RUNNING   = 'running';
	const STATUS_PAUSED    = 'paused';
	const STATUS_COMPLETED = 'completed';
	const STATUS_FAILED    = 'failed';

	/**
	 * Create a new job.
	 *
	 * @param string $migration_id Migration UUID.
	 * @param string $type         export|import.
	 * @param array  $meta         Optional meta.
	 * @return int|false Job ID.
	 */
	public static function create_job( $migration_id, $type = 'export', array $meta = array() ) {
		global $wpdb;

		$now = current_time( 'mysql', true );
		$ok  = $wpdb->insert(
			$wpdb->prefix . 'tex_jobs',
			array(
				'migration_id' => sanitize_text_field( $migration_id ),
				'type'         => sanitize_key( $type ),
				'status'       => self::STATUS_PENDING,
				'created_at'   => $now,
				'updated_at'   => $now,
				'created_by'   => get_current_user_id(),
				'meta'         => wp_json_encode( $meta ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Get job by ID.
	 *
	 * @param int $job_id Job ID.
	 * @return array|null
	 */
	public static function get_job( $job_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}tex_jobs WHERE id = %d", $job_id ),
			ARRAY_A
		);
		if ( $row && ! empty( $row['meta'] ) ) {
			$row['meta'] = json_decode( $row['meta'], true );
		}
		return $row;
	}

	/**
	 * Get job by migration ID.
	 *
	 * @param string $migration_id Migration ID.
	 * @param string $type         Job type.
	 * @return array|null
	 */
	public static function get_job_by_migration( $migration_id, $type = '' ) {
		global $wpdb;

		if ( $type ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}tex_jobs WHERE migration_id = %s AND type = %s ORDER BY id DESC LIMIT 1",
					$migration_id,
					$type
				),
				ARRAY_A
			);
		} else {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}tex_jobs WHERE migration_id = %s ORDER BY id DESC LIMIT 1",
					$migration_id
				),
				ARRAY_A
			);
		}

		if ( $row && ! empty( $row['meta'] ) ) {
			$row['meta'] = json_decode( $row['meta'], true );
		}
		return $row;
	}

	/**
	 * Update job status.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $status Status.
	 */
	public static function update_job_status( $job_id, $status ) {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'tex_jobs',
			array(
				'status'     => sanitize_key( $status ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $job_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Create job step.
	 *
	 * @param int    $job_id    Job ID.
	 * @param string $component Component name.
	 * @return int|false
	 */
	public static function create_step( $job_id, $component ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$ok  = $wpdb->insert(
			$wpdb->prefix . 'tex_job_steps',
			array(
				'job_id'     => $job_id,
				'component'  => sanitize_key( $component ),
				'status'     => self::STATUS_PENDING,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Get steps for job.
	 *
	 * @param int $job_id Job ID.
	 * @return array
	 */
	public static function get_steps( $job_id ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}tex_job_steps WHERE job_id = %d ORDER BY id ASC", $job_id ),
			ARRAY_A
		);
		foreach ( $rows as $i => $row ) {
			$rows[ $i ] = self::decode_step_row( $row );
		}
		return $rows;
	}

	/**
	 * Get step by component.
	 *
	 * @param int    $job_id    Job ID.
	 * @param string $component Component.
	 * @return array|null
	 */
	public static function get_step( $job_id, $component ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}tex_job_steps WHERE job_id = %d AND component = %s",
				$job_id,
				$component
			),
			ARRAY_A
		);
		return $row ? self::decode_step_row( $row ) : null;
	}

	/**
	 * Get step by ID.
	 *
	 * @param int $step_id Step ID.
	 * @return array|null
	 */
	public static function get_step_by_id( $step_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}tex_job_steps WHERE id = %d", $step_id ),
			ARRAY_A
		);
		return $row ? self::decode_step_row( $row ) : null;
	}

	/**
	 * Decode JSON meta on a step row.
	 *
	 * @param array $row Step row.
	 * @return array
	 */
	private static function decode_step_row( array $row ) {
		if ( ! empty( $row['meta'] ) && is_string( $row['meta'] ) ) {
			$decoded = json_decode( $row['meta'], true );
			$row['meta'] = is_array( $decoded ) ? $decoded : array();
		}
		return $row;
	}

	/**
	 * Delete chunk records for a step.
	 *
	 * @param int $step_id Step ID.
	 */
	public static function delete_step_chunks( $step_id ) {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'tex_chunks', array( 'step_id' => $step_id ), array( '%d' ) );
	}

	/**
	 * Update step progress.
	 *
	 * @param int    $step_id Step ID.
	 * @param array  $data    Data to update.
	 */
	public static function update_step( $step_id, array $data ) {
		global $wpdb;
		$allowed = array( 'status', 'total_chunks', 'completed_chunks', 'total_bytes', 'meta' );
		$update  = array( 'updated_at' => current_time( 'mysql', true ) );
		foreach ( $allowed as $key ) {
			if ( isset( $data[ $key ] ) ) {
				$update[ $key ] = 'meta' === $key ? wp_json_encode( $data[ $key ] ) : $data[ $key ];
			}
		}
		$wpdb->update( $wpdb->prefix . 'tex_job_steps', $update, array( 'id' => $step_id ) );
	}

	/**
	 * Create chunk record.
	 *
	 * @param int    $step_id     Step ID.
	 * @param int    $chunk_index Chunk index.
	 * @param string $file_path   File path.
	 * @param int    $file_size   File size.
	 * @param string $checksum    SHA-256.
	 * @param array  $meta        Meta.
	 * @return int|false
	 */
	public static function create_chunk( $step_id, $chunk_index, $file_path, $file_size, $checksum, array $meta = array() ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$ok  = $wpdb->insert(
			$wpdb->prefix . 'tex_chunks',
			array(
				'step_id'      => $step_id,
				'chunk_index'  => $chunk_index,
				'file_path'    => $file_path,
				'file_size'    => $file_size,
				'checksum'     => $checksum,
				'status'       => self::STATUS_COMPLETED,
				'created_at'   => $now,
				'updated_at'   => $now,
				'checksum_verified_at' => $now,
				'meta'         => wp_json_encode( $meta ),
			),
			array( '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Get chunks for step.
	 *
	 * @param int $step_id Step ID.
	 * @return array
	 */
	public static function get_chunks( $step_id ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}tex_chunks WHERE step_id = %d ORDER BY chunk_index ASC", $step_id ),
			ARRAY_A
		);
		foreach ( $rows as &$row ) {
			if ( ! empty( $row['meta'] ) ) {
				$row['meta'] = json_decode( $row['meta'], true );
			}
		}
		return $rows;
	}

	/**
	 * Update chunk status.
	 *
	 * @param int    $chunk_id Chunk ID.
	 * @param string $status   Status.
	 */
	public static function update_chunk_status( $chunk_id, $status ) {
		global $wpdb;
		$data = array(
			'status'     => sanitize_key( $status ),
			'updated_at' => current_time( 'mysql', true ),
		);
		if ( self::STATUS_COMPLETED === $status ) {
			$data['checksum_verified_at'] = current_time( 'mysql', true );
		}
		$wpdb->update( $wpdb->prefix . 'tex_chunks', $data, array( 'id' => $chunk_id ) );
	}

	/**
	 * List recent jobs.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public static function list_jobs( $limit = 20 ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}tex_jobs ORDER BY id DESC LIMIT %d", $limit ),
			ARRAY_A
		);
	}

	/**
	 * Acquire job lock.
	 *
	 * @param string $migration_id Migration ID.
	 * @param int    $job_id       Job ID for ownership.
	 * @return bool
	 */
	public static function acquire_lock( $migration_id, $job_id = 0 ) {
		$key      = 'te_lock_' . sanitize_key( $migration_id );
		$existing = get_transient( $key );

		if ( $existing ) {
			if ( self::is_lock_stale( $existing ) ) {
				delete_transient( $key );
			} else {
				return false;
			}
		}

		set_transient( $key, array(
			'owner'  => get_current_user_id(),
			'job_id' => (int) $job_id,
			'at'     => time(),
		), 30 * MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * Whether lock payload is stale.
	 *
	 * @param mixed $lock Lock data.
	 * @return bool
	 */
	public static function is_lock_stale( $lock ) {
		if ( ! is_array( $lock ) ) {
			return ( time() - (int) $lock ) > self::LOCK_STALE_SECONDS;
		}
		$at = isset( $lock['at'] ) ? (int) $lock['at'] : 0;
		return ( time() - $at ) > self::LOCK_STALE_SECONDS;
	}

	/**
	 * Lock age in seconds.
	 *
	 * @param array|false $lock Lock info from get_lock_info.
	 * @return int
	 */
	public static function lock_age_seconds( $lock ) {
		if ( ! is_array( $lock ) ) {
			return 0;
		}
		$at = isset( $lock['at'] ) ? (int) $lock['at'] : 0;
		return $at > 0 ? max( 0, time() - $at ) : 0;
	}

	/**
	 * Build a migration-locked error payload.
	 *
	 * @param string $migration_id Migration ID.
	 * @return array
	 */
	public static function locked_error( $migration_id ) {
		$lock = self::get_lock_info( $migration_id );
		return array(
			'success'           => false,
			'error'             => __( 'Migration locked', 'the-exporter' ),
			'lock'              => $lock,
			'lock_age_seconds'  => self::lock_age_seconds( $lock ),
			'lock_stale'        => $lock && ! empty( $lock['stale'] ),
		);
	}

	/**
	 * Get lock info.
	 *
	 * @param string $migration_id Migration ID.
	 * @return array|false
	 */
	public static function get_lock_info( $migration_id ) {
		$lock = get_transient( 'te_lock_' . sanitize_key( $migration_id ) );
		if ( ! $lock ) {
			return false;
		}
		if ( is_array( $lock ) ) {
			$lock['stale'] = self::is_lock_stale( $lock );
			return $lock;
		}
		return array(
			'owner' => (int) $lock,
			'at'    => 0,
			'stale' => self::is_lock_stale( $lock ),
		);
	}

	/**
	 * Force release migration lock.
	 *
	 * @param string $migration_id Migration ID.
	 */
	public static function force_release_lock( $migration_id ) {
		delete_transient( 'te_lock_' . sanitize_key( $migration_id ) );
	}

	/**
	 * Release job lock.
	 *
	 * @param string $migration_id Migration ID.
	 */
	public static function release_lock( $migration_id ) {
		self::force_release_lock( $migration_id );
	}

	/**
	 * Touch lock heartbeat.
	 *
	 * @param string $migration_id Migration ID.
	 */
	public static function touch_lock( $migration_id ) {
		$key  = 'te_lock_' . sanitize_key( $migration_id );
		$lock = get_transient( $key );
		if ( is_array( $lock ) ) {
			$lock['at'] = time();
			set_transient( $key, $lock, 30 * MINUTE_IN_SECONDS );
		}
	}
}
