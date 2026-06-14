<?php
/**
 * Restore point management.
 *
 * @package TheExporter
 */

namespace TheExporter\Restore;

use TheExporter\Database\Dumper;
use TheExporter\Files\InventoryBuilder;
use TheExporter\Files\SegmentWriter;
use TheExporter\Logging\AuditLogger;
use TheExporter\Settings;
use TheExporter\Validation\ChecksumService;

defined( 'ABSPATH' ) || exit;

/**
 * Class RestorePointManager
 */
class RestorePointManager {

	/**
	 * Create restore point before import.
	 *
	 * @param string $migration_id Migration ID.
	 * @param string $component    Component being imported.
	 * @return array
	 */
	public static function create( $migration_id, $component ) {
		$timestamp = gmdate( 'Y-m-d-His' );
		$base      = trailingslashit( Settings::get( 'restore_base_path' ) ) . $timestamp . '-' . sanitize_file_name( $migration_id );
		wp_mkdir_p( $base );

		$manifest = array(
			'created_at'   => gmdate( 'c' ),
			'migration_id' => $migration_id,
			'component'    => $component,
			'items'        => array(),
		);

		if ( 'database' === $component ) {
			$schema = Dumper::export_schema( $base . '/database' );
			if ( $schema ) {
				$manifest['items'][] = array(
					'type'     => 'database_schema',
					'path'     => 'database/schema.sql.gz',
					'checksum' => $schema['checksum'],
				);
			}
			foreach ( Dumper::get_tables() as $table ) {
				$result = Dumper::export_table( $table, $base . '/database/data' );
				if ( $result ) {
					$manifest['items'][] = array(
						'type'     => 'database_table',
						'table'    => $table,
						'path'     => 'database/data/' . basename( $result['path'] ),
						'checksum' => $result['checksum'],
					);
				}
			}
		} else {
			$map = array(
				'plugins'          => WP_CONTENT_DIR . '/plugins',
				'themes'           => WP_CONTENT_DIR . '/themes',
				'mu-plugins'       => WP_CONTENT_DIR . '/mu-plugins',
				'uploads'          => WP_CONTENT_DIR . '/uploads',
				'wp-content-other' => WP_CONTENT_DIR,
				'config'           => trailingslashit( Settings::get( 'restore_base_path' ) ) . 'config-snapshots',
			);
			if ( isset( $map[ $component ] ) && is_dir( $map[ $component ] ) ) {
				if ( 'config' === $component ) {
					wp_mkdir_p( $map[ $component ] );
					$env = $map[ $component ] . '/environment-backup-' . gmdate( 'Y-m-d-His' ) . '.json';
					if ( is_dir( WP_CONTENT_DIR ) ) {
						$manifest['items'][] = array( 'type' => 'config_snapshot', 'path' => basename( $env ) );
					}
				} else {
					$scanned  = InventoryBuilder::scan( $map[ $component ] );
					$segment_result = SegmentWriter::create_segments( $scanned, $map[ $component ], $base . '/' . $component );
					$manifest['items'] = $segment_result['chunks'];
				}
			}
		}

		$manifest_file = $base . '/restore-manifest.json';
		file_put_contents( $manifest_file, wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );
		ChecksumService::write_sidecar( $manifest_file );

		AuditLogger::log( 'restore_point_created', "Restore point for {$component}", array(
			'migration_id' => $migration_id,
			'component'    => $component,
			'path'         => $base,
		), 'success' );

		return array( 'success' => true, 'path' => $base, 'manifest' => $manifest );
	}

	/**
	 * List restore points.
	 *
	 * @return array
	 */
	public static function list_points() {
		$base = Settings::get( 'restore_base_path' );
		if ( ! is_dir( $base ) ) {
			return array();
		}

		$points = array();
		foreach ( scandir( $base ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$full = $base . '/' . $entry;
			if ( is_dir( $full ) && file_exists( $full . '/restore-manifest.json' ) ) {
				$points[] = array(
					'id'       => $entry,
					'path'     => $full,
					'manifest' => json_decode( file_get_contents( $full . '/restore-manifest.json' ), true ),
				);
			}
		}
		return $points;
	}
}
