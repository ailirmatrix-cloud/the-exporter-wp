<?php
/**
 * SHA-256 checksum service.
 *
 * @package TheExporter
 */

namespace TheExporter\Validation;

defined( 'ABSPATH' ) || exit;

/**
 * Class ChecksumService
 */
class ChecksumService {

	/**
	 * Compute SHA-256 hash of a file (streaming).
	 *
	 * @param string $file_path File path.
	 * @return string|false
	 */
	public static function hash_file( $file_path ) {
		if ( ! is_readable( $file_path ) ) {
			return false;
		}
		return hash_file( 'sha256', $file_path );
	}

	/**
	 * Compute SHA-256 hash of a string.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function hash_string( $content ) {
		return hash( 'sha256', $content );
	}

	/**
	 * Write sidecar checksum file.
	 *
	 * @param string $file_path File to hash.
	 * @return string|false Checksum or false on failure.
	 */
	public static function write_sidecar( $file_path ) {
		$hash = self::hash_file( $file_path );
		if ( false === $hash ) {
			return false;
		}

		$sidecar = $file_path . '.sha256';
		$written = file_put_contents( $sidecar, $hash . '  ' . basename( $file_path ) . "\n" );
		return false !== $written ? $hash : false;
	}

	/**
	 * Verify file against expected checksum.
	 *
	 * @param string $file_path File path.
	 * @param string $expected  Expected SHA-256.
	 * @return bool
	 */
	public static function verify_file( $file_path, $expected ) {
		$actual = self::hash_file( $file_path );
		return $actual && hash_equals( strtolower( $expected ), strtolower( $actual ) );
	}

	/**
	 * Verify file against sidecar .sha256 file.
	 *
	 * @param string $file_path File path.
	 * @return bool
	 */
	public static function verify_sidecar( $file_path ) {
		$sidecar = $file_path . '.sha256';
		if ( ! file_exists( $sidecar ) ) {
			return false;
		}

		$content = trim( file_get_contents( $sidecar ) );
		$parts   = preg_split( '/\s+/', $content );
		if ( empty( $parts[0] ) ) {
			return false;
		}

		return self::verify_file( $file_path, $parts[0] );
	}
}
