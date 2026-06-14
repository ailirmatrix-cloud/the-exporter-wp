<?php
/**
 * Browser upload of migration package files.
 *
 * @package TheExporter
 */

namespace TheExporter\Transfer;

use TheExporter\Logging\AuditLogger;
use TheExporter\Security\DirectoryGuard;
use TheExporter\Settings;
use TheExporter\Validation\ChecksumService;

defined( 'ABSPATH' ) || exit;

/**
 * Class FileUploader
 */
class FileUploader {

	/**
	 * Handle file upload for a component.
	 *
	 * @param string $migration_id  Migration ID.
	 * @param string $component     Component or manifest.
	 * @param string $relative_path Relative path within migration.
	 * @param array  $file          $_FILES entry.
	 * @param string $checksum      Expected checksum.
	 * @param array  $options       Options (server_transfer).
	 * @return array
	 */
	public static function upload( $migration_id, $component, $relative_path, array $file, $checksum = '', array $options = array() ) {
		$migration_id  = sanitize_text_field( $migration_id );
		$component     = sanitize_key( $component );
		$upload_name   = isset( $file['name'] ) ? PackageIndex::sanitize_upload_basename( $file['name'] ) : '';

		if ( ! PackageIndex::is_valid_component( $component ) ) {
			return array( 'success' => false, 'error' => 'Invalid component' );
		}

		if ( empty( $file['tmp_name'] ) ) {
			return array( 'success' => false, 'error' => 'No file uploaded' );
		}

		// REST multipart uploads may not pass is_uploaded_file() in all PHP/SAPI setups.
		if ( ! is_uploaded_file( $file['tmp_name'] ) && ! is_readable( $file['tmp_name'] ) ) {
			return array( 'success' => false, 'error' => 'Invalid upload temp file' );
		}

		$limits = Settings::php_upload_limits();
		$php_max = min( $limits['upload_max_filesize'], $limits['post_max_size'] );
		if ( empty( $options['server_transfer'] ) && $php_max > 0 && (int) $file['size'] > $php_max ) {
			return array( 'success' => false, 'error' => 'File exceeds PHP upload_max_filesize/post_max_size limit' );
		}

		$max = (int) Settings::get( 'browser_transfer_max_bytes', 67108864 );
		if ( empty( $options['server_transfer'] ) && (int) $file['size'] > $max ) {
			return array( 'success' => false, 'error' => 'File exceeds browser transfer limit' );
		}
		if ( ! empty( $options['server_transfer'] ) ) {
			$server_max = (int) Settings::get( 'chunk_max_bytes', 2147483648 );
			if ( (int) $file['size'] > $server_max ) {
				return array( 'success' => false, 'error' => 'File exceeds server transfer limit' );
			}
		}

		$expected = self::resolve_expected_file( $migration_id, $component, $relative_path, $upload_name );
		if ( ! $expected && ! empty( $options['server_transfer'] ) && $relative_path ) {
			$expected = PackageIndex::find_expected_file( $migration_id, $component, $relative_path, 'export' );
		}
		if ( ! $expected && 'manifest' !== $component ) {
			if ( empty( $options['server_transfer'] ) || ! $relative_path || ! self::path_allowed( self::sanitize_relative_path( $relative_path ), $component ) ) {
				return array(
					'success' => false,
					'error'   => 'File not recognized for this subject. Use the exact download filename (e.g. plugins__segments--segment-00001.tar.gz) or upload manifest.json first.',
				);
			}
			$relative_path = self::sanitize_relative_path( $relative_path );
		}

		if ( 'manifest' === $component ) {
			$relative_path = 'manifest.json';
		} elseif ( $expected ) {
			$relative_path = $expected['path'];
		} else {
			$relative_path = self::sanitize_relative_path( $relative_path );
		}

		if ( 'manifest' === $component && 'manifest.json' !== $relative_path ) {
			return array( 'success' => false, 'error' => 'Invalid manifest path' );
		}

		$base = Settings::migration_path( $migration_id, 'import' );
		wp_mkdir_p( $base );
		$dest = trailingslashit( $base ) . $relative_path;

		if ( ! self::path_allowed( $relative_path, $component ) ) {
			return array( 'success' => false, 'error' => 'Path not allowed for component' );
		}

		$expected_checksum = $checksum ?: ( $expected ? ( $expected['checksum'] ?? '' ) : '' );
		if ( ! empty( $options['server_transfer'] ) && file_exists( $dest ) ) {
			if ( 'manifest' === $component ) {
				if ( ! $expected_checksum || ChecksumService::verify_file( $dest, $expected_checksum ) ) {
					$file_size = (int) filesize( $dest );
					TransferProgress::log_receive( $migration_id, $relative_path, $component, $file_size );
					return array(
						'success' => true,
						'path'    => $relative_path,
						'skipped' => true,
						'size'    => $file_size,
					);
				}
				@unlink( $dest );
			} elseif ( VerifyQueue::accept_existing_file( $migration_id, $relative_path, $dest, $expected_checksum ) ) {
				$file_size = (int) filesize( $dest );
				TransferProgress::log_receive( $migration_id, $relative_path, $component, $file_size );
				return array(
					'success' => true,
					'path'    => $relative_path,
					'skipped' => true,
					'size'    => $file_size,
				);
			}
		}

		$tmp_dest = $dest . '.uploading';

		wp_mkdir_p( dirname( $dest ) );
		$validated = DirectoryGuard::validate_path( dirname( $dest ), $base );
		if ( false === $validated ) {
			return array( 'success' => false, 'error' => 'Invalid destination path' );
		}

		$moved = is_uploaded_file( $file['tmp_name'] )
			? move_uploaded_file( $file['tmp_name'], $tmp_dest )
			: @copy( $file['tmp_name'], $tmp_dest );

		if ( ! $moved ) {
			return array( 'success' => false, 'error' => 'Failed to save upload' );
		}

		if ( 'manifest' === $component ) {
			$manifest_check = self::validate_manifest_upload( $migration_id, $tmp_dest );
			if ( ! $manifest_check['success'] ) {
				@unlink( $tmp_dest );
				return $manifest_check;
			}
		} elseif ( $expected_checksum && empty( $options['server_transfer'] ) ) {
			$actual = ChecksumService::hash_file( $tmp_dest );
			if ( ! hash_equals( strtolower( $expected_checksum ), strtolower( (string) $actual ) ) ) {
				@unlink( $tmp_dest );
				if ( file_exists( $dest ) ) {
					@unlink( $dest );
				}
				if ( file_exists( $dest . '.uploading' ) ) {
					@unlink( $dest . '.uploading' );
				}
				AuditLogger::log( 'transfer_checksum_fail', 'Upload checksum failed: ' . $relative_path, array(
					'migration_id' => $migration_id,
					'component'    => $component,
				), 'error' );
				return array( 'success' => false, 'error' => 'Checksum verification failed for ' . $relative_path );
			}
		} elseif ( $expected && ! empty( $expected['checksum'] ) && empty( $options['server_transfer'] ) ) {
			@unlink( $tmp_dest );
			return array( 'success' => false, 'error' => 'Checksum required but missing for ' . $relative_path );
		} elseif ( $expected && ! empty( $expected['checksum'] ) && empty( $expected_checksum ) && ! empty( $options['server_transfer'] ) ) {
			@unlink( $tmp_dest );
			return array( 'success' => false, 'error' => 'Checksum required but missing for ' . $relative_path );
		}

		if ( file_exists( $dest ) ) {
			@unlink( $dest );
		}
		if ( ! self::atomic_finalize( $tmp_dest, $dest ) ) {
			return array( 'success' => false, 'error' => 'Failed to finalize upload' );
		}

		if ( 'manifest' === $component ) {
			PackageIndex::clear_request_cache();
			PackageIndex::cleanup_stale_uploads( $base );
		}

		if ( 'manifest' !== $component && empty( $options['defer_inventory'] ) ) {
			PackageIndex::ensure_component_inventory( $migration_id, $component );
		}

		AuditLogger::log( 'transfer_upload', 'Uploaded: ' . $relative_path, array(
			'migration_id' => $migration_id,
			'component'    => $component,
		), 'success' );

		$file_size = file_exists( $dest ) ? (int) filesize( $dest ) : (int) ( $file['size'] ?? 0 );
		$verify_pending = false;
		if ( ! empty( $options['server_transfer'] ) && 'manifest' !== $component && $expected_checksum ) {
			VerifyQueue::enqueue( $migration_id, $component, $relative_path, $dest, $expected_checksum );
			VerifyWorker::ensure_running( $migration_id );
			$verify_pending = true;
		}

		TransferProgress::log_receive( $migration_id, $relative_path, $component, $file_size );

		$response = array(
			'success'       => true,
			'path'          => $relative_path,
			'download_name' => $expected && ! empty( $expected['download_name'] ) ? $expected['download_name'] : $upload_name,
			'verified'      => ! $verify_pending,
			'verify_pending'=> $verify_pending,
			'size'          => $file_size,
		);

		if ( empty( $options['fast_response'] ) ) {
			$response['status'] = self::component_status( $migration_id, $component );
		}

		return $response;
	}

