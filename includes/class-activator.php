<?php
/**
 * Plugin activation.
 *
 * @package TheExporter
 */

namespace TheExporter;

defined( 'ABSPATH' ) || exit;

/**
 * Class Activator
 */
class Activator {

	/**
	 * Run on activation.
	 */
	public static function activate() {
		require_once TE_PLUGIN_DIR . 'includes/database/class-installer.php';
		require_once TE_PLUGIN_DIR . 'includes/security/class-directory-guard.php';
		Database\Installer::install();
		self::create_directories();
		flush_rewrite_rules();
	}

	/**
	 * Create default export/import directories with protection.
	 */
	private static function create_directories() {
		$paths = array(
			WP_CONTENT_DIR . '/migration-exports',
			WP_CONTENT_DIR . '/migration-imports',
			WP_CONTENT_DIR . '/migration-restore-points',
		);

		foreach ( $paths as $path ) {
			if ( ! is_dir( $path ) ) {
				wp_mkdir_p( $path );
			}
			Security\DirectoryGuard::protect( $path );
		}
	}
}
