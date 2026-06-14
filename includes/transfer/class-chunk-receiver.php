<?php
/**
 * Assemble chunked server-to-server file uploads.
 *
 * @package TheExporter
 */

namespace TheExporter\Transfer;

use TheExporter\Logging\AuditLogger;
use TheExporter\Security\DirectoryGuard;
use TheExporter\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class ChunkReceiver
 */
class ChunkReceiver {

	/**
	 * Bytes present on import disk for a catalog path (.uploading or final file).
	 *
	 * @param string $migration_id  Migration ID.
	 * @param string $relative_path Relative path.
	 * @return int
	 */
	public static function bytes_on_disk( $migration_id, $relative_path ) {
		$migration_id  = sanitize_text_field( $migration_id );
		$relative_path = FileUploader::sanitize_relative_path_public( $relative_path );
		if ( '' === $migration_id || '' === $relative_path ) {
			return 0;
		}

		$base = Settings::migration_path( $migration_id, 'import' );
		if ( ! $base ) {
			return 0;
		}

		$dest     = trailingslashit( $base ) . $relative_path;
		$tmp_dest = $dest . '.uploading';

		clearstatcache( true, $dest );
		clearstatcache( true, $tmp_dest );

		if ( file_exists( $tmp_dest ) ) {
			return (int) filesize( $tmp_dest );
		}
		if ( file_exists( $dest ) ) {
			return (int) filesize( $dest );
		}

		return 0;
	}

	/**
	 * Chunk resume snapshot for export push (disk-authoritative).
	 *
	 * @param string $migration_id  Migration ID.
	 * @param string $relative_path Optional relative path; defaults to inflight path.
	 * @return array
	 */
	public static function chunk_status( $migration_id, $relative_path = '' ) {
		$migration_id = sanitize_text_field( $migration_id );
		$inflight     = TransferProgress::get_receive_inflight( $migration_id );
		$path         = '' !== $relative_path
			? FileUploader::sanitize_relative_path_public( $relative_path )
			: ( isset( $inflight['path'] ) ? sanitize_text_field( $inflight['path'] ) : '' );

		$bytes = '' !== $path ? self::bytes_on_disk( $migration_id, $path ) : 0;
		$total = 0;
		if ( ! empty( $inflight['path'] ) && $inflight['path'] === $path ) {
			$total = (int) ( $inflight['bytes_total'] ?? 0 );
		}

		return array(
			'migration_id'   => $migration_id,
			'path'           => $path,
			'bytes_on_disk'  => $bytes,
			'total_size'     => $total,
			'inflight'       => $inflight,
		);
	}

