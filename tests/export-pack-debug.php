<?php
/**
 * Debug export segment read/pack for stuck cursor.
 *
 * Usage: studio wp eval-file tests/export-pack-debug.php <migration-id> <component>
 *
 * @package TheExporter
 */

defined( 'ABSPATH' ) || exit;

$id = sanitize_text_field( $args[0] ?? '' );
$component = sanitize_key( $args[1] ?? 'wp-content-other' );
$job = \TheExporter\Jobs\JobRepository::get_job_by_migration( $id, 'export' );
$step = \TheExporter\Jobs\JobRepository::get_step( (int) $job['id'], $component );
$meta = is_array( $step['meta'] ?? null ) ? $step['meta'] : array();

$path = \TheExporter\Settings::migration_path( $id, 'export' ) . '/' . $component;
$jsonl = $path . '/inventory.jsonl';
$offset = (int) ( $meta['files_offset'] ?? 0 );
$byte = (int) ( $meta['jsonl_byte_offset'] ?? 0 );
$chunk = (int) \TheExporter\Settings::effective_segment_size();
$max_files = (int) \TheExporter\Settings::effective_max_files_per_segment();

echo "offset=$offset byte=$byte chunk=$chunk max_files=$max_files\n";

$read = \TheExporter\Files\InventoryBuilder::read_jsonl_batch( $jsonl, $offset, $max_files, $chunk, $byte );
echo 'batch_count=' . count( $read['batch'] ) . PHP_EOL;
echo 'new_offset=' . (int) $read['offset'] . PHP_EOL;
echo 'eof=' . ( ! empty( $read['eof'] ) ? '1' : '0' ) . PHP_EOL;
if ( ! empty( $read['batch'] ) ) {
	echo 'first=' . wp_json_encode( $read['batch'][0] ) . PHP_EOL;
	$source = \TheExporter\Settings::migration_path( $id, 'export' ) . '/../..';
	$source = WP_CONTENT_DIR;
	$t0 = microtime( true );
	$chunk_row = \TheExporter\Files\SegmentWriter::pack_segment(
		$read['batch'],
		$source,
		$path,
		(int) ( $meta['segment_index'] ?? 5 ),
		array( 'skip_hash' => true )
	);
	echo 'pack_seconds=' . round( microtime( true ) - $t0, 3 ) . PHP_EOL;
	echo 'pack_ok=' . ( $chunk_row ? '1' : '0' ) . PHP_EOL;
	if ( $chunk_row ) {
		echo 'segment=' . wp_json_encode( $chunk_row ) . PHP_EOL;
	}
}
