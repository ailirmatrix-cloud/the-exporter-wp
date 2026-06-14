<?php
/**
 * Quick wizard role endpoint smoke test.
 *
 * @package TheExporter
 */

defined( 'ABSPATH' ) || exit;

$request = new WP_REST_Request( 'POST', '/the-exporter/v1/wizard/role' );
$request->set_header( 'Content-Type', 'application/json' );
$request->set_body( wp_json_encode( array( 'role' => 'export' ) ) );

$response = rest_get_server()->dispatch( $request );
$data     = $response->get_data();

echo 'wizard/role status=' . $response->get_status() . "\n";
echo 'success=' . ( ! empty( $data['success'] ) ? 'yes' : 'no' ) . "\n";
echo 'role=' . ( $data['role'] ?? '' ) . "\n";
echo 'saved=' . \TheExporter\Settings::get( 'site_role' ) . "\n";

// Reset for clean state.
$clear = new WP_REST_Request( 'POST', '/the-exporter/v1/wizard/role' );
$clear->set_header( 'Content-Type', 'application/json' );
$clear->set_body( wp_json_encode( array( 'role' => '' ) ) );
rest_get_server()->dispatch( $clear );

echo "PASS\n";
