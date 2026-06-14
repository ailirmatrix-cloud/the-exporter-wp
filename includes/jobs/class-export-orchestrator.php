<?php
/**
 * Export orchestration.
 *
 * @package TheExporter
 */

namespace TheExporter\Jobs;

use TheExporter\Config\EnvironmentExporter;
use TheExporter\Database\Dumper;
use TheExporter\Files\InventoryBuilder;
use TheExporter\Files\SegmentWriter;
use TheExporter\Logging\AuditLogger;
use TheExporter\Manifest\ManifestBuilder;
use TheExporter\Security\DirectoryGuard;
use TheExporter\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class ExportOrchestrator
 */
class ExportOrchestrator {

	/**
	 * Valid export components.
	 *
	 * @return array
	 */
	public static function components() {
		return array( 'database', 'uploads', 'themes', 'plugins', 'mu-plugins', 'wp-content-other', 'config' );
	}

	/**
	 * File-based components that support incremental segment export.
	 *
	 * @return array
	 */
	public static function file_components() {
		return array( 'uploads', 'themes', 'plugins', 'mu-plugins', 'wp-content-other' );
	}

	/**
	 * Whether component is file-based.
	 *
	 * @param string $component Component name.
	 * @return bool
	 */
	public static function is_file_component( $component ) {
		return in_array( $component, self::file_components(), true );
	}

	/**
	 * Initialize a new export migration.
	 *
	 * @param string $migration_id Optional UUID.
	 * @return array
	 */
	public static function init( $migration_id = '' ) {
		if ( ! $migration_id ) {
			$migration_id = wp_generate_uuid4();
		}

		$path = Settings::migration_path( $migration_id );
		wp_mkdir_p( $path );
		DirectoryGuard::protect( $path );

		$manifest = ManifestBuilder::skeleton( $migration_id );
		ManifestBuilder::save( $migration_id, $manifest );

		$job_id = JobRepository::create_job( $migration_id, 'export' );
		Settings::update( array( 'active_migration_id' => $migration_id ) );

		AuditLogger::log( 'export_init', 'Export migration initialized', array(
			'migration_id' => $migration_id,
			'job_id'       => $job_id,
		), 'success' );

		return array(
			'migration_id' => $migration_id,
			'job_id'       => $job_id,
			'path'         => $path,
		);
	}

	/**
	 * Export a single component.
	 *
	 * @param string $migration_id Migration ID.
	 * @param string $component    Component name.
	 * @return array
	 */
	public static function export_component( $migration_id, $component ) {
		\TheExporter\Runtime::prepare_job();
		$component = sanitize_key( $component );
		if ( ! in_array( $component, self::components(), true ) ) {
			return array( 'success' => false, 'error' => 'Invalid component' );
		}

		$job    = JobRepository::get_job_by_migration( $migration_id, 'export' );
		$job_id = $job ? (int) $job['id'] : JobRepository::create_job( $migration_id, 'export' );

		if ( ! JobRepository::acquire_lock( $migration_id, $job_id ) ) {
			return JobRepository::locked_error( $migration_id );
		}

		$path = Settings::migration_path( $migration_id );

		$step_id = JobRepository::create_step( $job_id, $component );
		JobRepository::update_job_status( $job_id, JobRepository::STATUS_RUNNING );
		JobRepository::update_step( $step_id, array( 'status' => JobRepository::STATUS_RUNNING ) );

		$result = array( 'success' => false );

		try {
			switch ( $component ) {
				case 'database':
					$result = self::export_database( $path, $step_id );
					break;
				case 'config':
					$result = self::export_config( $path, $step_id );
					break;
				default:
					if ( self::is_file_component( $component ) ) {
						$scan = self::export_scan( $migration_id, $component, true, true );
						if ( empty( $scan['success'] ) ) {
							$result = $scan;
							break;
						}
						do {
							$result = self::export_segment( $migration_id, $component, true, true );
						} while ( ! empty( $result['success'] ) && empty( $result['done'] ) );
					} else {
						$result = self::export_files_component( $path, $component, $step_id, $job_id );
					}
			}
		} catch ( \Exception $e ) {
			$result = array( 'success' => false, 'error' => $e->getMessage() );
		}

		if ( $result['success'] ) {
			JobRepository::update_step( $step_id, array(
				'status'            => JobRepository::STATUS_COMPLETED,
				'total_chunks'      => isset( $result['chunk_count'] ) ? $result['chunk_count'] : 1,
				'completed_chunks'  => isset( $result['chunk_count'] ) ? $result['chunk_count'] : 1,
				'total_bytes'       => isset( $result['total_bytes'] ) ? $result['total_bytes'] : 0,
			) );

			$manifest = ManifestBuilder::load( $path );
			if ( ! is_array( $manifest ) ) {
				$manifest = ManifestBuilder::skeleton( $migration_id );
			}
			$manifest = ManifestBuilder::add_component( $manifest, $component, array(
				'status'            => 'completed',
				'chunk_count'       => isset( $result['chunk_count'] ) ? $result['chunk_count'] : 1,
				'total_bytes'       => isset( $result['total_bytes'] ) ? $result['total_bytes'] : 0,
				'inventory_file'    => $component . '/inventory.json',
				'verification_mode' => isset( $result['verification_mode'] ) ? $result['verification_mode'] : ( Settings::is_fast_export() ? 'segment' : 'file' ),
			) );
			ManifestBuilder::save( $migration_id, $manifest );

			AuditLogger::log( 'export_component', "Exported {$component}", array(
				'migration_id' => $migration_id,
				'job_id'       => $job_id,
				'component'    => $component,
			), 'success' );
		} else {
			JobRepository::update_step( $step_id, array( 'status' => JobRepository::STATUS_FAILED ) );
			JobRepository::update_job_status( $job_id, JobRepository::STATUS_FAILED );
			AuditLogger::log( 'export_component', "Failed {$component}", array(
				'migration_id' => $migration_id,
				'component'    => $component,
				'error'        => isset( $result['error'] ) ? $result['error'] : '',
			), 'error' );
		}

		JobRepository::release_lock( $migration_id );
		$result['job_id'] = $job_id;
		return $result;
	}

