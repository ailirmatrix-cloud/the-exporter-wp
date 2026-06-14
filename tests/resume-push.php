<?php
/**
 * Resume export push until complete (CLI daemon).
 *
 * Usage:
 *   studio wp eval-file tests/resume-push.php
 *   studio wp eval-file tests/resume-push.php <migration-id>
 *
 * @package TheExporter
 */

defined( 'ABSPATH' ) || exit;

$migration_id = isset( $args[0] ) && $args[0]
	? sanitize_text_field( $args[0] )
	: (string) \TheExporter\Settings::get( 'active_migration_id', '' );

if ( '' === $migration_id ) {
	echo 'error=missing_migration_id' . PHP_EOL;
	exit( 1 );
}

\TheExporter\Transfer\TransferWorker::release( $migration_id );
\TheExporter\Transfer\RemotePusher::reconcile_sent_index( $migration_id );
\TheExporter\Transfer\TransferWorker::ensure_running( $migration_id );

$result = \TheExporter\Transfer\TransferWorker::run_daemon( $migration_id, 0 );

echo 'migration_id=' . $migration_id . PHP_EOL;
echo 'done=' . ( ! empty( $result['done'] ) ? '1' : '0' ) . PHP_EOL;
echo 'success=' . ( ! empty( $result['success'] ) ? '1' : '0' ) . PHP_EOL;
if ( ! empty( $result['error'] ) ) {
	echo 'error=' . $result['error'] . PHP_EOL;
}
echo 'daemon_seconds=' . ( $result['daemon_seconds'] ?? 0 ) . PHP_EOL;
print_r( \TheExporter\Transfer\RemotePusher::push_status( $migration_id ) );

exit( ! empty( $result['done'] ) ? 0 : 1 );
