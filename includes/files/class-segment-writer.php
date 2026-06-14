<?php
/**
 * Write file segments as tar.gz chunks.
 *
 * @package TheExporter
 */

namespace TheExporter\Files;

defined( 'ABSPATH' ) || exit;

/**
 * Class SegmentWriter
 */
class SegmentWriter {

	/**
	 * USTAR tar path limit used by PHP PharData.
	 */
	private const TAR_PATH_MAX_BYTES = 100;

	/**
	 * Internal tar entry prefix when original paths exceed USTAR limits.
	 */
	private const TAR_ENTRY_PREFIX = 'e/';

	/**
	 * Manifest file stored inside tar segments that use short internal paths.
	 */
	private const TAR_SEGMENT_MAP = 'segment-map.json';

	/**
	 * Create segments from file list.
	 *
	 * @param array    $files      File entries with path and size.
	 * @param string   $source_dir Source base directory.
	 * @param string   $output_dir Segments output directory.
	 * @param int|null $chunk_size Max segment size in bytes.
	 * @param array    $options    on_segment, on_file_progress, on_write_progress, on_heartbeat, defer_hash, skip_hash, start_index.
	 * @return array{chunks: array, files: array}
	 */
	public static function create_segments( array $files, $source_dir, $output_dir, $chunk_size = null, array $options = array() ) {
		$defaults = array(
			'on_segment'         => null,
			'on_file_progress'   => null,
			'on_write_progress'  => null,
			'on_heartbeat'       => null,
			'defer_hash'         => true,
			'skip_hash'          => null,
			'start_index'        => 1,
			'jsonl_path'         => null,
		);
		$options    = wp_parse_args( $options, $defaults );
		$chunk_size = $chunk_size ?: (int) \TheExporter\Settings::effective_segment_size();
		$max_files  = (int) \TheExporter\Settings::effective_max_files_per_segment();
		wp_mkdir_p( $output_dir . '/segments' );

		if ( null === $options['skip_hash'] ) {
			$options['skip_hash'] = \TheExporter\Settings::is_fast_export();
		}

		$total_bytes_est = 0;
		foreach ( $files as $f ) {
			$total_bytes_est += isset( $f['size'] ) ? (int) $f['size'] : 0;
		}
		$estimated_segments = \TheExporter\Settings::estimate_segment_count( $total_bytes_est, count( $files ) );

		$chunks       = array();
		$packed_files = array();
		$segment_idx  = (int) $options['start_index'];
		$current_size = 0;
		$batch        = array();
		$files_packed = 0;
		$files_total  = count( $files );
		$files_queued = 0;

		$pack_options = array(
			'skip_hash'         => (bool) $options['skip_hash'],
			'defer_hash'        => (bool) $options['defer_hash'],
			'on_write_progress' => $options['on_write_progress'],
			'on_heartbeat'      => $options['on_heartbeat'],
		);

		foreach ( $files as $file ) {
			$files_queued++;
			$file_size = isset( $file['size'] ) ? (int) $file['size'] : 0;

			$batch_full = ! empty( $batch ) && (
				$current_size + $file_size > $chunk_size ||
				count( $batch ) >= $max_files
			);

			if ( $batch_full ) {
				$chunk = self::pack_segment( $batch, $source_dir, $output_dir, $segment_idx, $pack_options );
				if ( $chunk ) {
					self::collect_packed_files( $chunk, $packed_files, $options['jsonl_path'] );
					unset( $chunk['hashed_files'] );
					$chunks[] = $chunk;
					$files_packed += count( $batch );
					if ( is_callable( $options['on_segment'] ) ) {
						call_user_func( $options['on_segment'], count( $chunks ), $estimated_segments, $chunk, $files_packed, $files_queued );
					}
				}
				$segment_idx++;
				$batch        = array();
				$current_size = 0;
			}

			$batch[]       = $file;
			$current_size += $file_size;

			if ( is_callable( $options['on_file_progress'] ) && 0 === $files_queued % 25 ) {
				call_user_func( $options['on_file_progress'], $files_queued, $files_total, $files_packed );
			}
		}

		if ( ! empty( $batch ) ) {
			$chunk = self::pack_segment( $batch, $source_dir, $output_dir, $segment_idx, $pack_options );
			if ( $chunk ) {
				self::collect_packed_files( $chunk, $packed_files, $options['jsonl_path'] );
				unset( $chunk['hashed_files'] );
				$chunks[] = $chunk;
				$files_packed += count( $batch );
				if ( is_callable( $options['on_segment'] ) ) {
					call_user_func( $options['on_segment'], count( $chunks ), $estimated_segments, $chunk, $files_packed, $files_queued );
				}
			}
		}

		if ( is_callable( $options['on_file_progress'] ) ) {
			call_user_func( $options['on_file_progress'], $files_total, $files_total, $files_packed );
		}

		return array(
			'chunks' => $chunks,
			'files'  => $packed_files,
		);
	}

