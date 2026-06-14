<?php
/**
 * File inventory builder with streaming checksums.
 *
 * @package TheExporter
 */

namespace TheExporter\Files;

defined( 'ABSPATH' ) || exit;

/**
 * Class InventoryBuilder
 */
class InventoryBuilder {

	/**
	 * Build file list for directory.
	 *
	 * @param string $source_dir Source directory.
	 * @param string $base_dir   Base for relative paths.
	 * @param array  $options    defer_hash, on_progress callable( int $count ).
	 * @return array Files with path, size, mtime, sha256 (optional).
	 */
	public static function scan( $source_dir, $base_dir = '', array $options = array() ) {
		$defaults = array(
			'defer_hash'  => false,
			'on_progress' => null,
		);
		$options  = wp_parse_args( $options, $defaults );

		$base_dir = $base_dir ?: $source_dir;
		$files    = array();
		$exclude  = \TheExporter\Settings::get( 'exclude_patterns', array() );
		$count    = 0;

		if ( ! is_dir( $source_dir ) ) {
			return $files;
		}

		if ( self::can_shell_scan() ) {
			return self::scan_shell( $source_dir, $base_dir, $exclude, $options );
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $source_dir, \RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$full_path = $file->getPathname();
			$relative  = ltrim( str_replace( wp_normalize_path( $base_dir ), '', wp_normalize_path( $full_path ) ), '/\\' );

			if ( self::matches_exclude( $relative, $exclude ) ) {
				continue;
			}

			$entry = array(
				'path'  => str_replace( '\\', '/', $relative ),
				'size'  => $file->getSize(),
				'mtime' => $file->getMTime(),
			);

			if ( ! $options['defer_hash'] && ! \TheExporter\Settings::is_fast_export() ) {
				$entry['sha256'] = \TheExporter\Validation\ChecksumService::hash_file( $full_path );
			}

			$files[] = $entry;
			$count++;

			if ( $count % 100 === 0 && is_callable( $options['on_progress'] ) ) {
				call_user_func( $options['on_progress'], $count );
			}
		}

		if ( is_callable( $options['on_progress'] ) ) {
			call_user_func( $options['on_progress'], $count );
		}

		return $files;
	}

