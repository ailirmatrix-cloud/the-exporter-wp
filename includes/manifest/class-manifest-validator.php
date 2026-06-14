<?php
/**
 * Manifest and package validation.
 *
 * @package TheExporter
 */

namespace TheExporter\Manifest;

defined( 'ABSPATH' ) || exit;

/**
 * Class ManifestValidator
 */
class ManifestValidator {

	/**
	 * Run full pre-import validation.
	 *
	 * @param string $migration_path Migration directory path.
	 * @param bool   $dry_run        Dry run (no writes).
	 * @return array Validation report.
	 */
	public static function validate( $migration_path, $dry_run = true, $expected_migration_id = '' ) {
		$report = array(
			'dry_run'    => $dry_run,
			'passed'     => true,
			'checked_at' => gmdate( 'c' ),
			'checks'     => array(),
			'warnings'   => array(),
			'errors'     => array(),
		);

		$manifest = ManifestBuilder::load( $migration_path );
		if ( ! $manifest ) {
			$report['passed']   = false;
			$report['errors'][] = array(
				'code'    => 'manifest_missing',
				'message' => __( 'manifest.json not found.', 'the-exporter' ),
			);
			return $report;
		}

		if ( $expected_migration_id ) {
			$id_check = self::verify_migration_id( $migration_path, $expected_migration_id, $manifest );
			if ( ! $id_check['passed'] ) {
				$report['passed']    = false;
				$report['errors'][] = array(
					'code'    => 'migration_id_mismatch',
					'message' => $id_check['message'],
				);
				return $report;
			}
			$report['checks'][] = array( 'name' => 'migration_id', 'status' => 'pass' );
		}

		self::check_manifest_checksum( $migration_path, $manifest, $report );
		self::check_version_compatibility( $manifest, $report );
		self::check_components( $migration_path, $manifest, $report );
		self::check_disk_space( $migration_path, $manifest, $report );

		$report['passed'] = empty( $report['errors'] );
		return $report;
	}

	/**
	 * Verify folder migration ID matches manifest.
	 *
	 * @param string     $migration_path        Path.
	 * @param string     $expected_migration_id Expected ID.
	 * @param array|null $manifest              Preloaded manifest.
	 * @return array
	 */
	public static function verify_migration_id( $migration_path, $expected_migration_id, $manifest = null ) {
		$expected_migration_id = sanitize_text_field( $expected_migration_id );
		$manifest = $manifest ?: ManifestBuilder::load( $migration_path );

		if ( ! $manifest || empty( $manifest['migration_id'] ) ) {
			return array(
				'passed'  => false,
				'message' => __( 'Manifest has no migration_id.', 'the-exporter' ),
			);
		}

		if ( ! hash_equals( $manifest['migration_id'], $expected_migration_id ) ) {
			return array(
				'passed'  => false,
				'message' => sprintf(
					/* translators: 1: manifest id, 2: expected id */
					__( 'Migration ID mismatch: manifest says %1$s but you entered %2$s.', 'the-exporter' ),
					$manifest['migration_id'],
					$expected_migration_id
				),
			);
		}

		return array( 'passed' => true, 'message' => '' );
	}

	/**
	 * Validate a single component package.
	 *
	 * @param string $migration_path Migration directory path.
	 * @param string $component      Component name.
	 * @param bool   $dry_run        Dry run.
	 * @return array
	 */
	public static function validate_component( $migration_path, $component, $dry_run = true ) {
		$report = array(
			'dry_run'    => $dry_run,
			'passed'     => true,
			'component'  => $component,
			'checked_at' => gmdate( 'c' ),
			'checks'     => array(),
			'warnings'   => array(),
			'errors'     => array(),
		);

		$manifest = ManifestBuilder::load( $migration_path );
		if ( ! $manifest ) {
			$report['passed']   = false;
			$report['errors'][] = array(
				'code'    => 'manifest_missing',
				'message' => __( 'manifest.json not found.', 'the-exporter' ),
			);
			return $report;
		}

		$max_bytes = (int) \TheExporter\Settings::get( 'browser_transfer_max_bytes', 67108864 );
		$comp_meta = null;
		$manifest_components = isset( $manifest['components'] ) && is_array( $manifest['components'] )
			? $manifest['components']
			: array();
		foreach ( $manifest_components as $c ) {
			if ( isset( $c['name'] ) && $c['name'] === $component ) {
				$comp_meta = $c;
				break;
			}
		}

		if ( ! $comp_meta ) {
			$report['passed']   = false;
			$report['errors'][] = array(
				'code'    => 'component_not_in_manifest',
				'message' => sprintf( __( 'Component %s not found in manifest.', 'the-exporter' ), $component ),
			);
			return $report;
		}

		self::check_single_component( $migration_path, $component, $comp_meta, $report, $max_bytes );
		$report['passed'] = empty( $report['errors'] );

		if ( preg_match( '/migration-([a-f0-9\-]+)$/i', $migration_path, $m ) ) {
			\TheExporter\Transfer\TransferStatus::mark_validated( $m[1], $component, $report );
		}

		return $report;
	}

