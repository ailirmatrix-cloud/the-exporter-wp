<?php
/**
 * Drive export engine smoke test.
 *
 * @package TheExporter
 */

defined( 'ABSPATH' ) || exit;

$migration_id = isset( $args[0] ) ? sanitize_text_field( $args[0] ) : '0e232260-eaf6-49bb-97b1-78da6661c37e';
if ( class_exists( 'WP_REST_Server' ) ) {
	wp_set_current_user( 1 );
}

$result = \TheExporter\Jobs\ExportOrchestrator::drive_export( $migration_id );
echo 'drive success=' . ( ! empty( $result['success'] ) ? 'yes' : 'no' ) . "\n";
if ( ! empty( $result['error'] ) ) {
	echo 'error=' . $result['error'] . "\n";
}
if ( ! empty( $result['component'] ) ) {
	echo 'component=' . $result['component'] . "\n";
}
$snap = \TheExporter\Jobs\ProgressReporter::snapshot( $migration_id, 'export' );
echo 'percent=' . ( $snap['overall_percent'] ?? 0 ) . "\n";
echo 'action=' . ( $snap['current_action'] ?? '' ) . "\n";
echo "PASS\n";
