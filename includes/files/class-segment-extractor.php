<?php
/**
 * Extract and verify file segments.
 *
 * @package TheExporter
 */

namespace TheExporter\Files;

defined( 'ABSPATH' ) || exit;

/**
 * Class SegmentExtractor
 */
class SegmentExtractor {

	/**
	 * Extract segment to staging directory.
	 *
	 * @param string $segment_path Segment file path.
	 * @param string $staging_dir  Staging directory.
	 * @param array  $inventory    File inventory for L1 verification.
	 * @param bool   $dry_run      Dry run.
	 * @return array
	 */
	public static function extract( $segment_path, $staging_dir, array $inventory, $dry_run = false ) {
		if ( $dry_run ) {
			return array( 'success' => true, 'dry_run' => true, 'segment' => $segment_path );
		}

		wp_mkdir_p( $staging_dir );

		$lower = strtolower( $segment_path );
		$is_zst = ( false !== strpos( $lower, '.tar.zst' ) || false !== strpos( $lower, '.zst' ) );
		$is_gz  = ( false !== strpos( $lower, '.tar.gz' ) || false !== strpos( $lower, '.tgz' ) );
		$is_tar = ! $is_zst && ! $is_gz && ( false !== strpos( $lower, '.tar' ) );

		if ( $is_zst && \TheExporter\Runtime::exec_available() && \TheExporter\Runtime::command_exists( 'zstd' ) ) {
			$cmd = sprintf(
				'zstd -d %s -o %s 2>/dev/null',
				escapeshellarg( $segment_path ),
				escapeshellarg( $staging_dir . '/_segment.tar' )
			);
			exec( $cmd, $output, $code );
			if ( 0 !== $code ) {
				return array( 'success' => false, 'error' => 'zstd decompression failed' );
			}
			$segment_path = $staging_dir . '/_segment.tar';
			$is_tar       = true;
		}

		if ( class_exists( 'PharData' ) && ( $is_gz || $is_tar ) ) {
			try {
				$phar = new \PharData( $segment_path );
				$phar->extractTo( $staging_dir, null, true );
				self::apply_segment_path_map( $staging_dir );
			} catch ( \Exception $e ) {
				if ( \TheExporter\Runtime::exec_available() && \TheExporter\Runtime::command_exists( 'tar' ) ) {
					$flag = $is_gz ? '-xzf' : '-xf';
					$cmd  = sprintf(
						'tar %s %s -C %s 2>/dev/null',
						$flag,
						escapeshellarg( $segment_path ),
						escapeshellarg( $staging_dir )
					);
					exec( $cmd, $output, $code );
					if ( 0 !== $code ) {
						return array( 'success' => false, 'error' => $e->getMessage() );
					}
					self::apply_segment_path_map( $staging_dir );
				} else {
					return array( 'success' => false, 'error' => $e->getMessage() );
				}
			}
		} elseif ( \TheExporter\Runtime::exec_available() && \TheExporter\Runtime::command_exists( 'tar' ) ) {
			$flag = $is_gz ? '-xzf' : '-xf';
			$cmd  = sprintf(
				'tar %s %s -C %s 2>/dev/null',
				$flag,
				escapeshellarg( $segment_path ),
				escapeshellarg( $staging_dir )
			);
			exec( $cmd, $output, $code );
			if ( 0 !== $code ) {
				return array( 'success' => false, 'error' => 'tar extraction failed' );
			}
			self::apply_segment_path_map( $staging_dir );
		} else {
			return array( 'success' => false, 'error' => 'No tar extractor available' );
		}

		$errors = self::verify_extracted_files( $staging_dir, $inventory );
		if ( ! empty( $errors ) ) {
			return array( 'success' => false, 'errors' => $errors );
		}

		return array( 'success' => true, 'staging_dir' => $staging_dir );
	}

