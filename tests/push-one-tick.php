<?php
/**
 * Attempt one push tick and print full error.
 *
 * @package TheExporter
 */

defined( 'ABSPATH' ) || exit;

$migration_id = 'a67a7001-a704-48ab-afcd-74a374fc028f';

\TheExporter\Runtime::prepare_job();

$job = \TheExporter\Jobs\JobRepository::get_job_by_migration( $migration_id, 'push' );
if ( $job && \TheExporter\Jobs\JobRepository::STATUS_FAILED === $job['status'] ) {
	echo "Resetting failed push job to running...\n";
	$step = \TheExporter\Jobs\JobRepository::get_step( (int) $job['id'], 'transfer' );
	if ( $step ) {
		\TheExporter\Jobs\JobRepository::update_step( (int) $step['id'], array(
			'status' => \TheExporter\Jobs\JobRepository::STATUS_RUNNING,
		) );
	}
	\TheExporter\Jobs\JobRepository::update_job_status( (int) $job['id'], \TheExporter\Jobs\JobRepository::STATUS_RUNNING );
}

$step = $job ? \TheExporter\Jobs\JobRepository::get_step( (int) $job['id'], 'transfer' ) : null;
if ( $step ) {
	$meta = is_array( $step['meta'] ) ? $step['meta'] : array();
	$idx  = (int) ( $meta['sent_index'] ?? $step['completed_chunks'] ?? 0 );
	echo 'before sent_index=' . $idx . ' completed_chunks=' . (int) ( $step['completed_chunks'] ?? 0 ) . PHP_EOL;
	$q = \TheExporter\Transfer\RemotePusher::build_file_queue( $migration_id );
	if ( isset( $q[ $idx ] ) ) {
		echo 'next=' . $q[ $idx ]['path'] . ' size=' . ( $q[ $idx ]['size'] ?? 0 ) . PHP_EOL;
	}
}

echo PHP_EOL . '=== drive_push ===' . PHP_EOL;
$result = \TheExporter\Transfer\RemotePusher::drive_push( $migration_id );
print_r( $result );

echo PHP_EOL . '=== push_status after ===' . PHP_EOL;
print_r( \TheExporter\Transfer\RemotePusher::push_status( $migration_id ) );
