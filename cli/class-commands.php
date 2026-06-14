<?php
/**
 * WP-CLI commands for The Exporter.
 *
 * @package TheExporter
 */

namespace TheExporter\CLI;

use TheExporter\Database\Dumper;
use TheExporter\Database\Importer;
use TheExporter\Jobs\ExportOrchestrator;
use TheExporter\Jobs\ImportOrchestrator;
use TheExporter\Jobs\JobRepository;
use TheExporter\Restore\RestorePointManager;
use TheExporter\Runtime;
use TheExporter\Settings;
use TheExporter\Transfer\FileUploader;
use TheExporter\Transfer\PackageIndex;
use TheExporter\Transfer\RemotePusher;
use TheExporter\Transfer\TransferWorker;

defined( 'ABSPATH' ) || exit;

/**
 * Class Commands
 */
class Commands {

	/**
	 * Export commands.
	 *
	 * ## SUBCOMMANDS
	 *
	 * init, component, finalize, status, run, workers
	 */
	public function export( $args, $assoc_args ) {
		$sub = isset( $args[0] ) ? $args[0] : 'status';

		switch ( $sub ) {
			case 'init':
				$id = isset( $assoc_args['migration-id'] ) ? $assoc_args['migration-id'] : '';
				$r  = ExportOrchestrator::init( $id );
				\WP_CLI::success( 'Migration initialized: ' . $r['migration_id'] );
				\WP_CLI::line( 'Path: ' . $r['path'] );
				break;

			case 'run':
				$id = isset( $assoc_args['migration-id'] ) ? $assoc_args['migration-id'] : Settings::get( 'active_migration_id' );
				if ( ! $id ) {
					\WP_CLI::error( 'Usage: wp the-exporter export run --migration-id=<id>' );
				}
				$r = ExportOrchestrator::export_run( $id );
				$r['success'] ? \WP_CLI::success( 'Export run complete.' ) : \WP_CLI::error( $r['error'] );
				break;

			case 'workers':
				$id = isset( $assoc_args['migration-id'] ) ? $assoc_args['migration-id'] : Settings::get( 'active_migration_id' );
				$component = isset( $args[1] ) ? $args[1] : '';
				$concurrency = isset( $assoc_args['concurrency'] ) ? (int) $assoc_args['concurrency'] : 3;
				if ( ! $id || ! $component ) {
					\WP_CLI::error( 'Usage: wp the-exporter export workers <component> --migration-id=<id> [--concurrency=3]' );
				}
				$scan = ExportOrchestrator::export_scan( $id, $component );
				if ( empty( $scan['success'] ) ) {
					\WP_CLI::error( $scan['error'] );
				}
				$done = false;
				while ( ! $done ) {
					$r = ExportOrchestrator::claim_segment( $id, $component, 'wp-cli' );
					if ( empty( $r['success'] ) ) {
						\WP_CLI::error( $r['error'] );
					}
					$done = ! empty( $r['done'] );
				}
				\WP_CLI::success( "Workers finished {$component}" );
				break;

			case 'component':
				$component = isset( $args[1] ) ? $args[1] : '';
				$id        = isset( $assoc_args['migration-id'] ) ? $assoc_args['migration-id'] : Settings::get( 'active_migration_id' );
				if ( ! $component || ! $id ) {
					\WP_CLI::error( 'Usage: wp the-exporter export component <name> --migration-id=<id>' );
				}
				$r = ExportOrchestrator::export_component( $id, $component );
				$r['success'] ? \WP_CLI::success( "Exported {$component}" ) : \WP_CLI::error( $r['error'] );
				break;

			case 'finalize':
				$id = isset( $assoc_args['migration-id'] ) ? $assoc_args['migration-id'] : Settings::get( 'active_migration_id' );
				$r  = ExportOrchestrator::finalize( $id );
				$r['success'] ? \WP_CLI::success( 'Export finalized.' ) : \WP_CLI::error( $r['error'] );
				break;

			case 'status':
			default:
				$id  = isset( $assoc_args['migration-id'] ) ? $assoc_args['migration-id'] : Settings::get( 'active_migration_id' );
				$job = JobRepository::get_job_by_migration( $id, 'export' );
				if ( $job ) {
					\WP_CLI\Utils\format_items( 'table', JobRepository::get_steps( $job['id'] ), array( 'component', 'status', 'completed_chunks', 'total_chunks', 'total_bytes' ) );
				} else {
					\WP_CLI::warning( 'No export job found.' );
				}
				break;
		}
	}

