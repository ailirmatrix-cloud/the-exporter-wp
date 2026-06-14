<?php
/**
 * Main plugin bootstrap.
 *
 * @package TheExporter
 */

namespace TheExporter;

defined( 'ABSPATH' ) || exit;

/**
 * Class Plugin
 */
class Plugin {

	/**
	 * Initialize plugin.
	 */
	public static function init() {
		self::maybe_upgrade_database();
		Settings::init();
		Logging\AuditLogger::init();
		Jobs\Scheduler::init();
		Transfer\TransferWorker::init();
		Transfer\VerifyQueue::init();
		Transfer\VerifyWorker::init();
		Transfer\LeanReceive::init();

		if ( is_admin() ) {
			Admin\AdminMenu::init();
		}

		Rest\Api::init();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once TE_PLUGIN_DIR . 'cli/class-commands.php';
			\WP_CLI::add_command( 'the-exporter', 'TheExporter\\CLI\\Commands' );
		}
	}

	/**
	 * Run dbDelta when plugin version advances (idempotent).
	 */
	private static function maybe_upgrade_database() {
		$installed = get_option( 'te_db_version', '' );
		if ( version_compare( (string) $installed, TE_VERSION, '>=' ) ) {
			return;
		}
		require_once TE_PLUGIN_DIR . 'includes/database/class-installer.php';
		Database\Installer::install();
	}
}
