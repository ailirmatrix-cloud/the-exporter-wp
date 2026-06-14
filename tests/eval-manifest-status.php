<?php
$id = 'd97936ef-d205-4d24-b059-46ec37036183';
try {
	$status = \TheExporter\Transfer\FileUploader::component_status( $id, 'manifest' );
	echo json_encode( $status ) . "\n";
} catch ( Throwable $e ) {
	echo "FATAL: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
}