	/**
	 * Queue export of multiple components via scheduler.
	 *
	 * @param string $migration_id Migration ID.
	 * @param array  $components   Component list.
	 * @return array
	 */
	public static function queue_export( $migration_id, array $components ) {
		$components = array_values( array_filter( array_map( 'sanitize_key', $components ) ) );
		if ( empty( $components ) ) {
			return array( 'success' => false, 'error' => 'No components selected' );
		}

		$job = JobRepository::get_job_by_migration( $migration_id, 'export' );
		$job_id = $job ? (int) $job['id'] : JobRepository::create_job( $migration_id, 'export', array( 'queued' => $components ) );

		if ( ! JobRepository::acquire_lock( $migration_id, $job_id ) ) {
			return JobRepository::locked_error( $migration_id );
		}

		JobRepository::update_job_status( $job_id, JobRepository::STATUS_RUNNING );
		$delay = 0;
		foreach ( $components as $component ) {
			if ( ! in_array( $component, self::components(), true ) ) {
				continue;
			}
			Scheduler::schedule( $job_id, $component, $delay );
			$delay += 2;
		}

		JobRepository::release_lock( $migration_id );

		return array(
			'success'    => true,
			'job_id'     => $job_id,
			'queued'     => $components,
			'scheduled'  => function_exists( 'as_schedule_single_action' ) || wp_next_scheduled( Scheduler::HOOK_PROCESS ),
		);
	}

	/**
	 * Check whether export can be finalized.
	 *
	 * @param string $migration_id Migration ID.
	 * @return array
	 */
	public static function can_finalize( $migration_id ) {
		$path     = Settings::migration_path( $migration_id );
		$manifest = ManifestBuilder::load( $path );
		$errors   = array();

		if ( ! $manifest ) {
			return array( 'ready' => false, 'errors' => array( __( 'Manifest not found.', 'the-exporter' ) ) );
		}

		$exported = array();
		$manifest_components = isset( $manifest['components'] ) && is_array( $manifest['components'] )
			? $manifest['components']
			: array();
		foreach ( $manifest_components as $comp ) {
			if ( ! empty( $comp['name'] ) ) {
				$exported[ $comp['name'] ] = $comp;
			}
		}

		if ( empty( $exported ) ) {
			$errors[] = __( 'No components exported yet.', 'the-exporter' );
		}

		foreach ( $exported as $name => $comp ) {
			if ( isset( $comp['status'] ) && 'completed' !== $comp['status'] ) {
				$errors[] = sprintf( __( 'Component %s is not completed.', 'the-exporter' ), $name );
			}
			$inventory = $path . '/' . $name . '/inventory.json';
			if ( ! file_exists( $inventory ) ) {
				$errors[] = sprintf( __( 'Missing inventory for %s.', 'the-exporter' ), $name );
			}
		}

		$job = JobRepository::get_job_by_migration( $migration_id, 'export' );
		if ( $job && JobRepository::STATUS_FAILED === $job['status'] ) {
			$errors[] = __( 'Export job has failed steps. Re-export failed components before finalizing.', 'the-exporter' );
		}

		return array(
			'ready'      => empty( $errors ),
			'errors'     => $errors,
			'components' => array_keys( $exported ),
		);
	}

	/**
	 * Finalize export — seal manifest.
	 *
	 * @param string $migration_id Migration ID.
	 * @return array
	 */
	public static function finalize( $migration_id ) {
		$gate = self::can_finalize( $migration_id );
		if ( ! $gate['ready'] ) {
			return array(
				'success' => false,
				'error'   => __( 'Cannot finalize: export is incomplete.', 'the-exporter' ),
				'errors'  => $gate['errors'],
			);
		}

		\TheExporter\Runtime::prepare_job();
		$path     = Settings::migration_path( $migration_id );
		$manifest = ManifestBuilder::load( $path );

		if ( ! $manifest ) {
			return array( 'success' => false, 'error' => 'Manifest not found' );
		}

		$tables = Dumper::get_tables();
		$rows   = 0;
		foreach ( $tables as $table ) {
			$rows += Dumper::get_row_count( $table );
		}
		$manifest['database']['table_count'] = count( $tables );
		$manifest['database']['total_rows']  = $rows;
		$manifest['transfer_catalog']        = \TheExporter\Transfer\PackageIndex::build_transfer_catalog( $path );

		ManifestBuilder::save( $migration_id, $manifest, true );

		$job = JobRepository::get_job_by_migration( $migration_id, 'export' );
		if ( $job ) {
			JobRepository::update_job_status( (int) $job['id'], JobRepository::STATUS_COMPLETED );
		}

		AuditLogger::log( 'export_finalize', 'Export finalized', array( 'migration_id' => $migration_id ), 'success' );

		$result = array( 'success' => true, 'path' => $path );

		if ( Settings::is_connected_transfer() && Settings::get( 'remote_auto_push' ) ) {
			$push = \TheExporter\Transfer\RemotePusher::queue_push( $migration_id );
			$result['auto_push'] = $push;
			if ( ! empty( $push['success'] ) && ! empty( $push['job_id'] ) ) {
				$result['auto_push']['first_tick'] = \TheExporter\Transfer\RemotePusher::process_tick( (int) $push['job_id'] );
			}
		}

		return $result;
	}

	/**
	 * Export database component.
	 *
	 * @param string $path    Migration path.
	 * @param int    $step_id Step ID.
	 * @return array
	 */
	private static function export_database( $path, $step_id ) {
		$db_dir       = $path . '/database';
		$chunk_size   = (int) Settings::get( 'chunk_size_bytes' );
		$max_transfer = (int) Settings::get( 'browser_transfer_max_bytes', 67108864 );
		$tables_meta  = array();

		foreach ( Dumper::get_tables() as $table ) {
			$tables_meta[] = array(
				'name' => $table,
				'rows' => Dumper::get_row_count( $table ),
			);
		}

		wp_mkdir_p( $db_dir );
		self::clean_database_export( $db_dir );
		$full = Dumper::export_full( $db_dir );
		if ( $full && isset( $full['engine'] ) && 'mydumper' === $full['engine'] ) {
			$chunks = array();
			$db_chunks = array();
			$idx = 1;
			foreach ( $full['chunks'] as $chunk ) {
				$rel = 'database/' . $chunk['path'];
				$chunks[] = array(
					'path'          => $rel,
					'size'          => (int) $chunk['size'],
					'checksum'      => $chunk['checksum'],
					'type'          => 'mydumper',
					'transfer_safe' => (int) $chunk['size'] <= $max_transfer,
				);
				$db_chunks[] = array( $idx, $rel, (int) $chunk['size'], $chunk['checksum'] );
				$idx++;
			}
			return self::save_database_inventory( $db_dir, $step_id, $tables_meta, $chunks, $db_chunks );
		}
		if ( $full && (int) $full['size'] <= $chunk_size ) {
			return self::save_database_inventory(
				$db_dir,
				$step_id,
				$tables_meta,
				array(
					array(
						'path'          => 'database/dump.sql.gz',
						'size'          => $full['size'],
						'checksum'      => $full['checksum'],
						'type'          => 'full',
						'transfer_safe' => $full['size'] <= $max_transfer,
					),
				),
				array( array( 0, 'database/dump.sql.gz', $full['size'], $full['checksum'] ) )
			);
		}

		return self::export_database_chunked( $path, $step_id, $tables_meta, $chunk_size, $max_transfer );
	}

