<?php
/**
 * Inspect import package files for a migration.
 *
 * @package TheExporter
 */

defined( 'ABSPATH' ) || exit;

$migration_id = 'a67a7001-a704-48ab-afcd-74a374fc028f';
$base         = \TheExporter\Settings::migration_path( $migration_id, 'import' );

echo 'import_base=' . $base . PHP_EOL;
if ( ! is_dir( $base ) ) {
	echo "import dir missing\n";
	exit( 0 );
}

$target = $base . '/plugins/segments/segment-00001.tar';
$uploading = $target . '.uploading';
echo 'plugins_exists=' . ( file_exists( $target ) ? 'yes size=' . filesize( $target ) : 'no' ) . PHP_EOL;
echo 'plugins_uploading=' . ( file_exists( $uploading ) ? 'yes size=' . filesize( $uploading ) : 'no' ) . PHP_EOL;

$status = \TheExporter\Transfer\FileUploader::migration_upload_status( $migration_id );
echo 'uploaded=' . (int) ( $status['uploaded'] ?? 0 ) . '/' . (int) ( $status['expected'] ?? 0 ) . PHP_EOL;

$free = @disk_free_space( $base );
echo 'disk_free=' . ( false === $free ? 'unknown' : $free ) . PHP_EOL;

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $base, RecursiveDirectoryIterator::SKIP_DOTS )
);
$count = 0;
foreach ( $iterator as $file ) {
	if ( $file->isFile() ) {
		$count++;
		if ( $count <= 20 ) {
			echo $file->getPathname() . ' ' . $file->getSize() . PHP_EOL;
		}
	}
}
echo 'total_files=' . $count . PHP_EOL;
