<?php
/**
 * Test server-side connectivity to import site.
 *
 * Usage: studio wp eval-file wp-content/plugins/the-exporter/tests/connectivity-diagnose.php
 *
 * @package TheExporter
 */

defined( 'ABSPATH' ) || exit;

$token = \TheExporter\Settings::get( 'remote_pairing_token', '' );
$push  = \TheExporter\Settings::effective_remote_push_url();
$browser = \TheExporter\Settings::remote_site_url();

echo 'browser_url=' . $browser . PHP_EOL;
echo 'push_url=' . $push . PHP_EOL;
echo 'token_set=' . ( '' !== $token ? 'yes' : 'no' ) . PHP_EOL;

$candidates = array_unique( array_filter( array(
	$push,
	$browser,
	'http://localhost:8881',
	'http://127.0.0.1:8881',
	'http://host.docker.internal:8881',
) ) );

foreach ( $candidates as $base ) {
	$url = trailingslashit( $base ) . 'wp-json/the-exporter/v1/pairing/verify';
	$r   = wp_remote_post(
		$url,
		array(
			'timeout' => 15,
			'headers' => array(
				'X-TE-Token' => $token,
				'Accept'     => 'application/json',
			),
		)
	);
	if ( is_wp_error( $r ) ) {
		echo $base . ' verify => WP_Error: ' . $r->get_error_message() . PHP_EOL;
		continue;
	}
	$code = wp_remote_retrieve_response_code( $r );
	$body = wp_remote_retrieve_body( $r );
	echo $base . ' verify => HTTP ' . $code . ' ' . substr( $body, 0, 100 ) . PHP_EOL;
}

// Test curl multipart to receive endpoint (tiny payload).
$migration_id = \TheExporter\Settings::get( 'active_migration_id', '' );
if ( '' === $migration_id ) {
	$job = \TheExporter\Jobs\JobRepository::get_job_by_migration( '', 'push' );
}
$jobs = \TheExporter\Jobs\JobRepository::list_jobs( 5 );
foreach ( $jobs as $job ) {
	if ( 'push' === ( $job['type'] ?? '' ) && ! empty( $job['migration_id'] ) ) {
		$migration_id = $job['migration_id'];
		break;
	}
}

if ( '' !== $migration_id && function_exists( 'curl_init' ) ) {
	$tmp = wp_tempnam( 'te-diag' );
	file_put_contents( $tmp, 'diag' );
	$endpoint = trailingslashit( $push ) . 'wp-json/the-exporter/v1/transfer/receive/' . rawurlencode( $migration_id ) . '/manifest';
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
	@unlink( $tmp );
	echo 'curl receive push_url => HTTP ' . $code . ' err=' . $err . ' body=' . substr( (string) $body, 0, 120 ) . PHP_EOL;
} else {
	echo 'No migration_id for curl receive test.' . PHP_EOL;
}
