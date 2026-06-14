<?php
/**
 * Test reconcile + one drive tick.
 *
 * @package TheExporter
 */

defined( 'ABSPATH' ) || exit;

$migration_id = 'a67a7001-a704-48ab-afcd-74a374fc028f';
\TheExporter\Runtime::prepare_job();

echo 'version=' . TE_VERSION . PHP_EOL;

\TheExporter\Transfer\RemotePusher::reconcile_sent_index( $migration_id );
$status = \TheExporter\Transfer\RemotePusher::push_status( $migration_id );
echo 'after reconcile sent=' . (int) ( $status['sent'] ?? 0 ) . ' failed=' . ( ! empty( $status['failed'] ) ? 'yes' : 'no' ) . PHP_EOL;
if ( ! empty( $status['current_file']['path'] ) ) {
	echo 'next=' . $status['current_file']['path'] . PHP_EOL;
}

$result = \TheExporter\Transfer\RemotePusher::drive_push( $migration_id );
echo 'drive result: ';
print_r( $result );

$status2 = \TheExporter\Transfer\RemotePusher::push_status( $migration_id );
echo 'after drive sent=' . (int) ( $status2['sent'] ?? 0 ) . PHP_EOL;
