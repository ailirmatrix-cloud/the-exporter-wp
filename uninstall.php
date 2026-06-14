<?php
/**
 * Uninstall handler — removes plugin options only (preserves migration data by default).
 *
 * @package TheExporter
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'te_settings' );
delete_option( 'te_db_version' );
delete_option( 'te_transfer_status' );

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_te_lock_%' OR option_name LIKE '_transient_timeout_te_lock_%'" );