	/**
	 * Pack a single tar.gz segment (public for incremental export).
	 *
	 * @param array  $batch       Files in segment.
	 * @param string $source_dir  Source directory.
	 * @param string $output_dir  Output directory.
	 * @param int    $index       Segment index.
	 * @param array  $options     skip_hash, defer_hash, on_write_progress, on_heartbeat.
	 * @return array|null
	 */
	public static function pack_segment( array $batch, $source_dir, $output_dir, $index, array $options = array() ) {
		if ( empty( $batch ) ) {
			return null;
		}

		wp_mkdir_p( $output_dir . '/segments' );

		$defaults = array(
			'skip_hash'         => null,
			'defer_hash'        => true,
			'on_write_progress' => null,
			'on_heartbeat'      => null,
		);
		$options = wp_parse_args( $options, $defaults );

		if ( null === $options['skip_hash'] ) {
			$options['skip_hash'] = \TheExporter\Settings::is_fast_export();
		}

		$hash_total = count( $batch );
		if ( ! $options['skip_hash'] && $options['defer_hash'] ) {
			$hashed_batch = array();
			$hash_done    = 0;
			foreach ( $batch as $file ) {
				$hashed_batch[] = InventoryBuilder::ensure_hash( $file, $source_dir );
				$hash_done++;
				if ( 0 === $hash_done % 50 && is_callable( $options['on_write_progress'] ) ) {
					call_user_func( $options['on_write_progress'], 'hashing', $hash_done, $hash_total );
				}
				if ( 0 === $hash_done % 50 && is_callable( $options['on_heartbeat'] ) ) {
					call_user_func( $options['on_heartbeat'] );
				}
			}
			$batch = $hashed_batch;
			if ( is_callable( $options['on_write_progress'] ) ) {
				call_user_func( $options['on_write_progress'], 'hashing', $hash_total, $hash_total );
			}
		}

		if ( is_callable( $options['on_write_progress'] ) ) {
			call_user_func( $options['on_write_progress'], 'compressing', 0, $hash_total );
		}
		if ( is_callable( $options['on_heartbeat'] ) ) {
			call_user_func( $options['on_heartbeat'] );
		}

		$compression = \TheExporter\EnvironmentProfile::effective_compression();
		$ext         = \TheExporter\EnvironmentProfile::segment_extension( $compression );
		$filename    = sprintf( 'segment-%05d%s', $index, $ext );
		$rel_path    = 'segments/' . $filename;
		$full        = $output_dir . '/' . $rel_path;

		@unlink( $full );

		$packed = self::write_segment_archive( $batch, $source_dir, $full, $compression, $index, $output_dir, $options );
		if ( ! $packed ) {
			return null;
		}

		if ( ! file_exists( $full ) ) {
			return null;
		}

		if ( is_callable( $options['on_write_progress'] ) ) {
			call_user_func( $options['on_write_progress'], 'compressing', $hash_total, $hash_total );
		}

		$checksum = \TheExporter\Validation\ChecksumService::write_sidecar( $full );
		$max      = \TheExporter\Settings::get( 'browser_transfer_max_bytes', 67108864 );
		$size     = filesize( $full );
		return array(
			'index'         => $index,
			'path'          => basename( dirname( $rel_path ) ) . '/' . $filename,
			'size'          => $size,
			'checksum'      => $checksum,
			'transfer_safe' => $size <= $max,
			'files'         => array_column( $batch, 'path' ),
			'file_count'    => count( $batch ),
			'hashed_files'  => $batch,
			'compression'   => $compression,
		);
	}

