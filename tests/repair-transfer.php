<?php
/**
 * Repair a stuck transfer: drop corrupt partials and realign push index.
 *
 * Usage: wp eval-file tests/repair-transfer.php
 *
 * @package TheExporter
 */

defined( 'ABSPATH' ) || exit;

$migration_id = '9d375206-c828-4483-b459-4b398ecc77c8';
$import_base  = \TheExporter\Settings::migration_path( $migration_id, 'import' );

$cleaned = 0;
if ( is_dir( $import_base ) ) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $import_base, RecursiveDirectoryIterator::SKIP_DOTS )
	);
	foreach ( $iterator as $item ) {
		/** @var SplFileInfo $item */
		$name = $item->getFilename();
		if ( str_ends_with( $name, '.uploading' ) ) {
			@unlink( $item->getPathname() );
			$cleaned++;
			echo 'Removed partial: ' . $item->getPathname() . PHP_EOL;
		}
	}
}

\TheExporter\Transfer\TransferWorker::release( $migration_id );
\TheExporter\Transfer\RemotePusher::reconcile_sent_index( $migration_id );
\TheExporter\Transfer\TransferWorker::ensure_running( $migration_id );

echo 'Cleaned ' . $cleaned . ' partial file(s).' . PHP_EOL;
print_r( \TheExporter\Transfer\RemotePusher::push_status( $migration_id ) );
