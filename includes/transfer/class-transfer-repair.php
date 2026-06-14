<?php
/**
 * Repair corrupt or partial transfer files on import site.
 *
 * @package TheExporter
 */

namespace TheExporter\Transfer;

use TheExporter\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class TransferRepair
 */
class TransferRepair {

	/**
	 * Remove a corrupt received file and roll back progress counters.
	 *
	 * @param string $migration_id  Migration ID.
	 * @param string $relative_path Relative path.
	 * @param string $component     Component slug.
	 */
	public static function purge_file( $migration_id, $relative_path, $component = '' ) {
		$migration_id  = sanitize_text_field( $migration_id );
		$relative_path = sanitize_text_field( $relative_path );
		$base          = Settings::migration_path( $migration_id, 'import' );
		$dest          = trailingslashit( $base ) . $relative_path;

		foreach ( array( $dest, $dest . '.uploading' ) as $path ) {
			if ( file_exists( $path ) ) {
				@unlink( $path );
			}
		}

		TransferProgress::unlog_receive( $migration_id, $relative_path );
		TransferProgress::clear_receive_inflight( $migration_id );

		if ( '' === $component ) {
			$parts = explode( '/', $relative_path );
			$component = isset( $parts[0] ) ? sanitize_key( $parts[0] ) : '';
		}
	}

	/**
	 * Remove partial .uploading file for one catalog path.
	 *
	 * @param string $migration_id  Migration ID.
	 * @param string $relative_path Relative path.
	 */
	public static function purge_partial_file( $migration_id, $relative_path ) {
		$migration_id  = sanitize_text_field( $migration_id );
		$relative_path = sanitize_text_field( $relative_path );
		$base          = Settings::migration_path( $migration_id, 'import' );
		if ( ! $base || '' === $relative_path ) {
			return;
		}
		$uploading = trailingslashit( $base ) . $relative_path . '.uploading';
		if ( file_exists( $uploading ) ) {
			@unlink( $uploading );
		}
		TransferProgress::clear_receive_inflight( $migration_id );
	}

	/**
	 * Remove all partial .uploading files for a migration.
	 *
	 * @param string $migration_id Migration ID.
	 * @return int Files removed.
	 */
	public static function purge_partials( $migration_id ) {
		$migration_id = sanitize_text_field( $migration_id );
		$base         = Settings::migration_path( $migration_id, 'import' );
		$removed      = 0;

		if ( ! is_dir( $base ) ) {
			return 0;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $base, \RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $item ) {
			/** @var \SplFileInfo $item */
			if ( str_ends_with( $item->getFilename(), '.uploading' ) ) {
				if ( @unlink( $item->getPathname() ) ) {
					$removed++;
				}
			}
		}

		return $removed;
	}
}
