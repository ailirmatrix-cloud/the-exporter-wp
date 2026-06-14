<?php
/**
 * Minimal E2E: export config, push, confirm receive snapshot reaches ready.
 *
 * Run on export site first (push), then import site (receive check) with same migration id.
 *
 * Usage:
 *   studio wp eval-file tests/e2e-verify-nudge.php export
 *   studio wp eval-file tests/e2e-verify-nudge.php receive <migration-id>
 *
 * @package TheExporter
 */

defined( 'ABSPATH' ) || exit;

$mode = isset( $args[0] ) ? sanitize_key( $args[0] ) : '';

if ( 'export' === $mode ) {
	$init = \TheExporter\Jobs\ExportOrchestrator::init();
	$id   = $init['migration_id'];

	$r = \TheExporter\Jobs\ExportOrchestrator::export_component( $id, 'config' );
	if ( empty( $r['success'] ) ) {
		echo 'export_failed=1' . PHP_EOL;
		echo 'error=' . ( $r['error'] ?? 'unknown' ) . PHP_EOL;
		exit( 1 );
	}

	$f = \TheExporter\Jobs\ExportOrchestrator::finalize( $id );
	if ( empty( $f['success'] ) ) {
		echo 'finalize_failed=1' . PHP_EOL;
		echo 'error=' . ( $f['error'] ?? 'unknown' ) . PHP_EOL;
		exit( 1 );
	}

	$p = \TheExporter\Transfer\RemotePusher::queue_push( $id );
	if ( empty( $p['success'] ) ) {
		echo 'push_failed=1' . PHP_EOL;
		echo 'error=' . ( $p['error'] ?? 'unknown' ) . PHP_EOL;
		exit( 1 );
	}

	\TheExporter\Transfer\TransferWorker::run_daemon( $id, 120 );
	$ps = \TheExporter\Transfer\RemotePusher::push_status( $id );

	echo 'migration_id=' . $id . PHP_EOL;
	echo 'push_done=' . ( ! empty( $ps['done'] ) ? '1' : '0' ) . PHP_EOL;
	exit( ! empty( $ps['done'] ) ? 0 : 1 );
}

if ( 'receive' === $mode ) {
	$id = isset( $args[1] ) ? sanitize_text_field( $args[1] ) : '';
	if ( '' === $id ) {
		echo 'error=missing_migration_id' . PHP_EOL;
		exit( 1 );
	}

	$deadline = time() + 60;
	$ready    = false;

	while ( time() < $deadline ) {
		$snap = \TheExporter\Transfer\TransferProgress::receive_snapshot( $id );
		if ( ! empty( $snap['upload']['ready'] ) ) {
			$ready = true;
			echo 'receive_ready=1' . PHP_EOL;
			echo 'verify_done=' . ( ! empty( $snap['verify']['done'] ) ? '1' : '0' ) . PHP_EOL;
			echo 'current_action=' . ( $snap['current_action'] ?? '' ) . PHP_EOL;
			break;
		}
		usleep( 500000 );
	}

	if ( ! $ready ) {
		$snap = \TheExporter\Transfer\TransferProgress::receive_snapshot( $id );
		echo 'receive_ready=0' . PHP_EOL;
		echo 'uploaded=' . (int) ( $snap['upload']['uploaded'] ?? 0 ) . PHP_EOL;
		echo 'expected=' . (int) ( $snap['upload']['expected'] ?? 0 ) . PHP_EOL;
		echo 'verify_pending=' . (int) ( $snap['verify']['pending'] ?? 0 ) . PHP_EOL;
		echo 'current_action=' . ( $snap['current_action'] ?? '' ) . PHP_EOL;
		exit( 1 );
	}

	exit( 0 );
}

echo 'usage=export|receive' . PHP_EOL;
exit( 1 );
