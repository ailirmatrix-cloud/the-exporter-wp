<?php
/**
 * Aggregated migration progress for admin wizard UI.
 *
 * @package TheExporter
 */

namespace TheExporter\Jobs;

use TheExporter\Settings;
use TheExporter\Transfer\FileUploader;
use TheExporter\Transfer\PackageIndex;
use TheExporter\Transfer\TransferProgress;

defined( 'ABSPATH' ) || exit;

/**
 * Class ProgressReporter
 */
class ProgressReporter {

	/**
	 * Build unified progress snapshot.
	 *
	 * @param string $migration_id Migration UUID.
	 * @param string $context      export|import|auto.
	 * @return array
	 */
	public static function snapshot( $migration_id, $context = 'auto' ) {
		$migration_id = sanitize_text_field( $migration_id );
		$export_job   = JobRepository::get_job_by_migration( $migration_id, 'export' );
		$import_job   = JobRepository::get_job_by_migration( $migration_id, 'import' );
		$upload       = FileUploader::migration_upload_status( $migration_id );

		$phase = self::detect_phase( $context, $export_job, $import_job, $upload );

		$components = array();
		$job        = null;

		if ( in_array( $phase, array( 'export', 'finalize' ), true ) && $export_job ) {
			$job        = $export_job;
			$components = self::steps_to_components( JobRepository::get_steps( $export_job['id'] ), ExportOrchestrator::components() );
		} elseif ( in_array( $phase, array( 'import', 'upload' ), true ) ) {
			if ( 'upload' === $phase || ! $import_job ) {
				$components = self::upload_to_components( $upload );
			} else {
				$job        = $import_job;
				$components = self::steps_to_components( JobRepository::get_steps( $import_job['id'] ), PackageIndex::component_order() );
			}
		}

		$overall = self::overall_percent( $components, $phase, $upload );

		return array(
			'migration_id'    => $migration_id,
			'phase'           => $phase,
			'overall_percent' => $overall,
			'job_id'          => $job ? (int) $job['id'] : 0,
			'job_status'      => $job ? $job['status'] : '',
			'job_type'        => $job ? $job['type'] : '',
			'components'      => $components,
			'current_action'  => self::current_action( $components, $phase, $upload ),
			'heartbeat_at'    => self::latest_heartbeat( $export_job, $import_job ),
			'upload'          => array(
				'expected'  => (int) ( $upload['expected'] ?? 0 ),
				'uploaded'  => (int) ( $upload['uploaded'] ?? 0 ),
				'ready'     => ! empty( $upload['ready_to_validate'] ),
			),
			'warnings'        => self::warnings( $migration_id ),
		);
	}

	/**
	 * Detect workflow phase.
	 *
	 * @param string     $context    Requested context.
	 * @param array|null $export_job Export job.
	 * @param array|null $import_job Import job.
	 * @param array      $upload     Upload status.
	 * @return string
	 */
	private static function detect_phase( $context, $export_job, $import_job, $upload ) {
		if ( 'export' === $context ) {
			return $export_job && JobRepository::STATUS_COMPLETED !== $export_job['status'] ? 'export' : 'finalize';
		}
		if ( 'import' === $context ) {
			if ( $import_job && in_array( $import_job['status'], array( JobRepository::STATUS_RUNNING, JobRepository::STATUS_PENDING ), true ) ) {
				return 'import';
			}
			if ( ! empty( $upload['expected'] ) && (int) $upload['uploaded'] < (int) $upload['expected'] ) {
				return 'upload';
			}
			return 'import';
		}

		if ( $export_job && in_array( $export_job['status'], array( JobRepository::STATUS_RUNNING, JobRepository::STATUS_PENDING ), true ) ) {
			return 'export';
		}
		if ( $import_job && in_array( $import_job['status'], array( JobRepository::STATUS_RUNNING, JobRepository::STATUS_PENDING ), true ) ) {
			return 'import';
		}
		if ( ! empty( $upload['expected'] ) && (int) $upload['uploaded'] < (int) $upload['expected'] ) {
			return 'upload';
		}
		if ( ! empty( $upload['ready_to_validate'] ) ) {
			return 'validate';
		}
		return 'idle';
	}