	/**
	 * Resolve uploaded file against manifest catalog.
	 *
	 * @param string $migration_id  Migration ID.
	 * @param string $component     Component.
	 * @param string $relative_path Client-provided path.
	 * @param string $upload_name   Original filename.
	 * @return array|null
	 */
	private static function resolve_expected_file( $migration_id, $component, $relative_path, $upload_name ) {
		if ( 'manifest' === $component ) {
			return array( 'path' => 'manifest.json', 'checksum' => '' );
		}

		$relative_path = self::sanitize_relative_path( $relative_path );
		if ( $relative_path ) {
			$match = PackageIndex::find_expected_file( $migration_id, $component, $relative_path, 'import' );
			if ( $match ) {
				return $match;
			}
		}

		if ( $upload_name ) {
			return PackageIndex::find_file_by_upload_name( $migration_id, $component, $upload_name, 'import' );
		}

		return null;
	}

	/**
	 * Validate uploaded manifest.json.
	 *
	 * @param string $migration_id Migration ID.
	 * @param string $tmp_path     Temp file path.
	 * @return array
	 */
	private static function validate_manifest_upload( $migration_id, $tmp_path ) {
		$raw = file_get_contents( $tmp_path );
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return array( 'success' => false, 'error' => 'Invalid manifest JSON' );
		}

