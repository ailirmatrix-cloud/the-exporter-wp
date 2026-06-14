<?php
/**
 * Dump export job status for debugging stuck exports.
 *
 * Usage: studio wp eval-file tests/export-status.php <migration-id>
 *
 * @package TheExporter
 */

defined( 'ABSPATH' ) || exit;

$id = isset( $args[0] ) ? sanitize_text_field( $args[0] ) : \TheExporter\Settings::get( 'active_migration_id', '' );
if ( '' === $id ) {
	echo "no_migration_id\n";
	exit( 1 );
}

echo 'migration_id=' . $id . PHP_EOL;
echo 'plugin=' . ( defined( 'TE_VERSION' ) ? TE_VERSION : 'unknown' ) . PHP_EOL;
echo 'segment_size=' . \TheExporter\Settings::effective_segment_size() . PHP_EOL;

$job = \TheExporter\Jobs\JobRepository::get_job_by_migration( $id, 'export' );
if ( ! $job ) {
	echo "no_export_job\n";
	exit( 1 );
}

echo 'job_id=' . (int) $job['id'] . PHP_EOL;
echo 'job_status=' . $job['status'] . PHP_EOL;
echo 'job_updated=' . ( $job['updated_at'] ?? '' ) . PHP_EOL;

global $wpdb;
$steps = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT * FROM {$wpdb->prefix}tex_job_steps WHERE job_id = %d ORDER BY id ASC",
		(int) $job['id']
	),
	ARRAY_A
);

foreach ( (array) $steps as $step ) {
	$meta = ! empty( $step['meta'] ) ? json_decode( $step['meta'], true ) : array();
	if ( ! is_array( $meta ) ) {
		$meta = array();
	}
	echo '---' . PHP_EOL;
	echo 'component=' . $step['component'] . PHP_EOL;
	echo 'step_status=' . $step['status'] . PHP_EOL;
	echo 'chunks=' . (int) $step['completed_chunks'] . '/' . (int) $step['total_chunks'] . PHP_EOL;
	echo 'step_updated=' . ( $step['updated_at'] ?? '' ) . PHP_EOL;
	echo 'phase=' . ( $meta['phase'] ?? '' ) . PHP_EOL;
	echo 'sub_phase=' . ( $meta['sub_phase'] ?? '' ) . PHP_EOL;
	echo 'segment_index=' . ( $meta['segment_index'] ?? '' ) . PHP_EOL;
	echo 'files_packed=' . ( $meta['files_packed'] ?? '' ) . PHP_EOL;
	echo 'files_total=' . ( $meta['files_total'] ?? '' ) . PHP_EOL;
	echo 'files_offset=' . ( $meta['files_offset'] ?? '' ) . PHP_EOL;
	echo 'hash=' . ( $meta['hash_done'] ?? '' ) . '/' . ( $meta['hash_total'] ?? '' ) . PHP_EOL;

	$seg_dir = \TheExporter\Settings::migration_path( $id, 'export' ) . '/' . $step['component'] . '/segments';
	if ( is_dir( $seg_dir ) ) {
		$files = glob( $seg_dir . '/segment-*' );
		echo 'segments_on_disk=' . count( (array) $files ) . PHP_EOL;
		if ( ! empty( $files ) ) {
			usort( $files, 'strnatcasecmp' );
			$last = end( $files );
			echo 'last_segment=' . basename( $last ) . ' size=' . ( file_exists( $last ) ? filesize( $last ) : 0 ) . PHP_EOL;
		}
	}
}

$path = \TheExporter\Settings::migration_path( $id, 'export' );
echo 'export_path=' . $path . PHP_EOL;
echo 'manifest=' . ( file_exists( $path . '/manifest.json' ) ? 'yes' : 'no' ) . PHP_EOL;