	/**
	 * Map job steps to component progress rows.
	 *
	 * @param array $steps     Step rows.
	 * @param array $order     Component order.
	 * @return array
	 */
	private static function steps_to_components( array $steps, array $order ) {
		$by_name = array();
		foreach ( $steps as $step ) {
			$meta = array();
			if ( ! empty( $step['meta'] ) ) {
				$meta = is_array( $step['meta'] ) ? $step['meta'] : json_decode( $step['meta'], true );
				if ( ! is_array( $meta ) ) {
					$meta = array();
				}
			}
			$completed = (int) $step['completed_chunks'];
			$by_name[ $step['component'] ] = array(
				'name'              => $step['component'],
				'status'            => $step['status'],
				'chunks_done'       => $completed,
				'chunks_total'      => (int) $step['total_chunks'],
				'bytes_done'        => (int) $step['total_bytes'],
				'bytes_total'       => isset( $meta['bytes_total'] ) ? (int) $meta['bytes_total'] : 0,
				'files_scanned'     => isset( $meta['files_scanned'] ) ? (int) $meta['files_scanned'] : 0,
				'files_total'       => isset( $meta['files_total'] ) ? (int) $meta['files_total'] : 0,
				'files_packed'      => isset( $meta['files_packed'] ) ? (int) $meta['files_packed'] : 0,
				'files_queued'      => isset( $meta['files_queued'] ) ? (int) $meta['files_queued'] : 0,
				'phase_detail'      => isset( $meta['phase'] ) ? $meta['phase'] : '',
				'sub_phase'         => isset( $meta['sub_phase'] ) ? $meta['sub_phase'] : '',
				'hash_done'         => isset( $meta['hash_done'] ) ? (int) $meta['hash_done'] : 0,
				'hash_total'        => isset( $meta['hash_total'] ) ? (int) $meta['hash_total'] : 0,
				'updated_at'        => $step['updated_at'],
			);
		}

		$out = array();
		foreach ( $order as $name ) {
			if ( isset( $by_name[ $name ] ) ) {
				$out[] = $by_name[ $name ];
			} else {
				$out[] = array(
					'name'         => $name,
					'status'       => JobRepository::STATUS_PENDING,
					'chunks_done'  => 0,
					'chunks_total' => 0,
					'bytes_done'   => 0,
					'bytes_total'  => 0,
				);
			}
		}
		return $out;
	}

	/**
	 * Upload status as pseudo-components.
	 *
	 * @param array $upload Upload status.
	 * @return array
	 */
	private static function upload_to_components( $upload ) {
		return TransferProgress::components_from_upload( is_array( $upload ) ? $upload : array() );
	}

	/**
	 * Overall percent across components.
	 *
	 * @param array  $components Component rows.
	 * @param string $phase      Phase.
	 * @param array  $upload     Upload status.
	 * @return int
	 */
	private static function overall_percent( array $components, $phase, $upload ) {
		if ( 'upload' === $phase ) {
			$exp = (int) ( $upload['expected'] ?? 0 );
			$up  = (int) ( $upload['uploaded'] ?? 0 );
			return $exp > 0 ? (int) floor( ( $up / $exp ) * 100 ) : 0;
		}
		if ( empty( $components ) ) {
			return 0;
		}
		$sum = 0;
		$n   = 0;
		foreach ( $components as $c ) {
			if ( JobRepository::STATUS_COMPLETED === $c['status'] ) {
				$sum += 100;
			} elseif ( JobRepository::STATUS_RUNNING === $c['status'] ) {
				$total = max( 1, (int) $c['chunks_total'] );
				if ( $total > 0 && in_array( $c['phase_detail'], array( 'segmenting', 'packing' ), true ) ) {
					$chunk_pct = (int) floor( ( (int) $c['chunks_done'] / $total ) * 70 );
					$file_pct  = 0;
					if ( ! empty( $c['files_total'] ) ) {
						$file_pct = (int) floor( ( (int) $c['files_packed'] / max( 1, (int) $c['files_total'] ) ) * 30 );
					}
					$sum += min( 99, $chunk_pct + $file_pct );
				} elseif ( ! empty( $c['phase_detail'] ) && 'scanning' === $c['phase_detail'] && ! empty( $c['files_scanned'] ) ) {
					$sum += min( 40, (int) floor( ( $c['files_scanned'] / max( $c['files_scanned'], 1000 ) ) * 40 ) );
				} else {
					$sum += (int) floor( ( (int) $c['chunks_done'] / $total ) * 100 );
				}
			}
			$n++;
		}
		return $n > 0 ? (int) floor( $sum / $n ) : 0;
	}

