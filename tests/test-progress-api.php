<?php
/**
 * Test migration progress API.
 *
 * Usage: wp eval-file tests/test-progress-api.php
 *
 * @package TheExporter
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TheExporter\\Jobs\\ProgressReporter' ) ) {
	echo "FAIL: ProgressReporter missing\n";
	exit( 1 );
}

$snap = \TheExporter\Jobs\ProgressReporter::snapshot( '00000000-0000-0000-0000-000000000099', 'export' );

$required = array( 'migration_id', 'phase', 'overall_percent', 'components', 'current_action', 'heartbeat_at' );
foreach ( $required as $key ) {
	if ( ! array_key_exists( $key, $snap ) ) {
		echo "FAIL: missing key $key\n";
		exit( 1 );
	}
}

echo "PASS: progress snapshot (" . $snap['phase'] . ", " . $snap['overall_percent'] . "%)\n";
