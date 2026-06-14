<?php
defined( 'ABSPATH' ) || exit;

$id   = '9d375206-c828-4483-b459-4b398ecc77c8';
$base = \TheExporter\Settings::migration_path( $id, 'import' );

$paths = array(
	'themes/segments/segment-00050.tar',
	'themes/segments/segment-00050.tar.uploading',
	'uploads/segments/segment-00007.tar',
	'uploads/segments/segment-00007.tar.uploading',
);

foreach ( $paths as $p ) {
	$full = trailingslashit( $base ) . $p;
	echo $p . ': ' . ( file_exists( $full ) ? filesize( $full ) : 'MISSING' ) . PHP_EOL;
}

$exp = \TheExporter\Transfer\PackageIndex::find_expected_file( $id, 'themes', 'themes/segments/segment-00050.tar', 'import' );
if ( $exp ) {
	echo 'segment-00050 expected size: ' . ( $exp['size'] ?? 0 ) . PHP_EOL;
	echo 'segment-00050 expected checksum: ' . ( $exp['checksum'] ?? '' ) . PHP_EOL;
}
$full50 = trailingslashit( $base ) . 'themes/segments/segment-00050.tar';
if ( file_exists( $full50 ) ) {
	echo 'segment-00050 actual checksum: ' . \TheExporter\Validation\ChecksumService::hash_file( $full50 ) . PHP_EOL;
}

$export_base = \TheExporter\Settings::migration_path( $id, 'export' );
$export50    = trailingslashit( $export_base ) . 'themes/segments/segment-00050.tar';
if ( file_exists( $export50 ) ) {
	echo 'export segment-00050 size: ' . filesize( $export50 ) . PHP_EOL;
	echo 'export segment-00050 checksum: ' . \TheExporter\Validation\ChecksumService::hash_file( $export50 ) . PHP_EOL;
}

print_r( \TheExporter\Transfer\RemotePusher::push_status( $id ) );
