<?php
/**
 * Remove migration package artifacts accidentally scanned into wp-content-other.
 *
 * Usage: studio wp eval-file tests/repair-export-inventory.php <migration-id>
 *
 * @package TheExporter
 */

defined( 'ABSPATH' ) || exit;

$id = isset( $args[0] ) ? sanitize_text_field( $args[0] ) : '';
if ( '' === $id ) {
	echo "usage: migration-id\n";
	exit( 1 );
}

$component = 'wp-content-other';
$jsonl     = \TheExporter\Settings::migration_path( $id, 'export' ) . '/' . $component . '/inventory.jsonl';
if ( ! is_readable( $jsonl ) ) {
	echo "no_inventory\n";
	exit( 1 );
}

$bad_prefixes = array(
	'migration-exports/',
	'migration-imports/',
	'migration-restore-points/',
);

$lines   = file( $jsonl, FILE_IGNORE_NEW_LINES );
$kept    = array();
$removed = 0;

foreach ( (array) $lines as $line ) {
	$line = trim( (string) $line );
	if ( '' === $line ) {
		continue;
	}
	$row = json_decode( $line, true );
	if ( ! is_array( $row ) ) {
		continue;
	}
	$path = ltrim( str_replace( '\\', '/', (string) ( $row['path'] ?? '' ) ), '/' );
	$drop = false;
	foreach ( $bad_prefixes as $prefix ) {
		if ( 0 === strpos( $path, $prefix ) ) {
			$drop = true;
			break;
		}
	}
	if ( str_ends_with( $path, '/inventory.jsonl' ) && false !== strpos( $path, 'migration-exports/' ) ) {
		$drop = true;
	}
	if ( $drop ) {
		$removed++;
		echo 'removed=' . $path . PHP_EOL;
		continue;
	}
	$kept[] = $line;
}

file_put_contents( $jsonl, implode( "\n", $kept ) . ( ! empty( $kept ) ? "\n" : '' ) );

$job = \TheExporter\Jobs\JobRepository::get_job_by_migration( $id, 'export' );
if ( $job ) {
	$step = \TheExporter\Jobs\JobRepository::get_step( (int) $job['id'], $component );
	if ( $step ) {
		$meta = is_array( $step['meta'] ) ? $step['meta'] : array();
		$new_total = count( $kept );
		$packed    = min( (int) ( $meta['files_packed'] ?? 0 ), $new_total );
		$offset    = min( (int) ( $meta['files_offset'] ?? 0 ), $new_total );
		if ( $offset >= $new_total ) {
			$meta['phase']     = 'segmenting';
			$meta['sub_phase'] = '';
		}
		$meta['files_total']  = $new_total;
		$meta['files_packed'] = $packed;
		$meta['files_offset'] = $offset;
		\TheExporter\Jobs\JobRepository::update_step(
			(int) $step['id'],
			array(
				'status' => \TheExporter\Jobs\JobRepository::STATUS_RUNNING,
				'meta'   => $meta,
			)
		);
		\TheExporter\Jobs\JobRepository::update_job_status( (int) $job['id'], \TheExporter\Jobs\JobRepository::STATUS_RUNNING );
		echo 'files_total=' . $new_total . PHP_EOL;
		echo 'files_offset=' . $offset . PHP_EOL;
	}
}

echo 'removed_count=' . $removed . PHP_EOL;
echo 'repair_ok=1' . PHP_EOL;
