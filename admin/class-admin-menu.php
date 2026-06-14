<?php
/**
 * Admin menu registration.
 *
 * @package TheExporter
 */

namespace TheExporter\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Class AdminMenu
 */
class AdminMenu {

	/**
	 * Init admin.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'redirect_legacy_pages' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Redirect old admin URLs to the unified wizard / activity / advanced pages.
	 */
	public static function redirect_legacy_pages() {
		if ( ! is_admin() || ! isset( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$page = sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$map  = array(
			'the-exporter'        => 'the-exporter-migrate',
			'the-exporter-export' => 'the-exporter-migrate',
			'the-exporter-import' => 'the-exporter-migrate',
			'the-exporter-jobs'   => 'the-exporter-activity',
			'the-exporter-settings' => 'the-exporter-advanced',
		);
		if ( isset( $map[ $page ] ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . $map[ $page ] ) );
			exit;
		}
	}

	/**
	 * Register menu pages.
	 */
	public static function register_menu() {
		add_menu_page(
			__( 'The Exporter', 'the-exporter' ),
			__( 'The Exporter', 'the-exporter' ),
			'manage_options',
			'the-exporter-migrate',
			array( __CLASS__, 'render_migrate' ),
			'dashicons-database-export',
			80
		);

		$pages = array(
			'the-exporter-migrate'  => array( 'render_migrate', __( 'Migrate', 'the-exporter' ) ),
			'the-exporter-activity' => array( 'render_activity', __( 'Activity', 'the-exporter' ) ),
			'the-exporter-advanced' => array( 'render_advanced', __( 'Advanced', 'the-exporter' ) ),
		);

		foreach ( $pages as $slug => $config ) {
			if ( 'the-exporter-migrate' === $slug ) {
				continue;
			}
			add_submenu_page(
				'the-exporter-migrate',
				$config[1],
				$config[1],
				'manage_options',
				$slug,
				array( __CLASS__, $config[0] )
			);
		}
	}

	/**
	 * Enqueue admin assets on plugin pages only.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function enqueue_assets( $hook ) {
		if ( strpos( $hook, 'the-exporter' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'te-tokens',
			TE_PLUGIN_URL . 'admin/assets/css/tokens.css',
			array(),
			TE_VERSION
		);
		wp_enqueue_style(
			'te-glass',
			TE_PLUGIN_URL . 'admin/assets/css/glass.css',
			array( 'te-tokens' ),
			TE_VERSION
		);
		wp_enqueue_style(
			'te-admin',
			TE_PLUGIN_URL . 'admin/assets/css/admin.css',
			array( 'te-glass' ),
			TE_VERSION
		);
		wp_enqueue_style(
			'te-pipeline',
			TE_PLUGIN_URL . 'admin/assets/css/pipeline.css',
			array( 'te-admin' ),
			TE_VERSION
		);

		wp_enqueue_script(
			'te-status',
			TE_PLUGIN_URL . 'admin/assets/js/status.js',
			array(),
			TE_VERSION,
			true
		);

		wp_enqueue_script(
			'te-progress',
			TE_PLUGIN_URL . 'admin/assets/js/progress.js',
			array( 'te-status' ),
			TE_VERSION,
			true
		);

		wp_enqueue_script(
			'te-transfer',
			TE_PLUGIN_URL . 'admin/assets/js/transfer.js',
			array( 'te-status', 'te-progress' ),
			TE_VERSION,
			true
		);

		wp_enqueue_script(
			'te-admin',
			TE_PLUGIN_URL . 'admin/assets/js/admin.js',
			array( 'te-status', 'te-transfer', 'te-progress' ),
			TE_VERSION,
			true
		);

		if ( strpos( $hook, 'the-exporter-activity' ) !== false ) {
			wp_enqueue_script(
				'te-import',
				TE_PLUGIN_URL . 'admin/assets/js/import.js',
				array( 'te-status', 'te-transfer', 'te-admin', 'te-progress' ),
				TE_VERSION,
				true
			);
		}

		if ( strpos( $hook, 'the-exporter-advanced' ) !== false ) {
			wp_enqueue_script(
				'te-settings',
				TE_PLUGIN_URL . 'admin/assets/js/settings.js',
				array( 'te-status', 'te-admin' ),
				TE_VERSION,
				true
			);
		}

		if ( strpos( $hook, 'the-exporter-migrate' ) !== false ) {
			$asset_file = TE_PLUGIN_DIR . 'build/index.asset.php';
			if ( file_exists( $asset_file ) ) {
				$asset = include $asset_file;
				wp_enqueue_script(
					'te-migrate',
					TE_PLUGIN_URL . 'build/index.js',
					$asset['dependencies'],
					$asset['version'],
					true
				);
				wp_enqueue_style(
					'te-migrate',
					TE_PLUGIN_URL . 'build/style-index.css',
					array( 'wp-components' ),
					$asset['version']
				);
				wp_localize_script( 'te-migrate', 'teMigrate', self::migrate_config() );
			}
		}

		wp_localize_script( 'te-transfer', 'teAdmin', array(
			'root'       => esc_url_raw( rest_url( 'the-exporter/v1/' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'migrationId'=> \TheExporter\Settings::get( 'active_migration_id' ),
			'browserTransferMaxBytes' => (int) \TheExporter\Settings::get( 'browser_transfer_max_bytes', 67108864 ),
			'transferMode'            => \TheExporter\Settings::transfer_mode(),
			'segmentSizeBytes'        => (int) \TheExporter\Settings::effective_segment_size(),
			'isConnected'             => \TheExporter\Settings::is_connected_transfer(),
			'remoteAutoPush'          => (bool) \TheExporter\Settings::get( 'remote_auto_push' ),
			'exportComponents' => \TheExporter\Jobs\ExportOrchestrator::components(),
			'fileComponents'   => \TheExporter\Jobs\ExportOrchestrator::file_components(),
			'fastExport'       => \TheExporter\Settings::is_fast_export(),
			'strings'    => array(
				'confirmWord' => 'CONFIRM',
				'polling'     => __( 'Updating…', 'the-exporter' ),
				'error'       => __( 'An error occurred.', 'the-exporter' ),
				'loadingPackages' => __( 'Loading download packages…', 'the-exporter' ),
				'noPackages'      => __( 'No packages available yet.', 'the-exporter' ),
				'stillWorking'    => __( 'Still working — large folders can take 20+ minutes.', 'the-exporter' ),
			),
		) );
	}

	/**
	 * Config passed to the React migrate wizard.
	 *
	 * @return array
	 */
	private static function migrate_config() {
		return array(
			'root'               => esc_url_raw( rest_url( 'the-exporter/v1/' ) ),
			'nonce'              => wp_create_nonce( 'wp_rest' ),
			'siteRole'           => \TheExporter\Settings::get( 'site_role', '' ),
			'migrationId'        => \TheExporter\Settings::get( 'active_migration_id' ),
			'remoteSiteUrl'      => \TheExporter\Settings::remote_site_url(),
			'remotePairingToken' => \TheExporter\Settings::get( 'remote_pairing_token', '' ),
			'remoteAutoPush'     => (bool) \TheExporter\Settings::get( 'remote_auto_push' ),
			'exportComponents'   => \TheExporter\Jobs\ExportOrchestrator::components(),
			'activityUrl'        => admin_url( 'admin.php?page=the-exporter-activity' ),
			'strings'            => array(
				'roleTitle'    => __( 'What is this site?', 'the-exporter' ),
				'roleIntro'    => __( 'Choose whether this WordPress site is the one you are copying from or copying to.', 'the-exporter' ),
				'exportSite'   => __( 'Export site', 'the-exporter' ),
				'exportHint'   => __( 'Copy this site to another server', 'the-exporter' ),
				'importSite'   => __( 'Import site', 'the-exporter' ),
				'importHint'   => __( 'Receive a migration from another site', 'the-exporter' ),
				'connectTitle' => __( 'Connect to import site', 'the-exporter' ),
				'connectIntro' => __( 'Paste the import site URL and pairing code from the import site wizard.', 'the-exporter' ),
				'importUrl'    => __( 'Import site URL', 'the-exporter' ),
				'pairingCode'  => __( 'Pairing code', 'the-exporter' ),
				'testConnect'  => __( 'Test & save connection', 'the-exporter' ),
				'exportTitle'  => __( 'Export this site', 'the-exporter' ),
				'exportIntro'  => __( 'Build checksum-verified packages on the server. Large sites run in the background.', 'the-exporter' ),
				'startExport'  => __( 'Start export', 'the-exporter' ),
				'resumeExport' => __( 'Resume / run export', 'the-exporter' ),
				'transferTitle'=> __( 'Send to import site', 'the-exporter' ),
				'transferIntro'=> __( 'Push packages to the connected import site over HTTP.', 'the-exporter' ),
				'sendNow'      => __( 'Send to import site', 'the-exporter' ),
				'autoPushOn'   => __( 'Auto-send is enabled — files are pushed automatically after export completes.', 'the-exporter' ),
				'pairTitle'    => __( 'Pair with export site', 'the-exporter' ),
				'pairIntro'    => __( 'Generate a pairing code and paste it on the export site.', 'the-exporter' ),
				'generateCode' => __( 'Generate pairing code', 'the-exporter' ),
				'copyCode'     => __( 'Copy this code to the export site:', 'the-exporter' ),
				'thisSiteUrl'  => __( 'This site URL:', 'the-exporter' ),
				'receiveTitle' => __( 'Receive packages', 'the-exporter' ),
				'receiveIntro' => __( 'Enter the Migration ID from the export site and wait for files to arrive.', 'the-exporter' ),
				'migrationId'  => __( 'Migration ID', 'the-exporter' ),
				'watchFiles'   => __( 'Watch for incoming files', 'the-exporter' ),
				'importTitle'  => __( 'Import migration', 'the-exporter' ),
				'validate'     => __( 'Validate packages', 'the-exporter' ),
				'runImport'    => __( 'Run import', 'the-exporter' ),
				'doneTitle'    => __( 'Migration step complete', 'the-exporter' ),
				'doneExport'   => __( 'Packages sent to import site. Finish import on the other site using the Migration ID below.', 'the-exporter' ),
				'doneImport'   => __( 'Import finished. Review your site and clear caches if needed.', 'the-exporter' ),
				'viewActivity' => __( 'View Activity log', 'the-exporter' ),
				'continue'     => __( 'Continue', 'the-exporter' ),
				'changeRole'   => __( 'Change site role', 'the-exporter' ),
				'saveContinue' => __( 'Save & continue', 'the-exporter' ),
				'retryPush'    => __( 'Retry send', 'the-exporter' ),
				'studioHint'   => __( 'Studio tip: keep both site tabs open during export and send. The server resolves Docker push URLs automatically.', 'the-exporter' ),
				'copyId'       => __( 'Copy Migration ID', 'the-exporter' ),
				'copied'       => __( 'Copied!', 'the-exporter' ),
				'componentProgress' => __( 'Component progress', 'the-exporter' ),
				'recentActivity'    => __( 'Recent activity', 'the-exporter' ),
				'filesPerSec'       => __( 'files', 'the-exporter' ),
				'remaining'         => __( 'left', 'the-exporter' ),
				'justNow'           => __( 'just now', 'the-exporter' ),
				'waitingSend'       => __( 'Waiting for export site to send files… Complete Send on the export site and keep both tabs open.', 'the-exporter' ),
			),
		);
	}

	/**
	 * Render page with layout.
	 *
	 * @param string $title       Title.
	 * @param string $active      Active slug.
	 * @param string $view_file   View file path relative to admin/.
	 * @param bool   $wizard_mode Minimal layout without activity sidebar.
	 */
	private static function render_page( $title, $active, $view_file, $wizard_mode = false ) {
		ob_start();
		include TE_PLUGIN_DIR . 'admin/' . $view_file;
		$content = ob_get_clean();

		$active_page      = $active;
		$te_wizard_layout = $wizard_mode;
		include TE_PLUGIN_DIR . 'admin/views/layout.php';
	}

	/** Migrate wizard */
	public static function render_migrate() {
		self::render_page( __( 'Migrate', 'the-exporter' ), 'migrate', 'views/migrate.php', true );
	}

	/** Activity (jobs & logs) */
	public static function render_activity() {
		self::render_page( __( 'Activity', 'the-exporter' ), 'activity', 'views/jobs.php' );
	}

	/** Advanced settings */
	public static function render_advanced() {
		self::render_page( __( 'Advanced', 'the-exporter' ), 'advanced', 'views/settings.php' );
	}
}
