<?php
/**
 * Component-grouped package index for browser transfer.
 *
 * @package TheExporter
 */

namespace TheExporter\Transfer;

use TheExporter\Jobs\ExportOrchestrator;
use TheExporter\Manifest\ManifestBuilder;
use TheExporter\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class PackageIndex
 */
class PackageIndex {

	/**
	 * Request-level component cache.
	 *
	 * @var array
	 */
	private static $components_cache = array();

	/**
	 * Recommended component order.
	 *
	 * @return array
	 */
	public static function component_order() {
		return array( 'mu-plugins', 'plugins', 'themes', 'uploads', 'wp-content-other', 'config', 'database' );
	}

	/**
	 * Human label for component.
	 *
	 * @param string $name Component slug.
	 * @return string
	 */
	public static function component_label( $name ) {
		$labels = array(
			'mu-plugins'       => __( 'Must-Use Plugins', 'the-exporter' ),
			'plugins'          => __( 'Plugins', 'the-exporter' ),
			'themes'           => __( 'Themes', 'the-exporter' ),
			'uploads'          => __( 'Media Uploads', 'the-exporter' ),
			'wp-content-other' => __( 'Other wp-content', 'the-exporter' ),
			'config'           => __( 'Configuration', 'the-exporter' ),
			'database'         => __( 'Database', 'the-exporter' ),
			'manifest'         => __( 'Manifest', 'the-exporter' ),
		);
		return isset( $labels[ $name ] ) ? $labels[ $name ] : ucwords( str_replace( '-', ' ', $name ) );
	}

	/**
	 * Resolve migration path (export first, then import).
	 *
	 * @param string $migration_id Migration ID.
	 * @param string $prefer       export|import.
	 * @return string|false
	 */
	public static function resolve_path( $migration_id, $prefer = 'export' ) {
		$export = Settings::migration_path( $migration_id, 'export' );
		$import = Settings::migration_path( $migration_id, 'import' );

		if ( 'import' === $prefer && is_dir( $import ) ) {
			return $import;
		}
		if ( is_dir( $export ) ) {
			return $export;
		}
		if ( is_dir( $import ) ) {
			return $import;
		}
		return false;
	}

	/**
	 * Get all components with file lists.
	 *
	 * @param string $migration_id Migration ID.
	 * @param string $prefer       export|import.
	 * @return array
	 */
	public static function get_components( $migration_id, $prefer = 'export' ) {
		$cache_key = $migration_id . ':' . $prefer;
		if ( isset( self::$components_cache[ $cache_key ] ) ) {
			return self::$components_cache[ $cache_key ];
		}

		$path     = self::resolve_path( $migration_id, $prefer );
		$manifest = $path ? ManifestBuilder::load( $path ) : false;
		$max      = (int) Settings::get( 'browser_transfer_max_bytes', 67108864 );
		$result   = array();

		$manifest_components = array();
		if ( $manifest && ! empty( $manifest['components'] ) ) {
			foreach ( $manifest['components'] as $c ) {
				$manifest_components[ $c['name'] ] = $c;
			}
		}

		$order = self::component_order();
		foreach ( $order as $name ) {
			$comp = self::build_component( $path, $name, $manifest_components, $manifest, $max );
			if ( $comp ) {
				$result[] = $comp;
			}
		}

		self::$components_cache[ $cache_key ] = $result;
		return $result;
	}

	/**
	 * Clear request-level caches (e.g. after manifest upload).
	 */
	public static function clear_request_cache() {
		self::$components_cache = array();
	}

	/**
	 * Get single component package.
	 *
	 * @param string $migration_id Migration ID.
	 * @param string $component    Component name.
	 * @param string $prefer       export|import.
	 * @return array|null
	 */
	public static function get_component( $migration_id, $component, $prefer = 'export' ) {
		foreach ( self::get_components( $migration_id, $prefer ) as $comp ) {
			if ( $comp['name'] === $component ) {
				return $comp;
			}
		}
		return null;
	}