	/**
	 * Import commands.
	 */
	public function import( $args, $assoc_args ) {
		$sub = isset( $args[0] ) ? $args[0] : 'validate';
		$id  = isset( $assoc_args['migration-id'] ) ? $assoc_args['migration-id'] : '';

		switch ( $sub ) {
			case 'status':
				$id = isset( $args[1] ) ? $args[1] : $id;
				if ( ! $id ) {
					\WP_CLI::error( 'Usage: wp the-exporter import status <migration-id>' );
				}
				$status   = FileUploader::migration_upload_status( $id );
				$max      = (int) ( $status['browser_transfer_max_bytes'] ?? Settings::get( 'browser_transfer_max_bytes', 67108864 ) );
				$pending  = array();
				foreach ( (array) ( $status['files'] ?? array() ) as $file ) {
					if ( ! empty( $file['uploaded'] ) ) {
						continue;
					}
					$pending[] = array(
						'file'      => $file['download_name'] ?? '',
						'size'      => isset( $file['size'] ) ? (int) $file['size'] : 0,
						'reason'    => $file['block_reason'] ?? 'missing',
						'sftp_only' => empty( $file['browser_uploadable'] ),
						'path'      => $file['path'] ?? '',
					);
				}
				\WP_CLI::line( 'Migration: ' . $id );
				\WP_CLI::line( 'Uploaded: ' . (int) ( $status['uploaded'] ?? 0 ) . ' / ' . (int) ( $status['expected'] ?? 0 ) );
				\WP_CLI::line( 'Ready to validate: ' . ( ! empty( $status['ready_to_validate'] ) ? 'yes' : 'no' ) );
				\WP_CLI::line( 'Browser transfer max: ' . size_format( $max ) );
				if ( ! empty( $status['needs_manifest'] ) ) {
					\WP_CLI::warning( 'manifest.json not uploaded yet.' );
				}
				if ( $pending ) {
					\WP_CLI\Utils\format_items( 'table', $pending, array( 'file', 'size', 'reason', 'sftp_only', 'path' ) );
				} else {
					\WP_CLI::success( 'All package files present on import server.' );
				}
				break;

			case 'validate':
				$r = ImportOrchestrator::validate( $id, true );
				\WP_CLI::line( wp_json_encode( $r, JSON_PRETTY_PRINT ) );
				$r['passed'] ? \WP_CLI::success( 'Validation passed.' ) : \WP_CLI::error( 'Validation failed.' );
				break;

			case 'database':
			case 'uploads':
			case 'themes':
			case 'plugins':
			case 'mu-plugins':
			case 'wp-content-other':
			case 'config':
				$dry     = isset( $assoc_args['dry-run'] );
				$confirm = isset( $assoc_args['confirm'] );
				$force   = isset( $assoc_args['force'] );
				$r       = ImportOrchestrator::import_component( $id, $sub, array(
					'dry_run' => $dry,
					'confirm' => $confirm,
					'force'   => $force,
				) );
				$r['success'] ? \WP_CLI::success( "Import {$sub} complete." ) : \WP_CLI::error( isset( $r['error'] ) ? $r['error'] : 'Import failed' );
				break;

			default:
				\WP_CLI::error( 'Unknown import subcommand.' );
		}
	}