	/**
	 * Write segment archive using best available pack method.
	 *
	 * @param array  $batch       Files.
	 * @param string $source_dir  Source directory.
	 * @param string $full        Output path.
	 * @param string $compression Compression mode.
	 * @param int    $index       Segment index.
	 * @param string $output_dir  Component output dir.
	 * @param array  $options     Heartbeat callbacks.
	 * @return bool
	 */
	private static function write_segment_archive( array $batch, $source_dir, $full, $compression, $index, $output_dir, array $options ) {
		if ( self::prefer_shell_tar() ) {
			self::write_segment_shell( $batch, $source_dir, $full, $compression );
			return true;
		}

		// Streamed PHP tar is much faster than PharData for large batches (Studio / shared hosting).
		if ( self::write_segment_php_tar( $batch, $source_dir, $full, $compression, $options ) ) {
			return true;
		}

		if ( self::can_write_phar() && 'zstd' !== $compression ) {
			$tar_path = $output_dir . '/segments/_tmp_' . $index . '.tar';
			@unlink( $tar_path );
			@unlink( $full );

			try {
				$tar = new \PharData( $tar_path );
				$map = self::add_files_to_phar_tar( $tar, $batch, $source_dir, $options );
				if ( ! empty( $map ) ) {
					$map_file = $output_dir . '/segments/_map_' . $index . '.json';
					file_put_contents( $map_file, wp_json_encode( $map ) );
					$tar->addFile( $map_file, self::TAR_SEGMENT_MAP );
					@unlink( $map_file );
				}
				if ( 'store' === $compression ) {
					copy( $tar_path, $full );
				} else {
					$tar->compress( \Phar::GZ );
				}
				unset( $tar );
			} catch ( \Exception $e ) {
				@unlink( $tar_path );
				@unlink( $full );
				if ( self::shell_tar_available() ) {
					self::write_segment_shell( $batch, $source_dir, $full, $compression );
					return true;
				}
				return self::write_segment_php_tar( $batch, $source_dir, $full, $compression, $options );
			}
			@unlink( $tar_path );

			if ( ! file_exists( $full ) && file_exists( $tar_path . '.gz' ) ) {
				rename( $tar_path . '.gz', $full );
			}
			if ( file_exists( $full ) ) {
				return true;
			}
		}

		if ( self::shell_tar_available() ) {
			self::write_segment_shell( $batch, $source_dir, $full, $compression );
			return true;
		}

		return false;
	}

	/**
	 * Pack segment with pure PHP tar writer.
	 *
	 * @param array  $batch       Files.
	 * @param string $source_dir  Source directory.
	 * @param string $full        Output path.
	 * @param string $compression Compression mode.
	 * @param array  $options     Options.
	 * @return bool
	 */
	private static function write_segment_php_tar( array $batch, $source_dir, $full, $compression, array $options ) {
		$tar_path = preg_replace( '/\.gz$/', '', preg_replace( '/\.zst$/', '', $full ) );
		if ( $tar_path === $full && 'store' !== $compression ) {
			$tar_path = preg_replace( '/\.tar\.[^.]+$/', '.tar', $full );
		}
		if ( substr( $tar_path, -4 ) !== '.tar' ) {
			$tar_path .= '.tar';
		}

		@unlink( $tar_path );
		@unlink( $full );

		if ( ! PhpTarWriter::write_tar( $batch, $source_dir, $tar_path, $options ) ) {
			return false;
		}

		if ( 'store' === $compression ) {
			if ( $tar_path !== $full ) {
				rename( $tar_path, $full );
			}
			return file_exists( $full );
		}

		$level = ( 'gzip' === $compression ) ? 6 : 1;
		if ( ! PhpTarWriter::gzip_file( $tar_path, $full, $level ) ) {
			@unlink( $tar_path );
			return false;
		}
		@unlink( $tar_path );
		return file_exists( $full );
	}

	/**
	 * Collect hashed file metadata without array_merge on huge lists.
	 *
	 * @param array       $chunk        Segment chunk.
	 * @param array       $packed_files Accumulator (by reference).
	 * @param string|null $jsonl_path   Optional jsonl append path.
	 */
	private static function collect_packed_files( array $chunk, array &$packed_files, $jsonl_path = null ) {
		if ( empty( $chunk['hashed_files'] ) ) {
			return;
		}
		if ( $jsonl_path ) {
			foreach ( $chunk['hashed_files'] as $file ) {
				InventoryBuilder::append_jsonl( $jsonl_path, $file );
			}
			return;
		}
		foreach ( $chunk['hashed_files'] as $file ) {
			$packed_files[] = $file;
		}
	}

	/**
	 * Whether shell tar is preferred over PharData.
	 *
	 * @return bool
	 */
	private static function prefer_shell_tar() {
		$profile = \TheExporter\EnvironmentProfile::detect();
		return ! empty( $profile['tar'] ) && self::shell_tar_available();
	}