	/**
	 * Export database as per-table chunks (large databases).
	 *
	 * @param string $path         Migration path.
	 * @param int    $step_id      Step ID.
	 * @param array  $tables_meta  Table metadata.
	 * @param int    $chunk_size   Segment size.
	 * @param int    $max_transfer Browser transfer max.
	 * @return array
	 */
	private static function export_database_chunked( $path, $step_id, array $tables_meta, $chunk_size, $max_transfer ) {
		$db_dir   = $path . '/database';
		$data_dir = $db_dir . '/data';
		wp_mkdir_p( $data_dir );
		self::clean_database_export( $db_dir );

		$chunks      = array();
		$total_bytes = 0;
		$db_chunks   = array();

		$schema = Dumper::export_schema( $db_dir );
		if ( $schema ) {
			$chunks[] = array(
				'path'          => 'database/schema.sql.gz',
				'size'          => $schema['size'],
				'checksum'      => $schema['checksum'],
				'type'          => 'schema',
				'transfer_safe' => $schema['size'] <= $max_transfer,
			);
			$total_bytes += $schema['size'];
			$db_chunks[] = array( 0, 'database/schema.sql.gz', $schema['size'], $schema['checksum'] );
		}

		$chunk_index = 1;
		foreach ( Dumper::get_tables() as $table ) {
			$row_count = 0;
			foreach ( $tables_meta as $meta ) {
				if ( $meta['name'] === $table ) {
					$row_count = (int) $meta['rows'];
					break;
				}
			}

			$estimate = $row_count * 500;
			if ( $estimate > $chunk_size && Dumper::get_primary_key( $table ) ) {
				$batches = Dumper::get_pk_batches( $table );
				$part    = 1;
				foreach ( $batches as $where ) {
					$result = Dumper::export_table( $table, $data_dir, $where, $part );
					if ( $result ) {
						$rel = 'database/data/' . basename( $result['path'] );
						$chunks[] = array(
							'path'          => $rel,
							'size'          => $result['size'],
							'checksum'      => $result['checksum'],
							'table'         => $table,
							'where'         => $where,
							'transfer_safe' => $result['size'] <= $max_transfer,
						);
						$total_bytes += $result['size'];
						$db_chunks[] = array( $chunk_index++, $rel, $result['size'], $result['checksum'], array( 'table' => $table ) );
					}
					$part++;
				}
			} else {
				$result = Dumper::export_table( $table, $data_dir );
				if ( $result ) {
					$rel = 'database/data/' . basename( $result['path'] );
					$chunks[] = array(
						'path'          => $rel,
						'size'          => $result['size'],
						'checksum'      => $result['checksum'],
						'table'         => $table,
						'transfer_safe' => $result['size'] <= $max_transfer,
					);
					$total_bytes += $result['size'];
					$db_chunks[] = array( $chunk_index++, $rel, $result['size'], $result['checksum'], array( 'table' => $table ) );
				}
			}
		}

		foreach ( $db_chunks as $db_chunk ) {
			$meta = isset( $db_chunk[4] ) ? $db_chunk[4] : array();
			JobRepository::create_chunk( $step_id, $db_chunk[0], $db_chunk[1], $db_chunk[2], $db_chunk[3], $meta );
		}

		return self::save_database_inventory( $db_dir, $step_id, $tables_meta, $chunks, null );
	}

	/**
	 * Persist database inventory and return export result.
	 *
	 * @param string $db_dir      Database directory.
	 * @param int    $step_id     Step ID.
	 * @param array  $tables_meta Table metadata.
	 * @param array  $chunks      Chunk metadata.
	 * @param array|null $db_chunks Optional chunk rows for JobRepository.
	 * @return array
	 */
	private static function save_database_inventory( $db_dir, $step_id, array $tables_meta, array $chunks, $db_chunks ) {
		$total_bytes = 0;
		foreach ( $chunks as $chunk ) {
			$total_bytes += (int) $chunk['size'];
		}

		if ( is_array( $db_chunks ) ) {
			foreach ( $db_chunks as $db_chunk ) {
				$meta = isset( $db_chunk[4] ) ? $db_chunk[4] : array();
				JobRepository::create_chunk( $step_id, $db_chunk[0], $db_chunk[1], $db_chunk[2], $db_chunk[3], $meta );
			}
		}

		$inventory = array(
			'component'   => 'database',
			'tables'      => $tables_meta,
			'chunks'      => $chunks,
			'total_bytes' => $total_bytes,
		);
		InventoryBuilder::save( $db_dir, $inventory );

		return array(
			'success'     => true,
			'chunk_count' => count( $chunks ),
			'total_bytes' => $total_bytes,
		);
	}

	/**
	 * Remove stale database export artifacts before re-export.
	 *
	 * @param string $db_dir Database component directory.
	 */
	private static function clean_database_export( $db_dir ) {
		foreach ( array( 'dump.sql.gz', 'schema.sql.gz' ) as $file ) {
			$path = $db_dir . '/' . $file;
			if ( file_exists( $path ) ) {
				@unlink( $path );
			}
			if ( file_exists( $path . '.sha256' ) ) {
				@unlink( $path . '.sha256' );
			}
		}

		$data_dir = $db_dir . '/data';
		if ( ! is_dir( $data_dir ) ) {
			return;
		}

		$items = scandir( $data_dir );
		if ( ! is_array( $items ) ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $data_dir . '/' . $item;
			if ( is_dir( $path ) ) {
				continue;
			}
			@unlink( $path );
		}
	}

