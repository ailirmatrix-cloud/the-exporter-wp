<?php
/**
 * Resume stuck export until complete.
 *
 * Usage: studio wp eval-file tests/resume-export.php [migration-id] [max-seconds]
 *
 * @package TheExporter
 */

defined( 'ABSPATH' ) || exit;

$id = isset( $args[0] ) && $args[0]
	? sanitize_text_field( $args[0] )
	: (string) \TheExporter\Settings::get( 'active_migration_id', '' );
$max = isset( $args[1] ) && $args[1] ? (int) $args[1] : 300;

if ( '' === $id ) {
	echo 'error=missing_migration_id' . PHP_EOL;
	exit( 1 );
}

\TheExporter\Jobs\JobRepository::force_release_lock( $id );
$result = \TheExporter\Jobs\ExportOrchestrator::drive_export_batch( $id, $max );

echo 'migration_id=' . $id . PHP_EOL;
echo 'success=' . ( ! empty( $result['success'] ) ? '1' : '0' ) . PHP_EOL;
echo 'done=' . ( ! empty( $result['done'] ) ? '1' : '0' ) . PHP_EOL;
echo 'finalized=' . ( ! empty( $result['finalized'] ) ? '1' : '0' ) . PHP_EOL;
echo 'component=' . ( $result['component'] ?? '' ) . PHP_EOL;
echo 'batch_ticks=' . ( $result['batch_ticks'] ?? 0 ) . PHP_EOL;
echo 'batch_seconds=' . ( $result['batch_seconds'] ?? 0 ) . PHP_EOL;
if ( ! empty( $result['error'] ) ) {
	echo 'error=' . $result['error'] . PHP_EOL;
}
print_r( $result );

exit( ! empty( $result['finalized'] ) || ! empty( $result['path'] ) ? 0 : 1 );
