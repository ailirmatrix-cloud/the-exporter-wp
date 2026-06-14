<?php
/**
 * Show last inventory entries for stuck export debugging.
 *
 * Usage: studio wp eval-file tests/export-last-files.php <migration-id> [component]
 *
 * @package TheExporter
 */

defined( 'ABSPATH' ) || exit;

$id = isset( $args[0] ) ? sanitize_text_field( $args[0] ) : \TheExporter\Settings::get( 'active_migration_id', '' );
$component = isset( $args[1] ) ? sanitize_key( $args[1] ) : 'wp-content-other';
$path = \TheExporter\Settings::migration_path( $id, 'export' ) . '/' . $component . '/inventory.jsonl';

if ( ! file_exists( $path ) ) {
	echo "missing=$path\n";
	exit( 1 );
}

$lines = file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
$total = count( $lines );
echo "component=$component\n";
echo "lines=$total\n";
foreach ( array_slice( $lines, -3 ) as $i => $row ) {
	echo 'tail_' . $i . '=' . $row . PHP_EOL;
}
