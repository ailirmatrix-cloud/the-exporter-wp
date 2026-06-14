<?php
/**
 * Test curl file push to import site.
 *
 * @package TheExporter
 */

defined( 'ABSPATH' ) || exit;

$token = \TheExporter\Settings::get( 'remote_pairing_token', '' );
$migration_id = '';
$jobs = \TheExporter\Jobs\JobRepository::list_jobs( 10 );
foreach ( $jobs as $job ) {
	if ( 'push' === ( $job['type'] ?? '' ) && ! empty( $job['migration_id'] ) ) {
		$migration_id = $job['migration_id'];
		break;
	}
}
if ( '' === $migration_id ) {
	$migration_id = \TheExporter\Settings::get( 'active_migration_id', '' );
}
echo 'migration_id=' . $migration_id . PHP_EOL;

if ( '' === $migration_id || ! function_exists( 'curl_init' ) ) {
	exit( 0 );
}

$tmp = wp_tempnam( 'te-diag' );
file_put_contents( $tmp, 'diag' );

foreach ( array( 'http://localhost:8881', 'http://host.docker.internal:8881' ) as $base ) {
	$endpoint = trailingslashit( $base ) . 'wp-json/the-exporter/v1/transfer/receive/' . rawurlencode( $migration_id ) . '/manifest';
	$cfile    = curl_file_create( $tmp, 'text/plain', 'diag.txt' );
	$ch       = curl_init( $endpoint );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => array(
				'file'          => $cfile,
				'relative_path' => 'diag.txt',
				'checksum'      => '',
			),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_HTTPHEADER     => array(
				'X-TE-Token: ' . $token,
				'Accept: application/json',
			),
		)
	);
	$body = curl_exec( $ch );
	$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	$err  = curl_error( $ch );
	curl_close( $ch );
	echo $base . ' curl => HTTP ' . $code . ' err=' . $err . ' body=' . substr( (string) $body, 0, 120 ) . PHP_EOL;
}

@unlink( $tmp );
