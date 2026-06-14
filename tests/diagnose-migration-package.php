<?php
/**
 * Diagnose migration package on disk.
 *
 * Usage: wp --skip-plugins --skip-themes eval-file diagnose-migration-package.php <migration_id>
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$migration_id = isset( $args[0] ) ? $args[0] : '';
if ( ! $migration_id ) {
	echo "Usage: wp eval-file diagnose-migration-package.php <migration_id>\n";
	exit( 1 );
}

$import_base = WP_CONTENT_DIR . '/migration-imports';
$export_base = WP_CONTENT_DIR . '/migration-exports';
$import      = $import_base . '/migration-' . preg_replace( '/[^a-zA-Z0-9\-]/', '', $migration_id );
$export      = $export_base . '/migration-' . preg_replace( '/[^a-zA-Z0-9\-]/', '', $migration_id );

echo "import_path={$import}\n";
echo "export_path={$export}\n";

$count_files = static function ( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return 0;
	}
	$n  = 0;
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $it as $f ) {
		if ( $f->isFile() ) {
			$n++;
		}
	}
	return $n;
};

echo 'import_file_count=' . $count_files( $import ) . "\n";
echo 'export_file_count=' . $count_files( $export ) . "\n";

foreach ( array( 'mu-plugins', 'plugins', 'themes', 'uploads', 'database' ) as $comp ) {
	$seg_dir = trailingslashit( $import ) . $comp . '/segments';
	if ( ! is_dir( $seg_dir ) ) {
		echo "{$comp}_segments=MISSING\n";
		continue;
	}
	$files = glob( $seg_dir . '/*' );
	echo "{$comp}_segments=" . count( $files ) . "\n";
	foreach ( array_slice( $files, 0, 3 ) as $f ) {
		echo "  " . basename( $f ) . ' ' . filesize( $f ) . "\n";
	}
}

$staging_base = $import_base;
foreach ( array( 'plugins', 'themes' ) as $comp ) {
	$staging = $staging_base . '/staging-' . $comp;
	echo "staging_{$comp}=" . ( is_dir( $staging ) ? 'yes files=' . $count_files( $staging ) : 'no' ) . "\n";
}

echo 'monarch_core=' . ( file_exists( WP_CONTENT_DIR . '/plugins/monarch/core/init.php' ) ? 'yes' : 'no' ) . "\n";
echo 'divi_core=' . ( file_exists( WP_CONTENT_DIR . '/themes/Divi/core/init.php' ) ? 'yes' : 'no' ) . "\n";

global $wpdb;
$jobs = $wpdb->get_results(
	$wpdb->prepare( "SELECT id, type, status FROM {$wpdb->prefix}tex_jobs WHERE migration_id=%s", $migration_id ),
	ARRAY_A
);
echo 'jobs=' . wp_json_encode( $jobs ) . "\n";

$steps = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT s.id, s.job_id, s.component, s.status, s.completed_chunks, s.total_chunks, j.type AS job_type
		 FROM {$wpdb->prefix}tex_job_steps s
		 INNER JOIN {$wpdb->prefix}tex_jobs j ON j.id = s.job_id
		 WHERE j.migration_id=%s",
		$migration_id
	),
	ARRAY_A
);
echo 'steps=' . wp_json_encode( $steps ) . "\n";
