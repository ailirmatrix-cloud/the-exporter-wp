<?php
/**
 * Import orchestration.
 *
 * @package TheExporter
 */

namespace TheExporter\Jobs;

use TheExporter\Database\Importer;
use TheExporter\Files\SegmentExtractor;
use TheExporter\Logging\AuditLogger;
use TheExporter\Manifest\ManifestValidator;
use TheExporter\Restore\RestorePointManager;
use TheExporter\Runtime;
use TheExporter\Settings;
use TheExporter\Transfer\PackageIndex;
use TheExporter\Transfer\VerifyQueue;
use TheExporter\Validation\ChecksumService;

defined( 'ABSPATH' ) || exit;

/**
 * Class ImportOrchestrator
 */
class ImportOrchestrator {

	/**
	 * Run pre-import validation.
	 *
	 * @param string $migration_id Migration ID.
	 * @param bool   $dry_run      Dry run.
	 * @return array
	 */
	public static function validate( $migration_id, $dry_run = true ) {
		VerifyQueue::flush_pending( $migration_id );
		$path = PackageIndex::resolve_path( $migration_id, 'import' );
		if ( ! $path ) {
			return array(
				'dry_run'  => $dry_run,
				'passed'   => false,
				'errors'   => array( array( 'code' => 'migration_missing', 'message' => __( 'Migration package not found.', 'the-exporter' ) ) ),
				'warnings' => array(),
				'checks'   => array(),
			);
		}
		return ManifestValidator::validate( $path, $dry_run, $migration_id );
	}

	/**
	 * Import a component.
	 *
	 * @param string $migration_id Migration ID.
	 * @param string $component    Component.
	 * @param array  $args         Options.
	 * @return array
	 */
	public static function import_component( $migration_id, $component, array $args = array() ) {
		Runtime::prepare_job();

		$defaults = array(
			'dry_run'              => false,
			'confirm'              => false,
			'force'                => false,
			'skip_validation'      => false,
			'create_restore_point' => true,
			'resume'               => true,
			'job_id'               => 0,
			'single_tick'          => false,
		);
		$args = wp_parse_args( $args, $defaults );

		$path = PackageIndex::resolve_path( $migration_id, 'import' );
		if ( ! $path ) {
			return array( 'success' => false, 'error' => 'Migration package not found' );
		}

		$id_check = ManifestValidator::verify_migration_id( $path, $migration_id );
		if ( ! $id_check['passed'] ) {
			return array( 'success' => false, 'error' => $id_check['message'], 'code' => 'migration_id_mismatch' );
		}

		if ( ! $args['skip_validation'] ) {
			$validation = ManifestValidator::validate( $path, true, $migration_id );
			if ( ! $validation['passed'] && ! $args['force'] ) {
				return array( 'success' => false, 'error' => 'Validation failed', 'validation' => $validation );
			}
		}

		if ( $args['dry_run'] ) {
			return array( 'success' => true, 'dry_run' => true, 'component' => $component );
		}

		$upload_status = \TheExporter\Transfer\FileUploader::component_status( $migration_id, $component );
		if ( ! empty( $upload_status['skipped'] ) ) {
			return array(
				'success'   => true,
				'skipped'   => true,
				'component' => $component,
				'message'   => isset( $upload_status['message'] ) ? $upload_status['message'] : '',
			);
		}

		if ( ! $args['confirm'] ) {
			return array( 'success' => false, 'error' => 'Import requires explicit confirmation' );
		}

		$job_id = (int) $args['job_id'];
		if ( ! $job_id ) {
			$existing = JobRepository::get_job_by_migration( $migration_id, 'import' );
			if ( $existing && in_array( $existing['status'], array( JobRepository::STATUS_PENDING, JobRepository::STATUS_RUNNING ), true ) ) {
				$job_id = (int) $existing['id'];
			} else {
				$job_id = JobRepository::create_job( $migration_id, 'import' );
			}
		}

		if ( ! $job_id ) {
			return array( 'success' => false, 'error' => 'Could not create import job' );
		}

		if ( ! JobRepository::acquire_lock( $migration_id, $job_id ) ) {
			$lock = JobRepository::get_lock_info( $migration_id );
			return array(
				'success' => false,
				'error'   => 'Migration locked',
				'lock'    => $lock,
			);
		}

		$step = JobRepository::get_step( $job_id, $component );
		$step_id = $step ? (int) $step['id'] : JobRepository::create_step( $job_id, $component );
		$offset  = $step && $args['resume'] ? (int) $step['completed_chunks'] : 0;

		if ( $args['create_restore_point'] && 0 === $offset ) {
			RestorePointManager::create( $migration_id, $component );
		}

		JobRepository::update_job_status( $job_id, JobRepository::STATUS_RUNNING );
		JobRepository::update_step( $step_id, array( 'status' => JobRepository::STATUS_RUNNING ) );

		$result = array( 'success' => false );

		try {
			if ( 'database' === $component ) {
				$result = self::import_database( $path, $step_id, $args['force'], $offset, $args['single_tick'] );
			} elseif ( 'config' === $component ) {
				$result = self::import_config( $path, $step_id, $offset, $args['single_tick'] );
			} else {
				$result = self::import_files( $path, $component, $step_id, $args['confirm'], $offset, $args['single_tick'] );
			}
		} catch ( \Exception $e ) {
			$result = array( 'success' => false, 'error' => $e->getMessage() );
		}

		$status = ( ! empty( $result['success'] ) && empty( $result['done'] ) && ! empty( $args['single_tick'] ) )
			? JobRepository::STATUS_RUNNING
			: ( $result['success'] ? JobRepository::STATUS_COMPLETED : JobRepository::STATUS_FAILED );
		JobRepository::update_step( $step_id, array( 'status' => $status ) );
		if ( ! empty( $args['single_tick'] ) && ! empty( $result['success'] ) && empty( $result['done'] ) ) {
			JobRepository::update_job_status( $job_id, JobRepository::STATUS_RUNNING );
		} else {
			JobRepository::update_job_status( $job_id, $status );
		}
		JobRepository::release_lock( $migration_id );

		AuditLogger::log( 'import_component', "Import {$component}", array(
			'migration_id' => $migration_id,
			'job_id'       => $job_id,
			'component'    => $component,
			'success'      => $result['success'],
			'error'        => isset( $result['error'] ) ? $result['error'] : '',
		), $result['success'] ? 'success' : 'error' );

		if ( $result['success'] ) {
			\TheExporter\Transfer\TransferStatus::mark_imported( $migration_id, $component );
		}

		$result['job_id'] = $job_id;
		return $result;
	}

