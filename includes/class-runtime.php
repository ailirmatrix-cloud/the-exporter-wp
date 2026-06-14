<?php
/**
 * Runtime helpers for long operations.
 *
 * @package TheExporter
 */

namespace TheExporter;

defined( 'ABSPATH' ) || exit;

/**
 * Class Runtime
 */
class Runtime {

	/**
	 * Extend PHP execution time for migration work.
	 *
	 * @param int $seconds Seconds.
	 */
	public static function bump_time_limit( $seconds = 300 ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( max( 60, (int) $seconds ) );
		}
	}

	/**
	 * Bump memory when below target.
	 *
	 * @param string $target e.g. 512M.
	 */
	public static function bump_memory( $target = '512M' ) {
		$current = ini_get( 'memory_limit' );
		if ( '-1' === $current ) {
			return;
		}
		$cur_bytes = Settings::ini_size_to_bytes( $current );
		$target_bytes = Settings::ini_size_to_bytes( $target );
		if ( $target_bytes > $cur_bytes ) {
			@ini_set( 'memory_limit', $target );
		}
	}

	/**
	 * Prepare environment for heavy job.
	 */
	public static function prepare_job() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		} else {
			self::bump_time_limit( 3600 );
		}
		self::bump_memory( '1024M' );
	}

	/**
	 * Check whether a shell command is available (cross-platform).
	 *
	 * @param string $command Command name (e.g. mysqldump).
	 * @return bool
	 */
	public static function command_exists( $command ) {
		$command = preg_replace( '/[^a-zA-Z0-9._-]/', '', $command );
		if ( '' === $command ) {
			return false;
		}

		if ( self::exec_available() ) {
			if ( 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) ) {
				exec( 'where ' . escapeshellarg( $command ) . ' 2>NUL', $output, $code );
			} else {
				exec( 'command -v ' . escapeshellarg( $command ) . ' 2>/dev/null', $output, $code );
			}
			return 0 === $code && ! empty( $output );
		}

		if ( ! function_exists( 'shell_exec' ) ) {
			return false;
		}

		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		if ( in_array( 'shell_exec', $disabled, true ) ) {
			return false;
		}

		if ( 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) ) {
			$probe = 'where ' . escapeshellarg( $command ) . ' 2>NUL';
		} else {
			$probe = 'command -v ' . escapeshellarg( $command ) . ' 2>/dev/null';
		}

		$path = shell_exec( $probe );
		return ! empty( trim( (string) $path ) );
	}

	/**
	 * Whether PHP can run exec().
	 *
	 * @return bool
	 */
	public static function exec_available() {
		if ( ! function_exists( 'exec' ) ) {
			return false;
		}
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		return ! in_array( 'exec', $disabled, true );
	}
}
