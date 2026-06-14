<?php
/**
 * Host capability detection and recommended export profile.
 *
 * @package TheExporter
 */

namespace TheExporter;

defined( 'ABSPATH' ) || exit;

/**
 * Class EnvironmentProfile
 */
class EnvironmentProfile {

	/**
	 * Cached profile snapshot.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Detect host capabilities.
	 *
	 * @param bool $refresh Force refresh.
	 * @return array
	 */
	public static function detect( $refresh = false ) {
		if ( null !== self::$cache && ! $refresh ) {
			return self::$cache;
		}

		$exec = Runtime::exec_available();
		$profile = array(
			'exec'              => $exec,
			'tar'               => $exec && Runtime::command_exists( 'tar' ),
			'pigz'              => $exec && Runtime::command_exists( 'pigz' ),
			'gzip'              => $exec && Runtime::command_exists( 'gzip' ),
			'zstd'              => $exec && Runtime::command_exists( 'zstd' ),
			'find'              => $exec && Runtime::command_exists( 'find' ),
			'mydumper'          => $exec && Runtime::command_exists( 'mydumper' ),
			'myloader'          => $exec && Runtime::command_exists( 'myloader' ),
			'mysqldump'         => Database\Dumper::has_mysqldump(),
			'mysql_cli'         => Database\Importer::has_mysql_cli(),
			'phar'              => class_exists( 'PharData', false ),
			'php_tar'           => true,
			'action_scheduler'  => function_exists( 'as_schedule_single_action' ),
			'wp_cli'            => defined( 'WP_CLI' ) && WP_CLI,
			'pack_method'       => 'phar',
			'scan_method'       => 'php',
			'compression'       => 'gzip_fast',
			'database_engine'   => 'mysqldump',
		);

		if ( $profile['tar'] && $exec ) {
			$profile['pack_method'] = 'shell_tar';
		} else {
			$profile['pack_method'] = 'php_tar';
		}

		$profile['scan_method'] = $profile['find'] ? 'shell_find' : 'php';

		if ( $profile['zstd'] ) {
			$profile['compression'] = 'zstd';
		} elseif ( $profile['pigz'] || $profile['gzip'] ) {
			$profile['compression'] = 'gzip_fast';
		} else {
			$profile['compression'] = 'store';
		}

		if ( $profile['mydumper'] ) {
			$profile['database_engine'] = 'mydumper';
		} elseif ( $profile['mysqldump'] ) {
			$profile['database_engine'] = 'mysqldump';
		} else {
			$profile['database_engine'] = 'php';
		}

		self::$cache = $profile;
		return $profile;
	}

	/**
	 * Resolve segment compression mode from settings + host.
	 *
	 * @return string store|gzip_fast|gzip|zstd
	 */
	public static function effective_compression() {
		$setting = Settings::segment_compression();
		if ( 'auto' !== $setting ) {
			return $setting;
		}
		if ( Settings::is_localhost_studio() ) {
			return 'store';
		}
		$profile = self::detect();
		return $profile['compression'];
	}

	/**
	 * Resolve database engine from settings + host.
	 *
	 * @return string mydumper|mysqldump|php
	 */
	public static function effective_database_engine() {
		$setting = Settings::database_engine();
		if ( 'auto' !== $setting ) {
			return $setting;
		}
		$profile = self::detect();
		return $profile['database_engine'];
	}

	/**
	 * Segment file extension for compression mode.
	 *
	 * @param string|null $mode Compression mode.
	 * @return string
	 */
	public static function segment_extension( $mode = null ) {
		$mode = $mode ?: self::effective_compression();
		switch ( $mode ) {
			case 'zstd':
				return '.tar.zst';
			case 'store':
				return '.tar';
			case 'gzip':
			case 'gzip_fast':
			default:
				return '.tar.gz';
		}
	}
}