	/**
	 * Import all components in recommended order (after full validation).
	 *
	 * @param string $migration_id Migration ID.
	 * @param array  $args         Options.
	 * @return array
	 */
	public static function import_all( $migration_id, array $args = array() ) {
		$defaults = array(
			'dry_run' => false,
			'confirm' => false,
			'force'   => false,
		);
		$args = wp_parse_args( $args, $defaults );

		$validation = self::validate( $migration_id, true );
		if ( ! $validation['passed'] && ! $args['force'] ) {
			return array(
				'success'    => false,
				'error'      => 'Validation failed',
				'validation' => $validation,
			);
		}

		if ( $args['dry_run'] ) {
			return array( 'success' => true, 'dry_run' => true );
		}

		if ( ! $args['confirm'] ) {
			return array( 'success' => false, 'error' => 'Import requires explicit confirmation' );
		}

		$results       = array();
		$first_restore = true;

		foreach ( PackageIndex::component_order() as $component ) {
			$upload_status = \TheExporter\Transfer\FileUploader::component_status( $migration_id, $component );
			if ( ! empty( $upload_status['skipped'] ) ) {
				$results[ $component ] = array(
					'success'   => true,
					'skipped'   => true,
					'component' => $component,
				);
				\TheExporter\Transfer\TransferStatus::mark_imported( $migration_id, $component );
				continue;
			}

			$result = self::import_component(
				$migration_id,
				$component,
				array(
					'confirm'              => true,
					'skip_validation'      => true,
					'force'                => $args['force'],
					'create_restore_point' => $first_restore,
				)
			);
			$first_restore         = false;
			$results[ $component ] = $result;

			if ( empty( $result['success'] ) ) {
				return array(
					'success'   => false,
					'error'     => isset( $result['error'] ) ? $result['error'] : 'Import failed',
					'component' => $component,
					'results'   => $results,
				);
			}
		}

		return array(
			'success' => true,
			'results' => $results,
		);
	}

