<?php
/**
 * Same-host filesystem copy when export package is visible to import PHP.
 *
 * @package TheExporter
 */

namespace TheExporter\Transfer;

use TheExporter\Logging\AuditLogger;
use TheExporter\Security\DirectoryGuard;
use TheExporter\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class LocalTransfer
 */
class LocalTransfer {

	/**
	 * Ask import site to copy from a host-visible export path.
	 *
	 * @param string $migration_id  Migration ID.
	 * @param string $export_path   Absolute export package path on export host.
	 * @return array
	 */
	public static function request_import_copy( $migration_id, $export_path ) {
		$migration_id = sanitize_text_field( $migration_id );
		$export_path  = wp_normalize_path( (string) $export_path );

		if ( ! Settings::is_connected_transfer() ) {
			return array( 'success' => false, 'skipped' => true );
		}

		$remote_url = Settings::effective_remote_push_url();
		$token      = Settings::get( 'remote_pairing_token', '' );
		if ( ! $remote_url || '' === trim( (string) $token ) || ! function_exists( 'curl_init' ) ) {
			return array( 'success' => false, 'skipped' => true );
		}

		$endpoint = trailingslashit( $remote_url ) . 'wp-json/the-exporter/v1/transfer/local-copy';
		$ch       = curl_init( $endpoint );
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => wp_json_encode(
					array(
						'migration_id' => $migration_id,
						'export_path'  => $export_path,
					)
				),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 300,
				CURLOPT_HTTPHEADER     => array(
					'X-TE-Token: ' . $token,
					'Content-Type: application/json',
					'Accept: application/json',
				),
			)
		);

		$body = curl_exec( $ch );
		$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		if ( false === $body || 200 !== $code ) {
			return array( 'success' => false, 'skipped' => true );
		}

		$data = json_decode( $body, true );
		return is_array( $data ) ? $data : array( 'success' => false, 'skipped' => true );
	}

	/**
	 * Import-side copy when export_path is readable on this host.
	 *
	 * @param string $migration_id Migration ID.
	 * @param string $export_path  Export package path.
	 * @return array
	 */
	public static function copy_from_export_path( $migration_id, $export_path ) {
		$migration_id = sanitize_text_field( $migration_id );
		$export_path  = wp_normalize_path( sanitize_text_field( $export_path ) );
		$resolved     = realpath( $export_path );

		if ( ! $resolved || ! is_dir( $resolved ) ) {
			$peer = Settings::peer_export_package_path( $migration_id );
			if ( $peer ) {
				$export_path = $peer;
				$resolved    = realpath( $peer );
			}
		}

		if ( ! $resolved || ! is_dir( $resolved ) ) {
			return array( 'success' => false, 'error' => 'Export path not readable on import host.' );
		}

		if ( ! self::path_matches_migration( $resolved, $migration_id ) ) {
			return array( 'success' => false, 'error' => 'Export path does not match migration ID.' );
		}

		if ( ! file_exists( $resolved . '/manifest.json' ) ) {
			return array( 'success' => false, 'error' => 'manifest.json missing in export path.' );
		}

		$dest = Settings::migration_path( $migration_id, 'import' );
		wp_mkdir_p( $dest );

		$copied = self::copy_directory( $resolved, $dest );
		if ( ! $copied['success'] ) {
			return $copied;
		}

		PackageIndex::clear_request_cache();
		TransferProgress::seed_from_local_copy( $migration_id, $copied['files'], $copied['bytes'] );

		AuditLogger::log(
			'local_transfer_copy',
			'Copied migration package from shared filesystem',
			array(
				'migration_id' => $migration_id,
				'files'        => $copied['files'],
				'bytes'        => $copied['bytes'],
			),
			'success'
		);

		return array(
			'success'  => true,
			'complete' => true,
			'files'    => $copied['files'],
			'bytes'    => $copied['bytes'],
			'mode'     => 'local_copy',
		);
	}

	/**
	 * @param string $src  Source directory.
	 * @param string $dest Destination directory.
	 * @return array
	 */
	private static function copy_directory( $src, $dest ) {
		$src  = trailingslashit( wp_normalize_path( $src ) );
		$dest = trailingslashit( wp_normalize_path( $dest ) );
		$base = Settings::get( 'import_base_path' );

		$files = 0;
		$bytes = 0;

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $src, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			/** @var \SplFileInfo $item */
			$rel = ltrim( str_replace( $src, '', wp_normalize_path( $item->getPathname() ) ), '/' );
			if ( '' === $rel ) {
				continue;
			}

			$target = $dest . $rel;
			if ( false === DirectoryGuard::validate_path( $target, $base ) ) {
				return array( 'success' => false, 'error' => 'Invalid destination path during local copy.' );
			}

			if ( $item->isDir() ) {
				wp_mkdir_p( $target );
				continue;
			}

			wp_mkdir_p( dirname( $target ) );
			if ( ! @copy( $item->getPathname(), $target ) ) {
				return array( 'success' => false, 'error' => 'Failed copying: ' . $rel );
			}
			$files++;
			$bytes += (int) $item->getSize();
		}

		return array(
			'success' => true,
			'files'   => $files,
			'bytes'   => $bytes,
		);
	}

	/**
	 * @param string $path         Resolved path.
	 * @param string $migration_id Migration ID.
	 * @return bool
	 */
	private static function path_matches_migration( $path, $migration_id ) {
		$slug = 'migration-' . sanitize_file_name( $migration_id );
		return false !== strpos( wp_normalize_path( $path ), $slug );
	}
}