		if ( empty( $data['migration_id'] ) ) {
			return array( 'success' => false, 'error' => 'Manifest missing migration_id' );
		}

		if ( ! hash_equals( $data['migration_id'], $migration_id ) ) {
			return array(
				'success' => false,
				'error'   => sprintf(
					'Manifest migration_id (%s) does not match entered ID (%s)',
					$data['migration_id'],
					$migration_id
				),
			);
		}

		if ( empty( $data['transfer_catalog'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Manifest has no transfer_catalog. Re-finalize export on the source site.',
			);
		}

		$base = Settings::migration_path( $migration_id, 'import' );
		$existing = \TheExporter\Manifest\ManifestBuilder::load( $base );
		if ( $existing ) {
			$old_fp = PackageIndex::catalog_fingerprint( $existing );
			$new_fp = PackageIndex::catalog_fingerprint( $data );
			if ( $old_fp !== $new_fp ) {
				PackageIndex::reset_import_package( $migration_id, false );
				AuditLogger::log( 'transfer_reset', 'Import package reset due to manifest catalog change', array(
					'migration_id' => $migration_id,
				), 'warn' );
			}
		}

		if ( ! empty( $data['checksums']['manifest_sha256'] ) ) {
			$actual = \TheExporter\Manifest\ManifestBuilder::file_checksum_without_embedded_hash( $tmp_path );
			if ( ! hash_equals( strtolower( $data['checksums']['manifest_sha256'] ), strtolower( (string) $actual ) ) ) {
				AuditLogger::log(
					'manifest_checksum_warn',
					'Manifest checksum mismatch (legacy finalize or edited manifest); continuing upload',
					array( 'migration_id' => $migration_id ),
					'warn'
				);
			}
		}

		TransferStatus::prune();
		return array( 'success' => true );
	}

