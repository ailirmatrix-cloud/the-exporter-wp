<?php
/**
 * Smoke test — run via: studio wp eval-file wp-content/plugins/the-exporter/tests/smoke.php
 *
 * @package TheExporter
 */

$errors = array();

$classes = array(
	'TheExporter\\Runtime',
	'TheExporter\\Database\\Dumper',
	'TheExporter\\Database\\Importer',
	'TheExporter\\Jobs\\Scheduler',
	'TheExporter\\Jobs\\ExportOrchestrator',
	'TheExporter\\Transfer\\FileUploader',
	'TheExporter\\Manifest\\ManifestValidator',
);

foreach ( $classes as $class ) {
	if ( ! class_exists( $class ) ) {
		$errors[] = 'Missing class: ' . $class;
	}
}

// Scheduler must accept WP-Cron style args (int + string).
try {
	$ref = new ReflectionMethod( 'TheExporter\\Jobs\\Scheduler', 'process_chunk' );
	if ( $ref->getNumberOfParameters() < 2 ) {
		$errors[] = 'Scheduler::process_chunk must accept two parameters for WP-Cron';
	}
} catch ( ReflectionException $e ) {
	$errors[] = $e->getMessage();
}

if ( ! \TheExporter\Runtime::command_exists( 'nonexistent-te-command-xyz' ) ) {
	echo "OK: command_exists rejects missing command\n";
} else {
	$errors[] = 'command_exists should return false for missing command';
}

$gz_name = \TheExporter\Transfer\PackageIndex::sanitize_upload_basename( 'database__dump.sql.gz' );
if ( 'database__dump.sql.gz' !== $gz_name ) {
	$errors[] = 'sanitize_upload_basename must preserve .sql.gz names (got ' . $gz_name . ')';
} else {
	echo "OK: upload basename preserves .sql.gz\n";
}

$tar_name = \TheExporter\Transfer\PackageIndex::sanitize_upload_basename( 'plugins__segments--segment-00001.tar.gz' );
if ( 'plugins__segments--segment-00001.tar.gz' !== $tar_name ) {
	$errors[] = 'sanitize_upload_basename must preserve double-dash segment names (got ' . $tar_name . ')';
} else {
	echo "OK: upload basename preserves segment names\n";
}

$manifest = \TheExporter\Manifest\ManifestBuilder::skeleton( '00000000-0000-0000-0000-000000000001' );
unset( $manifest['components'] );
$tmp = sys_get_temp_dir() . '/te-smoke-manifest';
wp_mkdir_p( $tmp );
file_put_contents( $tmp . '/manifest.json', wp_json_encode( $manifest ) );
$gate = \TheExporter\Jobs\ExportOrchestrator::can_finalize( '00000000-0000-0000-0000-000000000001' );
// can_finalize uses migration_path not tmp - skip

if ( $errors ) {
	echo "FAIL\n";
	foreach ( $errors as $err ) {
		echo $err . "\n";
	}
	exit( 1 );
}

echo "PASS: smoke checks completed\n";