	/**
	 * Verify manifest checksum (L3).
	 *
	 * @param string $path     Migration path.
	 * @param array  $manifest Manifest.
	 * @param array  $report   Report ref.
	 */
	private static function check_manifest_checksum( $path, array $manifest, array &$report ) {
		$manifest_file = trailingslashit( $path ) . 'manifest.json';
		$expected      = isset( $manifest['checksums']['manifest_sha256'] ) ? $manifest['checksums']['manifest_sha256'] : '';

		if ( $expected ) {
			$actual = ManifestBuilder::file_checksum_without_embedded_hash( $manifest_file );
			$valid  = $actual && hash_equals( strtolower( $expected ), strtolower( (string) $actual ) );
			$report['checks'][] = array(
				'layer'  => 'L3',
				'name'   => 'manifest_checksum',
				'status' => $valid ? 'pass' : 'fail',
			);
			if ( ! $valid ) {
				$report['errors'][] = array(
					'code'    => 'manifest_checksum_mismatch',
					'message' => __( 'Manifest checksum verification failed.', 'the-exporter' ),
				);
			}
		} else {
			$report['warnings'][] = array(
				'code'    => 'manifest_not_finalized',
				'message' => __( 'Manifest has no sealed checksum (export may not be finalized).', 'the-exporter' ),
			);
		}
	}

	/**
	 * Check WP/PHP version compatibility.
	 *
	 * @param array $manifest Manifest.
	 * @param array $report   Report ref.
	 */
	private static function check_version_compatibility( array $manifest, array &$report ) {
		$source_wp = isset( $manifest['source']['wp_version'] ) ? $manifest['source']['wp_version'] : '';
		$local_wp  = get_bloginfo( 'version' );

		$source_major = (int) explode( '.', $source_wp )[0];
		$local_major  = (int) explode( '.', $local_wp )[0];

		if ( $source_major && $local_major && abs( $source_major - $local_major ) > 1 ) {
			$report['warnings'][] = array(
				'code'    => 'wp_version_mismatch',
				'message' => sprintf(
					/* translators: 1: source version, 2: local version */
					__( 'WordPress version gap: source %1$s, destination %2$s.', 'the-exporter' ),
					$source_wp,
					$local_wp
				),
			);
		}

		$report['checks'][] = array(
			'name'   => 'version_compatibility',
			'status' => 'pass',
			'source' => $manifest['source'],
			'local'  => array(
				'wp_version'  => $local_wp,
				'php_version' => PHP_VERSION,
			),
		);
	}

	/**
	 * Validate each component package.
	 *
	 * @param string $path     Migration path.
	 * @param array  $manifest Manifest.
	 * @param array  $report   Report ref.
	 */
	private static function check_components( $path, array $manifest, array &$report ) {
		$components = isset( $manifest['components'] ) ? $manifest['components'] : array();
		$max_bytes  = (int) \TheExporter\Settings::get( 'browser_transfer_max_bytes', 67108864 );

		foreach ( $components as $component ) {
			self::check_single_component( $path, $component['name'], $component, $report, $max_bytes );
		}
	}

