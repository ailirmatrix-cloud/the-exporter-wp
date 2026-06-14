<?php
/**
 * Inspect push step meta and try single file push.
 *
 * @package TheExporter
 */

defined( 'ABSPATH' ) || exit;

$id = 'c610434c-28f8-4027-b0d8-db68278a0f88';
$job = \TheExporter\Jobs\JobRepository::get_job_by_migration( $id, 'push' );
if ( ! $job ) {
	echo 'No push job' . PHP_EOL;
	exit( 0 );
}

$step = \TheExporter\Jobs\JobRepository::get_step( (int) $job['id'], 'transfer' );
echo 'job_status=' . $job['status'] . ' step_status=' . ( $step['status'] ?? '' ) . PHP_EOL;
echo 'completed_chunks=' . ( $step['completed_chunks'] ?? 0 ) . ' total_chunks=' . ( $step['total_chunks'] ?? 0 ) . PHP_EOL;

$meta = is_array( $step['meta'] ?? null ) ? $step['meta'] : array();
$idx  = (int) ( $meta['sent_index'] ?? 0 );
$queue = $meta['file_queue'] ?? array();
echo 'sent_index=' . $idx . ' queue_count=' . count( $queue ) . PHP_EOL;

if ( isset( $queue[ $idx ] ) ) {
	$entry = $queue[ $idx ];
	echo 'pushing: ' . print_r( $entry, true );
	$t0 = microtime( true );
	$result = \TheExporter\Transfer\RemotePusher::push_file( $id, $entry );
	$dt = round( microtime( true ) - $t0, 2 );
	echo 'push_file took ' . $dt . 's' . PHP_EOL;
	print_r( $result );
} else {
	echo 'No queue entry at index ' . $idx . PHP_EOL;
}

$step2 = \TheExporter\Jobs\JobRepository::get_step( (int) $job['id'], 'transfer' );
$meta2 = is_array( $step2['meta'] ?? null ) ? $step2['meta'] : array();
echo 'after sent_index=' . (int) ( $meta2['sent_index'] ?? 0 ) . PHP_EOL;