	/**
	 * Get upload status for component.
	 *
	 * @param string $migration_id Migration ID.
	 * @param string $component    Component.
	 * @return array
	 */
	public static function component_status( $migration_id, $component ) {
		if ( 'manifest' === $component ) {
			$base = Settings::migration_path( $migration_id, 'import' );
			$exists = file_exists( $base . '/manifest.json' );
			$manifest = $exists ? \TheExporter\Manifest\ManifestBuilder::load( $base ) : false;
			$has_catalog = is_array( $manifest ) && ! empty( $manifest['transfer_catalog'] );
			return array(
				'component'         => 'manifest',
				'expected'          => 1,
				'uploaded'          => $exists ? 1 : 0,
				'missing'           => $exists ? array() : array( 'manifest.json' ),
				'checksum_failures' => array(),
				'ready_to_validate' => $exists,
				'has_catalog'       => $has_catalog,
			);
		}

		$comp = PackageIndex::get_component( $migration_id, $component, 'import' );
		if ( ! $comp ) {
			$comp = PackageIndex::get_component( $migration_id, $component, 'export' );
		}

		if ( ! $comp || empty( $comp['files'] ) ) {
			$import_path = Settings::migration_path( $migration_id, 'import' );
			$manifest    = \TheExporter\Manifest\ManifestBuilder::load( $import_path );
			if ( is_array( $manifest ) && ! empty( $manifest['transfer_catalog'] ) ) {
				if ( ! isset( $manifest['transfer_catalog'][ $component ] ) ) {
					return array(
						'component'         => $component,
						'expected'          => 0,
						'uploaded'          => 0,
						'missing'           => array(),
						'checksum_failures' => array(),
						'ready_to_validate' => true,
						'skipped'           => true,
						'files'             => array(),
						'message'           => __( 'Not included in this export.', 'the-exporter' ),
					);
				}
				if ( isset( $manifest['transfer_catalog'][ $component ] ) ) {
					$catalog_chunks = isset( $manifest['transfer_catalog'][ $component ]['chunks'] )
						? $manifest['transfer_catalog'][ $component ]['chunks']
						: array();
					if ( empty( $catalog_chunks ) ) {
						return array(
							'component'         => $component,
							'expected'          => 0,
							'uploaded'          => 0,
							'missing'           => array(),
							'checksum_failures' => array(),
							'ready_to_validate' => true,
							'skipped'           => true,
							'files'             => array(),
							'message'           => __( 'Nothing was exported for this subject on the source site. You can skip to Validate.', 'the-exporter' ),
						);
					}
				}
			}

			return array(
				'component'         => $component,
				'expected'          => 0,
				'uploaded'          => 0,
				'missing'           => array(),
				'checksum_failures' => array(),
				'ready_to_validate' => false,
				'needs_manifest'    => true,
				'message'           => __( 'Upload manifest.json first so expected files are known.', 'the-exporter' ),
			);
		}

		$base    = Settings::migration_path( $migration_id, 'import' );
		$missing = array();
		$uploaded = 0;
		$failures = array();

		foreach ( $comp['files'] as $file ) {
			$full = trailingslashit( $base ) . $file['path'];
			if ( ! file_exists( $full ) ) {
				$missing[] = ! empty( $file['download_name'] ) ? $file['download_name'] : $file['path'];
				continue;
			}
			if ( ! empty( $file['checksum'] ) ) {
				if ( ! VerifyQueue::accept_existing_file( $migration_id, $file['path'], $full, $file['checksum'] ) ) {
					$missing[] = ! empty( $file['download_name'] ) ? $file['download_name'] : $file['path'];
					continue;
				}
			}
			$uploaded++;
		}

		$expected = count( $comp['files'] );
		$ready    = $uploaded === $expected && empty( $failures ) && $expected > 0;
		if ( $ready ) {
			$ready = VerifyQueue::component_ready( $migration_id, $component );
		}

		if ( $ready ) {
			PackageIndex::ensure_component_inventory( $migration_id, $component );
			TransferStatus::update( $migration_id, $component, array( 'state' => 'uploaded' ) );
		}

		return array(
			'component'         => $component,
			'expected'          => $expected,
			'uploaded'          => $uploaded,
			'missing'           => $missing,
			'checksum_failures' => $failures,
			'ready_to_validate' => $ready,
			'upload_ready'      => $expected > 0 && ! $ready,
			'files'             => $comp['files'],
			'transfer_status'   => TransferStatus::get( $migration_id, $component ),
		);
	}

