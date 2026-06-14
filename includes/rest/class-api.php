<?php
/**
 * REST API for admin UI polling.
 *
 * @package TheExporter
 */

namespace TheExporter\Rest;

use TheExporter\Jobs\ExportOrchestrator;
use TheExporter\Jobs\ImportOrchestrator;
use TheExporter\Jobs\JobRepository;
use TheExporter\Jobs\ProgressReporter;
use TheExporter\Runtime;
use TheExporter\Logging\AuditLogger;
use TheExporter\Manifest\ManifestValidator;
use TheExporter\Settings;
use TheExporter\Transfer\ChunkReceiver;
use TheExporter\Transfer\FileDownloader;
use TheExporter\Transfer\FileUploader;
use TheExporter\Transfer\PackageIndex;
use TheExporter\Transfer\RemoteAuth;
use TheExporter\Transfer\LocalTransfer;
use TheExporter\Transfer\RemotePusher;
use TheExporter\Transfer\TransferProgress;
use TheExporter\Transfer\TransferStatus;
use TheExporter\Transfer\TransferWorker;
use TheExporter\Transfer\MigrationState;
use TheExporter\Transfer\VerifyWorker;

defined( 'ABSPATH' ) || exit;

/**
 * Class Api
 */
class Api {

	const NAMESPACE = 'the-exporter/v1';

	/**
	 * Init REST routes.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register routes.
	 */
	public static function register_routes() {
		register_rest_route( self::NAMESPACE, '/jobs/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_job' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NAMESPACE, '/jobs/migration/(?P<migration_id>[a-zA-Z0-9\-]+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_job_by_migration' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NAMESPACE, '/migration/(?P<migration_id>[a-zA-Z0-9\-]+)/progress', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'migration_progress' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NAMESPACE, '/migration/(?P<migration_id>[a-zA-Z0-9\-]+)/receive-progress', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'migration_receive_progress' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NAMESPACE, '/migration/(?P<migration_id>[a-zA-Z0-9\-]+)/push-progress', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'migration_push_progress' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NAMESPACE, '/jobs/(?P<id>\d+)/chunks', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_chunks' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NAMESPACE, '/validation/(?P<migration_id>[a-zA-Z0-9\-]+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_validation' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NAMESPACE, '/export/init', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'export_init' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NAMESPACE, '/export/component', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'export_component' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NAMESPACE, '/export/scan', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'export_scan' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NAMESPACE, '/export/segment', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'export_segment' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NAMESPACE, '/export/segment/claim', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'export_segment_claim' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NAMESPACE, '/export/queue', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'export_queue' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NAMESPACE, '/export/drive', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'export_drive' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NAMESPACE, '/export/worker-tick', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'export_worker_tick' ),
			'permission_callback' => array( __CLASS__, 'can_worker_tick' ),
		) );

		register_rest_route( self::NAMESPACE, '/export/can-finalize', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'export_can_finalize' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NAMESPACE, '/lock/release', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'release_lock' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NAMESPACE, '/preflight/(?P<migration_id>[a-zA-Z0-9\-]+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'preflight' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NAMESPACE, '/export/finalize', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'export_finalize' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NAMESPACE, '/import/validate', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'import_validate' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NAMESPACE, '/import/component', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'import_component' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NAMESPACE, '/import/all', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'import_all' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NAMESPACE, '/import/queue', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'import_queue' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NAMESPACE, '/import/segment', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'import_segment' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NAMESPACE, '/import/(?P<migration_id>[a-zA-Z0-9\-]+)/sftp-status', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'import_sftp_status' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NAMESPACE, '/import/(?P<migration_id>[a-zA-Z0-9\-]+)/upload-status', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'migration_upload_status' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NAMESPACE, '/environment', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_environment' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NAMESPACE, '/packages/(?P<migration_id>[a-zA-Z0-9\-]+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_packages' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NAMESPACE, '/packages/(?P<migration_id>[a-zA-Z0-9\-]+)/(?P<component>[a-zA-Z0-9\-]+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_package_component' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NAMESPACE, '/download/(?P<migration_id>[a-zA-Z0-9\-]+)/bundle', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'download_bundle' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NAMESPACE, '/download/(?P<migration_id>[a-zA-Z0-9\-]+)/bundle/(?P<component>[a-zA-Z0-9\-]+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'download_bundle_component' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NAMESPACE, '/download/(?P<migration_id>[a-zA-Z0-9\-]+)/(?P<file_hash>[a-f0-9]{16})', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'download_file' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NAMESPACE, '/upload/(?P<migration_id>[a-zA-Z0-9\-]+)/(?P<component>[a-zA-Z0-9\-]+)', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'upload_file' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NAMESPACE, '/upload/(?P<migration_id>[a-zA-Z0-9\-]+)/(?P<component>[a-zA-Z0-9\-]+)/status', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'upload_status' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NAMESPACE, '/validate/(?P<migration_id>[a-zA-Z0-9\-]+)/(?P<component>[a-zA-Z0-9\-]+)', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'validate_component' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NAMESPACE, '/pairing/generate', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'pairing_generate' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NAMESPACE, '/pairing/verify', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'pairing_verify_remote' ),
			'permission_callback' => array( __CLASS__, 'can_receive_transfer' ),
		) );

