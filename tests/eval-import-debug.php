<?php
/**
 * Debug import state for a migration ID.
 *
 * Usage: wp eval-file tests/eval-import-debug.php
 */

$ids = array(
	'd97936ef-d205-4d24-b059-46ec37036103',
	'd97936ef-d205-4d24-b059-46ec37036183',
);

foreach ( $ids as $id ) {
	echo "=== {$id} ===\n";
	$import = \TheExporter\Settings::migration_path( $id, 'import' );
	$export = \TheExporter\Settings::migration_path( $id, 'export' );
	$resolved = \TheExporter\Transfer\PackageIndex::resolve_path( $id, 'import' );
	echo "import path: {$import}\n";
	echo "import dir: " . ( is_dir( $import ) ? 'yes' : 'no' ) . "\n";
	echo "manifest: " . ( file_exists( $import . '/manifest.json' ) ? 'yes' : 'no' ) . "\n";
	echo "export dir: " . ( is_dir( $export ) ? 'yes' : 'no' ) . "\n";
	echo "resolved: " . ( $resolved ? $resolved : 'false' ) . "\n";
	$status = \TheExporter\Transfer\FileUploader::migration_upload_status( $id );
	echo 'upload status: ' . wp_json_encode( $status ) . "\n";
	$validation = \TheExporter\Jobs\ImportOrchestrator::validate( $id, true );
	echo 'validate passed: ' . ( $validation['passed'] ? 'yes' : 'no' ) . "\n";
	if ( ! empty( $validation['errors'][0]['message'] ) ) {
		echo 'validate error: ' . $validation['errors'][0]['message'] . "\n";
	}
	echo "\n";
}