	/**
	 * Global files (manifest).
	 *
	 * @param string $migration_id Migration ID.
	 * @param string $prefer       export|import.
	 * @return array
	 */
	public static function get_global_files( $migration_id, $prefer = 'export' ) {
		$path = self::resolve_path( $migration_id, $prefer );
		if ( ! $path ) {
			return array();
		}

		$files = array();
		$manifest_file = $path . '/manifest.json';
		if ( file_exists( $manifest_file ) ) {
			$checksum = \TheExporter\Validation\ChecksumService::hash_file( $manifest_file );
			$size     = filesize( $manifest_file );
			$max      = (int) Settings::get( 'browser_transfer_max_bytes', 67108864 );
			$files[]  = array(
				'path'          => 'manifest.json',
				'size'          => $size,
				'checksum'      => $checksum,
				'hash'          => self::file_hash( 'manifest.json' ),
				'browser_safe'  => $size <= $max,
				'component'     => 'manifest',
			);
		}
		return $files;
	}

	/**
	 * Find file by hash across migration.
	 *
	 * @param string $migration_id Migration ID.
	 * @param string $file_hash    File hash.
	 * @param string $prefer       export|import.
	 * @return array|null
	 */
	public static function find_file_by_hash( $migration_id, $file_hash, $prefer = 'export' ) {
		foreach ( self::get_global_files( $migration_id, $prefer ) as $file ) {
			if ( $file['hash'] === $file_hash ) {
				return $file;
			}
		}
		foreach ( self::get_components( $migration_id, $prefer ) as $comp ) {
			foreach ( $comp['files'] as $file ) {
				if ( $file['hash'] === $file_hash ) {
					return $file;
				}
			}
		}
		return null;
	}

	/**
	 * Hash for download URL.
	 *
	 * @param string $relative_path Relative path.
	 * @return string
	 */
	public static function file_hash( $relative_path ) {
		return substr( hash( 'sha256', $relative_path ), 0, 16 );
	}

	/**
	 * Browser-friendly download filename (component-prefixed).
	 *
	 * @param string $component     Component slug.
	 * @param string $relative_path Path within migration.
	 * @return string
	 */
	public static function download_name( $component, $relative_path ) {
		$path = str_replace( '\\', '/', $relative_path );
		if ( strpos( $path, $component . '/' ) === 0 ) {
			$suffix = substr( $path, strlen( $component ) + 1 );
			return $component . '__' . str_replace( '/', '--', $suffix );
		}
		return $component . '__' . basename( $path );
	}

	/**
	 * Resolve migration-relative path from a prefixed download name.
	 *
	 * @param string $component     Component slug.
	 * @param string $download_name Uploaded filename.
	 * @return string|null
	 */
	public static function path_from_download_name( $component, $download_name ) {
		$prefix = $component . '__';
		if ( strpos( $download_name, $prefix ) !== 0 ) {
			$single = $component . '_';
			if ( strpos( $download_name, $single ) === 0 && strpos( $download_name, $prefix ) !== 0 ) {
				$download_name = $prefix . substr( $download_name, strlen( $single ) );
			} else {
				return null;
			}
		}
		$suffix = substr( $download_name, strlen( $prefix ) );
		return $component . '/' . str_replace( '--', '/', $suffix );
	}

	/**
	 * Sanitize a browser upload basename without breaking multi-part extensions (.sql.gz, .tar.gz).
	 *
	 * WordPress sanitize_file_name() mangles names like database__dump.sql.gz.
	 *
	 * @param string $filename Original filename.
	 * @return string
	 */
	public static function sanitize_upload_basename( $filename ) {
		$filename = wp_basename( str_replace( '\\', '/', (string) $filename ) );
		$filename = preg_replace( '/[^a-zA-Z0-9._\-]+/', '', $filename );
		return $filename;
	}