	/**
	 * Overall upload status for the import migration.
	 *
	 * @param string $migration_id Migration ID.
	 * @param array  $options      Options (lightweight bool).
	 * @return array
	 */
	public static function migration_upload_status( $migration_id, array $options = array() ) {
		$lightweight = ! empty( $options['lightweight'] );
		$manifest_status = self::component_status( $migration_id, 'manifest' );
		if ( empty( $manifest_status['uploaded'] ) ) {
			return array(
				'migration_id'      => $migration_id,
				'needs_manifest'    => true,
				'expected'          => 0,
				'uploaded'          => 0,
				'missing'           => array( 'manifest.json' ),
				'checksum_failures' => array(),
				'ready_to_validate' => false,
				'files'             => array(),
			);
		}

		$files     = array();
		$missing   = array();
		$failures  = array();
		$uploaded  = 1;
		$expected  = 1;
		$base      = Settings::migration_path( $migration_id, 'import' );
		$max_bytes = (int) Settings::get( 'browser_transfer_max_bytes', 67108864 );

		foreach ( PackageIndex::component_order() as $component ) {
			$status = self::component_status( $migration_id, $component );
			if ( ! empty( $status['skipped'] ) ) {
				continue;
			}

			foreach ( (array) ( $status['files'] ?? array() ) as $file ) {
				$label = ! empty( $file['download_name'] ) ? $file['download_name'] : basename( $file['path'] );
				$size  = isset( $file['size'] ) ? (int) $file['size'] : 0;
				$expected++;
				$full  = trailingslashit( $base ) . $file['path'];
				$ok    = file_exists( $full );
				$checksum_failed = false;
				if ( $ok && ! $lightweight && ! empty( $file['checksum'] ) ) {
					$ok = VerifyQueue::accept_existing_file( $migration_id, $file['path'], $full, $file['checksum'] );
					if ( ! $ok ) {
						$checksum_failed = true;
						$failures[]      = $label;
					}
				}
				$browser_uploadable = $size <= $max_bytes;
				$block_reason       = self::file_upload_block_reason( $ok, $checksum_failed, $size, $max_bytes );
				$files[]            = array(
					'component'          => $component,
					'path'               => $file['path'],
					'download_name'      => $label,
					'size'               => $size,
					'checksum'           => isset( $file['checksum'] ) ? $file['checksum'] : '',
					'uploaded'           => $ok,
					'browser_uploadable' => $browser_uploadable,
					'block_reason'       => $block_reason,
				);
				if ( $ok ) {
					$uploaded++;
				} else {
					$missing[] = $label;
				}
			}
		}

		$ready = empty( $missing ) && empty( $failures ) && $expected > 0;
		if ( $ready ) {
			$ready = VerifyQueue::migration_ready( $migration_id );
		}

		return array(
			'migration_id'              => $migration_id,
			'needs_manifest'            => false,
			'has_catalog'               => ! empty( $manifest_status['has_catalog'] ),
			'expected'                  => $expected,
			'uploaded'                  => $uploaded,
			'missing'                   => $missing,
			'checksum_failures'         => $failures,
			'ready_to_validate'         => $ready,
			'browser_transfer_max_bytes'=> $max_bytes,
			'files'                     => $files,
		);
	}