	/**
	 * Promote staged files to destination.
	 *
	 * @param string $staging_dir  Staging directory.
	 * @param string $dest_dir     Destination directory.
	 * @param bool   $confirm      Must be true to write.
	 * @return array
	 */
	public static function promote( $staging_dir, $dest_dir, $confirm = false ) {
		if ( ! $confirm ) {
			return array( 'success' => false, 'error' => 'Confirmation required' );
		}

		wp_mkdir_p( $dest_dir );
		$copied     = 0;
		$overwritten = 0;

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $staging_dir, \RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$rel  = ltrim( str_replace( $staging_dir, '', $file->getPathname() ), '/\\' );
			$dest = trailingslashit( $dest_dir ) . $rel;
			wp_mkdir_p( dirname( $dest ) );

			if ( file_exists( $dest ) ) {
				$overwritten++;
			}
			if ( self::can_rename_to( $file->getPathname(), $dest ) ) {
				if ( ! @rename( $file->getPathname(), $dest ) ) {
					copy( $file->getPathname(), $dest );
				}
			} else {
				copy( $file->getPathname(), $dest );
			}
			$copied++;
		}

		return array( 'success' => true, 'copied' => $copied, 'overwritten' => $overwritten );
	}

	/**
	 * Whether rename is safe (same volume, avoids 2× disk).
	 *
	 * @param string $source Source path.
	 * @param string $dest   Destination path.
	 * @return bool
	 */
	private static function can_rename_to( $source, $dest ) {
		if ( ! function_exists( 'disk_total_space' ) ) {
			return true;
		}
		$src_root = self::volume_root( $source );
		$dst_root = self::volume_root( $dest );
		return $src_root && $dst_root && $src_root === $dst_root;
	}

	/**
	 * Best-effort volume root for rename checks.
	 *
	 * @param string $path File path.
	 * @return string
	 */
	private static function volume_root( $path ) {
		$path = wp_normalize_path( $path );
		if ( function_exists( 'disk_total_space' ) && @disk_total_space( $path ) ) {
			return dirname( $path );
		}
		return '';
	}

	/**
	 * Restore original relative paths after tar segments that used short internal names.
	 *
	 * @param string $staging_dir Staging directory.
	 */
	private static function apply_segment_path_map( $staging_dir ) {
		$map_file = trailingslashit( $staging_dir ) . 'segment-map.json';
		if ( ! file_exists( $map_file ) ) {
			return;
		}

		$map = json_decode( file_get_contents( $map_file ), true );
		@unlink( $map_file );
		if ( ! is_array( $map ) ) {
			return;
		}

		foreach ( $map as $internal => $dest_rel ) {
			$src  = trailingslashit( $staging_dir ) . $internal;
			$dest = trailingslashit( $staging_dir ) . $dest_rel;
			if ( ! file_exists( $src ) ) {
				continue;
			}
			wp_mkdir_p( dirname( $dest ) );
			rename( $src, $dest );
		}

		$entry_dir = trailingslashit( $staging_dir ) . 'e';
		if ( is_dir( $entry_dir ) ) {
			@rmdir( $entry_dir );
		}
	}

	/**
	 * Verify extracted files against inventory L1 checksums.
	 *
	 * @param string $staging_dir Staging dir.
	 * @param array  $inventory   Inventory with files array.
	 * @return array Errors.
	 */
	private static function verify_extracted_files( $staging_dir, array $inventory ) {
		$mode = isset( $inventory['verification_mode'] ) ? $inventory['verification_mode'] : 'file';
		if ( 'segment' === $mode ) {
			return array();
		}

		$errors = array();
		$files  = isset( $inventory['files'] ) ? $inventory['files'] : array();
		$lookup = array();
		foreach ( $files as $f ) {
			$lookup[ $f['path'] ] = $f['sha256'];
		}

		foreach ( $lookup as $path => $expected ) {
			$full = trailingslashit( $staging_dir ) . $path;
			if ( ! file_exists( $full ) ) {
				continue; // File may be in another segment.
			}
			if ( ! \TheExporter\Validation\ChecksumService::verify_file( $full, $expected ) ) {
				$errors[] = "Checksum mismatch: {$path}";
			}
		}

		return $errors;
	}
}