	/**
	 * Normalize browser upload filename to catalog download_name format.
	 *
	 * @param string $component Component slug.
	 * @param string $filename  Uploaded filename.
	 * @return string
	 */
	public static function normalize_upload_filename( $component, $filename ) {
		$filename = self::sanitize_upload_basename( $filename );
		$double   = $component . '__';
		$single   = $component . '_';
		if ( strpos( $filename, $single ) === 0 && strpos( $filename, $double ) !== 0 ) {
			return $double . substr( $filename, strlen( $single ) );
		}
		return $filename;
	}

	/**
	 * Snapshot all component inventories for manifest embedding.
	 *
	 * @param string $migration_path Migration directory.
	 * @return array
	 */
	public static function build_transfer_catalog( $migration_path ) {
		$catalog = array();
		foreach ( self::component_order() as $name ) {
			$inventory_path = trailingslashit( $migration_path ) . $name . '/inventory.json';
			if ( ! file_exists( $inventory_path ) ) {
				continue;
			}
			$data = json_decode( file_get_contents( $inventory_path ), true );
			if ( is_array( $data ) ) {
				$catalog[ $name ] = $data;
			}
		}
		return $catalog;
	}

	/**
	 * Find expected file entry for upload/validation.
	 *
	 * @param string $migration_id  Migration ID.
	 * @param string $component     Component.
	 * @param string $relative_path Relative path.
	 * @param string $prefer        export|import.
	 * @return array|null
	 */
	public static function find_expected_file( $migration_id, $component, $relative_path, $prefer = 'import' ) {
		$comp = self::get_component( $migration_id, $component, $prefer );
		if ( ! $comp ) {
			$comp = self::get_component( $migration_id, $component, 'export' );
		}
		if ( ! $comp ) {
			return null;
		}
		foreach ( $comp['files'] as $file ) {
			if ( $file['path'] === $relative_path ) {
				return $file;
			}
		}
		return null;
	}

	/**
	 * Match an uploaded browser filename to a catalog entry.
	 *
	 * @param string $migration_id Migration ID.
	 * @param string $component    Component.
	 * @param string $filename     Uploaded filename.
	 * @param string $prefer       export|import.
	 * @return array|null
	 */
	public static function find_file_by_upload_name( $migration_id, $component, $filename, $prefer = 'import' ) {
		$comp = self::get_component( $migration_id, $component, $prefer );
		if ( ! $comp ) {
			$comp = self::get_component( $migration_id, $component, 'export' );
		}
		if ( ! $comp ) {
			return null;
		}

		$filename   = self::sanitize_upload_basename( $filename );
		$normalized = self::normalize_upload_filename( $component, $filename );
		$candidates = array_unique( array_filter( array( $filename, $normalized ) ) );

		foreach ( $candidates as $candidate ) {
			$from_prefixed = self::path_from_download_name( $component, $candidate );
			foreach ( $comp['files'] as $file ) {
				if ( ! empty( $file['download_name'] ) && $file['download_name'] === $candidate ) {
					return $file;
				}
				if ( $from_prefixed && $file['path'] === $from_prefixed ) {
					return $file;
				}
				$basename = basename( $file['path'] );
				if ( $basename === $candidate || $basename === basename( $from_prefixed ? $from_prefixed : '' ) ) {
					return $file;
				}
			}
		}

		return null;
	}

