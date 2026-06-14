<?php
/**
 * Plugin settings.
 *
 * @package TheExporter
 */

namespace TheExporter;

defined( 'ABSPATH' ) || exit;

/**
 * Class Settings
 */
class Settings {

	const OPTION_KEY = 'te_settings';

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'export_base_path'    => WP_CONTENT_DIR . '/migration-exports',
			'import_base_path'    => WP_CONTENT_DIR . '/migration-imports',
			'restore_base_path'   => WP_CONTENT_DIR . '/migration-restore-points',
			'chunk_size_bytes'    => 1073741824, // 1 GB.
			'chunk_min_bytes'     => 524288000,  // 500 MB.
			'chunk_max_bytes'     => 2147483648, // 2 GB.
			'exclude_patterns'    => array(
				'**/cache/**',
				'**/*.log',
				'**/migration-exports/**',
				'**/migration-imports/**',
				'**/backup*/**',
				'**/node_modules/**',
				'**/.git/**',
			'**/et-cache/**',
			'**/.sass-cache/**',
			'**/includes/builder/feature/dynamic-assets/**',
				'**/*.map',
			),
			'fast_export'         => true,
			'max_files_per_segment' => 400,
			'compression_level'   => 'fast',
			'segment_compression' => 'auto',
			'database_engine'     => 'auto',
			'export_worker_concurrency' => 'auto',
			'exclude_db_tables'   => array(),
			'active_migration_id' => '',
			'transfer_mode'       => 'browser',
			'large_segments_sftp' => false,
			'remote_site_url'     => '',
			'remote_site_url_push' => '',
			'remote_pairing_token' => '',
			'remote_auto_push'    => false,
			'site_role'           => '',
			'browser_transfer_max_bytes'     => 67108864,  // 64 MB.
			'browser_transfer_min_bytes'     => 1048576,   // 1 MB.
			'browser_transfer_max_bytes_cap' => 134217728, // 128 MB.
			'connected_segment_bytes'        => 67108864,  // 64 MB segments for site-to-site.
			'connected_segment_bytes_local'  => 134217728, // 128 MB for localhost peers.
			'peer_export_base_path'          => '',
			'peer_php_limits'                => array(),
		);
	}

	/**
	 * Init settings hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/**
	 * Register settings.
	 */
	public static function register_settings() {
		register_setting( 'te_settings_group', self::OPTION_KEY, array(
			'type'              => 'array',
			'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			'default'           => self::defaults(),
		) );
	}

	/**
	 * Sanitize settings input.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$output   = wp_parse_args( is_array( $input ) ? $input : array(), $defaults );

		$output['export_base_path']  = self::sanitize_storage_path( $output['export_base_path'], $defaults['export_base_path'] );
		$output['import_base_path']  = self::sanitize_storage_path( $output['import_base_path'], $defaults['import_base_path'] );
		$output['restore_base_path'] = self::sanitize_storage_path( $output['restore_base_path'], $defaults['restore_base_path'] );
		$output['chunk_size_bytes']  = max(
			(int) $output['chunk_min_bytes'],
			min( (int) $output['chunk_max_bytes'], absint( $output['chunk_size_bytes'] ) )
		);

		$cap = isset( $output['browser_transfer_max_bytes_cap'] ) ? (int) $output['browser_transfer_max_bytes_cap'] : 134217728;
		$min = isset( $output['browser_transfer_min_bytes'] ) ? (int) $output['browser_transfer_min_bytes'] : 1048576;
		$output['browser_transfer_max_bytes'] = max( $min, min( $cap, absint( $output['browser_transfer_max_bytes'] ) ) );
		$output['large_segments_sftp']        = ! empty( $output['large_segments_sftp'] );
		$mode = isset( $output['transfer_mode'] ) ? sanitize_key( $output['transfer_mode'] ) : 'browser';
		$output['transfer_mode']              = in_array( $mode, array( 'browser', 'sftp', 'connected' ), true ) ? $mode : 'browser';
		if ( 'sftp' === $output['transfer_mode'] ) {
			$output['large_segments_sftp'] = true;
		}
		if ( 'connected' === $output['transfer_mode'] ) {
			$output['large_segments_sftp'] = false;
		}
		$output['connected_segment_bytes'] = max( 16777216, min( 268435456, absint( $output['connected_segment_bytes'] ?? 67108864 ) ) );
		$output['connected_segment_bytes_local'] = max( 33554432, min( 268435456, absint( $output['connected_segment_bytes_local'] ?? 134217728 ) ) );
		$output['peer_export_base_path'] = sanitize_text_field( $output['peer_export_base_path'] ?? '' );
		$output['remote_site_url'] = self::sanitize_remote_url( $output['remote_site_url'] ?? '' );
		$push_url = isset( $output['remote_site_url_push'] ) ? $output['remote_site_url_push'] : '';
		$output['remote_site_url_push'] = $push_url ? self::sanitize_remote_url( $push_url ) : '';
		$output['remote_pairing_token'] = sanitize_text_field( $output['remote_pairing_token'] ?? '' );
		$output['remote_auto_push'] = ! empty( $output['remote_auto_push'] );
		$site_role = isset( $output['site_role'] ) ? sanitize_key( $output['site_role'] ) : '';
		$output['site_role']        = in_array( $site_role, array( 'export', 'import' ), true ) ? $site_role : '';
		$output['fast_export']               = ! empty( $output['fast_export'] );
		$output['max_files_per_segment']     = max( 50, min( 2000, absint( $output['max_files_per_segment'] ) ) );
		$compression = isset( $output['compression_level'] ) ? sanitize_key( $output['compression_level'] ) : 'fast';
		$output['compression_level']         = in_array( $compression, array( 'fast', 'normal' ), true ) ? $compression : 'fast';
		$seg_comp = isset( $output['segment_compression'] ) ? sanitize_key( $output['segment_compression'] ) : 'auto';
		$output['segment_compression']       = in_array( $seg_comp, array( 'auto', 'store', 'gzip_fast', 'gzip', 'zstd' ), true ) ? $seg_comp : 'auto';
		$db_engine = isset( $output['database_engine'] ) ? sanitize_key( $output['database_engine'] ) : 'auto';
		$output['database_engine']           = in_array( $db_engine, array( 'auto', 'mydumper', 'mysqldump', 'php' ), true ) ? $db_engine : 'auto';
		$workers = isset( $output['export_worker_concurrency'] ) ? $output['export_worker_concurrency'] : 'auto';
		if ( 'auto' !== $workers ) {
			$output['export_worker_concurrency'] = max( 1, min( 8, absint( $workers ) ) );
		} else {
			$output['export_worker_concurrency'] = 'auto';
		}

		if ( ! empty( $output['exclude_patterns'] ) ) {
			if ( is_string( $output['exclude_patterns'] ) ) {
				$output['exclude_patterns'] = array_filter( array_map( 'trim', explode( "\n", $output['exclude_patterns'] ) ) );
			} elseif ( is_array( $output['exclude_patterns'] ) ) {
				$output['exclude_patterns'] = array_filter( array_map( 'trim', $output['exclude_patterns'] ) );
			}
			$output['exclude_patterns'] = self::normalize_exclude_patterns( $output['exclude_patterns'] );
		}

		return $output;
	}

	/**
	 * Drop legacy patterns that skip required plugin/theme files (e.g. Divi/monarch core/).
	 *
	 * @param array $patterns Exclude patterns.
	 * @return array
	 */
	private static function normalize_exclude_patterns( array $patterns ) {
		$blocked = array( '**/core/**', 'core/**', '**/core' );
		return array_values( array_filter( $patterns, static function ( $pattern ) use ( $blocked ) {
			$pattern = ltrim( str_replace( '\\', '/', (string) $pattern ), '/' );
			return ! in_array( $pattern, $blocked, true );
		} ) );
	}

	/**
	 * Restrict storage paths to wp-content for safety.
	 *
	 * @param string $path     Submitted path.
	 * @param string $fallback Default path.
	 * @return string
	 */
	private static function sanitize_storage_path( $path, $fallback ) {
		$path = wp_normalize_path( trim( sanitize_text_field( $path ) ) );
		if ( '' === $path ) {
			return $fallback;
		}

		$content_dir = wp_normalize_path( WP_CONTENT_DIR );
		if ( 0 !== strpos( $path, $content_dir ) ) {
			return $fallback;
		}

		return untrailingslashit( $path );
	}

	/**
	 * Get all settings.
	 *
	 * @return array
	 */
	public static function get_all() {
		$all = wp_parse_args( get_option( self::OPTION_KEY, array() ), self::defaults() );
		if ( ! empty( $all['exclude_patterns'] ) && is_array( $all['exclude_patterns'] ) ) {
			$all['exclude_patterns'] = self::normalize_exclude_patterns( $all['exclude_patterns'] );
		}
		return $all;
	}

	/**
	 * Get single setting.
	 *
	 * @param string $key Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::get_all();
		return isset( $all[ $key ] ) ? $all[ $key ] : $default;
	}

	/**
	 * Update settings.
	 *
	 * @param array $values Values to merge.
	 */
	public static function update( array $values ) {
		$current = self::get_all();
		update_option( self::OPTION_KEY, array_merge( $current, $values ) );
	}

	/**
	 * Get migration directory path.
	 *
	 * @param string $migration_id Migration UUID.
	 * @param string $type         export|import.
	 * @return string
	 */
	public static function migration_path( $migration_id, $type = 'export' ) {
		$base = 'import' === $type ? self::get( 'import_base_path' ) : self::get( 'export_base_path' );
		return trailingslashit( $base ) . 'migration-' . sanitize_file_name( $migration_id );
	}

	/**
	 * Effective segment size (capped for browser transfer).
	 *
	 * @return int
	 */
	public static function effective_segment_size() {
		if ( self::uses_large_segments() ) {
			return (int) self::get( 'chunk_size_bytes' );
		}
		if ( self::is_connected_transfer() ) {
			if ( self::is_localhost_peer() ) {
				return (int) self::get( 'connected_segment_bytes_local', 134217728 );
			}
			return (int) self::get( 'connected_segment_bytes', 67108864 );
		}
		return min(
			(int) self::get( 'chunk_size_bytes' ),
			(int) self::get( 'browser_transfer_max_bytes', 67108864 )
		);
	}

	/**
	 * Whether large segments (500MB–2GB) are enabled.
	 *
	 * @return bool
	 */
	public static function uses_large_segments() {
		return self::is_sftp_transfer() || self::get( 'large_segments_sftp' );
	}

	/**
	 * Adaptive HTTP chunk size for site-to-site push/receive.
	 *
	 * @return int
	 */
	public static function transfer_chunk_size() {
		if ( self::is_localhost_peer() ) {
			return 33554432; // 32 MB localhost peers.
		}
		$limit = function_exists( 'wp_convert_hr_to_bytes' )
			? (int) wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) )
			: self::ini_size_to_bytes( ini_get( 'memory_limit' ) );

		if ( $limit > 0 && $limit <= 134217728 ) {
			return 8388608; // 8 MB shared hosting.
		}
		if ( defined( 'TE_WORKER_SOURCE' ) && 'cli' === TE_WORKER_SOURCE ) {
			return 33554432; // 32 MB CLI worker.
		}
		$profile = EnvironmentProfile::detect();
		if ( ! empty( $profile['wp_cli'] ) || ! empty( $profile['action_scheduler'] ) ) {
			return 33554432;
		}
		return 16777216; // 16 MB default worker.
	}

	/**
	 * HTTP chunk size capped by import peer PHP post_max_size (set during pairing).
	 *
	 * @param int|null $override Optional per-file override from push meta.
	 * @return int Minimum 1 MB.
	 */
	public static function effective_peer_chunk_size( $override = null ) {
		$local = null !== $override ? max( 1048576, (int) $override ) : self::transfer_chunk_size();
		$peer  = self::get( 'peer_php_limits', array() );
		if ( ! is_array( $peer ) ) {
			return $local;
		}
		$post_max = isset( $peer['post_max_size'] ) ? (int) $peer['post_max_size'] : 0;
		if ( $post_max <= 0 ) {
			return $local;
		}
		$cap = (int) floor( $post_max * 0.85 );
		return max( 1048576, min( $local, $cap ) );
	}

	/**
	 * Whether peer PHP limits are too low for connected chunk transfer.
	 *
	 * @return bool
	 */
	public static function peer_chunk_transfer_viable() {
		$peer = self::get( 'peer_php_limits', array() );
		if ( ! is_array( $peer ) || empty( $peer['post_max_size'] ) ) {
			return true;
		}
		return (int) $peer['post_max_size'] >= 12582912; // 12 MB minimum for ~8 MB chunks.
	}

	/**
	 * Transfer mode: browser, sftp (folder), or connected.
	 *
	 * @return string browser|sftp|connected
	 */
	public static function transfer_mode() {
		$mode = self::get( 'transfer_mode', 'browser' );
		return in_array( $mode, array( 'browser', 'sftp', 'connected' ), true ) ? $mode : 'browser';
	}

	/**
	 * Whether manual folder transfer is the primary workflow.
	 *
	 * @return bool
	 */
	public static function is_sftp_transfer() {
		return 'sftp' === self::transfer_mode();
	}

	/**
	 * Whether connected site auto-push is enabled.
	 *
	 * @return bool
	 */
	public static function is_connected_transfer() {
		return 'connected' === self::transfer_mode();
	}

	/**
	 * Whether the paired site is on localhost (Studio / Docker).
	 *
	 * @return bool
	 */
	public static function is_localhost_peer() {
		if ( ! self::is_connected_transfer() ) {
			return false;
		}
		$remote = self::remote_site_url();
		$push   = (string) self::get( 'remote_site_url_push', '' );
		return self::is_localhost_url( $remote )
			|| ( $push && self::is_localhost_url( $push ) )
			|| self::is_localhost_url( home_url() );
	}

	/**
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_localhost_url( $url ) {
		return (bool) preg_match( '#localhost|127\.0\.0\.1#i', (string) $url );
	}

	/**
	 * Whether this WordPress install is a local Studio/dev site.
	 *
	 * @return bool
	 */
	public static function is_localhost_studio() {
		return self::is_localhost_url( home_url() );
	}

	/**
	 * Export segment packing budget (seconds per tick).
	 *
	 * @return int
	 */
	public static function export_segment_time_budget() {
		return self::is_localhost_studio() ? 90 : 20;
	}

	/**
	 * Export segments per HTTP tick on local Studio.
	 *
	 * @return int
	 */
	public static function export_segments_per_tick() {
		return self::is_localhost_studio() ? 10 : 3;
	}

	/**
	 * Export drive batch time budget (seconds).
	 *
	 * @return int
	 */
	public static function export_drive_seconds() {
		return self::is_localhost_studio() ? 120 : 20;
	}

	/**
	 * Browser nudge time budget for connected transfer (seconds).
	 *
	 * @return int
	 */
	public static function transfer_drive_seconds() {
		$base = self::is_localhost_studio() ? 25 : 60;
		$max  = (int) ini_get( 'max_execution_time' );
		if ( $max > 0 && $max < 120 ) {
			return max( 10, $max - 5 );
		}
		return $base;
	}

	/**
	 * Host-visible export package path for local copy (import site).
	 *
	 * @param string $migration_id Migration ID.
	 * @return string
	 */
	public static function peer_export_package_path( $migration_id ) {
		$base = trim( (string) self::get( 'peer_export_base_path', '' ) );
		if ( '' === $base ) {
			return '';
		}
		$base = wp_normalize_path( untrailingslashit( $base ) );
		$path = $base . '/migration-' . sanitize_file_name( $migration_id );
		return is_dir( $path ) ? $path : '';
	}

	/**
	 * Remote import site URL for connected transfer.
	 *
	 * @return string
	 */
	public static function remote_site_url() {
		return (string) self::get( 'remote_site_url', '' );
	}

	/**
	 * Server-side push URL (Docker/Studio may differ from browser URL).
	 *
	 * @return string
	 */
	public static function effective_remote_push_url() {
		$browser = self::remote_site_url();
		$push    = (string) self::get( 'remote_site_url_push', '' );

		if ( '' !== $push && \TheExporter\Transfer\RemoteAuth::probe_reachable( $push ) ) {
			return $push;
		}

		if ( $browser ) {
			$resolved = \TheExporter\Transfer\RemoteAuth::resolve_server_url( $browser );
			if ( $resolved && $resolved !== $push ) {
				self::update( array( 'remote_site_url_push' => $resolved ) );
			}
			return $resolved ?: $browser;
		}

		return $push;
	}

	/**
	 * Sanitize remote site URL (HTTPS production, HTTP localhost for dev).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function sanitize_remote_url( $url ) {
		$normalized = \TheExporter\Transfer\RemoteAuth::normalize_site_url( $url );
		return $normalized ? $normalized : '';
	}

	/**
	 * Estimate disk space required for import (package + staging overhead).
	 *
	 * @param int $package_bytes Total migration bytes.
	 * @return array
	 */
	public static function estimate_import_disk_bytes( $package_bytes ) {
		$package_bytes = max( 0, (int) $package_bytes );
		$staging       = (int) ceil( $package_bytes * 0.15 );
		$peak          = $package_bytes + $staging;
		return array(
			'package_bytes' => $package_bytes,
			'staging_bytes' => $staging,
			'peak_bytes'    => $peak,
			'formula'       => 'package + 15% staging (rename reduces copy overhead)',
		);
	}

	/**
	 * Max files packed into one browser-safe segment.
	 *
	 * @return int
	 */
	public static function max_files_per_segment() {
		return (int) self::get( 'max_files_per_segment', 400 );
	}

	/**
	 * Max files per segment adjusted for host pack speed.
	 *
	 * Without shell tar, smaller batches avoid multi-minute HTTP stalls (Studio, shared hosting).
	 *
	 * @return int
	 */
	public static function effective_max_files_per_segment() {
		$max     = self::max_files_per_segment();
		$profile = EnvironmentProfile::detect();
		if ( empty( $profile['tar'] ) ) {
			return min( $max, 100 );
		}
		return $max;
	}

	/**
	 * Whether fast export (segment-level verification) is enabled.
	 *
	 * @return bool
	 */
	public static function is_fast_export() {
		return (bool) self::get( 'fast_export', true );
	}

	/**
	 * Gzip compression level for tar segments.
	 *
	 * @return string fast|normal
	 */
	public static function compression_level() {
		$level = self::get( 'compression_level', 'fast' );
		return in_array( $level, array( 'fast', 'normal' ), true ) ? $level : 'fast';
	}

	/**
	 * Segment compression mode.
	 *
	 * @return string auto|store|gzip_fast|gzip|zstd
	 */
	public static function segment_compression() {
		$mode = self::get( 'segment_compression', 'auto' );
		return in_array( $mode, array( 'auto', 'store', 'gzip_fast', 'gzip', 'zstd' ), true ) ? $mode : 'auto';
	}

	/**
	 * Database export engine preference.
	 *
	 * @return string auto|mydumper|mysqldump|php
	 */
	public static function database_engine() {
		$engine = self::get( 'database_engine', 'auto' );
		return in_array( $engine, array( 'auto', 'mydumper', 'mysqldump', 'php' ), true ) ? $engine : 'auto';
	}

	/**
	 * Parallel segment worker concurrency.
	 *
	 * @return int|string auto or 1-8
	 */
	public static function export_worker_concurrency() {
		$val = self::get( 'export_worker_concurrency', 'auto' );
		if ( 'auto' === $val ) {
			return 'auto';
		}
		return max( 1, min( 8, (int) $val ) );
	}

	/**
	 * Resolved worker count for parallel segment packing.
	 *
	 * @return int
	 */
	public static function resolved_export_worker_concurrency() {
		$val = self::export_worker_concurrency();
		if ( 'auto' !== $val ) {
			return (int) $val;
		}
		if ( self::is_localhost_studio() ) {
			return 6;
		}
		$profile = EnvironmentProfile::detect();
		return ! empty( $profile['tar'] ) ? 4 : 2;
	}

	/**
	 * Estimate segment count using byte and file-count caps.
	 *
	 * @param int $bytes_total Total bytes.
	 * @param int $files_total Total files.
	 * @return int
	 */
	public static function estimate_segment_count( $bytes_total, $files_total ) {
		$chunk_size = max( 1, (int) self::effective_segment_size() );
		$max_files  = max( 1, (int) self::effective_max_files_per_segment() );
		$by_bytes   = (int) ceil( max( 0, (int) $bytes_total ) / $chunk_size );
		$by_files   = (int) ceil( max( 0, (int) $files_total ) / $max_files );
		return max( 1, $by_bytes, $by_files );
	}

	/**
	 * Parse PHP ini size string to bytes.
	 *
	 * @param string $val Ini value.
	 * @return int
	 */
	public static function ini_size_to_bytes( $val ) {
		$val  = trim( $val );
		$last = strtolower( $val[ strlen( $val ) - 1 ] );
		$num  = (int) $val;
		switch ( $last ) {
			case 'g':
				$num *= 1024;
				// no break
			case 'm':
				$num *= 1024;
				// no break
			case 'k':
				$num *= 1024;
		}
		return $num;
	}

	/**
	 * Get PHP upload limits for UI warnings.
	 *
	 * @return array
	 */
	public static function php_upload_limits() {
		return array(
			'upload_max_filesize' => self::ini_size_to_bytes( ini_get( 'upload_max_filesize' ) ),
			'post_max_size'       => self::ini_size_to_bytes( ini_get( 'post_max_size' ) ),
		);
	}

	/**
	 * Apply a migration profile with sensible defaults for the wizard.
	 *
	 * @param string $profile connected|sftp|browser
	 */
	public static function apply_profile( $profile = 'connected' ) {
		$profile = sanitize_key( $profile );
		if ( 'sftp' === $profile ) {
			self::update( array(
				'transfer_mode'       => 'sftp',
				'large_segments_sftp' => true,
				'fast_export'         => true,
				'remote_auto_push'    => false,
			) );
			return;
		}
		if ( 'browser' === $profile ) {
			self::update( array(
				'transfer_mode'       => 'browser',
				'large_segments_sftp' => false,
				'fast_export'         => true,
				'remote_auto_push'    => false,
			) );
			return;
		}
		self::update( array(
			'transfer_mode'            => 'connected',
			'large_segments_sftp'      => false,
			'connected_segment_bytes'  => 67108864,
			'fast_export'              => true,
			'segment_compression'      => 'store',
			'remote_auto_push'         => true,
		) );
	}
}
