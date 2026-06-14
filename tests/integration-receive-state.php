<?php
/**
 * Integration checks for receive/push completion invariants (v2.9+).
 *
 * Usage: studio wp eval-file tests/integration-receive-state.php
 *
 * @package TheExporter
 */

defined( 'ABSPATH' ) || exit;

$failures = 0;

/**
 * @param string $name    Test name.
 * @param bool   $passed  Result.
 */
function te_assert( $name, $passed ) {
	global $failures;
	if ( $passed ) {
		echo 'ok ' . $name . PHP_EOL;
		return;
	}
	echo 'FAIL ' . $name . PHP_EOL;
	$failures++;
}

// Disk-authoritative receive_state returns array with required keys.
$fake_id = '00000000-0000-0000-0000-000000009999';
$state   = \TheExporter\Transfer\MigrationState::receive_state( $fake_id );
te_assert( 'receive_state_has_keys', isset( $state['expected'], $state['uploaded'], $state['ready'], $state['components'] ) );

// sync_counters_from_disk does not fatal without manifest.
\TheExporter\Transfer\TransferProgress::sync_counters_from_disk( $fake_id );
te_assert( 'sync_counters_no_manifest', true );

// clear_receive_state is callable.
\TheExporter\Transfer\TransferProgress::clear_receive_state( $fake_id );
te_assert( 'clear_receive_state', true );

// MigrationState push_state returns done key.
$push = \TheExporter\Transfer\MigrationState::push_state( $fake_id );
te_assert( 'push_state_has_done', array_key_exists( 'done', $push ) );

// components_from_upload is public.
$upload = array(
	'needs_manifest' => true,
	'files'          => array(),
);
$comps = \TheExporter\Transfer\TransferProgress::components_from_upload( $upload );
te_assert( 'components_from_upload_public', is_array( $comps ) && ! empty( $comps ) );

// Verify queue nudge API (v2.10).
$stats = \TheExporter\Transfer\VerifyQueue::pending_stats( $fake_id );
te_assert( 'pending_stats_has_keys', isset( $stats['pending'], $stats['verified'], $stats['total'] ) );

$flush = \TheExporter\Transfer\VerifyQueue::flush_pending_budget( $fake_id, 1, 1 );
te_assert( 'flush_pending_budget_has_remaining', is_array( $flush ) && array_key_exists( 'remaining', $flush ) );

$nudge = \TheExporter\Transfer\VerifyQueue::nudge_on_receive_poll( $fake_id );
te_assert( 'nudge_on_receive_poll_has_remaining', is_array( $nudge ) && array_key_exists( 'remaining', $nudge ) );

$snap = \TheExporter\Transfer\TransferProgress::receive_snapshot( $fake_id );
te_assert( 'receive_snapshot_has_verify', isset( $snap['verify']['pending'], $snap['verify']['done'] ) );

$verify_state = \TheExporter\Transfer\MigrationState::verify_state( $fake_id );
te_assert( 'migration_verify_state', array_key_exists( 'ready', $verify_state ) );

echo 'failures=' . $failures . PHP_EOL;
exit( $failures > 0 ? 1 : 0 );
