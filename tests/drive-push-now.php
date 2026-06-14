<?php
/**
 * Drive push forward and show result.
 *
 * @package TheExporter
 */

defined( 'ABSPATH' ) || exit;

$id = 'c610434c-28f8-4027-b0d8-db68278a0f88';

echo 'Before: ';
print_r( \TheExporter\Transfer\RemotePusher::push_status( $id ) );

$job = \TheExporter\Jobs\JobRepository::get_job_by_migration( $id, 'push' );
$step = $job ? \TheExporter\Jobs\JobRepository::get_step( (int) $job['id'], 'transfer' ) : null;
if ( $step && is_array( $step['meta'] ) ) {
	$idx = (int) ( $step['meta']['sent_index'] ?? 0 );
	$queue = $step['meta']['file_queue'] ?? array();
	echo PHP_EOL . 'next_file_index=' . $idx . PHP_EOL;
	if ( isset( $queue[ $idx ] ) ) {
		echo 'next_file=' . print_r( $queue[ $idx ], true );
		$path = \TheExporter\Settings::migration_path( $id, 'export' ) . '/' . $queue[ $idx ]['path'];
		echo 'local_size=' . ( file_exists( $path ) ? filesize( $path ) : 'missing' ) . PHP_EOL;
	}
}

echo PHP_EOL . '=== drive_push ===' . PHP_EOL;
$result = \TheExporter\Transfer\RemotePusher::drive_push( $id );
print_r( $result );

echo PHP_EOL . '=== drive_push_batch (3 files) ===' . PHP_EOL;
$result2 = \TheExporter\Transfer\RemotePusher::drive_push_batch( $id, 60, 3 );
print_r( $result2 );

echo PHP_EOL . 'After: ';
print_r( \TheExporter\Transfer\RemotePusher::push_status( $id ) );
