<?php
/**
 * Export non-secret environment/config data.
 *
 * @package TheExporter
 */

namespace TheExporter\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Class EnvironmentExporter
 */
class EnvironmentExporter {

	/**
	 * Collect safe wp-config related data.
	 *
	 * @return array
	 */
	public static function collect() {
		$data = array(
			'table_prefix'     => isset( $GLOBALS['table_prefix'] ) ? $GLOBALS['table_prefix'] : 'wp_',
			'wp_debug'         => defined( 'WP_DEBUG' ) ? WP_DEBUG : false,
			'wp_debug_log'     => defined( 'WP_DEBUG_LOG' ) ? WP_DEBUG_LOG : false,
			'wp_memory_limit'  => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : '',
			'wp_max_memory'    => defined( 'WP_MAX_MEMORY_LIMIT' ) ? WP_MAX_MEMORY_LIMIT : '',
			'abspath'          => ABSPATH,
			'content_dir'      => WP_CONTENT_DIR,
			'upload_dir'       => wp_upload_dir(),
			'locale'           => get_locale(),
			'timezone_string'  => get_option( 'timezone_string' ),
			'permalink_structure' => get_option( 'permalink_structure' ),
		);

		// Never export secrets.
		$redacted_keys = array( 'DB_PASSWORD', 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY' );
		$data['redacted_constants'] = $redacted_keys;

		return $data;
	}

	/**
	 * Export to migration config directory.
	 *
	 * @param string $migration_path Migration path.
	 * @return array Inventory info.
	 */
	public static function export( $migration_path ) {
		$dir = trailingslashit( $migration_path ) . 'config';
		wp_mkdir_p( $dir );

		$data = self::collect();
		$file = $dir . '/environment.json';
		file_put_contents( $file, wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

		$checksum = \TheExporter\Validation\ChecksumService::write_sidecar( $file );

		$inventory = array(
			'component' => 'config',
			'files'     => array(
				array(
					'path'   => 'config/environment.json',
					'size'   => filesize( $file ),
					'sha256' => $checksum,
				),
			),
			'chunks'    => array(
				array(
					'path'     => 'config/environment.json',
					'size'     => filesize( $file ),
					'checksum' => $checksum,
				),
			),
			'total_bytes' => filesize( $file ),
		);

		\TheExporter\Files\InventoryBuilder::save( trailingslashit( $migration_path ) . 'config', $inventory );

		return $inventory;
	}
}