	/**
	 * Scan files for a file-based component (separate from packing).
	 *
	 * @param string $migration_id Migration ID.
	 * @param string $component    Component name.
	 * @param bool   $resume         Resume partial scan jsonl.
	 * @param bool   $skip_lock      Caller holds lock.
	 * @return array
	 */
	public static function export_scan( $migration_id, $component, $resume = true, $skip_lock = false ) {
		\TheExporter\Runtime::prepare_job();
		$component = sanitize_key( $component );
		if ( ! self::is_file_component( $component ) ) {
			return array( 'success' => false, 'error' => 'Not a file component' );
		}

		$job    = JobRepository::get_job_by_migration( $migration_id, 'export' );
		$job_id = $job ? (int) $job['id'] : JobRepository::create_job( $migration_id, 'export' );

		if ( ! $skip_lock && ! JobRepository::acquire_lock( $migration_id, $job_id ) ) {
			return JobRepository::locked_error( $migration_id );
		}

		$path = Settings::migration_path( $migration_id );
		$step = JobRepository::get_step( $job_id, $component );
		$step_id = $step ? (int) $step['id'] : JobRepository::create_step( $job_id, $component );
		$meta    = self::step_meta( $step_id );

		if ( ! empty( $meta['scan_complete'] ) ) {
			if ( ! $skip_lock ) {
				JobRepository::release_lock( $migration_id );
			}
			return array(
				'success'         => true,
				'phase'           => 'scan',
				'already_complete' => true,
				'files_total'     => isset( $meta['files_total'] ) ? (int) $meta['files_total'] : 0,
				'component'       => $component,
				'job_id'            => $job_id,
			);
		}

		$source_map = self::file_component_sources();
		$source     = $source_map[ $component ];
		$out        = $path . '/' . $component;
		wp_mkdir_p( $out );
		$jsonl_path = $out . '/inventory.jsonl';

		if ( ! $resume ) {
			self::reset_file_component_export( $out, $step_id );
			$meta = array();
		}

		JobRepository::update_job_status( $job_id, JobRepository::STATUS_RUNNING );
		JobRepository::update_step( $step_id, array(
			'status' => JobRepository::STATUS_RUNNING,
			'meta'   => array_merge( $meta, array( 'phase' => 'scanning', 'files_scanned' => 0 ) ),
		) );

		try {
			$scan = InventoryBuilder::scan_to_jsonl(
				$source,
				self::scan_base_dir( $component ),
				$jsonl_path,
				array(
					'resume'                 => $resume && file_exists( $jsonl_path ),
					'extra_exclude_prefixes' => self::component_exclude_prefixes( $component ),
					'on_progress'            => function ( $count ) use ( $step_id, $job_id, $migration_id ) {
						JobRepository::update_step( $step_id, array(
							'meta' => array_merge( self::step_meta( $step_id ), array(
								'phase'         => 'scanning',
								'files_scanned' => $count,
							) ),
						) );
						self::touch_export_progress( $job_id, $step_id, $migration_id );
					},
				)
			);

			$meta = array_merge( $meta, array(
				'phase'         => 'segmenting',
				'scan_complete' => true,
				'files_total'   => (int) $scan['files_total'],
				'files_scanned' => (int) $scan['files_total'],
				'bytes_total'   => (int) $scan['bytes_total'],
				'files_offset'  => 0,
				'segment_index' => 1,
				'files_packed'  => 0,
				'files_queued'  => 0,
			) );
			$estimated = Settings::estimate_segment_count( $meta['bytes_total'], $meta['files_total'] );
			JobRepository::update_step( $step_id, array(
				'total_chunks'     => $estimated,
				'completed_chunks' => 0,
				'meta'             => $meta,
			) );
			self::touch_export_progress( $job_id, $step_id, $migration_id );

			if ( ! $skip_lock ) {
				JobRepository::release_lock( $migration_id );
			}

			return array(
				'success'     => true,
				'phase'       => 'scan',
				'files_total' => (int) $scan['files_total'],
				'bytes_total' => (int) $scan['bytes_total'],
				'segments_est'=> $estimated,
				'component'   => $component,
				'job_id'      => $job_id,
			);
		} catch ( \Exception $e ) {
			JobRepository::update_step( $step_id, array( 'status' => JobRepository::STATUS_FAILED ) );
			if ( ! $skip_lock ) {
				JobRepository::release_lock( $migration_id );
			}
			return array( 'success' => false, 'error' => $e->getMessage(), 'job_id' => $job_id );
		}
	}