	/**
	 * Stream scan results to inventory.jsonl on disk.
	 *
	 * @param string $source_dir Source directory.
	 * @param string $base_dir   Base for relative paths.
	 * @param string $jsonl_path Output jsonl path.
	 * @param array  $options    resume, on_progress.
	 * @return array{files_total: int, bytes_total: int}
	 */
	public static function scan_to_jsonl( $source_dir, $base_dir, $jsonl_path, array $options = array() ) {
		$defaults = array(
			'resume'                 => false,
			'on_progress'            => null,
			'extra_exclude_prefixes' => array(),
		);
		$options  = wp_parse_args( $options, $defaults );
		$base_dir = $base_dir ?: $source_dir;

		if ( ! $options['resume'] && file_exists( $jsonl_path ) ) {
			@unlink( $jsonl_path );
		}

		wp_mkdir_p( dirname( $jsonl_path ) );

		$exclude = \TheExporter\Settings::get( 'exclude_patterns', array() );
		$prefix_excludes = array_filter( array_map( 'strval', (array) $options['extra_exclude_prefixes'] ) );
		$count   = 0;
		$bytes   = 0;

		$skip_relative = function ( $relative ) use ( $exclude, $prefix_excludes ) {
			if ( self::matches_exclude( $relative, $exclude ) ) {
				return true;
			}
			$relative = ltrim( str_replace( '\\', '/', $relative ), '/' );
			foreach ( $prefix_excludes as $prefix ) {
				$prefix = ltrim( str_replace( '\\', '/', $prefix ), '/' );
				if ( '' !== $prefix && 0 === strpos( $relative, $prefix ) ) {
					return true;
				}
			}
			return false;
		};

		if ( ! is_dir( $source_dir ) ) {
			return array( 'files_total' => 0, 'bytes_total' => 0 );
		}

		$write_entry = function ( array $entry ) use ( $jsonl_path, &$count, &$bytes, $options ) {
			self::append_jsonl( $jsonl_path, $entry );
			$count++;
			$bytes += (int) $entry['size'];
			if ( 0 === $count % 100 && is_callable( $options['on_progress'] ) ) {
				call_user_func( $options['on_progress'], $count );
			}
		};

		if ( self::can_shell_scan() ) {
			$source_dir = wp_normalize_path( $source_dir );
			$base_dir   = wp_normalize_path( $base_dir );
			$cmd        = sprintf( 'find %s -type f 2>/dev/null', escapeshellarg( $source_dir ) );
			exec( $cmd, $paths );
			foreach ( $paths as $full_path ) {
				$full_path = wp_normalize_path( trim( $full_path ) );
				if ( '' === $full_path || ! is_file( $full_path ) ) {
					continue;
				}
				$relative = ltrim( str_replace( $base_dir, '', $full_path ), '/' );
				if ( $skip_relative( $relative ) ) {
					continue;
				}
				$write_entry( array(
					'path'  => str_replace( '\\', '/', $relative ),
					'size'  => filesize( $full_path ),
					'mtime' => filemtime( $full_path ),
				) );
			}
		} else {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $source_dir, \RecursiveDirectoryIterator::SKIP_DOTS )
			);
			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}
				$full_path = $file->getPathname();
				$relative  = ltrim( str_replace( wp_normalize_path( $base_dir ), '', wp_normalize_path( $full_path ) ), '/\\' );
				if ( $skip_relative( $relative ) ) {
					continue;
				}
				$write_entry( array(
					'path'  => str_replace( '\\', '/', $relative ),
					'size'  => $file->getSize(),
					'mtime' => $file->getMTime(),
				) );
			}
		}

		if ( is_callable( $options['on_progress'] ) ) {
			call_user_func( $options['on_progress'], $count );
		}

		return array(
			'files_total' => $count,
			'bytes_total' => $bytes,
		);
	}

	/**
	 * Append one inventory entry as JSONL.
	 *
	 * @param string $path  Jsonl file path.
	 * @param array  $entry File entry.
	 */
	public static function append_jsonl( $path, array $entry ) {
		file_put_contents( $path, wp_json_encode( $entry ) . "\n", FILE_APPEND );
	}

	/**
	 * Read next file batch from jsonl using dual byte/file caps.
	 *
	 * @param string $jsonl_path Jsonl path.
	 * @param int    $offset     Line offset (0-based).
	 * @param int    $max_files  Max files in batch.
	 * @param int    $max_bytes  Max bytes in batch.
	 * @param int    $byte_offset Optional byte offset into JSONL (avoids O(n²) line skip).
	 * @return array{batch: array, offset: int, byte_offset: int, eof: bool}
	 */
	public static function read_jsonl_batch( $jsonl_path, $offset, $max_files, $max_bytes, $byte_offset = 0 ) {
		$batch        = array();
		$current_size = 0;
		$line         = 0;

		if ( ! file_exists( $jsonl_path ) ) {
			return array( 'batch' => array(), 'offset' => $offset, 'byte_offset' => 0, 'eof' => true );
		}

		$handle = fopen( $jsonl_path, 'rb' );
		if ( ! $handle ) {
			return array( 'batch' => array(), 'offset' => $offset, 'byte_offset' => 0, 'eof' => true );
		}

		if ( $byte_offset > 0 ) {
			fseek( $handle, $byte_offset );
			$line = (int) $offset;
		} else {
			while ( $line < $offset && ( $row = fgets( $handle ) ) !== false ) {
				$line++;
			}
		}

		while ( ( $row = fgets( $handle ) ) !== false ) {
			$row_start = ftell( $handle ) - strlen( $row );
			$entry     = json_decode( trim( $row ), true );
			if ( ! is_array( $entry ) || empty( $entry['path'] ) ) {
				$line++;
				continue;
			}

			$file_size = isset( $entry['size'] ) ? (int) $entry['size'] : 0;
			if ( ! empty( $batch ) && ( $current_size + $file_size > $max_bytes || count( $batch ) >= $max_files ) ) {
				// Rewind: fgets consumed this line but it belongs in the next batch.
				fseek( $handle, $row_start );
				break;
			}

			$batch[]       = $entry;
			$current_size += $file_size;
			$line++;

			if ( count( $batch ) >= $max_files || $current_size >= $max_bytes ) {
				break;
			}
		}

		$eof = feof( $handle );
		$next_byte = ftell( $handle );
		fclose( $handle );

		return array(
			'batch'       => $batch,
			'offset'      => $line,
			'byte_offset' => (int) $next_byte,
			'eof'         => $eof,
		);
	}

	/**
	 * Count lines in jsonl file.
	 *
	 * @param string $jsonl_path Jsonl path.
	 * @return int
	 */
	public static function count_jsonl_lines( $jsonl_path ) {
		if ( ! file_exists( $jsonl_path ) ) {
			return 0;
		}
		$count  = 0;
		$handle = fopen( $jsonl_path, 'rb' );
		if ( ! $handle ) {
			return 0;
		}
		while ( fgets( $handle ) !== false ) {
			$count++;
		}
		fclose( $handle );
		return $count;
	}

	/**
	 * Load all entries from jsonl into array (for final inventory).
	 *
	 * @param string $jsonl_path Jsonl path.
	 * @return array
	 */
	public static function load_jsonl( $jsonl_path ) {
		$files = array();
		if ( ! file_exists( $jsonl_path ) ) {
			return $files;
		}
		$handle = fopen( $jsonl_path, 'rb' );
		if ( ! $handle ) {
			return $files;
		}
		while ( ( $row = fgets( $handle ) ) !== false ) {
			$entry = json_decode( trim( $row ), true );
			if ( is_array( $entry ) && ! empty( $entry['path'] ) ) {
				$files[] = $entry;
			}
		}
		fclose( $handle );
		return $files;
	}

	/**
	 * Append chunk metadata to chunks.json.
	 *
	 * @param string $component_dir Component directory.
	 * @param array  $chunk         Chunk metadata.
	 */
	public static function append_chunk_manifest( $component_dir, array $chunk ) {
		$jsonl = trailingslashit( $component_dir ) . 'chunks.jsonl';
		file_put_contents( $jsonl, wp_json_encode( $chunk ) . "\n", FILE_APPEND | LOCK_EX );

		$path   = trailingslashit( $component_dir ) . 'chunks.json';
		$chunks = self::load_chunk_manifest( $component_dir );
		$chunks[] = $chunk;
		file_put_contents( $path, wp_json_encode( $chunks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Load incremental chunks manifest.
	 *
	 * @param string $component_dir Component directory.
	 * @return array
	 */
	public static function load_chunk_manifest( $component_dir ) {
		$jsonl = trailingslashit( $component_dir ) . 'chunks.jsonl';
		if ( file_exists( $jsonl ) ) {
			$chunks = array();
			$handle = fopen( $jsonl, 'rb' );
			if ( $handle ) {
				while ( ( $row = fgets( $handle ) ) !== false ) {
					$entry = json_decode( trim( $row ), true );
					if ( is_array( $entry ) ) {
						$chunks[] = $entry;
					}
				}
				fclose( $handle );
			}
			if ( ! empty( $chunks ) ) {
				return $chunks;
			}
		}

		$path = trailingslashit( $component_dir ) . 'chunks.json';
		if ( ! file_exists( $path ) ) {
			return array();
		}
		$decoded = json_decode( file_get_contents( $path ), true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Build final inventory.json from jsonl + chunks.
	 *
	 * @param string $component_dir     Component output directory.
	 * @param string $component         Component name.
	 * @param array  $chunks            Chunk rows.
	 * @param string $verification_mode segment|file.
	 */
	public static function finalize_inventory( $component_dir, $component, array $chunks, $verification_mode = 'segment' ) {
		$jsonl_path = trailingslashit( $component_dir ) . 'inventory.jsonl';
		$files      = self::load_jsonl( $jsonl_path );
		$total_bytes = 0;
		foreach ( $chunks as $chunk ) {
			$total_bytes += isset( $chunk['size'] ) ? (int) $chunk['size'] : 0;
		}

		$inventory = array(
			'component'          => $component,
			'verification_mode'  => $verification_mode,
			'files'              => $files,
			'chunks'             => $chunks,
			'total_bytes'        => $total_bytes,
		);
		self::save( $component_dir, $inventory );
	}

	/**
	 * Whether native find scan is available.
	 *
	 * @return bool
	 */
	public static function can_shell_scan() {
		if ( 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) ) {
			return false;
		}
		return \TheExporter\Runtime::command_exists( 'find' );
	}

	/**
	 * Shell find scan returning in-memory list (used by scan()).
	 *
	 * @param string $source_dir Source directory.
	 * @param string $base_dir   Base directory.
	 * @param array  $exclude    Exclude patterns.
	 * @param array  $options    Scan options.
	 * @return array
	 */
	private static function scan_shell( $source_dir, $base_dir, array $exclude, array $options ) {
		$files      = array();
		$count      = 0;
		$source_dir = wp_normalize_path( $source_dir );
		$base_dir   = wp_normalize_path( $base_dir );
		$cmd        = sprintf( 'find %s -type f 2>/dev/null', escapeshellarg( $source_dir ) );
		exec( $cmd, $paths );

		foreach ( $paths as $full_path ) {
			$full_path = wp_normalize_path( trim( $full_path ) );
			if ( '' === $full_path || ! is_file( $full_path ) ) {
				continue;
			}
			$relative = ltrim( str_replace( $base_dir, '', $full_path ), '/' );
			if ( self::matches_exclude( $relative, $exclude ) ) {
				continue;
			}
			$entry = array(
				'path'  => str_replace( '\\', '/', $relative ),
				'size'  => filesize( $full_path ),
				'mtime' => filemtime( $full_path ),
			);
			if ( ! $options['defer_hash'] && ! \TheExporter\Settings::is_fast_export() ) {
				$entry['sha256'] = \TheExporter\Validation\ChecksumService::hash_file( $full_path );
			}
			$files[] = $entry;
			$count++;
			if ( $count % 100 === 0 && is_callable( $options['on_progress'] ) ) {
				call_user_func( $options['on_progress'], $count );
			}
		}

		if ( is_callable( $options['on_progress'] ) ) {
			call_user_func( $options['on_progress'], $count );
		}

		return $files;
	}

	/**
	 * Ensure file entry has sha256 hash.
	 *
	 * @param array  $file       File entry.
	 * @param string $source_dir Source base.
	 * @return array
	 */
	public static function ensure_hash( array $file, $source_dir ) {
		if ( \TheExporter\Settings::is_fast_export() ) {
			return $file;
		}
		if ( ! empty( $file['sha256'] ) ) {
			return $file;
		}
		$full = trailingslashit( $source_dir ) . $file['path'];
		$file['sha256'] = \TheExporter\Validation\ChecksumService::hash_file( $full );
		return $file;
	}

	/**
	 * Check if path matches exclude pattern.
	 *
	 * @param string $path    Relative path.
	 * @param array  $patterns Patterns.
	 * @return bool
	 */
	public static function matches_exclude( $path, array $patterns ) {
		$path = ltrim( str_replace( '\\', '/', (string) $path ), '/' );
		foreach ( $patterns as $pattern ) {
			$pattern = ltrim( str_replace( '\\', '/', (string) $pattern ), '/' );
			if ( '' === $pattern ) {
				continue;
			}
			$regex = '#^' . str_replace(
				array( '\*\*', '\*' ),
				array( '.*', '[^/]*' ),
				preg_quote( $pattern, '#' )
			) . '$#i';
			if ( preg_match( $regex, $path ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Save inventory JSON.
	 *
	 * @param string $output_dir Output directory.
	 * @param array  $inventory  Inventory data.
	 */
	public static function save( $output_dir, array $inventory ) {
		wp_mkdir_p( $output_dir );
		file_put_contents(
			$output_dir . '/inventory.json',
			wp_json_encode( $inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
		);
	}
}
