<?php
/**
 * Migration manifest builder.
 *
 * @package TheExporter
 */

namespace TheExporter\Manifest;

defined( 'ABSPATH' ) || exit;

/**
 * Class ManifestBuilder
 */
class ManifestBuilder {

	/**
	 * Build initial manifest skeleton.
	 *
	 * @param string $migration_id Migration ID.
	 * @return array
	 */
	public static function skeleton( $migration_id ) {
		global $wpdb;

		return array(
			'schema_version'      => TE_SCHEMA_VERSION,
			'migration_id'        => $migration_id,
			'export_started_at'   => gmdate( 'c' ),
			'export_completed_at' => null,
			'source'              => self::collect_source_info(),
			'components'          => array(),
			'active_plugins'      => self::get_active_plugins(),
			'active_theme'        => wp_get_theme()->get_stylesheet(),
			'must_use_plugins'    => self::get_mu_plugins(),
			'database'            => array(
				'engine'          => self::detect_db_engine(),
				'table_count'     => 0,
				'total_rows'      => 0,
				'excluded_tables' => \TheExporter\Settings::get( 'exclude_db_tables', array() ),
			),
			'checksums'           => array(
				'manifest_sha256' => null,
				'chunks'          => array(),
			),
			'validation'          => array(
				'pre_import_checks'       => array(),
				'compatibility_warnings'  => array(),
			),
		);
	}

	/**
	 * Collect source environment info.
	 *
	 * @return array
	 */
	public static function collect_source_info() {
		global $wpdb;

		$mysql_version = '';
		if ( method_exists( $wpdb, 'db_version' ) ) {
			$mysql_version = $wpdb->db_version();
		}

		return array(
			'wp_version'    => get_bloginfo( 'version' ),
			'php_version'   => PHP_VERSION,
			'mysql_version' => $mysql_version,
			'site_url'      => site_url(),
			'home_url'      => home_url(),
			'table_prefix'  => $wpdb->prefix,
		);
	}

	/**
	 * Detect database engine label.
	 *
	 * @return string
	 */
	private static function detect_db_engine() {
		if ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) {
			return 'sqlite';
		}
		return 'mysql';
	}

	/**
	 * Get active plugins list.
	 *
	 * @return array
	 */
	private static function get_active_plugins() {
		$active = get_option( 'active_plugins', array() );
		return is_array( $active ) ? $active : array();
	}

	/**
	 * Get must-use plugins.
	 *
	 * @return array
	 */
	private static function get_mu_plugins() {
		if ( ! function_exists( 'get_mu_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$mu = get_mu_plugins();
		return array_keys( $mu );
	}

	/**
	 * Save manifest to migration directory.
	 *
	 * @param string $migration_id Migration ID.
	 * @param array  $manifest     Manifest data.
	 * @param bool   $finalize     Write checksum sidecar.
	 */
	public static function save( $migration_id, array $manifest, $finalize = false ) {
		$path = \TheExporter\Settings::migration_path( $migration_id );
		wp_mkdir_p( $path );

		if ( $finalize ) {
			$manifest['export_completed_at'] = gmdate( 'c' );
			$manifest['checksums']['manifest_sha256'] = null;
		}

		$json          = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$manifest_file = $path . '/manifest.json';
		file_put_contents( $manifest_file, $json );

		if ( $finalize ) {
			$hash = \TheExporter\Validation\ChecksumService::hash_file( $manifest_file );
			$manifest['checksums']['manifest_sha256'] = $hash;
			$json = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			file_put_contents( $manifest_file, $json );
			file_put_contents( $path . '/manifest.sha256', $hash . "\n" );
		}
	}

	/**
	 * Hash manifest JSON with embedded checksum removed (matches pre-finalize write).
	 *
	 * @param string $manifest_path Path to manifest.json.
	 * @return string|false
	 */
	public static function file_checksum_without_embedded_hash( $manifest_path ) {
		if ( ! is_readable( $manifest_path ) ) {
			return false;
		}

		$raw = file_get_contents( $manifest_path );
		if ( false === $raw ) {
			return false;
		}

		$stripped = preg_replace(
			'/"manifest_sha256"\s*:\s*"[a-f0-9]{64}"/i',
			'"manifest_sha256": null',
			$raw,
			1
		);

		if ( null === $stripped || $stripped === $raw ) {
			$stripped = preg_replace(
				'/"manifest_sha256"\s*:\s*null/',
				'"manifest_sha256": null',
				$raw,
				1
			);
		}

		return \TheExporter\Validation\ChecksumService::hash_string( $stripped );
	}

	/**
	 * Load manifest from path.
	 *
	 * @param string $migration_path Migration directory.
	 * @return array|false
	 */
	public static function load( $migration_path ) {
		$file = trailingslashit( $migration_path ) . 'manifest.json';
		if ( ! file_exists( $file ) ) {
			return false;
		}
		$data = json_decode( file_get_contents( $file ), true );
		return is_array( $data ) ? $data : false;
	}

	/**
	 * Update component in manifest.
	 *
	 * @param array  $manifest  Manifest.
	 * @param string $component Component name.
	 * @param array  $info      Component info.
	 * @return array
	 */
	public static function add_component( array $manifest, $component, array $info ) {
		$manifest['components'] = array_values( array_filter(
			$manifest['components'],
			function ( $c ) use ( $component ) {
				return $c['name'] !== $component;
			}
		) );
		$manifest['components'][] = array_merge( array( 'name' => $component ), $info );
		return $manifest;
	}
}