	/**
	 * Queue import of all components via scheduler.
	 *
	 * @param string $migration_id Migration ID.
	 * @param array  $args         Options.
	 * @return array
	 */
	public static function queue_import( $migration_id, array $args = array() ) {
		$defaults = array(
			'confirm' => false,
			'force'   => false,
		);
		$args = wp_parse_args( $args, $defaults );

		$validation = self::validate( $migration_id, true );
		if ( ! $validation['passed'] && ! $args['force'] ) {
			return array(
				'success'    => false,
				'error'      => 'Validation failed',
				'validation' => $validation,
			);
		}

		if ( ! $args['confirm'] ) {
			return array( 'success' => false, 'error' => 'Import requires explicit confirmation' );
		}

		$components = array();
		foreach ( PackageIndex::component_order() as $component ) {
			$upload_status = \TheExporter\Transfer\FileUploader::component_status( $migration_id, $component );
			if ( empty( $upload_status['skipped'] ) ) {
				$components[] = $component;
			}
		}

		if ( empty( $components ) ) {
			return array( 'success' => true, 'message' => 'Nothing to import', 'queued' => array() );
		}

		$job    = JobRepository::get_job_by_migration( $migration_id, 'import' );
		$job_id = $job ? (int) $job['id'] : JobRepository::create_job( $migration_id, 'import', array( 'queued' => $components ) );

		if ( ! JobRepository::acquire_lock( $migration_id, $job_id ) ) {
			return array( 'success' => false, 'error' => 'Migration locked', 'lock' => JobRepository::get_lock_info( $migration_id ) );
		}

		JobRepository::update_job_status( $job_id, JobRepository::STATUS_RUNNING );
		$delay = 0;
		foreach ( $components as $component ) {
			Scheduler::schedule( $job_id, $component, $delay );
			$delay += 2;
		}

		JobRepository::release_lock( $migration_id );

		return array(
			'success'   => true,
			'job_id'    => $job_id,
			'queued'    => $components,
			'scheduled' => function_exists( 'as_schedule_single_action' ) || wp_next_scheduled( Scheduler::HOOK_PROCESS ),
		);
	}

	/**
	 * Import database from chunks with resume offset.
	 *
	 * @param string $path    Migration path.
	 * @param int    $step_id Step ID.
	 * @param bool   $force   Force row mismatch.
	 * @param int    $offset  Completed chunks.
	 * @return array
	 */
	private static function import_database( $path, $step_id, $force = false, $offset = 0, $single_tick = false ) {
		$inventory = self::load_inventory( $path, 'database' );
		if ( ! $inventory ) {
			return array( 'success' => false, 'error' => 'Database inventory missing' );
		}

		$chunks   = isset( $inventory['chunks'] ) ? $inventory['chunks'] : array();
		$imported = $offset;

		JobRepository::update_step( $step_id, array( 'total_chunks' => count( $chunks ) ) );

		$end = $single_tick ? min( $offset + 1, count( $chunks ) ) : count( $chunks );
		for ( $i = $offset; $i < $end; $i++ ) {
			$chunk      = $chunks[ $i ];
			$chunk_path = $path . '/' . self::normalize_chunk_path( 'database', $chunk['path'] );

			JobRepository::touch_lock( self::migration_id_from_path( $path ) );

			if ( ! empty( $chunk['checksum'] ) && ! ChecksumService::verify_file( $chunk_path, $chunk['checksum'] ) ) {
				return array( 'success' => false, 'error' => 'Checksum failed: ' . $chunk['path'], 'resume_at' => $imported );
			}

			if ( isset( $chunk['type'] ) && in_array( $chunk['type'], array( 'schema', 'full' ), true ) ) {
				$r = Importer::import_schema( $chunk_path );
			} else {
				$table = isset( $chunk['table'] ) ? $chunk['table'] : '';
				$r     = Importer::import_table_chunk( $chunk_path, $table, 0, false, $force );
			}

			if ( ! $r['success'] ) {
				return array_merge( $r, array( 'resume_at' => $imported ) );
			}

			$imported++;
			JobRepository::update_step( $step_id, array( 'completed_chunks' => $imported ) );
		}

		$total = count( $chunks );
		if ( $imported < $total ) {
			return array(
				'success'         => true,
				'done'            => false,
				'imported_chunks' => $imported,
				'resume_at'       => $imported,
			);
		}

		return array( 'success' => true, 'done' => true, 'imported_chunks' => $imported );
	}

