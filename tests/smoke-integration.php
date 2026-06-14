<?php
\TheExporter\Rest\Api::register_routes();
$routes = rest_get_server()->get_routes();
$te     = array_filter( array_keys( $routes ), function ( $r ) {
	return strpos( $r, 'the-exporter' ) !== false;
} );
echo count( $te ) . " REST routes\n";

$id   = 'd97936ef-d205-4d24-b059-46ec37036183';
$s    = \TheExporter\Transfer\FileUploader::component_status( $id, 'database' );
$gate = \TheExporter\Jobs\ExportOrchestrator::can_finalize( $id );

echo 'database files=' . count( $s['files'] ?? array() ) . ' uploaded=' . ( $s['uploaded'] ?? 0 ) . "\n";
echo 'finalize ready=' . ( $gate['ready'] ? 'yes' : 'no' ) . "\n";
