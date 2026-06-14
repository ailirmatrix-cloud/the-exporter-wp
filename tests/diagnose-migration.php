<?php
/**
 * Diagnose a specific migration push/receive state.
 *
 * Usage: studio wp eval-file tests/diagnose-migration.php
 *
 * @package TheExporter
 */

defined( 'ABSPATH' ) || exit;

$migration_id = 'a67a7001-a704-48ab-afcd-74a374fc028f';

echo 'TE_VERSION=' . TE_VERSION . PHP_EOL;
echo 'site=' . home_url() . PHP_EOL;
echo 'migration_id=' . $migration_id . PHP_EOL;

$push = \TheExporter\Transfer\RemotePusher::push_status( $migration_id );
echo PHP_EOL . '=== push_status ===' . PHP_EOL;
print_r( $push );

$job = \TheExporter\Jobs\JobRepository::get_job_by_migration( $migration_id, 'push' );
echo PHP_EOL . '=== push job ===' . PHP_EOL;
print_r( $job );

$export_path = \TheExporter\Settings::migration_path( $migration_id, 'export' );
$import_path = \TheExporter\Settings::migration_path( $migration_id, 'import' );
echo PHP_EOL . 'export_path=' . $export_path . ' exists=' . ( is_dir( $export_path ) ? 'yes' : 'no' ) . PHP_EOL;
echo 'import_path=' . $import_path . ' exists=' . ( is_dir( $import_path ) ? 'yes' : 'no' ) . PHP_EOL;

if ( $job ) {
	$step = \TheExporter\Jobs\JobRepository::get_step( (int) $job['id'], 'transfer' );
	$meta = is_array( $step['meta'] ?? null ) ? $step['meta'] : array();
	$idx  = (int) ( $meta['sent_index'] ?? 0 );
	$q    = \TheExporter\Transfer\RemotePusher::build_file_queue( $migration_id );
	echo 'sent_index=' . $idx . ' queue_total=' . count( $q ) . PHP_EOL;
	if ( isset( $q[ $idx ] ) ) {
		$next  = $q[ $idx ];
		$local = $export_path . '/' . $next['path'];
		echo 'next_file=' . $next['path'] . PHP_EOL;
		echo 'next_component=' . $next['component'] . PHP_EOL;
		echo 'next_size=' . ( $next['size'] ?? 0 ) . PHP_EOL;
		echo 'local_exists=' . ( file_exists( $local ) ? 'yes' : 'no' ) . PHP_EOL;
		echo 'local_size=' . ( file_exists( $local ) ? filesize( $local ) : 0 ) . PHP_EOL;
	}
}

$hb = get_option( 'te_transfer_push_heartbeat', array() );
if ( ! empty( $hb[ $migration_id ] ) ) {
	echo PHP_EOL . '=== push_heartbeat ===' . PHP_EOL;
	print_r( $hb[ $migration_id ] );
}

if ( is_dir( $import_path ) ) {
	echo PHP_EOL . '=== receive_snapshot ===' . PHP_EOL;
	print_r( \TheExporter\Transfer\TransferProgress::receive_snapshot( $migration_id ) );
}

echo PHP_EOL . 'remote_url=' . \TheExporter\Settings::remote_site_url() . PHP_EOL;
echo 'push_url=' . \TheExporter\Settings::effective_remote_push_url() . PHP_EOL;