	/**
	 * Write inventory.json on import path once all chunk files are present.
	 *
	 * @param string $migration_id Migration ID.
	 * @param string $component    Component.
	 * @return bool
	 */
	public static function ensure_component_inventory( $migration_id, $component ) {
		$import_path = Settings::migration_path( $migration_id, 'import' );
		$inventory_path = trailingslashit( $import_path ) . $component . '/inventory.json';
		if ( file_exists( $inventory_path ) ) {
			return true;
		}

		$manifest = ManifestBuilder::load( $import_path );
		if ( ! $manifest || empty( $manifest['transfer_catalog'][ $component ] ) ) {
			return false;
		}

		$catalog = $manifest['transfer_catalog'][ $component ];
		$chunks  = isset( $catalog['chunks'] ) ? $catalog['chunks'] : array();
		foreach ( $chunks as $chunk ) {
			$rel = isset( $chunk['path'] ) ? $chunk['path'] : '';
			if ( ! $rel ) {
				return false;
			}
			$full = ( strpos( $rel, $component . '/' ) === 0 )
				? trailingslashit( $import_path ) . $rel
				: trailingslashit( $import_path ) . $component . '/' . ltrim( str_replace( $component . '/', '', $rel ), '/' );
			if ( ! file_exists( $full ) ) {
				return false;
			}
		}

		\TheExporter\Files\InventoryBuilder::save( trailingslashit( $import_path ) . $component, $catalog );
		return true;
	}

	/**
	 * Fingerprint transfer catalog for change detection.
	 *
	 * @param array $manifest Manifest.
	 * @return string
	 */
	public static function catalog_fingerprint( array $manifest ) {
		$catalog = isset( $manifest['transfer_catalog'] ) ? $manifest['transfer_catalog'] : array();
		return hash( 'sha256', wp_json_encode( $catalog ) );
	}

	/**
	 * Reset import package files (keeps optional manifest).
	 *
	 * @param string $migration_id   Migration ID.
	 * @param bool   $remove_manifest Remove manifest too.
	 */
	public static function reset_import_package( $migration_id, $remove_manifest = false ) {
		$import_path = Settings::migration_path( $migration_id, 'import' );
		if ( ! is_dir( $import_path ) ) {
			return;
		}

		foreach ( self::component_order() as $component ) {
			$dir = $import_path . '/' . $component;
			if ( is_dir( $dir ) ) {
				self::delete_directory( $dir );
			}
		}

		if ( $remove_manifest && file_exists( $import_path . '/manifest.json' ) ) {
			@unlink( $import_path . '/manifest.json' );
			@unlink( $import_path . '/manifest.sha256' );
		}

		TransferStatus::reset_migration( $migration_id );
		self::cleanup_stale_uploads( $import_path );
		TransferProgress::clear_receive_state( $migration_id );
	}