	/**
	 * Human-readable current action.
	 *
	 * @param array  $components Components.
	 * @param string $phase      Phase.
	 * @param array  $upload     Upload.
	 * @return string
	 */
	private static function current_action( array $components, $phase, $upload ) {
		if ( 'upload' === $phase ) {
			return sprintf(
				/* translators: 1: uploaded count, 2: expected count */
				__( 'Uploading package files (%1$d / %2$d)', 'the-exporter' ),
				(int) ( $upload['uploaded'] ?? 0 ),
				(int) ( $upload['expected'] ?? 0 )
			);
		}
		foreach ( $components as $c ) {
			if ( JobRepository::STATUS_RUNNING !== $c['status'] ) {
				continue;
			}
			if ( ! empty( $c['phase_detail'] ) && 'scanning' === $c['phase_detail'] ) {
				return sprintf(
					/* translators: 1: component name, 2: file count */
					__( 'Scanning %1$s (%2$d files)', 'the-exporter' ),
					$c['name'],
					(int) $c['files_scanned']
				);
			}
			if ( in_array( $c['phase_detail'], array( 'segmenting', 'packing' ), true ) && (int) $c['chunks_total'] > 0 ) {
				$line = sprintf(
					/* translators: 1: component, 2: segment done, 3: segment total */
					__( 'Packing %1$s — segment %2$d / %3$d', 'the-exporter' ),
					$c['name'],
					min( (int) $c['chunks_done'] + 1, (int) $c['chunks_total'] ),
					(int) $c['chunks_total']
				);
				if ( ! empty( $c['sub_phase'] ) && 'hashing' === $c['sub_phase'] && ! empty( $c['hash_total'] ) ) {
					return sprintf(
						/* translators: 1: component, 2: hash done, 3: hash total, 4: segment number */
						__( 'Hashing %1$s segment (%2$d / %3$d files)…', 'the-exporter' ),
						$c['name'],
						(int) $c['hash_done'],
						(int) $c['hash_total']
					);
				}
				if ( ! empty( $c['sub_phase'] ) && 'compressing' === $c['sub_phase'] ) {
					return sprintf(
						/* translators: %s: component name */
						__( 'Compressing %s segment…', 'the-exporter' ),
						$c['name']
					);
				}
				if ( ! empty( $c['files_total'] ) ) {
					$line .= sprintf(
						' (%d packed / %d total)',
						(int) $c['files_packed'],
						(int) $c['files_total']
					);
				}
				return $line;
			}
			return sprintf(
				/* translators: %s: component name */
				__( 'Exporting %s…', 'the-exporter' ),
				$c['name']
			);
		}
		if ( 'export' === $phase || 'finalize' === $phase ) {
			return __( 'Starting export engine…', 'the-exporter' );
		}
		return __( 'Ready', 'the-exporter' );
	}

	/**
	 * Latest heartbeat timestamp from jobs.
	 *
	 * @param array|null $export_job Export job.
	 * @param array|null $import_job Import job.
	 * @return string ISO-ish mysql time.
	 */
	private static function latest_heartbeat( $export_job, $import_job ) {
		$times = array();
		if ( $export_job && ! empty( $export_job['updated_at'] ) ) {
			$times[] = $export_job['updated_at'];
			if ( ! empty( $export_job['id'] ) ) {
				foreach ( JobRepository::get_steps( $export_job['id'] ) as $step ) {
					if ( ! empty( $step['updated_at'] ) ) {
						$times[] = $step['updated_at'];
					}
				}
			}
		}
		if ( $import_job && ! empty( $import_job['updated_at'] ) ) {
			$times[] = $import_job['updated_at'];
			if ( ! empty( $import_job['id'] ) ) {
				foreach ( JobRepository::get_steps( $import_job['id'] ) as $step ) {
					if ( ! empty( $step['updated_at'] ) ) {
						$times[] = $step['updated_at'];
					}
				}
			}
		}
		return $times ? max( $times ) : current_time( 'mysql', true );
	}

	/**
	 * Collect warnings.
	 *
	 * @param string $migration_id Migration ID.
	 * @return array
	 */
	private static function warnings( $migration_id ) {
		$warnings = array();
		$limits   = Settings::php_upload_limits();
		$transfer = (int) Settings::get( 'browser_transfer_max_bytes' );
		if ( $transfer > min( $limits['upload_max_filesize'], $limits['post_max_size'] ) ) {
			$warnings[] = __( 'PHP upload limits are below browser transfer size.', 'the-exporter' );
		}
		$lock = JobRepository::get_lock_info( $migration_id );
		if ( ! empty( $lock['locked'] ) && ! empty( $lock['stale'] ) ) {
			$warnings[] = __( 'Migration lock may be stale.', 'the-exporter' );
		}
		return $warnings;
	}
}