	/**
	 * Export one or more segments of a file-based component (pack only; scan separately).
	 *
	 * @param string $migration_id Migration ID.
	 * @param string $component    Component name.
	 * @param bool   $resume       Resume from saved cursor.
	 * @param bool   $skip_lock    Skip lock acquire/release (caller holds lock).
	 * @param int    $max_segments Max segments per request (time-budgeted).
	 * @return array
	 */
	public static function export_segment( $migration_id, $component, $resume = true, $skip_lock = false, $max_segments = 3 ) {
		\TheExporter\Runtime::prepare_job();
		$component = sanitize_key( $component );
		if ( ! self::is_file_component( $component ) ) {
			return array( 'success' => false, 'error' => 'Not a file component' );
		}

		$job    = JobRepository::get_job_by_migration( $migration_id, 'export' );
		$job_id = $job ? (int) $job['id'] : JobRepository::create_job( $migration_id, 'export' );

		if ( ! $skip_lock && ! JobRepository::acquire_lock( $migration_id, $job_id ) ) {
			return JobRepository::locked_error( $migration_id );
		}

		$path = Settings::migration_path( $migration_id );
		$step = JobRepository::get_step( $job_id, $component );
		if ( $step && JobRepository::STATUS_COMPLETED === $step['status'] ) {
			if ( ! $skip_lock ) {
				JobRepository::release_lock( $migration_id );
			}
			return array(
				'success'     => true,
				'done'        => true,
				'component'   => $component,
				'job_id'      => $job_id,
				'chunk_count' => (int) $step['total_chunks'],
				'total_bytes' => (int) $step['total_bytes'],
			);
		}

		$step_id = $step ? (int) $step['id'] : JobRepository::create_step( $job_id, $component );
		$meta    = self::step_meta( $step_id );

		if ( ! $resume ) {
			$out = $path . '/' . $component;
			self::reset_file_component_export( $out, $step_id );
			$meta = array();
		}

		if ( empty( $meta['scan_complete'] ) ) {
			if ( ! $skip_lock ) {
				JobRepository::release_lock( $migration_id );
			}
			return array(
				'success'    => false,
				'error'      => __( 'Component not scanned yet. Run export/scan first.', 'the-exporter' ),
				'needs_scan' => true,
			);
		}

		JobRepository::update_job_status( $job_id, JobRepository::STATUS_RUNNING );
		JobRepository::update_step( $step_id, array( 'status' => JobRepository::STATUS_RUNNING ) );

		$source_map = self::file_component_sources();
		$source     = $source_map[ $component ];
		$out        = $path . '/' . $component;
		wp_mkdir_p( $out );
		$jsonl_path = $out . '/inventory.jsonl';

		$max_segments = max( 1, min( 15, (int) $max_segments ) );
		if ( $max_segments <= 3 && Settings::is_localhost_studio() ) {
			$max_segments = Settings::export_segments_per_tick();
		}
		$started_at   = time();
		$time_budget  = Settings::export_segment_time_budget();
		$packed_now   = 0;

		try {
			$meta          = self::step_meta( $step_id );
			$files_offset  = isset( $meta['files_offset'] ) ? (int) $meta['files_offset'] : 0;
			$jsonl_byte    = isset( $meta['jsonl_byte_offset'] ) ? (int) $meta['jsonl_byte_offset'] : 0;
			$segment_index = isset( $meta['segment_index'] ) ? (int) $meta['segment_index'] : 1;
			$files_total   = isset( $meta['files_total'] ) ? (int) $meta['files_total'] : InventoryBuilder::count_jsonl_lines( $jsonl_path );
			$files_packed  = isset( $meta['files_packed'] ) ? (int) $meta['files_packed'] : 0;
			$chunk_size    = (int) Settings::effective_segment_size();
			$max_files     = (int) Settings::effective_max_files_per_segment();
			$estimated     = isset( $meta['bytes_total'] )
				? Settings::estimate_segment_count( (int) $meta['bytes_total'], $files_total )
				: max( 1, (int) ( JobRepository::get_step_by_id( $step_id )['total_chunks'] ?? 1 ) );

			while ( $files_offset < $files_total && $packed_now < $max_segments && ( time() - $started_at ) < $time_budget ) {
				if ( $skip_lock && self::segment_locked_by_other( self::step_meta( $step_id ), get_transient( 'te_worker_' . sanitize_key( $migration_id ) . '_' . sanitize_key( $component ) ) ?: '' ) ) {
					break;
				}

				$read = InventoryBuilder::read_jsonl_batch( $jsonl_path, $files_offset, $max_files, $chunk_size, $jsonl_byte );
				if ( empty( $read['batch'] ) && $files_offset < $files_total ) {
					$read = InventoryBuilder::read_jsonl_batch( $jsonl_path, $files_offset, $max_files, $chunk_size, 0 );
				}
				if ( empty( $read['batch'] ) ) {
					break;
				}

				$heartbeat = function () use ( $job_id, $step_id, $migration_id ) {
					self::touch_export_progress( $job_id, $step_id, $migration_id );
				};

				$batch_count    = 0;
				$write_progress = function ( $sub_phase, $done, $total ) use ( $step_id, $job_id, $migration_id, $segment_index, $estimated, $files_packed, $files_total, $files_offset, &$batch_count ) {
					$packed_now = $files_packed;
					if ( 'compressing' === $sub_phase && $total > 0 && $batch_count > 0 ) {
						$packed_now = $files_packed + (int) floor( ( (int) $done / (int) $total ) * $batch_count );
					}
					$meta_now = self::step_meta( $step_id );
					JobRepository::update_step( $step_id, array(
						'total_chunks'     => $estimated,
						'completed_chunks' => max( 0, $segment_index - 1 ),
						'meta'             => array_merge( $meta_now, array(
							'phase'         => 'packing',
							'sub_phase'     => $sub_phase,
							'hash_done'     => (int) $done,
							'hash_total'    => (int) $total,
							'segment_index' => $segment_index,
							'files_total'   => $files_total,
							'files_packed'  => $packed_now,
							'files_queued'  => $files_offset,
							'files_scanned' => $files_total,
						) ),
					) );
					self::touch_export_progress( $job_id, $step_id, $migration_id );
				};

				$batch_count = count( $read['batch'] );
				$chunk = SegmentWriter::pack_segment( $read['batch'], $source, $out, $segment_index, array(
					'on_write_progress' => $write_progress,
					'on_heartbeat'      => $heartbeat,
				) );

				if ( ! $chunk ) {
					throw new \RuntimeException( 'Failed to write segment ' . $segment_index );
				}

				$chunk_row = array(
					'path'          => $component . '/' . $chunk['path'],
					'size'          => $chunk['size'],
					'checksum'      => $chunk['checksum'],
					'transfer_safe' => $chunk['transfer_safe'],
					'file_count'    => isset( $chunk['file_count'] ) ? $chunk['file_count'] : count( $read['batch'] ),
				);
				InventoryBuilder::append_chunk_manifest( $out, $chunk_row );
				JobRepository::create_chunk( $step_id, $segment_index, $chunk_row['path'], $chunk['size'], $chunk['checksum'] );

				$files_packed += count( $read['batch'] );
				$files_offset  = (int) $read['offset'];
				$jsonl_byte    = isset( $read['byte_offset'] ) ? (int) $read['byte_offset'] : $jsonl_byte;
				$segments_done = $segment_index;
				$meta = array_merge( self::step_meta( $step_id ), array(
					'phase'             => 'segmenting',
					'sub_phase'         => '',
					'files_offset'      => $files_offset,
					'jsonl_byte_offset' => $jsonl_byte,
					'segment_index'     => $segment_index + 1,
					'files_packed'      => $files_packed,
					'files_queued'      => $files_offset,
					'files_total'       => $files_total,
					'files_scanned'     => $files_total,
					'scan_complete'     => true,
				) );
				$states = isset( $meta['segment_states'] ) && is_array( $meta['segment_states'] ) ? $meta['segment_states'] : array();
				$states[ (string) $segment_index ] = JobRepository::STATUS_COMPLETED;
				$locks  = isset( $meta['segment_locks'] ) && is_array( $meta['segment_locks'] ) ? $meta['segment_locks'] : array();
				unset( $locks[ (string) $segment_index ] );
				$meta['segment_states'] = $states;
				$meta['segment_locks']    = $locks;
				$segment_index++;
				$packed_now++;
				JobRepository::update_step( $step_id, array(
					'total_chunks'     => max( $estimated, $segments_done ),
					'completed_chunks' => $segments_done,
					'meta'             => $meta,
				) );
				self::touch_export_progress( $job_id, $step_id, $migration_id );
			}

			if ( $files_offset < $files_total ) {
				if ( ! $skip_lock ) {
					JobRepository::release_lock( $migration_id );
				}
				$segments_done = $segment_index - 1;
				return array(
					'success'         => true,
					'done'            => false,
					'component'       => $component,
					'segment'         => $segments_done,
					'segments'        => max( $estimated, $segments_done + 1 ),
					'segments_packed' => $packed_now,
					'files_packed'    => $files_packed,
					'files_total'     => $files_total,
					'job_id'          => $job_id,
				);
			}

			$result = self::finalize_file_component( $path, $migration_id, $component, $step_id, $job_id, $out );
			if ( ! $skip_lock ) {
				JobRepository::release_lock( $migration_id );
			}
			return $result;
		} catch ( \Exception $e ) {
			JobRepository::update_step( $step_id, array( 'status' => JobRepository::STATUS_FAILED ) );
			JobRepository::update_job_status( $job_id, JobRepository::STATUS_FAILED );
			if ( ! $skip_lock ) {
				JobRepository::release_lock( $migration_id );
			}
			return array( 'success' => false, 'error' => $e->getMessage(), 'job_id' => $job_id );
		}
	}

	/**
	 * Base directory for relative paths when scanning.
	 *
	 * @param string $component Component.
	 * @return string
	 */
	private static function scan_base_dir( $component ) {
		return 'wp-content-other' === $component ? WP_CONTENT_DIR : self::file_component_sources()[ $component ];
	}

	/**
	 * Extra path prefixes to skip when scanning wp-content-other.
	 *
	 * @param string $component Component.
	 * @return array
	 */
	private static function component_exclude_prefixes( $component ) {
		if ( 'wp-content-other' !== $component ) {
			return array();
		}
		return array(
			'uploads/',
			'themes/',
			'plugins/',
			'mu-plugins/',
			'migration-exports/',
			'migration-imports/',
			'migration-restore-points/',
		);
	}