	/**
	 * Handle one chunk of a large file upload.
	 *
	 * @param string $migration_id  Migration ID.
	 * @param string $component     Component slug.
	 * @param array  $file          Uploaded chunk ($_FILES entry).
	 * @param array  $params        offset, total_size, part_index, part_total, relative_path, checksum, is_final.
	 * @return array
	 */
	public static function receive_chunk( $migration_id, $component, array $file, array $params ) {
		$migration_id  = sanitize_text_field( $migration_id );
		$component     = sanitize_key( $component );
		$relative_path = isset( $params['relative_path'] ) ? FileUploader::sanitize_relative_path_public( $params['relative_path'] ) : '';
		$offset        = isset( $params['offset'] ) ? (int) $params['offset'] : 0;
		$total_size    = isset( $params['total_size'] ) ? (int) $params['total_size'] : 0;
		$part_index    = isset( $params['part_index'] ) ? (int) $params['part_index'] : 0;
		$part_total    = isset( $params['part_total'] ) ? (int) $params['part_total'] : 0;
		$is_final      = ! empty( $params['is_final'] );
		$checksum      = isset( $params['checksum'] ) ? sanitize_text_field( $params['checksum'] ) : '';

		if ( ! PackageIndex::is_valid_component( $component ) || ! $relative_path ) {
			return array( 'success' => false, 'error' => 'Invalid chunk parameters' );
		}

		if ( empty( $file['tmp_name'] ) || ! is_readable( $file['tmp_name'] ) ) {
			return array( 'success' => false, 'error' => 'No chunk data received' );
		}

		$expected = PackageIndex::find_expected_file( $migration_id, $component, $relative_path, 'import' );
		if ( ! $expected && 'manifest' !== $component ) {
			$expected = PackageIndex::find_expected_file( $migration_id, $component, $relative_path, 'export' );
		}
		if ( ! $expected ) {
			return array( 'success' => false, 'error' => 'File not in transfer catalog: ' . $relative_path );
		}

		$relative_path = $expected['path'];
		if ( ! FileUploader::path_allowed_public( $relative_path, $component ) ) {
			return array( 'success' => false, 'error' => 'Path not allowed for component' );
		}

		$base     = Settings::migration_path( $migration_id, 'import' );
		$dest     = trailingslashit( $base ) . $relative_path;
		$tmp_dest = $dest . '.uploading';

		wp_mkdir_p( dirname( $dest ) );
		$validated = DirectoryGuard::validate_path( dirname( $dest ), $base );
		if ( false === $validated ) {
			return array( 'success' => false, 'error' => 'Invalid destination path' );
		}

		$in = fopen( $file['tmp_name'], 'rb' );
		if ( ! $in ) {
			return array( 'success' => false, 'error' => 'Could not read chunk data' );
		}

		if ( 0 === $offset && file_exists( $tmp_dest ) ) {
			@unlink( $tmp_dest );
		}
		if ( file_exists( $dest ) && 0 === $offset ) {
			@unlink( $dest );
		}

		$write = self::write_chunk_stream_at_offset( $tmp_dest, $offset, $in );
		fclose( $in );
		if ( empty( $write['success'] ) ) {
			$bytes_on_disk = self::bytes_on_disk( $migration_id, $relative_path );
			$write['bytes_on_disk'] = $bytes_on_disk;
			$write['total_size']    = $total_size;
			$write['path']          = $relative_path;
			if ( ! empty( $write['reset'] ) ) {
				TransferProgress::clear_receive_inflight( $migration_id );
			} elseif ( $bytes_on_disk > 0 && $total_size > 0 ) {
				TransferProgress::set_receive_inflight(
					$migration_id,
					$relative_path,
					$component,
					min( $total_size, $bytes_on_disk ),
					$total_size
				);
			}
			return $write;
		}

		$written       = (int) ( $write['written'] ?? 0 );
		$bytes_on_disk = self::bytes_on_disk( $migration_id, $relative_path );
		$bytes_done    = $total_size > 0 ? min( $total_size, $bytes_on_disk ) : $bytes_on_disk;
		TransferProgress::set_receive_inflight( $migration_id, $relative_path, $component, $bytes_done, $total_size );

		if ( ! $is_final ) {
			return array(
				'success'        => true,
				'part_ok'        => true,
				'bytes_received' => $bytes_done,
				'bytes_on_disk'  => $bytes_on_disk,
				'total_size'     => $total_size,
				'path'           => $relative_path,
			);
		}

		clearstatcache( true, $tmp_dest );
		$actual_size = file_exists( $tmp_dest ) ? (int) filesize( $tmp_dest ) : 0;
		if ( $total_size > 0 && $actual_size !== $total_size ) {
			@unlink( $tmp_dest );
			TransferProgress::clear_receive_inflight( $migration_id );
			return array( 'success' => false, 'error' => 'Assembled file size mismatch' );
		}

		if ( file_exists( $dest ) ) {
			@unlink( $dest );
		}
		if ( ! rename( $tmp_dest, $dest ) ) {
			if ( ! @copy( $tmp_dest, $dest ) ) {
				@unlink( $tmp_dest );
				TransferProgress::clear_receive_inflight( $migration_id );
				return array( 'success' => false, 'error' => 'Failed to finalize chunked upload' );
			}
			@unlink( $tmp_dest );
		}

		$expected_checksum = $checksum ?: ( $expected['checksum'] ?? '' );
		if ( $expected_checksum ) {
			VerifyQueue::enqueue( $migration_id, $component, $relative_path, $dest, $expected_checksum );
			VerifyWorker::ensure_running( $migration_id );
		}

		TransferProgress::clear_receive_inflight( $migration_id );
		$file_size = (int) filesize( $dest );
		TransferProgress::log_receive( $migration_id, $relative_path, $component, $file_size );

		AuditLogger::log( 'transfer_upload', 'Chunked upload complete: ' . $relative_path, array(
			'migration_id' => $migration_id,
			'component'    => $component,
			'parts'        => $part_total,
			'verify'       => 'deferred',
		), 'success' );

		return array(
			'success'        => true,
			'file_complete'  => true,
			'path'           => $relative_path,
			'size'           => $file_size,
			'bytes_on_disk'  => $file_size,
			'total_size'     => $total_size,
			'verify_pending' => (bool) $expected_checksum,
		);
	}

	/**
	 * Stream chunk bytes to an explicit offset (bounded memory).
	 *
	 * @param string   $tmp_dest Temp destination path.
	 * @param int      $offset   Byte offset.
	 * @param resource $in       Readable stream.
	 * @return array
	 */
	private static function write_chunk_stream_at_offset( $tmp_dest, $offset, $in ) {
		$offset = max( 0, (int) $offset );

		clearstatcache( true, $tmp_dest );
		$current = file_exists( $tmp_dest ) ? (int) filesize( $tmp_dest ) : 0;

		if ( $offset > 0 && $current !== $offset ) {
			if ( $current < $offset ) {
				return array(
					'success'       => false,
					'error'         => 'Chunk offset mismatch',
					'resume_from'   => $current,
					'expected'      => $offset,
					'actual'        => $current,
					'bytes_on_disk' => $current,
				);
			}
			@unlink( $tmp_dest );
			return array(
				'success'       => false,
				'error'         => 'Chunk offset mismatch',
				'reset'         => true,
				'expected'      => $offset,
				'actual'        => $current,
				'bytes_on_disk' => 0,
			);
		}

		if ( 0 === $offset && file_exists( $tmp_dest ) ) {
			@unlink( $tmp_dest );
			$current = 0;
		}

		$mode = ( 0 === $offset ) ? 'wb' : 'ab';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fh = fopen( $tmp_dest, $mode );
		if ( ! $fh ) {
			wp_mkdir_p( dirname( $tmp_dest ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$fh = fopen( $tmp_dest, $mode );
		}
		if ( ! $fh ) {
			return array( 'success' => false, 'error' => 'Failed writing chunk data' );
		}

		$written = 0;
		while ( ! feof( $in ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$buf = fread( $in, 1048576 );
			if ( false === $buf || '' === $buf ) {
				break;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			$w = fwrite( $fh, $buf );
			if ( false === $w ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $fh );
				@unlink( $tmp_dest );
				return array( 'success' => false, 'error' => 'Failed writing chunk data', 'reset' => true );
			}
			$written += (int) $w;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $fh );

		if ( 0 === $written ) {
			@unlink( $tmp_dest );
			return array( 'success' => false, 'error' => 'Empty chunk payload' );
		}

		return array( 'success' => true, 'written' => $written );
	}
}