	/**
	 * Import config (plain JSON, not tar segments).
	 *
	 * @param string $path    Migration path.
	 * @param int    $step_id Step ID.
	 * @param int    $offset  Resume offset.
	 * @param bool   $single_tick Process one chunk only.
	 * @return array
	 */
	private static function import_config( $path, $step_id, $offset = 0, $single_tick = false ) {
		$inventory = self::load_inventory( $path, 'config' );
		if ( ! $inventory ) {
			return array( 'success' => false, 'error' => 'Config inventory missing' );
		}

		$chunks   = isset( $inventory['chunks'] ) ? $inventory['chunks'] : array();
		$imported = $offset;

		JobRepository::update_step( $step_id, array( 'total_chunks' => count( $chunks ) ) );

		$end = $single_tick ? min( $offset + 1, count( $chunks ) ) : count( $chunks );
		for ( $i = $offset; $i < $end; $i++ ) {
			$chunk = $chunks[ $i ];
			$rel   = self::normalize_chunk_path( 'config', $chunk['path'] );
			$full  = trailingslashit( $path ) . $rel;

			if ( ! empty( $chunk['checksum'] ) && ! ChecksumService::verify_file( $full, $chunk['checksum'] ) ) {
				return array( 'success' => false, 'error' => 'Checksum failed: ' . $rel );
			}

			if ( ! file_exists( $full ) ) {
				return array( 'success' => false, 'error' => 'Config file missing: ' . $rel );
			}

			$data = json_decode( file_get_contents( $full ), true );
			if ( ! is_array( $data ) ) {
				return array( 'success' => false, 'error' => 'Invalid config JSON: ' . $rel );
			}

			$imported++;
			JobRepository::update_step( $step_id, array( 'completed_chunks' => $imported ) );
		}

		$total = count( $chunks );
		if ( $imported < $total ) {
			return array(
				'success'         => true,
				'done'            => false,
				'imported_chunks' => $imported,
				'resume_at'       => $imported,
			);
		}

		return array( 'success' => true, 'done' => true, 'imported_chunks' => $imported, 'note' => 'Config stored in package; apply settings manually if needed.' );
	}

	/**
	 * Import one segment/chunk (REST + scheduler tick).
	 *
	 * @param string $migration_id Migration ID.
	 * @param string $component    Component.
	 * @param array  $args         Options.
	 * @return array
	 */
	public static function import_segment( $migration_id, $component, array $args = array() ) {
		$args['single_tick'] = true;
		$args['confirm']     = true;
		$args['resume']      = true;
		return self::import_component( $migration_id, $component, $args );
	}