	/**
	 * Finalize file component after all segments written.
	 *
	 * @param string $path         Migration path.
	 * @param string $migration_id Migration ID.
	 * @param string $component    Component.
	 * @param int    $step_id      Step ID.
	 * @param int    $job_id       Job ID.
	 * @param string $out          Component output dir.
	 * @return array
	 */
	private static function finalize_file_component( $path, $migration_id, $component, $step_id, $job_id, $out ) {
		$chunks              = InventoryBuilder::load_chunk_manifest( $out );
		$verification_mode   = Settings::is_fast_export() ? 'segment' : 'file';
		InventoryBuilder::finalize_inventory( $out, $component, $chunks, $verification_mode );

		$total_bytes = 0;
		foreach ( $chunks as $chunk ) {
			$total_bytes += isset( $chunk['size'] ) ? (int) $chunk['size'] : 0;
		}

		JobRepository::update_step( $step_id, array(
			'status'           => JobRepository::STATUS_COMPLETED,
			'total_chunks'     => count( $chunks ),
			'completed_chunks' => count( $chunks ),
			'total_bytes'      => $total_bytes,
			'meta'             => array( 'phase' => 'done' ),
		) );

		$manifest = ManifestBuilder::load( $path );
		$manifest = ManifestBuilder::add_component( $manifest, $component, array(
			'status'            => 'completed',
			'chunk_count'       => count( $chunks ),
			'total_bytes'       => $total_bytes,
			'inventory_file'    => $component . '/inventory.json',
			'verification_mode' => $verification_mode,
		) );
		ManifestBuilder::save( $migration_id, $manifest );

		AuditLogger::log( 'export_component', "Exported {$component}", array(
			'migration_id' => $migration_id,
			'job_id'       => $job_id,
			'component'    => $component,
		), 'success' );

		return array(
			'success'             => true,
			'done'                => true,
			'component'           => $component,
			'chunk_count'         => count( $chunks ),
			'total_bytes'         => $total_bytes,
			'verification_mode'   => $verification_mode,
			'job_id'              => $job_id,
		);
	}

	/**
	 * Source directories for file components.
	 *
	 * @return array
	 */
	private static function file_component_sources() {
		return array(
			'uploads'          => WP_CONTENT_DIR . '/uploads',
			'themes'           => WP_CONTENT_DIR . '/themes',
			'plugins'          => WP_CONTENT_DIR . '/plugins',
			'mu-plugins'       => WP_CONTENT_DIR . '/mu-plugins',
			'wp-content-other' => WP_CONTENT_DIR,
		);
	}

	/**
	 * Whether another worker holds a fresh lock on the current segment.
	 *
	 * @param array  $meta      Step meta.
	 * @param string $worker_id Current worker ID.
	 * @return bool
	 */
	private static function segment_locked_by_other( array $meta, $worker_id ) {
		$locks = isset( $meta['segment_locks'] ) && is_array( $meta['segment_locks'] ) ? $meta['segment_locks'] : array();
		$index = isset( $meta['segment_index'] ) ? (string) (int) $meta['segment_index'] : '1';
		if ( empty( $locks[ $index ] ) ) {
			return false;
		}
		$lock = $locks[ $index ];
		if ( empty( $lock['at'] ) || ( time() - (int) $lock['at'] ) > 300 ) {
			return false;
		}
		return ! empty( $lock['worker'] ) && (string) $lock['worker'] !== (string) $worker_id;
	}

	/**
	 * Read step meta as array.
	 *
	 * @param int $step_id Step ID.
	 * @return array
	 */
	private static function step_meta( $step_id ) {
		$step = JobRepository::get_step_by_id( $step_id );
		if ( ! $step || empty( $step['meta'] ) ) {
			return array();
		}
		$meta = is_array( $step['meta'] ) ? $step['meta'] : json_decode( $step['meta'], true );
		return is_array( $meta ) ? $meta : array();
	}

	/**
	 * Reset incremental export artifacts for a file component.
	 *
	 * @param string $out     Component directory.
	 * @param int    $step_id Step ID.
	 */
	private static function reset_file_component_export( $out, $step_id ) {
		foreach ( array( 'inventory.jsonl', 'inventory.json', 'chunks.json', 'chunks.jsonl' ) as $file ) {
			$path = trailingslashit( $out ) . $file;
			if ( file_exists( $path ) ) {
				@unlink( $path );
			}
		}
		$segments = trailingslashit( $out ) . 'segments';
		if ( is_dir( $segments ) ) {
			$items = scandir( $segments );
			if ( is_array( $items ) ) {
				foreach ( $items as $item ) {
					if ( '.' === $item || '..' === $item ) {
						continue;
					}
					@unlink( $segments . '/' . $item );
				}
			}
		}
		JobRepository::delete_step_chunks( $step_id );
	}

