<?php
/**
 * Integration checks for chunk resume + peer chunk sizing (v2.12).
 *
 * Usage: studio wp eval-file tests/integration-chunk-resume.php
 *
 * @package TheExporter
 */

defined( 'ABSPATH' ) || exit;

$failures = 0;

/**
 * @param string $name    Test name.
 * @param bool   $passed  Result.
 */
function te_chunk_assert( $name, $passed ) {
	global $failures;
	if ( $passed ) {
		echo 'ok ' . $name . PHP_EOL;
		return;
	}
	echo 'FAIL ' . $name . PHP_EOL;
	$failures++;
}

$fake_id = '00000000-0000-0000-0000-000000009911';
$path    = 'wp-content-other/segments/segment-00002.tar';

$zero = \TheExporter\Transfer\ChunkReceiver::bytes_on_disk( $fake_id, $path );
te_chunk_assert( 'bytes_on_disk_zero', 0 === $zero );

$status = \TheExporter\Transfer\ChunkReceiver::chunk_status( $fake_id, $path );
te_chunk_assert( 'chunk_status_has_bytes', isset( $status['bytes_on_disk'], $status['path'] ) );

$fetch = \TheExporter\Transfer\RemotePusher::fetch_import_chunk_status( $fake_id, $path, 100 );
te_chunk_assert( 'fetch_import_chunk_status_callable', is_array( $fetch ) );

$verify = \TheExporter\Transfer\MigrationState::verify_state( $fake_id );
te_chunk_assert( 'verify_state_has_ready', array_key_exists( 'ready', $verify ) );

$worker = \TheExporter\Transfer\VerifyWorker::status( $fake_id );
te_chunk_assert( 'verify_worker_status', is_array( $worker ) );

$snap_verify = \TheExporter\Transfer\VerifyWorker::snapshot_verify( $fake_id, false );
te_chunk_assert( 'snapshot_verify_light', isset( $snap_verify['pending'] ) );

$inflight = array(
	'path'        => $path,
	'updated_at'  => gmdate( 'c' ),
	'bytes_done'  => 67108864,
	'bytes_total' => 252064256,
);
$push_err = array( 'error' => 'Chunk offset mismatch' );
te_chunk_assert(
	'push_error_hidden_during_inflight',
	! \TheExporter\Transfer\MigrationState::push_error_visible( $inflight, $push_err )
);

te_chunk_assert(
	'push_error_visible_when_idle',
	\TheExporter\Transfer\MigrationState::push_error_visible( null, $push_err )
);

$reconciled = \TheExporter\Transfer\TransferProgress::reconcile_receive_inflight( $fake_id );
te_chunk_assert( 'reconcile_inflight_callable', null === $reconciled || is_array( $reconciled ) );

$peer_cap = \TheExporter\Settings::effective_peer_chunk_size();
te_chunk_assert( 'effective_peer_chunk_size_min_1mb', $peer_cap >= 1048576 );

\TheExporter\Settings::update( array(
	'peer_php_limits' => array(
		'upload_max_filesize' => 8388608,
		'post_max_size'       => 8388608,
	),
) );
$capped = \TheExporter\Settings::effective_peer_chunk_size();
te_chunk_assert( 'peer_post_max_caps_chunk_size', $capped <= 8388608 );
\TheExporter\Settings::update( array( 'peer_php_limits' => array() ) );

$auth_src = file_get_contents( TE_PLUGIN_DIR . 'includes/transfer/class-remote-auth.php' );
te_chunk_assert( 'remote_auth_imports_settings', false !== strpos( $auth_src, 'use TheExporter\Settings;' ) );

\TheExporter\Transfer\TransferRepair::purge_partial_file( $fake_id, $path );
te_chunk_assert( 'purge_partial_callable', true );

echo 'failures=' . $failures . PHP_EOL;
exit( $failures > 0 ? 1 : 0 );