	/**
	 * Whether shell tar can run on this host.
	 *
	 * @return bool
	 */
	private static function shell_tar_available() {
		if ( 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) ) {
			return false;
		}
		return \TheExporter\Runtime::exec_available() && \TheExporter\Runtime::command_exists( 'tar' );
	}

	/**
	 * Whether Phar archives can be created.
	 *
	 * @return bool
	 */
	private static function can_write_phar() {
		return class_exists( 'PharData', false );
	}

	/**
	 * Whether a relative path fits in USTAR tar headers (PharData).
	 *
	 * @param string $path Relative path.
	 * @return bool
	 */
	private static function tar_path_fits( $path ) {
		return strlen( $path ) <= self::TAR_PATH_MAX_BYTES;
	}

	/**
	 * Add batch files to a Phar tar, using short internal paths when needed.
	 *
	 * @param \PharData $tar        Tar archive.
	 * @param array     $batch      Files in segment.
	 * @param string    $source_dir Source directory.
	 * @param array     $options    on_heartbeat.
	 * @return array Path map when short internal names were used.
	 */
	private static function add_files_to_phar_tar( \PharData $tar, array $batch, $source_dir, array $options = array() ) {
		$use_short_paths = false;
		foreach ( $batch as $file ) {
			if ( ! self::tar_path_fits( $file['path'] ) ) {
				$use_short_paths = true;
				break;
			}
		}

		$map       = array();
		$entry_idx = 0;
		$added     = 0;
		foreach ( $batch as $file ) {
			$rel     = $file['path'];
			$full_fp = trailingslashit( $source_dir ) . $rel;
			if ( ! file_exists( $full_fp ) ) {
				continue;
			}

			if ( $use_short_paths ) {
				$entry_idx++;
				$internal         = sprintf( self::TAR_ENTRY_PREFIX . '%07d', $entry_idx );
				$map[ $internal ] = $rel;
				$tar->addFile( $full_fp, $internal );
			} else {
				$tar->addFile( $full_fp, $rel );
			}

			$added++;
			if ( 0 === $added % 10 && is_callable( $options['on_write_progress'] ?? null ) ) {
				call_user_func( $options['on_write_progress'], 'compressing', $added, count( $batch ) );
			}
			if ( 0 === $added % 25 && is_callable( $options['on_heartbeat'] ?? null ) ) {
				call_user_func( $options['on_heartbeat'] );
			}
		}

		return $map;
	}

	/**
	 * Shell tar fallback with fast gzip when configured.
	 *
	 * @param array  $batch      Files.
	 * @param string $source_dir Source dir.
	 * @param string $output     Output path.
	 */
	private static function write_segment_shell( array $batch, $source_dir, $output, $compression = 'gzip_fast' ) {
		wp_mkdir_p( dirname( $output ) );
		$list_file = dirname( $output ) . '/_filelist_' . wp_generate_password( 6, false ) . '.txt';
		$paths     = array();
		foreach ( $batch as $file ) {
			$paths[] = $file['path'];
		}
		file_put_contents( $list_file, implode( "\n", $paths ) );

		$compress = self::shell_compress_flag( $compression );
		$cmd      = sprintf(
			'cd %s && tar %s %s -T %s',
			escapeshellarg( $source_dir ),
			$compress,
			escapeshellarg( $output ),
			escapeshellarg( $list_file )
		);
		exec( $cmd, $output_lines, $code );
		@unlink( $list_file );

		if ( 0 !== $code || ! file_exists( $output ) ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %d: tar exit code */
					__( 'Shell tar failed (exit code %d).', 'the-exporter' ),
					(int) $code
				)
			);
		}
	}

	/**
	 * Tar compression flags for shell tar.
	 *
	 * @param string $compression Compression mode.
	 * @return string
	 */
	private static function shell_compress_flag( $compression = 'gzip_fast' ) {
		if ( 'store' === $compression ) {
			return '-cf';
		}
		if ( 'zstd' === $compression && \TheExporter\Runtime::command_exists( 'zstd' ) ) {
			return "--use-compress-program='zstd -1' -cf";
		}
		if ( 'gzip' === $compression ) {
			if ( \TheExporter\Runtime::command_exists( 'pigz' ) ) {
				return "--use-compress-program='pigz' -cf";
			}
			return '-czf';
		}
		if ( 'normal' === \TheExporter\Settings::compression_level() ) {
			return '-czf';
		}
		if ( \TheExporter\Runtime::command_exists( 'pigz' ) ) {
			return "--use-compress-program='pigz -1' -cf";
		}
		return "--use-compress-program='gzip -1' -cf";
	}
}
