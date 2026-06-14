<?php
/**
 * Migration push/receive status for a given ID.
 *
 * Usage: studio wp eval-file tests/migration-status.php
 *
 * @package TheExporter
 */

defined( 'ABSPATH' ) || exit;

$migration_id = 'c610434c-28f8-4027-b0d8-db68278a0f88';

echo 'site=' . home_url() . PHP_EOL;
echo 'migration_id=' . $migration_id . PHP_EOL;

echo PHP_EOL . '=== PUSH STATUS ===' . PHP_EOL;
print_r( \TheExporter\Transfer\RemotePusher::push_status( $migration_id ) );

echo PHP_EOL . '=== PUSH JOB ===' . PHP_EOL;
print_r( \TheExporter\Jobs\JobRepository::get_job_by_migration( $migration_id, 'push' ) );

echo PHP_EOL . '=== UPLOAD STATUS ===' . PHP_EOL;
print_r( \TheExporter\Transfer\FileUploader::migration_upload_status( $migration_id ) );

echo PHP_EOL . '=== PUSH URL ===' . PHP_EOL;
echo \TheExporter\Settings::effective_remote_push_url() . PHP_EOL;

$import_path = \TheExporter\Settings::migration_path( $migration_id, 'import' );
echo PHP_EOL . 'import_path=' . $import_path . ' exists=' . ( is_dir( $import_path ) ? 'yes' : 'no' ) . PHP_EOL;
if ( is_dir( $import_path ) ) {
	$n = 0;
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $import_path, FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $it as $f ) {
		if ( $f->isFile() ) {
			$n++;
		}
	}
	echo 'files_on_disk=' . $n . PHP_EOL;
}

$export_path = \TheExporter\Settings::migration_path( $migration_id, 'export' );
echo 'export_path=' . $export_path . ' exists=' . ( is_dir( $export_path ) ? 'yes' : 'no' ) . PHP_EOL;

// Recent audit log entries.
global $wpdb;
$table = $wpdb->prefix . 'tex_audit_log';
$rows  = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT id, level, message, created_at FROM {$table} WHERE migration_id = %s ORDER BY id DESC LIMIT 8",
		$migration_id
	),
	ARRAY_A
);
echo PHP_EOL . '=== RECENT AUDIT ===' . PHP_EOL;
print_r( $rows );
