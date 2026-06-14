<?php
/**
 * Wire Studio export/import sites for connected transfer (no secrets printed).
 *
 * Usage:
 *   Import: studio wp eval-file tests/studio-connect.php import
 *   Export: studio wp eval-file tests/studio-connect.php export <import-url> <token-file>
 *
 * @package TheExporter
 */

defined( 'ABSPATH' ) || exit;

$mode = isset( $args[0] ) ? sanitize_key( $args[0] ) : '';

if ( 'import' === $mode ) {
	\TheExporter\Settings::update(
		array(
			'site_role'             => 'import',
			'peer_export_base_path' => 'C:/Users/ailir/Studio/diviexporter/wp-content/migration-exports',
		)
	);
	$pair = \TheExporter\Transfer\RemoteAuth::generate_token();
	$path = WP_CONTENT_DIR . '/te-studio-pairing.token';
	file_put_contents( $path, $pair['token'] );
	echo 'import_ready=1' . PHP_EOL;
	echo 'token_file=' . $path . PHP_EOL;
	echo 'site_url=' . home_url() . PHP_EOL;
	exit( 0 );
}

if ( 'export' === $mode ) {
	$import_url = isset( $args[1] ) ? esc_url_raw( $args[1] ) : 'http://localhost:8881';
	$token_file = isset( $args[2] ) ? $args[2] : WP_CONTENT_DIR . '/te-studio-pairing.token';
	$token       = is_readable( $token_file ) ? trim( (string) file_get_contents( $token_file ) ) : '';

	if ( '' === $token ) {
		echo 'export_ready=0' . PHP_EOL;
		echo 'error=missing_token_file' . PHP_EOL;
		exit( 1 );
	}

	\TheExporter\Settings::apply_profile( 'connected' );
	\TheExporter\Settings::update(
		array(
			'site_role'              => 'export',
			'remote_site_url'        => $import_url,
			'remote_pairing_token'   => $token,
			'remote_auto_push'       => true,
		)
	);

	$verify = \TheExporter\Transfer\RemoteAuth::verify_remote_site( $import_url, $token );
	echo 'export_ready=' . ( ! empty( $verify['success'] ) ? '1' : '0' ) . PHP_EOL;
	echo 'segment_bytes=' . \TheExporter\Settings::effective_segment_size() . PHP_EOL;
	echo 'chunk_bytes=' . \TheExporter\Settings::transfer_chunk_size() . PHP_EOL;
	if ( empty( $verify['success'] ) ) {
		echo 'error=' . ( $verify['error'] ?? 'verify_failed' ) . PHP_EOL;
		exit( 1 );
	}
	echo 'import_url=' . $import_url . PHP_EOL;
	exit( 0 );
}

echo 'usage=import|export' . PHP_EOL;
exit( 1 );