	/**
	 * Import file component from segments or plain files.
	 *
	 * @param string $path        Migration path.
	 * @param string $component   Component.
	 * @param int    $step_id     Step ID.
	 * @param bool   $confirm     Confirmed.
	 * @param int    $offset      Resume offset.
	 * @param bool   $single_tick Process one chunk only.
	 * @return array
	 */
	private static function import_files( $path, $component, $step_id, $confirm, $offset = 0, $single_tick = false ) {
		$inventory = self::load_inventory( $path, $component );
		if ( ! $inventory ) {
			return array( 'success' => false, 'error' => 'Inventory missing' );
		}

		$chunks  = isset( $inventory['chunks'] ) ? $inventory['chunks'] : array();
		$staging = Settings::get( 'import_base_path' ) . '/staging-' . sanitize_file_name( $component );
		$dest_map = array(
			'uploads'          => WP_CONTENT_DIR . '/uploads',
			'themes'           => WP_CONTENT_DIR . '/themes',
			'plugins'          => WP_CONTENT_DIR . '/plugins',
			'mu-plugins'       => WP_CONTENT_DIR . '/mu-plugins',
			'wp-content-other' => WP_CONTENT_DIR,
		);
		$dest = isset( $dest_map[ $component ] ) ? $dest_map[ $component ] : WP_CONTENT_DIR;

		JobRepository::update_step( $step_id, array( 'total_chunks' => count( $chunks ) ) );

		$imported = $offset;
		$total    = count( $chunks );
		$end      = $single_tick ? min( $offset + 1, $total ) : $total;

		for ( $i = $offset; $i < $end; $i++ ) {
			$chunk = $chunks[ $i ];
			$rel   = self::normalize_chunk_path( $component, $chunk['path'] );
			$full  = trailingslashit( $path ) . $rel;

			if ( ! empty( $chunk['checksum'] ) && ! ChecksumService::verify_file( $full, $chunk['checksum'] ) ) {
				return array( 'success' => false, 'error' => 'Checksum failed: ' . $chunk['path'], 'resume_at' => $imported );
			}

			if ( self::is_segment_archive( $full ) ) {
				$extract = SegmentExtractor::extract( $full, $staging, $inventory );
				if ( ! $extract['success'] ) {
					return array_merge( $extract, array( 'resume_at' => $imported ) );
				}
			} elseif ( ! file_exists( $full ) ) {
				return array( 'success' => false, 'error' => 'File missing: ' . $rel, 'resume_at' => $imported );
			}

			$imported++;
			JobRepository::update_step( $step_id, array( 'completed_chunks' => $imported ) );
		}

		if ( $imported < $total ) {
			return array(
				'success'         => true,
				'done'            => false,
				'imported_chunks' => $imported,
				'resume_at'       => $imported,
			);
		}

		if ( $total > 0 && ! is_dir( $staging ) ) {
			return array(
				'success'   => false,
				'error'     => 'Staging directory missing after extraction',
				'resume_at' => $imported,
			);
		}

		if ( is_dir( $staging ) ) {
			$promote = SegmentExtractor::promote( $staging, $dest, $confirm );
			if ( ! empty( $promote['success'] ) ) {
				$promote['done'] = true;
			}
			return $promote;
		}

		return array( 'success' => true, 'done' => true, 'imported_chunks' => $imported );
	}

	/**
	 * Resume interrupted import (scheduler).
	 *
	 * @param int    $job_id    Job ID.
	 * @param string $component Component.
	 */
	public static function process_next_chunk( $job_id, $component ) {
		$job = JobRepository::get_job( $job_id );
		if ( ! $job ) {
			return;
		}

		$step = JobRepository::get_step( $job_id, $component );
		if ( $step && JobRepository::STATUS_COMPLETED === $step['status'] ) {
			return;
		}

		$result = self::import_component( $job['migration_id'], $component, array(
			'confirm'              => true,
			'skip_validation'      => true,
			'create_restore_point' => false,
			'resume'               => true,
			'job_id'               => $job_id,
			'single_tick'          => true,
		) );

		if ( ! empty( $result['success'] ) && empty( $result['done'] ) ) {
			Scheduler::schedule( $job_id, $component, 1 );
		}
	}

	/**
	 * Load inventory from disk or synthesize from manifest catalog.
	 *
	 * @param string $path      Migration path.
	 * @param string $component Component.
	 * @return array|null
	 */
	private static function load_inventory( $path, $component ) {
		$inventory_file = trailingslashit( $path ) . $component . '/inventory.json';
		if ( file_exists( $inventory_file ) ) {
			$data = json_decode( file_get_contents( $inventory_file ), true );
			return is_array( $data ) ? $data : null;
		}

		$manifest = \TheExporter\Manifest\ManifestBuilder::load( $path );
		if ( is_array( $manifest ) && ! empty( $manifest['transfer_catalog'][ $component ] ) ) {
			return $manifest['transfer_catalog'][ $component ];
		}
		return null;
	}

	/**
	 * Normalize chunk relative path.
	 *
	 * @param string $component Component.
	 * @param string $path      Path.
	 * @return string
	 */
	private static function normalize_chunk_path( $component, $path ) {
		$path = str_replace( '\\', '/', $path );
		if ( strpos( $path, $component . '/' ) === 0 ) {
			return $path;
		}
		return $component . '/' . ltrim( $path, '/' );
	}

	/**
	 * Whether file is a tar.gz segment.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	private static function is_segment_archive( $path ) {
		return (bool) preg_match( '/\.tar(\.gz)?$/i', $path );
	}

	/**
	 * Extract migration ID from folder path.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function migration_id_from_path( $path ) {
		if ( preg_match( '/migration-([a-f0-9\-]+)$/i', $path, $m ) ) {
			return $m[1];
		}
		return '';
	}
}
