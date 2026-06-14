<?php
/**
 * Reset all migration data for a fresh export/import test.
 *
 * Usage: wp eval-file tests/reset-migration-data.php
 *
 * @package TheExporter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Recursively delete a directory.
 *
 * @param string $dir Directory path.
 */
function te_reset_rmdir( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$items = scandir( $dir );
	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$path = $dir . '/' . $item;
		if ( is_dir( $path ) ) {
			te_reset_rmdir( $path );
		} else {
			@unlink( $path );
		}
	}
	@rmdir( $dir );
}

$bases = array(
	WP_CONTENT_DIR . '/migration-exports',
	WP_CONTENT_DIR . '/migration-imports',
	WP_CONTENT_DIR . '/migration-restore-points',
);

$removed = 0;
foreach ( $bases as $base ) {
	if ( ! is_dir( $base ) ) {
		continue;
	}
	foreach ( scandir( $base ) as $entry ) {
		if ( in_array( $entry, array( '.', '..', '.htaccess', 'index.php' ), true ) ) {
			continue;
		}
		$path = $base . '/' . $entry;
		if ( is_dir( $path ) ) {
			te_reset_rmdir( $path );
			$removed++;
			echo 'Removed: ' . $path . PHP_EOL;
		} elseif ( is_file( $path ) ) {
			@unlink( $path );
			echo 'Removed file: ' . $path . PHP_EOL;
		}
	}
}

update_option( 'te_settings', \TheExporter\Settings::defaults(), false );
update_option( 'te_transfer_status', array(), false );
delete_option( 'te_pairing_tokens' );
delete_option( 'te_transfer_receive_log' );
delete_option( 'te_transfer_push_log' );
delete_option( 'te_transfer_receive_counters' );
delete_option( 'te_transfer_receive_inflight' );
delete_option( 'te_transfer_push_heartbeat' );
delete_option( 'te_transfer_import_push_state' );
delete_option( 'te_transfer_worker_meta' );
delete_option( 'te_transfer_worker_token' );
delete_option( 'te_verify_worker_meta' );
delete_option( 'te_transfer_verify_queue' );
delete_option( 'te_transfer_verify_state' );

global $wpdb;
$tables = array( 'tex_chunks', 'tex_job_steps', 'tex_jobs', 'tex_audit_log' );
foreach ( $tables as $table ) {
	$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

$wpdb->query(
	"DELETE FROM {$wpdb->options}
	WHERE option_name LIKE '_transient_te_lock_%'
	OR option_name LIKE '_transient_timeout_te_lock_%'
	OR option_name LIKE '_transient_te_worker_%'
	OR option_name LIKE '_transient_te_push_worker_%'
	OR option_name LIKE '_transient_timeout_te_push_worker_%'
	OR option_name LIKE '_transient_te_verify_worker_%'
	OR option_name LIKE '_transient_timeout_te_verify_worker_%'"
);

\TheExporter\Jobs\JobRepository::force_release_lock( '' );

echo 'Reset complete. Removed ' . $removed . ' migration folder(s).' . PHP_EOL;
echo 'Settings restored to defaults. Jobs, audit log, transfer status, and pairing tokens cleared.' . PHP_EOL;
