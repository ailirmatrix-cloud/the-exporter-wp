<?php
/**
 * Plugin Name:       The Exporter
 * Plugin URI:        https://github.com/ailirmatrix-cloud/the-exporter-wp
 * Description:       Verification-first migration for very large WordPress sites. Exports chunked, checksum-verified packages for manual transfer.
 * Version:           2.13.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            The Exporter
 * License:           GPL-2.0-or-later
 * Text Domain:       the-exporter
 *
 * @package TheExporter
 */

defined( 'ABSPATH' ) || exit;

define( 'TE_VERSION', '2.13.2' );
define( 'TE_PLUGIN_FILE', __FILE__ );
define( 'TE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TE_SCHEMA_VERSION', '1.0.0' );

require_once TE_PLUGIN_DIR . 'includes/class-autoloader.php';

TheExporter\Autoloader::register( 'TheExporter\\' );

register_activation_hook( __FILE__, array( 'TheExporter\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'TheExporter\\Deactivator', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'TheExporter\\Plugin', 'init' ) );
