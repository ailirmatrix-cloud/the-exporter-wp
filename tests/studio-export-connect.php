<?php
/**
 * Wire export site to import (token passed as arg).
 *
 * Usage: studio wp eval-file tests/studio-export-connect.php <token>
 *
 * @package TheExporter
 */

defined( 'ABSPATH' ) || exit;

$token = isset( $args[0] ) ? trim( (string) $args[0] ) : '';
if ( '' === $token ) {
	echo 'export_ready=0' . PHP_EOL;
	echo 'error=missing_token' . PHP_EOL;
	exit( 1 );
}

$import_url = 'http://localhost:8881';

\TheExporter\Settings::apply_profile( 'connected' );
\TheExporter\Settings::update(
	array(
		'site_role'            => 'export',
		'remote_site_url'      => $import_url,
		'remote_pairing_token' => $token,
		'remote_auto_push'     => true,
	)
);

$verify = \TheExporter\Transfer\RemoteAuth::verify_remote_site( $import_url, $token );
echo 'export_ready=' . ( ! empty( $verify['success'] ) ? '1' : '0' ) . PHP_EOL;
if ( empty( $verify['success'] ) ) {
	echo 'error=' . ( $verify['error'] ?? 'verify_failed' ) . PHP_EOL;
	exit( 1 );
}
echo 'import_url=' . $import_url . PHP_EOL;
exit( 0 );
