<?php
/**
 * Test tar segments with paths longer than USTAR limits.
 *
 * Usage: wp eval-file tests/test-segment-long-paths.php
 *
 * @package TheExporter
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'PharData' ) ) {
	echo "SKIP: PharData not available\n";
	exit( 0 );
}

$tmpdir = sys_get_temp_dir() . '/te-long-path-' . wp_generate_password( 8, false );
$source = $tmpdir . '/source';
$output = $tmpdir . '/output';
$staging = $tmpdir . '/staging';
$long_name = '2026/05/الشيخ_علي_سلمان_أدين_العدوان_الصهيوني_على_الجمهورية_الإسلامية-150x150.png';

wp_mkdir_p( dirname( $source . '/' . $long_name ) );
file_put_contents( $source . '/' . $long_name, 'long-path-test' );
wp_mkdir_p( $output );

$files = array(
	array(
		'path' => $long_name,
		'size' => filesize( $source . '/' . $long_name ),
		'sha256' => hash_file( 'sha256', $source . '/' . $long_name ),
	),
);

try {
	$segment_result = \TheExporter\Files\SegmentWriter::create_segments( $files, $source, $output, 1048576 );
	$chunks = $segment_result['chunks'];
} catch ( Exception $e ) {
	echo 'FAIL: export threw: ' . $e->getMessage() . "\n";
	te_long_path_cleanup( $tmpdir );
	exit( 1 );
}

if ( empty( $chunks ) || empty( $chunks[0]['path'] ) ) {
	echo "FAIL: no segment created\n";
	te_long_path_cleanup( $tmpdir );
	exit( 1 );
}

$segment = $output . '/segments/' . basename( $chunks[0]['path'] );
if ( ! file_exists( $segment ) ) {
	echo "FAIL: segment file missing\n";
	te_long_path_cleanup( $tmpdir );
	exit( 1 );
}

wp_mkdir_p( $staging );
$inventory = array( 'files' => $files );
$extract = \TheExporter\Files\SegmentExtractor::extract( $segment, $staging, $inventory );

if ( empty( $extract['success'] ) ) {
	echo 'FAIL: extract failed: ' . ( $extract['error'] ?? 'unknown' ) . "\n";
	te_long_path_cleanup( $tmpdir );
	exit( 1 );
}

$restored = $staging . '/' . $long_name;
if ( ! file_exists( $restored ) || file_get_contents( $restored ) !== 'long-path-test' ) {
	echo "FAIL: restored file missing or wrong content\n";
	te_long_path_cleanup( $tmpdir );
	exit( 1 );
}

echo "PASS: long tar path round-trip\n";
te_long_path_cleanup( $tmpdir );

/**
 * Remove temp test directory.
 *
 * @param string $dir Directory.
 */
function te_long_path_cleanup( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $item ) {
		if ( $item->isDir() ) {
			@rmdir( $item->getPathname() );
		} else {
			@unlink( $item->getPathname() );
		}
	}
	@rmdir( $dir );
}