	/**
	 * Export file-based component (legacy single-request path).
	 *
	 * @param string $path      Migration path.
	 * @param string $component Component.
	 * @param int    $step_id      Step ID.
	 * @param int    $job_id       Job ID.
	 * @return array
	 */
	private static function export_files_component( $path, $component, $step_id, $job_id = 0 ) {
		$map = array(
			'uploads'          => WP_CONTENT_DIR . '/uploads',
			'themes'           => WP_CONTENT_DIR . '/themes',
			'plugins'          => WP_CONTENT_DIR . '/plugins',
			'mu-plugins'       => WP_CONTENT_DIR . '/mu-plugins',
			'wp-content-other' => WP_CONTENT_DIR,
		);

		$source       = $map[ $component ];
		$out          = $path . '/' . $component;
		$migration_id = basename( $path );
		if ( 0 === strpos( $migration_id, 'migration-' ) ) {
			$migration_id = substr( $migration_id, 10 );
		}
		wp_mkdir_p( $out );

		JobRepository::update_step( $step_id, array(
			'meta' => array( 'phase' => 'scanning', 'files_scanned' => 0 ),
		) );

		$scanned = InventoryBuilder::scan(
			$source,
			$component === 'wp-content-other' ? WP_CONTENT_DIR : $source,
			array(
				'defer_hash'  => true,
				'on_progress' => function ( $count ) use ( $step_id, $migration_id ) {
					JobRepository::update_step( $step_id, array(
						'meta' => array( 'phase' => 'scanning', 'files_scanned' => $count ),
					) );
					JobRepository::touch_lock( $migration_id );
				},
			)
		);

		$bytes_total = 0;
		foreach ( $scanned as $f ) {
			$bytes_total += (int) $f['size'];
		}

		$chunk_size         = (int) Settings::effective_segment_size();
		$estimated_segments = max( 1, (int) ceil( $bytes_total / max( 1, $chunk_size ) ) );
		$files_total        = count( $scanned );

		JobRepository::update_step( $step_id, array(
			'total_chunks'     => $estimated_segments,
			'completed_chunks' => 0,
			'meta'             => array(
				'phase'         => 'segmenting',
				'files_scanned' => $files_total,
				'files_total'   => $files_total,
				'files_packed'  => 0,
				'bytes_total'   => $bytes_total,
			),
		) );
		self::touch_export_progress( $job_id, $step_id, $migration_id );

		$segment_result = SegmentWriter::create_segments(
			$scanned,
			$source,
			$out,
			$chunk_size,
			array(
				'defer_hash'      => true,
				'on_segment'      => function ( $done, $estimated, $chunk, $files_packed ) use ( $step_id, $job_id, $migration_id, $files_total ) {
					JobRepository::update_step( $step_id, array(
						'total_chunks'     => max( $estimated, $done ),
						'completed_chunks' => $done,
						'meta'             => array(
							'phase'         => 'segmenting',
							'files_total'   => $files_total,
							'files_packed'  => $files_packed,
							'files_scanned' => $files_total,
						),
					) );
					self::touch_export_progress( $job_id, $step_id, $migration_id );
				},
				'on_file_progress' => function ( $packed, $total ) use ( $step_id, $job_id, $migration_id, $files_total, $estimated_segments ) {
					JobRepository::update_step( $step_id, array(
						'total_chunks'     => $estimated_segments,
						'meta'             => array(
							'phase'         => 'packing',
							'files_total'   => $files_total,
							'files_packed'  => $packed,
							'files_scanned' => $files_total,
						),
					) );
					self::touch_export_progress( $job_id, $step_id, $migration_id );
				},
			)
		);

		$segments = $segment_result['chunks'];
		$scanned  = $segment_result['files'];

		$total_bytes = 0;
		foreach ( $segments as $i => $seg ) {
			$total_bytes += $seg['size'];
			JobRepository::create_chunk( $step_id, $i + 1, $component . '/' . $seg['path'], $seg['size'], $seg['checksum'] );
		}

		JobRepository::update_step( $step_id, array(
			'total_chunks'     => count( $segments ),
			'completed_chunks' => count( $segments ),
			'total_bytes'      => $total_bytes,
			'meta'             => array( 'phase' => 'done' ),
		) );

		$inventory = array(
			'component'   => $component,
			'files'       => $scanned,
			'chunks'      => array_map( function ( $s ) use ( $component ) {
				$max = (int) Settings::get( 'browser_transfer_max_bytes', 67108864 );
				return array(
					'path'          => $component . '/' . $s['path'],
					'size'          => $s['size'],
					'checksum'      => $s['checksum'],
					'transfer_safe' => isset( $s['transfer_safe'] ) ? $s['transfer_safe'] : ( $s['size'] <= $max ),
				);
			}, $segments ),
			'total_bytes' => $total_bytes,
		);
		InventoryBuilder::save( $out, $inventory );

		return array(
			'success'     => true,
			'chunk_count' => count( $segments ),
			'total_bytes' => $total_bytes,
			'job_id'      => $job_id,
		);
	}

	/**
	 * Keep job lock and updated_at fresh during long exports.
	 *
	 * @param int    $job_id       Job ID.
	 * @param int    $step_id      Step ID.
	 * @param string $migration_id Migration ID.
	 */
	private static function touch_export_progress( $job_id, $step_id, $migration_id ) {
		if ( $job_id ) {
			JobRepository::update_job_status( $job_id, JobRepository::STATUS_RUNNING );
		}
		JobRepository::touch_lock( $migration_id );
	}

	/**
	 * Export config component.
	 *
	 * @param string $path    Migration path.
	 * @param int    $step_id Step ID.
	 * @return array
	 */
	private static function export_config( $path, $step_id ) {
		$inventory = EnvironmentExporter::export( $path );
		JobRepository::create_chunk( $step_id, 0, 'config/environment.json', $inventory['total_bytes'], $inventory['chunks'][0]['checksum'] );
		return array(
			'success'     => true,
			'chunk_count' => 1,
			'total_bytes' => $inventory['total_bytes'],
		);
	}

	/**
	 * Claim and pack one segment (worker pool entry point).
	 *
	 * @param string $migration_id Migration ID.
	 * @param string $component    File component.
	 * @param string $worker_id    Optional worker identifier.
	 * @return array
	 */
	public static function claim_segment( $migration_id, $component, $worker_id = '' ) {
		$worker_id = sanitize_text_field( $worker_id );
		$component = sanitize_key( $component );
		$job       = JobRepository::get_job_by_migration( $migration_id, 'export' );
		$job_id    = $job ? (int) $job['id'] : 0;
		$step      = $job_id ? JobRepository::get_step( $job_id, $component ) : null;
		$step_id   = $step ? (int) $step['id'] : 0;

		if ( $step_id && $worker_id ) {
			$meta = self::step_meta( $step_id );
			if ( self::segment_locked_by_other( $meta, $worker_id ) ) {
				return array(
					'success'  => true,
					'done'     => false,
					'deferred' => true,
					'component' => $component,
				);
			}
			$segment_index = isset( $meta['segment_index'] ) ? (int) $meta['segment_index'] : 1;
			$locks = isset( $meta['segment_locks'] ) && is_array( $meta['segment_locks'] ) ? $meta['segment_locks'] : array();
			$locks[ (string) $segment_index ] = array(
				'worker' => $worker_id,
				'at'     => time(),
				'status' => JobRepository::STATUS_RUNNING,
			);
			$states = isset( $meta['segment_states'] ) && is_array( $meta['segment_states'] ) ? $meta['segment_states'] : array();
			$states[ (string) $segment_index ] = JobRepository::STATUS_RUNNING;
			JobRepository::update_step( $step_id, array(
				'meta' => array_merge( $meta, array(
					'segment_locks'  => $locks,
					'segment_states' => $states,
				) ),
			) );
			set_transient( 'te_worker_' . sanitize_key( $migration_id ) . '_' . sanitize_key( $component ), $worker_id, 5 * MINUTE_IN_SECONDS );
		}

		return self::export_segment( $migration_id, $component, true, (bool) $worker_id, 1 );
	}