		register_rest_route( self::NAMESPACE, '/pairing/test', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'pairing_test_connection' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NAMESPACE, '/transfer/push', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'transfer_push' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NAMESPACE, '/transfer/drive', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'transfer_drive' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NAMESPACE, '/transfer/(?P<migration_id>[a-zA-Z0-9\-]+)/push-status', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'transfer_push_status' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NAMESPACE, '/transfer/receive/(?P<migration_id>[a-zA-Z0-9\-]+)/(?P<component>[a-zA-Z0-9\-]+)', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'transfer_receive' ),
			'permission_callback' => array( __CLASS__, 'can_receive_transfer' ),
		) );

		register_rest_route( self::NAMESPACE, '/transfer/receive-chunk/(?P<migration_id>[a-zA-Z0-9\-]+)/(?P<component>[a-zA-Z0-9\-]+)', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'transfer_receive_chunk' ),
			'permission_callback' => array( __CLASS__, 'can_receive_transfer' ),
		) );

		register_rest_route( self::NAMESPACE, '/transfer/push-state/(?P<migration_id>[a-zA-Z0-9\-]+)', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'transfer_push_state' ),
			'permission_callback' => array( __CLASS__, 'can_receive_transfer' ),
		) );

		register_rest_route( self::NAMESPACE, '/transfer/import-progress/(?P<migration_id>[a-zA-Z0-9\-]+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'transfer_import_progress' ),
			'permission_callback' => array( __CLASS__, 'can_receive_transfer' ),
		) );

		register_rest_route( self::NAMESPACE, '/transfer/worker-tick', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'transfer_worker_tick' ),
			'permission_callback' => array( __CLASS__, 'can_worker_tick' ),
		) );

		register_rest_route( self::NAMESPACE, '/transfer/verify-tick', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'transfer_verify_tick' ),
			'permission_callback' => array( __CLASS__, 'can_worker_tick' ),
		) );

		register_rest_route( self::NAMESPACE, '/transfer/chunk-status/(?P<migration_id>[a-zA-Z0-9\-]+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'transfer_chunk_status' ),
			'permission_callback' => array( __CLASS__, 'can_receive_transfer' ),
		) );

		register_rest_route( self::NAMESPACE, '/transfer/local-copy', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'transfer_local_copy' ),
			'permission_callback' => array( __CLASS__, 'can_receive_transfer' ),
		) );

		register_rest_route( self::NAMESPACE, '/wizard/role', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'wizard_set_role' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		register_rest_route( self::NAMESPACE, '/wizard/connect', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'wizard_connect' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );
	}

	/**
	 * Permission check.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Permission for site-to-site receive (pairing token).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function can_receive_transfer( $request ) {
		$token = RemoteAuth::token_from_request( $request );
		return RemoteAuth::verify_token( $token );
	}

	/**
	 * Permission for internal transfer worker loopback.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function can_worker_tick( $request ) {
		$token = $request->get_header( 'X-TE-Worker-Token' );
		if ( TransferWorker::verify_token( $token ) ) {
			return true;
		}
		return self::can_manage();
	}

	/**
	 * Get job status.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function get_job( $request ) {
		$job = JobRepository::get_job( (int) $request['id'] );
		if ( ! $job ) {
			return new \WP_REST_Response( array( 'error' => 'Not found' ), 404 );
		}
		$job['steps'] = self::format_steps( JobRepository::get_steps( $job['id'] ) );
		return new \WP_REST_Response( $job );
	}

	/**
	 * Get latest job for a migration.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function get_job_by_migration( $request ) {
		$migration_id = sanitize_text_field( $request['migration_id'] );
		$type         = sanitize_key( $request->get_param( 'type' ) ?: '' );
		$job          = JobRepository::get_job_by_migration( $migration_id, $type );
		if ( ! $job ) {
			return new \WP_REST_Response( array( 'error' => 'Not found' ), 404 );
		}
		$job['steps'] = self::format_steps( JobRepository::get_steps( $job['id'] ) );
		return new \WP_REST_Response( $job );
	}

	/**
	 * Unified migration progress for wizard UI.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function migration_progress( $request ) {
		$migration_id = sanitize_text_field( $request['migration_id'] );
		$context      = sanitize_key( $request->get_param( 'context' ) ?: 'auto' );
		return new \WP_REST_Response( ProgressReporter::snapshot( $migration_id, $context ) );
	}

	/**
	 * Rich receive progress for import wizard.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function migration_receive_progress( $request ) {
		$migration_id = sanitize_text_field( $request['migration_id'] );
		return new \WP_REST_Response( TransferProgress::receive_snapshot( $migration_id ) );
	}

	/**
	 * Rich push progress for export wizard.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function migration_push_progress( $request ) {
		$migration_id = sanitize_text_field( $request['migration_id'] );
		return new \WP_REST_Response( TransferProgress::push_snapshot( $migration_id ) );
	}

	/**
	 * Decode step meta for API consumers.
	 *
	 * @param array $steps Raw steps.
	 * @return array
	 */
	private static function format_steps( array $steps ) {
		foreach ( $steps as &$step ) {
			if ( ! empty( $step['meta'] ) && is_string( $step['meta'] ) ) {
				$decoded = json_decode( $step['meta'], true );
				$step['meta'] = is_array( $decoded ) ? $decoded : array();
			}
		}
		return $steps;
	}

	/**
	 * Get chunks for job steps.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function get_chunks( $request ) {
		$job    = JobRepository::get_job( (int) $request['id'] );
		$chunks = array();
		if ( $job ) {
			foreach ( JobRepository::get_steps( $job['id'] ) as $step ) {
				$chunks[ $step['component'] ] = JobRepository::get_chunks( $step['id'] );
			}
		}
		return new \WP_REST_Response( $chunks );
	}

	/**
	 * Get validation report.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function get_validation( $request ) {
		$report = ImportOrchestrator::validate( $request['migration_id'], true );
		return new \WP_REST_Response( $report );
	}

	/**
	 * Init export.
	 *
	 * @return \WP_REST_Response
	 */
	public static function export_init() {
		$result = ExportOrchestrator::init();
		return new \WP_REST_Response( $result );
	}

	/**
	 * Export component.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function export_component( $request ) {
		$params = RequestValidator::json_params( $request, array( 'migration_id', 'component' ) );
		if ( $params instanceof \WP_REST_Response ) {
			return $params;
		}
		$result = ExportOrchestrator::export_component(
			RequestValidator::migration_id( $params ),
			sanitize_key( $params['component'] )
		);
		return new \WP_REST_Response( $result, ! empty( $result['success'] ) ? 200 : 400 );
	}

	/**
	 * Scan files for a file component (inventory only).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function export_scan( $request ) {
		$params = RequestValidator::json_params( $request, array( 'migration_id', 'component' ) );
		if ( $params instanceof \WP_REST_Response ) {
			return $params;
		}
		$resume = ! isset( $params['resume'] ) || filter_var( $params['resume'], FILTER_VALIDATE_BOOLEAN );
		$result = ExportOrchestrator::export_scan(
			RequestValidator::migration_id( $params ),
			sanitize_key( $params['component'] ),
			$resume
		);
		return new \WP_REST_Response( $result, ! empty( $result['success'] ) ? 200 : 400 );
	}

	/**
	 * Export one or more segments of a file component (resumable).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function export_segment( $request ) {
		$params = RequestValidator::json_params( $request, array( 'migration_id', 'component' ) );
		if ( $params instanceof \WP_REST_Response ) {
			return $params;
		}
		$resume = ! isset( $params['resume'] ) || filter_var( $params['resume'], FILTER_VALIDATE_BOOLEAN );
		$max_segments = isset( $params['max_segments'] ) ? (int) $params['max_segments'] : 3;
		$result = ExportOrchestrator::export_segment(
			RequestValidator::migration_id( $params ),
			sanitize_key( $params['component'] ),
			$resume,
			false,
			$max_segments
		);
		return new \WP_REST_Response( $result, ! empty( $result['success'] ) ? 200 : 400 );
	}

	/**
	 * Claim and pack one segment (worker pool).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function export_segment_claim( $request ) {
		$params = RequestValidator::json_params( $request, array( 'migration_id', 'component' ) );
		if ( $params instanceof \WP_REST_Response ) {
			return $params;
		}
		$worker_id = isset( $params['worker_id'] ) ? sanitize_text_field( $params['worker_id'] ) : '';
		$result    = ExportOrchestrator::claim_segment(
			RequestValidator::migration_id( $params ),
			sanitize_key( $params['component'] ),
			$worker_id
		);
		return new \WP_REST_Response( $result, ! empty( $result['success'] ) ? 200 : 400 );
	}

	/**
	 * Queue export components via scheduler.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function export_queue( $request ) {
		$params = RequestValidator::json_params( $request, array( 'migration_id', 'components' ) );
		if ( $params instanceof \WP_REST_Response ) {
			return $params;
		}
		$components = is_array( $params['components'] ) ? $params['components'] : array();
		$result = ExportOrchestrator::queue_export( RequestValidator::migration_id( $params ), $components );
		return new \WP_REST_Response( $result, ! empty( $result['success'] ) ? 200 : 400 );
	}

	/**
	 * Drive export one tick (works without WP-Cron).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function export_drive( $request ) {
		$params = RequestValidator::json_params( $request, array( 'migration_id' ) );
		if ( $params instanceof \WP_REST_Response ) {
			return $params;
		}
		$result = ExportOrchestrator::drive_export_batch( RequestValidator::migration_id( $params ), 0 );
		if ( empty( $result['finalized'] ) && empty( $result['path'] ) && ! empty( $result['success'] ) ) {
			ExportOrchestrator::chain_loopback( RequestValidator::migration_id( $params ) );
		}
		return new \WP_REST_Response( $result, ! empty( $result['success'] ) ? 200 : 400 );
	}

	/**
	 * Internal export worker tick (loopback chain).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function export_worker_tick( $request ) {
		Runtime::prepare_job();
		$params       = $request->get_json_params();
		$migration_id = isset( $params['migration_id'] ) ? sanitize_text_field( $params['migration_id'] ) : Settings::get( 'active_migration_id' );
		if ( ! $migration_id ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'migration_id required' ), 400 );
		}

		$result = ExportOrchestrator::drive_export_batch( $migration_id, 0 );
		if ( empty( $result['finalized'] ) && empty( $result['path'] ) && ! empty( $result['success'] ) ) {
			ExportOrchestrator::chain_loopback( $migration_id );
		}
		return new \WP_REST_Response( $result, ! empty( $result['success'] ) ? 200 : 400 );
	}

	/**
	 * Check finalize readiness.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function export_can_finalize( $request ) {
		$params = RequestValidator::json_params( $request, array( 'migration_id' ) );
		if ( $params instanceof \WP_REST_Response ) {
			return $params;
		}
		return new \WP_REST_Response( ExportOrchestrator::can_finalize( RequestValidator::migration_id( $params ) ) );
	}

	/**
	 * Finalize export.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function export_finalize( $request ) {
		$params = RequestValidator::json_params( $request, array( 'migration_id' ) );
		if ( $params instanceof \WP_REST_Response ) {
			return $params;
		}
		$result = ExportOrchestrator::finalize( RequestValidator::migration_id( $params ) );
		return new \WP_REST_Response( $result, ! empty( $result['success'] ) ? 200 : 400 );
	}

	/**
	 * Validate import.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function import_validate( $request ) {
		$params = RequestValidator::json_params( $request, array( 'migration_id' ) );
		if ( $params instanceof \WP_REST_Response ) {
			return $params;
		}
		$result = ImportOrchestrator::validate( RequestValidator::migration_id( $params ), true );
		return new \WP_REST_Response( $result );
	}

	/**
	 * Import component.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function import_component( $request ) {
		$params = RequestValidator::json_params( $request, array( 'migration_id', 'component' ) );
		if ( $params instanceof \WP_REST_Response ) {
			return $params;
		}
		$result = ImportOrchestrator::import_component(
			RequestValidator::migration_id( $params ),
			sanitize_key( $params['component'] ),
			array(
				'dry_run'              => ! empty( $params['dry_run'] ),
				'confirm'              => ! empty( $params['confirm'] ),
				'force'                => ! empty( $params['force'] ),
				'create_restore_point' => ! isset( $params['create_restore_point'] ) || $params['create_restore_point'],
			)
		);
		return new \WP_REST_Response( $result, ! empty( $result['success'] ) ? 200 : 400 );
	}

	/**
	 * Import all components in order.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function import_all( $request ) {
		$params = RequestValidator::json_params( $request, array( 'migration_id' ) );
		if ( $params instanceof \WP_REST_Response ) {
			return $params;
		}
		$result = ImportOrchestrator::import_all(
			RequestValidator::migration_id( $params ),
			array(
				'dry_run' => ! empty( $params['dry_run'] ),
				'confirm' => ! empty( $params['confirm'] ),
				'force'   => ! empty( $params['force'] ),
			)
		);
		return new \WP_REST_Response( $result, ! empty( $result['success'] ) ? 200 : 400 );
	}

	/**
	 * Queue import via scheduler.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function import_queue( $request ) {
		$params = RequestValidator::json_params( $request, array( 'migration_id' ) );
		if ( $params instanceof \WP_REST_Response ) {
			return $params;
		}
		$result = ImportOrchestrator::queue_import(
			RequestValidator::migration_id( $params ),
			array(
				'confirm' => ! empty( $params['confirm'] ),
				'force'   => ! empty( $params['force'] ),
			)
		);
		return new \WP_REST_Response( $result, ! empty( $result['success'] ) ? 200 : 400 );
	}

	/**
	 * Import one segment/chunk per request (resumable).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function import_segment( $request ) {
		$params = RequestValidator::json_params( $request, array( 'migration_id', 'component' ) );
		if ( $params instanceof \WP_REST_Response ) {
			return $params;
		}
		$result = ImportOrchestrator::import_segment(
			RequestValidator::migration_id( $params ),
			sanitize_key( $params['component'] ),
			array(
				'confirm' => ! empty( $params['confirm'] ),
				'force'   => ! empty( $params['force'] ),
				'job_id'  => isset( $params['job_id'] ) ? absint( $params['job_id'] ) : 0,
			)
		);
		return new \WP_REST_Response( $result, ! empty( $result['success'] ) ? 200 : 400 );
	}

	/**
	 * Scan import folder for SFTP-dropped files (alias of upload-status with paths).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function import_sftp_status( $request ) {
		$migration_id = sanitize_text_field( $request['migration_id'] );
		$lightweight  = rest_sanitize_boolean( $request->get_param( 'lightweight' ) );
		$status       = FileUploader::migration_upload_status( $migration_id, array( 'lightweight' => $lightweight ) );
		$status['import_path']    = Settings::migration_path( $migration_id, 'import' );
		$status['transfer_mode']  = Settings::transfer_mode();
		$status['sftp_detected']  = ! empty( $status['has_catalog'] ) || ! empty( $status['uploaded'] );
		$transfer                 = PackageIndex::transfer_summary( $migration_id, 'import' );
		$status['transfer_summary'] = $transfer;
		if ( ! empty( $transfer['total_bytes'] ) ) {
			$status['disk_estimate'] = Settings::estimate_import_disk_bytes( (int) $transfer['total_bytes'] );
		}
		return new \WP_REST_Response( $status );
	}

	/**
	 * Overall import upload status.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function migration_upload_status( $request ) {
		$lightweight = rest_sanitize_boolean( $request->get_param( 'lightweight' ) );
		$status      = FileUploader::migration_upload_status(
			sanitize_text_field( $request['migration_id'] ),
			array( 'lightweight' => $lightweight )
		);
		return new \WP_REST_Response( $status );
	}

	/**
	 * Force release migration lock.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function release_lock( $request ) {
		$params = RequestValidator::json_params( $request, array( 'migration_id' ) );
		if ( $params instanceof \WP_REST_Response ) {
			return $params;
		}
		$id = RequestValidator::migration_id( $params );
		JobRepository::force_release_lock( $id );
		return new \WP_REST_Response( array( 'success' => true, 'migration_id' => $id ) );
	}

	/**
	 * Pre-flight checks for import/export.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function preflight( $request ) {
		$id     = sanitize_text_field( $request['migration_id'] );
		$limits = Settings::php_upload_limits();
		$lock   = JobRepository::get_lock_info( $id );
		$import = PackageIndex::get_diagnostics( $id, 'import' );
		$export = PackageIndex::get_diagnostics( $id, 'export' );
		$finalize = ExportOrchestrator::can_finalize( $id );
		$transfer = PackageIndex::transfer_summary( $id, 'export' );
		$disk_est = ! empty( $transfer['total_bytes'] )
			? Settings::estimate_import_disk_bytes( (int) $transfer['total_bytes'] )
			: null;

		return new \WP_REST_Response( array(
			'migration_id'  => $id,
			'lock'          => $lock,
			'php_limits'    => $limits,
			'disk_free'     => @disk_free_space( WP_CONTENT_DIR ),
			'import_status' => $import,
			'export_status' => $export,
			'can_finalize'  => $finalize,
			'mysqldump'     => \TheExporter\Database\Dumper::has_mysqldump(),
			'mysql_cli'     => \TheExporter\Database\Importer::has_mysql_cli(),
			'transfer_mode' => Settings::transfer_mode(),
			'segment_size'  => Settings::effective_segment_size(),
			'transfer'      => $transfer,
			'disk_estimate' => $disk_est,
		) );
	}

	/**
	 * Environment info for dashboard.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_environment() {
		$limits = Settings::php_upload_limits();
		$migration_id = Settings::get( 'active_migration_id' );
		$profile = \TheExporter\EnvironmentProfile::detect( true );
		return new \WP_REST_Response( array(
			'wp_version'   => get_bloginfo( 'version' ),
			'php_version'  => PHP_VERSION,
			'mysqldump'    => \TheExporter\Database\Dumper::has_mysqldump(),
			'mydumper'     => \TheExporter\Database\Dumper::has_mydumper(),
			'mysql_cli'    => \TheExporter\Database\Importer::has_mysql_cli(),
			'wp_cli'       => defined( 'WP_CLI' ) && WP_CLI,
			'disk_free'    => @disk_free_space( WP_CONTENT_DIR ),
			'export_path'  => Settings::get( 'export_base_path' ),
			'import_path'  => Settings::get( 'import_base_path' ),
			'migration_id' => $migration_id,
			'transfer_max' => Settings::get( 'browser_transfer_max_bytes' ),
			'transfer_mode' => Settings::transfer_mode(),
			'is_connected'  => Settings::is_connected_transfer(),
			'remote_site_url' => Settings::remote_site_url(),
			'segment_size' => Settings::effective_segment_size(),
			'large_segments_sftp' => Settings::get( 'large_segments_sftp' ),
			'segment_compression' => Settings::segment_compression(),
			'export_worker_concurrency' => Settings::resolved_export_worker_concurrency(),
			'php_limits'   => $limits,
			'lock'         => $migration_id ? JobRepository::get_lock_info( $migration_id ) : false,
			'scheduler'    => function_exists( 'as_schedule_single_action' ) ? 'action_scheduler' : 'wp_cron',
			'profile'      => $profile,
			'compression'  => \TheExporter\EnvironmentProfile::effective_compression(),
			'database_engine' => \TheExporter\EnvironmentProfile::effective_database_engine(),
		) );
	}

	/**
	 * List component packages.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function get_packages( $request ) {
		$id     = sanitize_text_field( $request['migration_id'] );
		$prefer = $request->get_param( 'context' ) === 'import' ? 'import' : 'export';
		$status = PackageIndex::get_diagnostics( $id, $prefer );
		return new \WP_REST_Response( array(
			'migration_id'    => $id,
			'global_files'    => PackageIndex::get_global_files( $id, $prefer ),
			'components'      => PackageIndex::get_components( $id, $prefer ),
			'component_order' => PackageIndex::component_order(),
			'status'          => $status,
			'transfer'        => PackageIndex::transfer_summary( $id, $prefer ),
		) );
	}

	/**
	 * Get single component package.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function get_package_component( $request ) {
		$id        = sanitize_text_field( $request['migration_id'] );
		$component = sanitize_key( $request['component'] );
		$prefer    = $request->get_param( 'context' ) === 'import' ? 'import' : 'export';

		if ( 'manifest' === $component ) {
			return new \WP_REST_Response( array(
				'files' => PackageIndex::get_global_files( $id, $prefer ),
			) );
		}

		$comp = PackageIndex::get_component( $id, $component, $prefer );
		if ( ! $comp ) {
			return new \WP_REST_Response( array( 'error' => 'Not found' ), 404 );
		}
		return new \WP_REST_Response( $comp );
	}

	/**
	 * Stream file download.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function download_file( $request ) {
		FileDownloader::stream(
			sanitize_text_field( $request['migration_id'] ),
			sanitize_text_field( $request['file_hash'] )
		);
	}

	/**
	 * Download full migration as one ZIP.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function download_bundle( $request ) {
		FileDownloader::stream_bundle(
			sanitize_text_field( $request['migration_id'] ),
			''
		);
	}

	/**
	 * Download one component as ZIP.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function download_bundle_component( $request ) {
		FileDownloader::stream_bundle(
			sanitize_text_field( $request['migration_id'] ),
			sanitize_key( $request['component'] )
		);
	}

	/**
	 * Upload package file.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function upload_file( $request ) {
		$files  = $request->get_file_params();
		$params = $request->get_body_params();

		// Multipart FormData fields are often in $_POST, not get_body_params().
		if ( empty( $params ) && ! empty( $_POST ) ) {
			$params = wp_unslash( $_POST );
		}

		$file = isset( $files['file'] ) ? $files['file'] : null;

		if ( ! $file || empty( $file['tmp_name'] ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'No file received' ), 400 );
		}

		$relative_path = isset( $params['relative_path'] ) ? sanitize_text_field( $params['relative_path'] ) : '';
		$checksum      = isset( $params['checksum'] ) ? sanitize_text_field( $params['checksum'] ) : '';

		$result = FileUploader::upload(
			sanitize_text_field( $request['migration_id'] ),
			sanitize_key( $request['component'] ),
			$relative_path,
			$file,
			$checksum
		);

		$code = ! empty( $result['success'] ) ? 200 : 400;
		return new \WP_REST_Response( $result, $code );
	}

	/**
	 * Upload status for component.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function upload_status( $request ) {
		$status = FileUploader::component_status(
			sanitize_text_field( $request['migration_id'] ),
			sanitize_key( $request['component'] )
		);
		return new \WP_REST_Response( $status );
	}

	/**
	 * Validate single component.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function validate_component( $request ) {
		$id        = sanitize_text_field( $request['migration_id'] );
		$component = sanitize_key( $request['component'] );
		$path      = PackageIndex::resolve_path( $id, 'import' );

		if ( ! $path ) {
			return new \WP_REST_Response( array( 'passed' => false, 'errors' => array( array( 'message' => 'Migration not found' ) ) ), 404 );
		}

		$report = ManifestValidator::validate_component( $path, $component, true );
		return new \WP_REST_Response( $report );
	}

	/**
	 * Generate pairing code (import site).
	 *
	 * @return \WP_REST_Response
	 */
	public static function pairing_generate() {
		$result = RemoteAuth::generate_token();
		return new \WP_REST_Response( $result );
	}

	/**
	 * Verify pairing token (import site, called by export site).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function pairing_verify_remote( $request ) {
		return new \WP_REST_Response( array(
			'success'    => true,
			'site_url'   => home_url(),
			'php_limits' => Settings::php_upload_limits(),
			'message'    => __( 'Pairing code accepted. This site is ready to receive packages.', 'the-exporter' ),
		) );
	}

	/**
	 * Test connection to import site (export site).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function pairing_test_connection( $request ) {
		$params = $request->get_json_params();
		$url    = isset( $params['remote_site_url'] ) ? $params['remote_site_url'] : Settings::remote_site_url();
		$token  = isset( $params['token'] ) ? $params['token'] : Settings::get( 'remote_pairing_token', '' );
		$result = RemoteAuth::verify_remote_site( $url, $token );
		$code   = ! empty( $result['success'] ) ? 200 : 400;
		return new \WP_REST_Response( $result, $code );
	}

	/**
	 * Queue push to connected import site.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function transfer_push( $request ) {
		$params = $request->get_json_params();
		$id     = isset( $params['migration_id'] ) ? sanitize_text_field( $params['migration_id'] ) : Settings::get( 'active_migration_id' );

		$export_path = Settings::migration_path( $id, 'export' );
		$local       = LocalTransfer::request_import_copy( $id, $export_path );
		if ( ! empty( $local['success'] ) && ! empty( $local['complete'] ) ) {
			RemotePusher::mark_push_complete( $id );
			$result = array_merge(
				$local,
				array(
					'progress' => TransferProgress::push_snapshot( $id ),
				)
			);
			return new \WP_REST_Response( $result, 200 );
		}

		$result = RemotePusher::queue_push( $id );
		if ( ! empty( $result['success'] ) ) {
			$result['progress'] = TransferProgress::push_snapshot( $id );
		}
		$code = ! empty( $result['success'] ) ? 200 : 400;
		return new \WP_REST_Response( $result, $code );
	}

	/**
	 * Watchdog nudge for server worker (optional browser fallback).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function transfer_drive( $request ) {
		Runtime::prepare_job();
		$params = $request->get_json_params();
		$id     = isset( $params['migration_id'] ) ? sanitize_text_field( $params['migration_id'] ) : Settings::get( 'active_migration_id' );
		RemotePusher::clear_push_url_cache();
		$nudged = false;
		$result = array( 'success' => true, 'nudge' => false );

		$budget_sec   = Settings::transfer_drive_seconds();
		$budget_bytes = TransferWorker::budget_bytes();

		RemotePusher::maybe_resume_failed_push( $id );

		if ( TransferWorker::try_acquire( $id ) ) {
			$nudged = true;
			$GLOBALS['te_push_worker_driven'] = true;
			try {
				$result = RemotePusher::process_tick_budget( $id, $budget_sec, $budget_bytes );
			} finally {
				unset( $GLOBALS['te_push_worker_driven'] );
				TransferWorker::release( $id );
			}
		} else {
			TransferWorker::ensure_running( $id );
			TransferWorker::chain_loopback( $id );
		}

		$result['progress'] = TransferProgress::push_snapshot( $id );
		$result['nudge']    = $nudged;
		$code               = ( ! empty( $result['success'] ) || ! empty( $result['retrying'] ) || ! $nudged ) ? 200 : 400;
		return new \WP_REST_Response( $result, $code );
	}

	/**
	 * Internal worker tick (loopback / admin nudge).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function transfer_worker_tick( $request ) {
		Runtime::prepare_job();
		$params       = $request->get_json_params();
		$migration_id = isset( $params['migration_id'] ) ? sanitize_text_field( $params['migration_id'] ) : Settings::get( 'active_migration_id' );
		if ( ! $migration_id ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'migration_id required' ), 400 );
		}

		TransferWorker::run_tick( array( 'migration_id' => $migration_id ) );
		$result = TransferProgress::push_snapshot( $migration_id );
		return new \WP_REST_Response(
			array(
				'success'  => true,
				'progress' => $result,
			)
		);
	}

	/**
	 * Internal verify worker tick (loopback).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function transfer_verify_tick( $request ) {
		Runtime::prepare_job();
		$params       = $request->get_json_params();
		$migration_id = isset( $params['migration_id'] ) ? sanitize_text_field( $params['migration_id'] ) : Settings::get( 'active_migration_id' );
		if ( ! $migration_id ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'migration_id required' ), 400 );
		}

		VerifyWorker::run_tick( array( 'migration_id' => $migration_id ) );
		return new \WP_REST_Response(
			array(
				'success' => true,
				'verify'  => MigrationState::verify_state( $migration_id ),
			)
		);
	}

	/**
	 * Disk-authoritative chunk resume status for export push.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function transfer_chunk_status( $request ) {
		$migration_id  = sanitize_text_field( $request['migration_id'] );
		$relative_path = isset( $request['path'] ) ? sanitize_text_field( $request['path'] ) : '';
		$total_size    = isset( $request['total_size'] ) ? (int) $request['total_size'] : 0;

		$status = ChunkReceiver::chunk_status( $migration_id, $relative_path );
		if ( $total_size > 0 ) {
			$status['total_size'] = $total_size;
		}

		return new \WP_REST_Response(
			array_merge(
				array( 'success' => true ),
				$status
			)
		);
	}

	/**
	 * Import-side local filesystem copy when export path is shared.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function transfer_local_copy( $request ) {
		Runtime::prepare_job();
		$params       = $request->get_json_params();
		$migration_id = isset( $params['migration_id'] ) ? sanitize_text_field( $params['migration_id'] ) : '';
		$export_path  = isset( $params['export_path'] ) ? sanitize_text_field( $params['export_path'] ) : '';

		if ( ! $migration_id || ! $export_path ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'migration_id and export_path required' ), 400 );
		}

		$result = LocalTransfer::copy_from_export_path( $migration_id, $export_path );
		$code   = ! empty( $result['success'] ) ? 200 : 400;
		return new \WP_REST_Response( $result, $code );
	}

	/**
	 * Push job status.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function transfer_push_status( $request ) {
		$status = RemotePusher::push_status( sanitize_text_field( $request['migration_id'] ) );
		return new \WP_REST_Response( $status );
	}

	/**
	 * Receive file from connected export site.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function transfer_receive( $request ) {
		Runtime::prepare_job();
		$files  = $request->get_file_params();
		$params = $request->get_body_params();
		if ( empty( $params ) && ! empty( $_POST ) ) {
			$params = wp_unslash( $_POST );
		}

		$file = isset( $files['file'] ) ? $files['file'] : null;
		if ( ! $file || empty( $file['tmp_name'] ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'No file received' ), 400 );
		}

		$relative_path = isset( $params['relative_path'] ) ? sanitize_text_field( $params['relative_path'] ) : '';
		$checksum      = isset( $params['checksum'] ) ? sanitize_text_field( $params['checksum'] ) : '';
		$token         = RemoteAuth::token_from_request( $request );

		$result = FileUploader::server_receive(
			sanitize_text_field( $request['migration_id'] ),
			sanitize_key( $request['component'] ),
			$relative_path,
			$file,
			$checksum,
			$token
		);

		$code = ! empty( $result['success'] ) ? 200 : 400;
		return new \WP_REST_Response( $result, $code );
	}

	/**
	 * Receive one chunk of a large file.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function transfer_receive_chunk( $request ) {
		Runtime::prepare_job();
		$files  = $request->get_file_params();
		$params = $request->get_body_params();
		if ( empty( $params ) && ! empty( $_POST ) ) {
			$params = wp_unslash( $_POST );
		}

		$file = isset( $files['file'] ) ? $files['file'] : null;
		if ( ! $file || empty( $file['tmp_name'] ) ) {
			$limits   = Settings::php_upload_limits();
			$post_max = (int) $limits['post_max_size'];
			$hint     = $post_max > 0
				? sprintf(
					/* translators: %s: PHP post_max_size */
					__( 'No chunk received — import host post_max_size may be too small (%s). Ask your host to raise it to 128M.', 'the-exporter' ),
					size_format( $post_max )
				)
				: __( 'No chunk received — check import host PHP post_max_size and web server upload limits.', 'the-exporter' );
			return new \WP_REST_Response( array( 'success' => false, 'error' => $hint ), 400 );
		}

		$chunk_bytes = isset( $file['size'] ) ? (int) $file['size'] : 0;
		$limits      = Settings::php_upload_limits();
		$post_max    = (int) $limits['post_max_size'];
		if ( $post_max > 0 && $chunk_bytes > $post_max ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => sprintf(
					/* translators: 1: chunk size, 2: post_max_size */
					__( 'Chunk size (%1$s) exceeds PHP post_max_size (%2$s).', 'the-exporter' ),
					size_format( $chunk_bytes ),
					size_format( $post_max )
				),
			), 413 );
		}

		$token = RemoteAuth::token_from_request( $request );
		if ( ! RemoteAuth::verify_token( $token ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'Invalid or expired pairing code' ), 403 );
		}

		$result = ChunkReceiver::receive_chunk(
			sanitize_text_field( $request['migration_id'] ),
			sanitize_key( $request['component'] ),
			$file,
			$params
		);

		$code = ! empty( $result['success'] ) ? 200 : 400;
		return new \WP_REST_Response( $result, $code );
	}

	/**
	 * Relay export push state for import receive UI.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function transfer_push_state( $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}
		$migration_id = sanitize_text_field( $request['migration_id'] );
		TransferProgress::set_import_push_state( $migration_id, $params );
		return new \WP_REST_Response( array( 'success' => true ) );
	}

	/**
	 * List received file paths for export resume/reconcile.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function transfer_import_progress( $request ) {
		$migration_id = sanitize_text_field( $request['migration_id'] );
		$base         = Settings::migration_path( $migration_id, 'import' );
		$paths        = array();

		if ( is_dir( $base ) ) {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $base, \RecursiveDirectoryIterator::SKIP_DOTS )
			);
			$prefix = wp_normalize_path( trailingslashit( $base ) );
			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}
				$full = wp_normalize_path( $file->getPathname() );
				if ( substr( $full, -10 ) === '.uploading' ) {
					continue;
				}
				$rel = ltrim( str_replace( $prefix, '', $full ), '/' );
				if ( 'inventory.json' === basename( $rel ) ) {
					continue;
				}
				$paths[] = $rel;
			}
			sort( $paths );
		}

		$status = FileUploader::migration_upload_status( $migration_id, array( 'lightweight' => true ) );

		return new \WP_REST_Response( array(
			'success'         => true,
			'received_paths'  => $paths,
			'uploaded'        => (int) ( $status['uploaded'] ?? 0 ),
			'expected'        => (int) ( $status['expected'] ?? 0 ),
		) );
	}

	/**
	 * Set wizard site role (export or import).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function wizard_set_role( $request ) {
		$params = $request->get_json_params();
		$role   = isset( $params['role'] ) ? sanitize_key( $params['role'] ) : '';
		if ( '' === $role ) {
			Settings::update( array( 'site_role' => '' ) );
			return new \WP_REST_Response( array( 'success' => true, 'role' => '' ) );
		}
		if ( ! in_array( $role, array( 'export', 'import' ), true ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'Invalid role' ), 400 );
		}
		Settings::update( array( 'site_role' => $role ) );
		if ( 'export' === $role ) {
			Settings::apply_profile( 'connected' );
		}
		return new \WP_REST_Response( array( 'success' => true, 'role' => $role ) );
	}

	/**
	 * Save connection settings and test remote import site.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function wizard_connect( $request ) {
		$params = $request->get_json_params();
		$url    = isset( $params['remote_site_url'] ) ? $params['remote_site_url'] : '';
		$token  = isset( $params['remote_pairing_token'] ) ? $params['remote_pairing_token'] : '';
		$skip   = ! empty( $params['skip_test'] );
		Settings::apply_profile( 'connected' );
		$browser = Settings::sanitize_remote_url( $url );
		$push    = $browser ? RemoteAuth::resolve_server_url( $browser ) : '';
		Settings::update( array(
			'remote_site_url'       => $browser,
			'remote_site_url_push'  => $push,
			'remote_pairing_token'  => sanitize_text_field( $token ),
		) );
		if ( $skip ) {
			return new \WP_REST_Response( array(
				'success'  => true,
				'message'  => __( 'Connection settings saved.', 'the-exporter' ),
				'push_url' => Settings::effective_remote_push_url(),
			) );
		}
		$result = RemoteAuth::verify_remote_site( Settings::remote_site_url(), Settings::get( 'remote_pairing_token', '' ) );
		$code   = ! empty( $result['success'] ) ? 200 : 400;
		if ( ! empty( $result['success'] ) ) {
			$result['message']  = __( 'Connected to import site.', 'the-exporter' );
			$result['push_url'] = Settings::effective_remote_push_url();
		}
		return new \WP_REST_Response( $result, $code );
	}
}