	/**
	 * Validate one component's chunks.
	 *
	 * @param string $path      Migration path.
	 * @param string $name      Component name.
	 * @param array  $component Component manifest entry.
	 * @param array  $report    Report ref.
	 * @param int    $max_bytes Browser max bytes.
	 */
	private static function check_single_component( $path, $name, array $component, array &$report, $max_bytes = 0 ) {
		$inventory_file = isset( $component['inventory_file'] ) ? $component['inventory_file'] : $name . '/inventory.json';
		$inventory_path = trailingslashit( $path ) . $inventory_file;
		$manifest         = ManifestBuilder::load( $path );
		$inventory        = null;

		if ( file_exists( $inventory_path ) ) {
			$inventory = json_decode( file_get_contents( $inventory_path ), true );
		} elseif ( is_array( $manifest ) && ! empty( $manifest['transfer_catalog'][ $name ] ) ) {
			$inventory = $manifest['transfer_catalog'][ $name ];
			$report['warnings'][] = array(
				'code'      => 'inventory_synthesized',
				'component' => $name,
				'message'   => sprintf( __( 'Using manifest catalog for %s (inventory.json will be created when all files are uploaded).', 'the-exporter' ), $name ),
			);
		}

		if ( ! is_array( $inventory ) ) {
			$report['errors'][] = array(
				'code'      => 'inventory_missing',
				'component' => $name,
				'message'   => sprintf( __( 'Missing inventory for %s. Upload manifest.json first, then all package files.', 'the-exporter' ), $name ),
			);
			return;
		}

		$chunks = isset( $inventory['chunks'] ) ? $inventory['chunks'] : array();

		if ( isset( $inventory['verification_mode'] ) && 'segment' === $inventory['verification_mode'] ) {
			$report['checks'][] = array(
				'name'   => $name,
				'status' => 'pass',
				'layer'  => 'L1-fast',
				'message' => __( 'Segment-level verification (fast export).', 'the-exporter' ),
			);
		}

		if ( empty( $chunks ) ) {
			$report['checks'][] = array(
				'name'    => $name,
				'status'  => 'skip',
				'message' => sprintf( __( 'No files for %s (empty export).', 'the-exporter' ), $name ),
			);
			return;
		}

		foreach ( $chunks as $chunk ) {
			$chunk_rel  = isset( $chunk['path'] ) ? $chunk['path'] : '';
			if ( $chunk_rel && strpos( $chunk_rel, $name . '/' ) !== 0 ) {
				$chunk_rel = $name . '/' . ltrim( $chunk_rel, '/' );
			}
			$chunk_path = trailingslashit( $path ) . $chunk_rel;

			if ( $max_bytes && isset( $chunk['size'] ) && (int) $chunk['size'] > $max_bytes ) {
				$report['warnings'][] = array(
					'code'      => 'chunk_oversized_browser',
					'component' => $name,
					'path'      => $chunk_rel,
					'message'   => sprintf( __( '%s exceeds browser transfer limit.', 'the-exporter' ), $chunk_rel ),
				);
			}

			if ( ! file_exists( $chunk_path ) ) {
				$report['errors'][] = array(
					'code'      => 'chunk_missing',
					'component' => $name,
					'path'      => $chunk_rel,
					'message'   => sprintf( __( 'Missing chunk: %s', 'the-exporter' ), $chunk_rel ),
				);
				continue;
			}

			if ( ! empty( $chunk['checksum'] ) ) {
				$valid = \TheExporter\Validation\ChecksumService::verify_file( $chunk_path, $chunk['checksum'] );
				$report['checks'][] = array(
					'layer'     => 'L2',
					'component' => $name,
					'path'      => $chunk_rel,
					'status'    => $valid ? 'pass' : 'fail',
				);
				if ( ! $valid ) {
					$report['errors'][] = array(
						'code'      => 'chunk_checksum_mismatch',
						'component' => $name,
						'path'      => $chunk_rel,
						'message'   => sprintf( __( 'Checksum failed: %s', 'the-exporter' ), $chunk_rel ),
					);
				}
			}
		}
	}

	/**
	 * Estimate disk space requirement.
	 *
	 * @param string $path     Migration path.
	 * @param array  $manifest Manifest.
	 * @param array  $report   Report ref.
	 */
	private static function check_disk_space( $path, array $manifest, array &$report ) {
		$total_bytes = 0;
		$components = isset( $manifest['components'] ) && is_array( $manifest['components'] )
			? $manifest['components']
			: array();
		foreach ( $components as $component ) {
			$total_bytes += isset( $component['total_bytes'] ) ? (int) $component['total_bytes'] : 0;
		}

		$free = @disk_free_space( WP_CONTENT_DIR );
		$report['checks'][] = array(
			'name'           => 'disk_space',
			'required_bytes' => $total_bytes * 2,
			'free_bytes'     => $free,
			'status'         => ( $free && $free > $total_bytes * 2 ) ? 'pass' : 'warn',
		);

		if ( $free && $free < $total_bytes * 2 ) {
			$report['warnings'][] = array(
				'code'    => 'disk_space_low',
				'message' => __( 'Insufficient disk space for safe import (need ~2x package size).', 'the-exporter' ),
			);
		}
	}
}
