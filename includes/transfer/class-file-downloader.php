<?php
/**
 * Stream migration files for browser download.
 *
 * @package TheExporter
 */

namespace TheExporter\Transfer;

use TheExporter\Logging\AuditLogger;
use TheExporter\Security\DirectoryGuard;
use TheExporter\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class FileDownloader
 */
class FileDownloader {

	/**
	 * Stream file to browser.
	 *
	 * @param string $migration_id Migration ID.
	 * @param string $file_hash    File hash.
	 */
	public static function stream( $migration_id, $file_hash ) {
		$migration_id = sanitize_text_field( $migration_id );
		$file_hash    = sanitize_text_field( $file_hash );

		$file = PackageIndex::find_file_by_hash( $migration_id, $file_hash, 'export' );
		if ( ! $file ) {
			status_header( 404 );
			wp_die( esc_html__( 'File not found.', 'the-exporter' ) );
		}

		$max = (int) Settings::get( 'browser_transfer_max_bytes', 67108864 );
		if ( empty( $file['browser_safe'] ) || (int) $file['size'] > $max ) {
			status_header( 413 );
			wp_die( esc_html__( 'File too large for browser download. Use SFTP.', 'the-exporter' ) );
		}

		$base = Settings::migration_path( $migration_id, 'export' );
		$full = trailingslashit( $base ) . $file['path'];

		$validated = DirectoryGuard::validate_path( $full, $base );
		if ( ! $validated || ! file_exists( $full ) ) {
			status_header( 404 );
			wp_die( esc_html__( 'File not found on disk.', 'the-exporter' ) );
		}

		AuditLogger::log( 'transfer_download', 'Browser download: ' . $file['path'], array(
			'migration_id' => $migration_id,
			'component'    => $file['component'],
		), 'info' );

		$filename = ! empty( $file['download_name'] ) ? $file['download_name'] : basename( $file['path'] );
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $full ) );
		if ( ! empty( $file['checksum'] ) ) {
			header( 'X-TE-Checksum-Sha256: ' . $file['checksum'] );
		}
		header( 'Cache-Control: no-cache' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fp = fopen( $full, 'rb' );
		if ( ! $fp ) {
			status_header( 500 );
			wp_die( esc_html__( 'Could not open file for download.', 'the-exporter' ) );
		}
		while ( ! feof( $fp ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			echo fread( $fp, 8192 );
			if ( ob_get_level() ) {
				ob_flush();
			}
			flush();
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $fp );
		exit;
	}

	/**
	 * Stream a ZIP bundle of browser-safe migration files.
	 *
	 * @param string $migration_id Migration ID.
	 * @param string $component    Optional component slug (empty = full migration).
	 */
	public static function stream_bundle( $migration_id, $component = '' ) {
		\TheExporter\Runtime::prepare_job();

		$migration_id = sanitize_text_field( $migration_id );
		$component    = sanitize_key( $component );

		$files = PackageIndex::list_browser_transfer_files( $migration_id, $component, 'export' );
		if ( empty( $files ) ) {
			status_header( 404 );
			wp_die( esc_html__( 'No downloadable files found.', 'the-exporter' ) );
		}

		if ( ! class_exists( 'ZipArchive', false ) ) {
			status_header( 501 );
			wp_die( esc_html__( 'ZIP is not available on this server. Use “Save all to folder” instead.', 'the-exporter' ) );
		}

		$total_bytes = 0;
		foreach ( $files as $file ) {
			$total_bytes += (int) $file['size'];
		}
		$max_zip = 1610612736;
		if ( $total_bytes > $max_zip ) {
			status_header( 413 );
			wp_die(
				esc_html__(
					'Package is too large for a single ZIP. Use “Save all to folder” or copy files via SFTP.',
					'the-exporter'
				)
			);
		}

		$base = Settings::migration_path( $migration_id, 'export' );
		$tmp  = wp_tempnam( 'te-bundle-' );
		if ( ! $tmp ) {
			status_header( 500 );
			wp_die( esc_html__( 'Could not create temporary file.', 'the-exporter' ) );
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			@unlink( $tmp );
			status_header( 500 );
			wp_die( esc_html__( 'Could not create ZIP archive.', 'the-exporter' ) );
		}

		foreach ( $files as $file ) {
			$full = trailingslashit( $base ) . $file['path'];
			$validated = DirectoryGuard::validate_path( $full, $base );
			if ( ! $validated || ! file_exists( $full ) ) {
				continue;
			}
			$entry = ! empty( $file['download_name'] ) ? $file['download_name'] : basename( $file['path'] );
			$zip->addFile( $full, $entry );
		}
		$zip->close();

		$label = $component ? $component : 'full';
		$filename = sprintf( 'the-exporter-%s-%s.zip', substr( $migration_id, 0, 8 ), $label );

		AuditLogger::log( 'transfer_bundle', 'ZIP bundle download: ' . $label, array(
			'migration_id' => $migration_id,
			'component'    => $component,
			'file_count'   => count( $files ),
		), 'info' );

		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $tmp ) );
		header( 'Cache-Control: no-cache' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $tmp );
		@unlink( $tmp );
		exit;
	}
}