	/**
	 * Site-to-site transfer commands.
	 *
	 * ## SUBCOMMANDS
	 *
	 * drive, worker, status
	 */
	public function transfer( $args, $assoc_args ) {
		$sub = isset( $args[0] ) ? $args[0] : 'worker';
		$id  = isset( $assoc_args['migration-id'] ) ? $assoc_args['migration-id'] : Settings::get( 'active_migration_id' );

		switch ( $sub ) {
			case 'worker':
				if ( ! $id ) {
					\WP_CLI::error( 'Usage: wp the-exporter transfer worker --migration-id=<id>' );
				}
				Runtime::prepare_job();
				$max_seconds = isset( $assoc_args['max-seconds'] ) ? (int) $assoc_args['max-seconds'] : 0;
				\WP_CLI::line( 'Running transfer worker for migration: ' . $id );
				$result = TransferWorker::run_daemon( $id, $max_seconds );
				\WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
				if ( ! empty( $result['done'] ) ) {
					\WP_CLI::success( 'Transfer complete.' );
				} elseif ( ! empty( $result['success'] ) || ! empty( $result['retrying'] ) ) {
					\WP_CLI::warning( 'Transfer in progress — run again to continue.' );
				} else {
					\WP_CLI::error( $result['error'] ?? 'Transfer failed.' );
				}
				break;

			case 'drive':
				if ( ! $id ) {
					\WP_CLI::error( 'Usage: wp the-exporter transfer drive --migration-id=<id>' );
				}
				Runtime::prepare_job();
				$max_seconds = isset( $assoc_args['max-seconds'] ) ? (int) $assoc_args['max-seconds'] : 0;
				\WP_CLI::line( 'Driving push for migration: ' . $id );
				$result = RemotePusher::drive_push_loop( $id, $max_seconds );
				\WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
				if ( ! empty( $result['done'] ) ) {
					\WP_CLI::success( 'Transfer complete.' );
				} elseif ( ! empty( $result['success'] ) || ! empty( $result['retrying'] ) ) {
					\WP_CLI::warning( 'Transfer in progress — run again to continue.' );
				} else {
					\WP_CLI::error( $result['error'] ?? 'Transfer failed.' );
				}
				break;

			case 'status':
				if ( ! $id ) {
					\WP_CLI::error( 'Usage: wp the-exporter transfer status --migration-id=<id>' );
				}
				$status = RemotePusher::push_status( $id );
				\WP_CLI::line( wp_json_encode( $status, JSON_PRETTY_PRINT ) );
				break;

			default:
				\WP_CLI::error( 'Unknown transfer subcommand. Use: worker, drive, status' );
		}
	}

	/**
	 * Restore commands.
	 */
	public function restore( $args, $assoc_args ) {
		$sub = isset( $args[0] ) ? $args[0] : 'list';

		if ( 'list' === $sub ) {
			$points = RestorePointManager::list_points();
			\WP_CLI\Utils\format_items( 'table', $points, array( 'id', 'path' ) );
		} else {
			\WP_CLI::warning( 'Restore run requires manual confirmation via admin UI in v1.' );
		}
	}

	/**
	 * Environment doctor checks.
	 */
	public function doctor( $args, $assoc_args ) {
		$profile = \TheExporter\EnvironmentProfile::detect( true );
		$migration_id = isset( $assoc_args['migration-id'] ) ? $assoc_args['migration-id'] : Settings::get( 'active_migration_id' );
		\WP_CLI::line( 'mysqldump: ' . ( Dumper::has_mysqldump() ? 'yes' : 'no' ) );
		\WP_CLI::line( 'mydumper: ' . ( Dumper::has_mydumper() ? 'yes' : 'no' ) );
		\WP_CLI::line( 'myloader: ' . ( \TheExporter\Runtime::command_exists( 'myloader' ) ? 'yes' : 'no' ) );
		\WP_CLI::line( 'mysql CLI: ' . ( Importer::has_mysql_cli() ? 'yes' : 'no' ) );
		\WP_CLI::line( 'pack method: ' . $profile['pack_method'] );
		\WP_CLI::line( 'compression: ' . \TheExporter\EnvironmentProfile::effective_compression() );
		\WP_CLI::line( 'database engine: ' . \TheExporter\EnvironmentProfile::effective_database_engine() );
		\WP_CLI::line( 'transfer mode: ' . Settings::transfer_mode() );
		\WP_CLI::line( 'segment size: ' . size_format( Settings::effective_segment_size() ) );
		\WP_CLI::line( 'workers: ' . Settings::export_worker_concurrency() );
		\WP_CLI::line( 'WP-CLI: yes' );
		\WP_CLI::line( 'PHP: ' . PHP_VERSION );
		\WP_CLI::line( 'WordPress: ' . get_bloginfo( 'version' ) );
		if ( $migration_id ) {
			$summary = PackageIndex::transfer_summary( $migration_id, 'export' );
			if ( ! empty( $summary['total_bytes'] ) ) {
				$est = Settings::estimate_import_disk_bytes( (int) $summary['total_bytes'] );
				\WP_CLI::line( 'migration: ' . $migration_id );
				\WP_CLI::line( 'package size: ' . size_format( (int) $summary['total_bytes'] ) );
				\WP_CLI::line( 'files: ' . (int) ( $summary['file_count'] ?? 0 ) );
				\WP_CLI::line( 'peak disk (import est.): ' . size_format( (int) $est['peak_bytes'] ) );
			}
		}
	}
}