	/**
	 * Remove stale .uploading files.
	 *
	 * @param string $base Base path.
	 */
	public static function cleanup_stale_uploads( $base ) {
		if ( ! is_dir( $base ) ) {
			return;
		}
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $base, \RecursiveDirectoryIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && substr( $file->getFilename(), -10 ) === '.uploading' ) {
				@unlink( $file->getPathname() );
			}
		}
	}

	/**
	 * Recursively delete directory.
	 *
	 * @param string $dir Directory.
	 */
	private static function delete_directory( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				self::delete_directory( $path );
			} else {
				@unlink( $path );
			}
		}
		@rmdir( $dir );
	}

	/**
	 * Build component data.
	 *
	 * @param string $path                Migration path.
	 * @param string $name                Component name.
	 * @param array  $manifest_components Manifest component map.
	 * @param array|false $manifest     Full manifest (for transfer_catalog).
	 * @param int    $max                 Browser max bytes.
	 * @return array|null
	 */
	private static function build_component( $path, $name, array $manifest_components, $manifest, $max ) {
		if ( ! $path ) {
			return null;
		}

		$inventory_path = $path . '/' . $name . '/inventory.json';
		$meta           = isset( $manifest_components[ $name ] ) ? $manifest_components[ $name ] : null;

		if ( ! file_exists( $inventory_path ) && ! $meta ) {
			return null;
		}

		$inventory = null;
		if ( file_exists( $inventory_path ) ) {
			$inventory = json_decode( file_get_contents( $inventory_path ), true );
		} elseif ( is_array( $manifest ) && ! empty( $manifest['transfer_catalog'][ $name ] ) ) {
			$inventory = $manifest['transfer_catalog'][ $name ];
		}

		$files        = array();
		$total_bytes  = 0;
		$browser_safe = true;

		if ( is_array( $inventory ) ) {
			$chunks = isset( $inventory['chunks'] ) ? $inventory['chunks'] : array();
			foreach ( $chunks as $chunk ) {
				$rel = isset( $chunk['path'] ) ? $chunk['path'] : '';
				if ( ! $rel ) {
					continue;
				}
				$full = ( strpos( $rel, $name . '/' ) === 0 ) ? $rel : $name . '/' . $rel;
				$size = isset( $chunk['size'] ) ? (int) $chunk['size'] : 0;
				$safe = isset( $chunk['transfer_safe'] ) ? (bool) $chunk['transfer_safe'] : ( $size <= $max );
				if ( ! $safe ) {
					$browser_safe = false;
				}
				$files[] = array(
					'path'          => $full,
					'size'          => $size,
					'checksum'      => isset( $chunk['checksum'] ) ? $chunk['checksum'] : '',
					'hash'          => self::file_hash( $full ),
					'browser_safe'  => $safe,
					'component'     => $name,
					'download_name' => self::download_name( $name, $full ),
				);
				$total_bytes += $size;
			}
		}

		return array(
			'name'         => $name,
			'label'        => self::component_label( $name ),
			'file_count'   => count( $files ),
			'total_bytes'  => $total_bytes,
			'browser_safe' => $browser_safe,
			'status'       => $meta && isset( $meta['status'] ) ? $meta['status'] : ( count( $files ) ? 'completed' : 'empty' ),
			'files'        => $files,
			'has_catalog'  => is_array( $manifest ) && ! empty( $manifest['transfer_catalog'][ $name ] ),
		);
	}

	/**
	 * Valid component names.
	 *
	 * @param string $component Component.
	 * @return bool
	 */
	public static function is_valid_component( $component ) {
		return in_array( $component, array_merge( self::component_order(), array( 'manifest' ) ), true );
	}

	/**
	 * List all files safe for browser transfer.
	 *
	 * @param string $migration_id Migration ID.
	 * @param string $component    Optional component filter.
	 * @param string $prefer       export|import.
	 * @return array
	 */
	public static function list_browser_transfer_files( $migration_id, $component = '', $prefer = 'export' ) {
		$files = array();

		if ( '' === $component || 'manifest' === $component ) {
			foreach ( self::get_global_files( $migration_id, $prefer ) as $file ) {
				if ( ! empty( $file['browser_safe'] ) ) {
					$files[] = $file;
				}
			}
		}

		if ( '' === $component || 'manifest' !== $component ) {
			foreach ( self::get_components( $migration_id, $prefer ) as $comp ) {
				if ( $component && $comp['name'] !== $component ) {
					continue;
				}
				foreach ( $comp['files'] as $file ) {
					if ( ! empty( $file['browser_safe'] ) ) {
						$files[] = $file;
					}
				}
			}
		}

		return $files;
	}

	/**
	 * Summary stats for browser transfer / bundle download.
	 *
	 * @param string $migration_id Migration ID.
	 * @param string $prefer       export|import.
	 * @return array
	 */
	public static function transfer_summary( $migration_id, $prefer = 'export' ) {
		$files       = self::list_browser_transfer_files( $migration_id, '', $prefer );
		$total_bytes = 0;
		foreach ( $files as $file ) {
			$total_bytes += (int) $file['size'];
		}
		$max_zip = 1610612736; // 1.5 GB.
		return array(
			'file_count'    => count( $files ),
			'total_bytes'   => $total_bytes,
			'zip_available' => class_exists( 'ZipArchive', false ) && $total_bytes > 0 && $total_bytes <= $max_zip,
			'zip_max_bytes' => $max_zip,
			'export_path'   => Settings::migration_path( $migration_id, 'export' ),
		);
	}

	/**
	 * Human-readable diagnostics for package listing UI.
	 *
	 * @param string $migration_id Migration ID.
	 * @param string $prefer       export|import.
	 * @return array
	 */
	public static function get_diagnostics( $migration_id, $prefer = 'export' ) {
		$export_path = Settings::migration_path( $migration_id, 'export' );
		$import_path = Settings::migration_path( $migration_id, 'import' );
		$path        = self::resolve_path( $migration_id, $prefer );
		$issues      = array();

		$export_exists = is_dir( $export_path );
		$import_exists = is_dir( $import_path );

		if ( ! $path ) {
			$issues[] = array(
				'code'     => 'path_missing',
				'severity' => 'error',
				'message'  => sprintf(
					/* translators: 1: export path, 2: import path */
					__( 'No migration folder found. Expected export at %1$s or import at %2$s.', 'the-exporter' ),
					$export_path,
					$import_path
				),
			);
		}

		$manifest_file   = $path ? trailingslashit( $path ) . 'manifest.json' : '';
		$manifest_exists = $manifest_file && file_exists( $manifest_file );
		$manifest        = $manifest_exists ? ManifestBuilder::load( $path ) : false;
		$finalized       = $manifest && ! empty( $manifest['checksums']['manifest_sha256'] );

		if ( $path && ! $manifest_exists ) {
			$issues[] = array(
				'code'     => 'manifest_missing',
				'severity' => 'error',
				'message'  => __( 'manifest.json is missing. Export your components, then click “Finalize Manifest”.', 'the-exporter' ),
			);
		} elseif ( $manifest_exists && ! $finalized ) {
			$issues[] = array(
				'code'     => 'manifest_not_finalized',
				'severity' => 'warning',
				'message'  => __( 'manifest.json exists but is not finalized. Click “Finalize Manifest” on the Export page.', 'the-exporter' ),
			);
		}

		$components = self::get_components( $migration_id, $prefer );
		$file_count = 0;
		foreach ( $components as $comp ) {
			$file_count += (int) $comp['file_count'];
		}

		if ( $path && $manifest_exists && 0 === $file_count ) {
			if ( $finalized && is_array( $manifest ) && empty( $manifest['transfer_catalog'] ) ) {
				$issues[] = array(
					'code'     => 'catalog_missing',
					'severity' => 'error',
					'message'  => __( 'Manifest has no transfer catalog. On the source site, open Export and click “Finalize Manifest” again, then re-upload manifest.json.', 'the-exporter' ),
				);
			} else {
				$issues[] = array(
					'code'     => 'no_package_files',
					'severity' => 'error',
					'message'  => __( 'Manifest found but no component files were detected. Re-run export for each subject.', 'the-exporter' ),
				);
			}
		}

		$ready = $path && $manifest_exists && $file_count > 0 && empty( array_filter( $issues, function ( $i ) {
			return 'error' === $i['severity'];
		} ) );

		$summary = __( 'Status unknown.', 'the-exporter' );
		if ( $ready ) {
			$summary = sprintf(
				/* translators: 1: component count, 2: file count */
				__( 'Ready: %1$d subjects, %2$d files available for download.', 'the-exporter' ),
				count( $components ),
				$file_count
			);
		} elseif ( ! empty( $issues ) ) {
			$summary = $issues[0]['message'];
		} elseif ( ! $path ) {
			$summary = __( 'Migration folder not found.', 'the-exporter' );
		}

		return array(
			'migration_id'       => $migration_id,
			'context'            => $prefer,
			'resolved_path'      => $path ? $path : '',
			'export_path'        => $export_path,
			'import_path'        => $import_path,
			'export_dir_exists'  => $export_exists,
			'import_dir_exists'  => $import_exists,
			'manifest_exists'    => $manifest_exists,
			'manifest_finalized' => $finalized,
			'component_count'    => count( $components ),
			'file_count'         => $file_count,
			'ready_for_download' => $ready,
			'issues'             => $issues,
			'summary'            => $summary,
		);
	}
}
