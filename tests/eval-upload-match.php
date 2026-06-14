<?php
$id = 'd97936ef-d205-4d24-b059-46ec37036183';

$cases = array(
	'database__dump.sql.gz',
	'database_dump.sql.gz',
	'dump.sql.gz',
);

foreach ( $cases as $name ) {
	$match = \TheExporter\Transfer\PackageIndex::find_file_by_upload_name( $id, 'database', $name, 'export' );
	echo $name . ' => ' . ( $match ? $match['path'] : 'NO MATCH' ) . "\n";
}

$status = \TheExporter\Transfer\FileUploader::component_status( $id, 'database' );
echo 'export database expected files: ' . count( $status['files'] ?? array() ) . "\n";

$import_status = \TheExporter\Transfer\FileUploader::component_status( $id, 'database' );
echo 'import database files in catalog: ' . count( $import_status['files'] ?? array() ) . "\n";
echo 'import needs_manifest: ' . ( ! empty( $import_status['needs_manifest'] ) ? 'yes' : 'no' ) . "\n";