	/**
	 * Reason a catalog file is not ready on the import server.
	 *
	 * @param bool $uploaded_ok       File present and checksum-valid.
	 * @param bool $checksum_failed   Checksum mismatch.
	 * @param int  $size              Expected file size.
	 * @param int  $max_bytes         Browser upload cap.
	 * @return string|null missing|checksum|browser_limit|null
	 */
	private static function file_upload_block_reason( $uploaded_ok, $checksum_failed, $size, $max_bytes ) {
		if ( $uploaded_ok ) {
			return null;
		}
		if ( $checksum_failed ) {
			return 'checksum';
		}
		if ( $size > $max_bytes ) {
			return 'browser_limit';
		}
		return 'missing';
	}

	/**
	 * Sanitize relative path.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function sanitize_relative_path( $path ) {
		$path = str_replace( '\\', '/', $path );
		$path = ltrim( $path, '/' );
		$path = preg_replace( '#\.\./#', '', $path );
		return sanitize_text_field( $path );
	}

	/**
	 * Check path belongs to component.
	 *
	 * @param string $relative_path Path.
	 * @param string $component     Component.
	 * @return bool
	 */
	private static function path_allowed( $relative_path, $component ) {
		if ( 'manifest' === $component ) {
			return 'manifest.json' === $relative_path;
		}
		return strpos( $relative_path, $component . '/' ) === 0 || $relative_path === $component;
	}

	/**
	 * Receive file from connected export site (large segments, pairing token).
	 *
	 * @param string $migration_id  Migration ID.
	 * @param string $component     Component.
	 * @param string $relative_path Relative path.
	 * @param array  $file          Upload file array.
	 * @param string $checksum      Expected checksum.
	 * @param string $token         Pairing token.
	 * @return array
	 */
	public static function server_receive( $migration_id, $component, $relative_path, array $file, $checksum, $token ) {
		if ( ! RemoteAuth::verify_token( $token ) ) {
			return array( 'success' => false, 'error' => 'Invalid or expired pairing code' );
		}

		return self::upload(
			$migration_id,
			$component,
			$relative_path,
			$file,
			$checksum,
			array(
				'server_transfer' => true,
				'fast_response'   => true,
				'defer_inventory' => true,
			)
		);
	}

	/**
	 * Public wrapper for chunk receiver path sanitization.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function sanitize_relative_path_public( $path ) {
		return self::sanitize_relative_path( $path );
	}

	/**
	 * Public wrapper for path allow check.
	 *
	 * @param string $relative_path Path.
	 * @param string $component     Component.
	 * @return bool
	 */
	public static function path_allowed_public( $relative_path, $component ) {
		return self::path_allowed( $relative_path, $component );
	}

	/**
	 * Rename or copy temp upload into final path.
	 *
	 * @param string $tmp_dest Temp path.
	 * @param string $dest     Final path.
	 * @return bool
	 */
	private static function atomic_finalize( $tmp_dest, $dest ) {
		if ( @rename( $tmp_dest, $dest ) ) {
			return true;
		}
		if ( @copy( $tmp_dest, $dest ) ) {
			@unlink( $tmp_dest );
			return true;
		}
		@unlink( $tmp_dest );
		return false;
	}
}
