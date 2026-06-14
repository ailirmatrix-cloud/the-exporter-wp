<?php
/**
 * PSR-4 style autoloader for The Exporter.
 *
 * @package TheExporter
 */

namespace TheExporter;

defined( 'ABSPATH' ) || exit;

/**
 * Class Autoloader
 */
class Autoloader {

	/**
	 * Namespace prefix.
	 *
	 * @var string
	 */
	private static $prefix = '';

	/**
	 * Register autoloader.
	 *
	 * @param string $prefix Namespace prefix.
	 */
	public static function register( $prefix ) {
		self::$prefix = $prefix;
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Convert CamelCase to kebab-case.
	 *
	 * @param string $name Class name segment.
	 * @return string
	 */
	private static function to_kebab( $name ) {
		$name = str_replace( '_', '-', $name );
		$name = preg_replace( '/([a-z])([A-Z])/', '$1-$2', $name );
		return strtolower( $name );
	}

	/**
	 * Resolve base directory for a class relative path.
	 *
	 * @param string $relative Class path within namespace.
	 * @return string
	 */
	private static function resolve_base_dir( $relative ) {
		if ( 0 === strpos( $relative, 'Admin\\' ) || 'Admin' === $relative ) {
			return TE_PLUGIN_DIR . 'admin/';
		}

		return TE_PLUGIN_DIR . 'includes/';
	}

	/**
	 * Strip mapped namespace segment from relative path.
	 *
	 * @param string $relative Class path within namespace.
	 * @return string
	 */
	private static function strip_mapped_prefix( $relative ) {
		if ( 0 === strpos( $relative, 'Admin\\' ) ) {
			return substr( $relative, 6 );
		}

		return $relative;
	}

	/**
	 * Load class file.
	 *
	 * @param string $class Class name.
	 */
	public static function load( $class ) {
		if ( strpos( $class, self::$prefix ) !== 0 ) {
			return;
		}

		$relative = substr( $class, strlen( self::$prefix ) );
		$base_dir = trailingslashit( self::resolve_base_dir( $relative ) );
		$relative = self::strip_mapped_prefix( $relative );

		$parts = explode( '\\', $relative );
		if ( empty( $parts ) || '' === $parts[0] ) {
			return;
		}

		$class_name = array_pop( $parts );
		$dir        = implode( '/', array_map( array( __CLASS__, 'to_kebab' ), $parts ) );
		$file_name  = 'class-' . self::to_kebab( $class_name ) . '.php';

		$paths = array();
		if ( $dir ) {
			$paths[] = $base_dir . $dir . '/' . $file_name;
		}
		$paths[] = $base_dir . $file_name;

		foreach ( $paths as $path ) {
			if ( file_exists( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
}
