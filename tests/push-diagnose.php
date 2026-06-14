<?php
/**
 * Diagnose push + import receive for a migration.
 *
 * @package TheExporter
 */

defined( 'ABSPATH' ) || exit;

$migration_id = '0e232260-eaf6-49bb-97b1-78da6661c37e';

echo "=== Push job ===\n";
$push = \TheExporter\Jobs\JobRepository::get_job_by_migration( $migration_id, 'push' );
print_r( $push );

echo "\n=== Push status ===\n";
print_r( \TheExporter\Transfer\RemotePusher::push_status( $migration_id ) );

echo "\n=== Settings ===\n";
echo 'remote_url=' . \TheExporter\Settings::remote_site_url() . "\n";
echo 'auto_push=' . ( \TheExporter\Settings::get( 'remote_auto_push' ) ? 'yes' : 'no' ) . "\n";
echo 'transfer_mode=' . \TheExporter\Settings::transfer_mode() . "\n";

echo "\n=== Import upload status ===\n";
print_r( \TheExporter\Transfer\FileUploader::migration_upload_status( $migration_id ) );

echo "\n=== Export manifest exists ===\n";
$export_path = \TheExporter\Settings::migration_path( $migration_id, 'export' );
echo 'export_path=' . $export_path . ' exists=' . ( is_dir( $export_path ) ? 'yes' : 'no' ) . "\n";
echo 'manifest=' . ( file_exists( $export_path . '/manifest.json' ) ? 'yes' : 'no' ) . "\n";

$import_path = \TheExporter\Settings::migration_path( $migration_id, 'import' );
echo 'import_path=' . $import_path . ' exists=' . ( is_dir( $import_path ) ? 'yes' : 'no' ) . "\n";