	/**
	 * Process one export tick (scan or single segment) for background jobs.
	 *
	 * @param int    $job_id    Job ID.
	 * @param string $component Component.
	 * @return array
	 */
	public static function process_export_tick( $job_id, $component, $skip_lock = false ) {
		$job = JobRepository::get_job( $job_id );
		if ( ! $job ) {
			return array( 'success' => false, 'error' => 'Job not found' );
		}

		$migration_id = $job['migration_id'];
		$component    = sanitize_key( $component );

		if ( ! in_array( $component, self::components(), true ) ) {
			return array( 'success' => false, 'error' => 'Invalid component' );
		}

		if ( ! self::is_file_component( $component ) ) {
			return self::export_component( $migration_id, $component );
		}

		$step    = JobRepository::get_step( $job_id, $component );
		$step_id = $step ? (int) $step['id'] : JobRepository::create_step( $job_id, $component );
		$meta    = self::step_meta( $step_id );

		if ( empty( $meta['scan_complete'] ) ) {
			return self::export_scan( $migration_id, $component, true, $skip_lock );
		}

		if ( $step && JobRepository::STATUS_COMPLETED === $step['status'] ) {
			return array( 'success' => true, 'done' => true, 'component' => $component );
		}

		$max_seg = Settings::is_localhost_studio() ? Settings::export_segments_per_tick() : 1;
		return self::export_segment( $migration_id, $component, true, $skip_lock, $max_seg );
	}

	/**
	 * Run full export in one process (WP-CLI / long-running HTTP).
	 *
	 * @param string $migration_id Migration ID.
	 * @param array  $components   Components to export (default: all).
	 * @return array
	 */
	public static function export_run( $migration_id, array $components = array() ) {
		\TheExporter\Runtime::prepare_job();
		if ( empty( $components ) ) {
			$components = self::components();
		}

		$failures = array();
		foreach ( $components as $component ) {
			$result = self::export_component( $migration_id, $component );
			if ( empty( $result['success'] ) ) {
				$failures[] = $component . ': ' . ( isset( $result['error'] ) ? $result['error'] : 'failed' );
				break;
			}
		}

		if ( ! empty( $failures ) ) {
			return array( 'success' => false, 'error' => implode( '; ', $failures ) );
		}

		return self::finalize( $migration_id );
	}

	/**
	 * Process next chunk (scheduler fallback).
	 *
	 * @param int    $job_id    Job ID.
	 * @param string $component Component.
	 */
	public static function process_next_chunk( $job_id, $component ) {
		$job = JobRepository::get_job( $job_id );
		if ( ! $job ) {
			return;
		}

		if ( 'export' === $job['type'] ) {
			$result = self::process_export_tick( $job_id, $component );
			if ( ! empty( $result['success'] ) && empty( $result['done'] ) ) {
				Scheduler::schedule( $job_id, $component, 1 );
			}
		}
	}

	/**
	 * Drive export forward one tick (admin / Studio fallback when WP-Cron is idle).
	 *
	 * @param string $migration_id Migration ID.
	 * @return array
	 */
	public static function drive_export( $migration_id ) {
		\TheExporter\Runtime::prepare_job();
		$migration_id = sanitize_text_field( $migration_id );

		$path = Settings::migration_path( $migration_id );
		if ( ! ManifestBuilder::load( $path ) ) {
			wp_mkdir_p( $path );
			DirectoryGuard::protect( $path );
			ManifestBuilder::save( $migration_id, ManifestBuilder::skeleton( $migration_id ) );
		}

		$job = JobRepository::get_job_by_migration( $migration_id, 'export' );
		if ( ! $job ) {
			$queued = self::queue_export( $migration_id, self::components() );
			if ( empty( $queued['success'] ) ) {
				return $queued;
			}
			$job = JobRepository::get_job_by_migration( $migration_id, 'export' );
			if ( ! $job ) {
				return array( 'success' => false, 'error' => 'Could not start export job.' );
			}
		}

		if ( JobRepository::STATUS_COMPLETED === $job['status'] ) {
			return array( 'success' => true, 'done' => true, 'finalized' => true );
		}

		$job_id     = (int) $job['id'];
		$components = array();
		if ( ! empty( $job['meta']['queued'] ) && is_array( $job['meta']['queued'] ) ) {
			$components = $job['meta']['queued'];
		} else {
			$components = self::components();
		}

		JobRepository::update_job_status( $job_id, JobRepository::STATUS_RUNNING );
		JobRepository::force_release_lock( $migration_id );

		foreach ( $components as $component ) {
			$step = JobRepository::get_step( $job_id, $component );
			if ( $step && JobRepository::STATUS_COMPLETED === $step['status'] ) {
				continue;
			}

			$tick = self::process_export_tick( $job_id, $component, true );
			$tick['job_id']    = $job_id;
			$tick['component'] = $component;

			if ( empty( $tick['success'] ) ) {
				JobRepository::update_job_status( $job_id, JobRepository::STATUS_FAILED );
				return $tick;
			}

			if ( empty( $tick['done'] ) ) {
				return $tick;
			}
		}

		$gate = self::can_finalize( $migration_id );
		if ( ! empty( $gate['ready'] ) ) {
			return self::finalize( $migration_id );
		}

		return array(
			'success'          => true,
			'done'             => false,
			'waiting_finalize' => true,
			'errors'           => isset( $gate['errors'] ) ? $gate['errors'] : array(),
		);
	}

	/**
	 * Run multiple export ticks in one HTTP request (faster wizard progress).
	 *
	 * @param string $migration_id Migration ID.
	 * @param int    $max_seconds  Time budget.
	 * @return array
	 */
	public static function drive_export_batch( $migration_id, $max_seconds = 0 ) {
		if ( $max_seconds <= 0 ) {
			$max_seconds = Settings::export_drive_seconds();
		}
		$started = microtime( true );
		$ticks   = 0;
		$last    = array( 'success' => false, 'error' => 'No ticks ran' );

		while ( ( microtime( true ) - $started ) < $max_seconds ) {
			$last = self::drive_export( $migration_id );
			$ticks++;

			if ( empty( $last['success'] ) ) {
				break;
			}
			if ( ! empty( $last['finalized'] ) || ! empty( $last['path'] ) ) {
				$last['finalized'] = true;
				break;
			}
			if ( ! empty( $last['waiting_finalize'] ) ) {
				break;
			}
		}

		$last['batch_ticks']   = $ticks;
		$last['batch_seconds'] = round( microtime( true ) - $started, 2 );
		if ( empty( $last['finalized'] ) && empty( $last['path'] ) && ! empty( $last['success'] ) ) {
			self::chain_loopback( $migration_id );
		}
		return $last;
	}

	/**
	 * Non-blocking loopback to keep export worker alive without browser polling.
	 *
	 * @param string $migration_id Migration ID.
	 */
	public static function chain_loopback( $migration_id ) {
		$url = rest_url( 'the-exporter/v1/export/worker-tick' );
		wp_remote_post(
			$url,
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'headers'   => array(
					'Content-Type'      => 'application/json',
					'X-TE-Worker-Token' => \TheExporter\Transfer\TransferWorker::get_token(),
				),
				'body'      => wp_json_encode( array( 'migration_id' => $migration_id ) ),
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			)
		);
	}
}
