<?php
/**
 * Protect migration directories from web access.
 *
 * @package TheExporter
 */

namespace TheExporter\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Class DirectoryGuard
 */
class DirectoryGuard {

	/**
	 * Add index.php and .htaccess to directory.
	 *
	 * @param string $path Directory path.
	 */
	public static function protect( $path ) {
		$path = trailingslashit( $path );

		$index = $path . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		$htaccess = $path . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Deny from all\n" );
		}
	}

	/**
	 * Validate path is within allowed base.
	 *
	 * @param string $path     Path to validate.
	 * @param string $base_dir Allowed base directory.
	 * @return string|false Real path or false.
	 */
	public static function validate_path( $path, $base_dir ) {
		$real_base = realpath( $base_dir );
		$real_path = realpath( $path );

		if ( false === $real_base ) {
			return false;
		}

		if ( false === $real_path ) {
			$parent = realpath( dirname( $path ) );
			if ( false === $parent || strpos( $parent, $real_base ) !== 0 ) {
				return false;
			}
			return $path;
		}

		if ( strpos( $real_path, $real_base ) !== 0 ) {
			return false;
		}

		return $real_path;
	}
}
