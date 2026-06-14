<?php
/**
 * List remaining inventory files for a component export.
 *
 * Usage: studio wp eval-file tests/export-remaining.php <migration-id> <component>
 *
 * @package TheExporter
 */

defined( 'ABSPATH' ) || exit;

$id        = isset( $args[0] ) ? sanitize_text_field( $args[0] ) : '';
$component = isset( $args[1] ) ? sanitize_key( $args[1] ) : 'wp-content-other';
if ( '' === $id ) {
	echo "usage: migration-id component\n";
	exit( 1 );
}

$job = \TheExporter\Jobs\JobRepository::get_job_by_migration( $id, 'export' );
if ( ! $job ) {
	echo "no_job\n";
	exit( 1 );
}
$step = \TheExporter\Jobs\JobRepository::get_step( (int) $job['id'], $component );
$meta = $step && is_array( $step['meta'] ) ? $step['meta'] : array();
$offset = (int) ( $meta['files_offset'] ?? 0 );
$total  = (int) ( $meta['files_total'] ?? 0 );

$jsonl = \TheExporter\Settings::migration_path( $id, 'export' ) . '/' . $component . '/inventory.jsonl';
echo 'offset=' . $offset . ' total=' . $total . PHP_EOL;

$lines = file( $jsonl, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
if ( ! is_array( $lines ) ) {
	echo "no_inventory\n";
	exit( 1 );
}

$remaining = array_slice( $lines, $offset );
echo 'remaining_count=' . count( $remaining ) . PHP_EOL;
foreach ( $remaining as $line ) {
	$row = json_decode( $line, true );
	if ( ! is_array( $row ) ) {
		continue;
	}
	$path = $row['path'] ?? '';
	$size = isset( $row['size'] ) ? (int) $row['size'] : 0;
	echo $path . ' size=' . $size . PHP_EOL;
}
