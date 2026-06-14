<?php
/**
 * Fast export tests — run via: studio wp eval-file wp-content/plugins/the-exporter/tests/test-fast-export.php
 *
 * @package TheExporter
 */

$errors = array();

$est = \TheExporter\Settings::estimate_segment_count( 192 * 1024 * 1024, 5179 );
if ( $est < 13 ) {
	$errors[] = 'estimate_segment_count should account for file cap (5179/400 > 13), got ' . $est;
} else {
	echo "OK: segment estimate uses file cap ({$est} segments)\n";
}

$tmpdir = sys_get_temp_dir() . '/te-fast-' . wp_generate_password( 6, false );
wp_mkdir_p( $tmpdir );
$jsonl  = $tmpdir . '/inventory.jsonl';

$entries = array();
for ( $i = 1; $i <= 5; $i++ ) {
	$entries[] = array( 'path' => "file{$i}.txt", 'size' => 100, 'mtime' => time() );
	\TheExporter\Files\InventoryBuilder::append_jsonl( $jsonl, $entries[ $i - 1 ] );
}

$batch = \TheExporter\Files\InventoryBuilder::read_jsonl_batch( $jsonl, 0, 2, 250 );
if ( 2 !== count( $batch['batch'] ) ) {
	$errors[] = 'read_jsonl_batch file cap failed (got ' . count( $batch['batch'] ) . ')';
} else {
	echo "OK: read_jsonl_batch respects file cap\n";
}

$batch2 = \TheExporter\Files\InventoryBuilder::read_jsonl_batch( $jsonl, (int) $batch['offset'], 10, 10000 );
if ( 3 !== count( $batch2['batch'] ) ) {
	$errors[] = 'read_jsonl_batch resume offset failed (got ' . count( $batch2['batch'] ) . ', offset ' . $batch['offset'] . ')';
} else {
	echo "OK: read_jsonl_batch resume offset\n";
}

$src = $tmpdir . '/src';
wp_mkdir_p( $src );
foreach ( $entries as $entry ) {
	file_put_contents( $src . '/' . $entry['path'], str_repeat( 'x', $entry['size'] ) );
}

$out = $tmpdir . '/out';
wp_mkdir_p( $out . '/segments' );

$old_fast = \TheExporter\Settings::get( 'fast_export' );
\TheExporter\Settings::update( array( 'fast_export' => true ) );

$chunk = \TheExporter\Files\SegmentWriter::pack_segment( $entries, $src, $out, 1, array( 'skip_hash' => true ) );
if ( ! $chunk || empty( $chunk['checksum'] ) ) {
	$errors[] = 'pack_segment failed with fast export';
} elseif ( ! empty( $chunk['hashed_files'][0]['sha256'] ) ) {
	$errors[] = 'pack_segment should skip per-file sha256 in fast mode';
} else {
	echo "OK: pack_segment fast mode skips per-file hash\n";
}

\TheExporter\Settings::update( array( 'fast_export' => $old_fast ) );

// Cleanup.
array_map( 'unlink', glob( $tmpdir . '/*/*' ) ?: array() );
@rmdir( $src );
@rmdir( $out . '/segments' );
@rmdir( $out );
@unlink( $jsonl );
@rmdir( $tmpdir );

if ( $errors ) {
	foreach ( $errors as $err ) {
		echo "FAIL: {$err}\n";
	}
	exit( 1 );
}

echo "All fast export tests passed.\n";
