<?php
/**
 * Diagnose a specific migration ID on current site.
 *
 * Usage: studio wp eval-file tests/diagnose-migration-id.php <migration-id>
 *
 * @package TheExporter
 */

defined( 'ABSPATH' ) || exit;

$id = isset( $args[0] ) ? sanitize_text_field( $args[0] ) : '';
if ( '' === $id ) {
	echo 'error=missing_migration_id' . PHP_EOL;
	exit( 1 );
}

echo 'site=' . home_url() . PHP_EOL;
echo 'migration_id=' . $id . PHP_EOL;
echo 'version=' . ( defined( 'TE_VERSION' ) ? TE_VERSION : 'unknown' ) . PHP_EOL;

echo PHP_EOL . '=== RECEIVE SNAPSHOT ===' . PHP_EOL;
$snap = \TheExporter\Transfer\TransferProgress::receive_snapshot( $id );
echo 'ready=' . ( ! empty( $snap['upload']['ready'] ) ? '1' : '0' ) . PHP_EOL;
echo 'uploaded=' . (int) ( $snap['upload']['uploaded'] ?? 0 ) . PHP_EOL;
echo 'expected=' . (int) ( $snap['upload']['expected'] ?? 0 ) . PHP_EOL;
echo 'bytes=' . (int) ( $snap['upload']['bytes_done'] ?? 0 ) . '/' . (int) ( $snap['upload']['bytes_total'] ?? 0 ) . PHP_EOL;
echo 'action=' . ( $snap['current_action'] ?? '' ) . PHP_EOL;
echo 'stale=' . ( ! empty( $snap['stale'] ) ? '1' : '0' ) . PHP_EOL;
print_r( $snap['push_state'] ?? array() );
print_r( $snap['inflight'] ?? array() );

echo PHP_EOL . '=== PUSH STATUS ===' . PHP_EOL;
print_r( \TheExporter\Transfer\RemotePusher::push_status( $id ) );

$import = \TheExporter\Settings::migration_path( $id, 'import' );
$export = \TheExporter\Settings::migration_path( $id, 'export' );
echo PHP_EOL . 'import_path=' . $import . ' exists=' . ( is_dir( $import ) ? 'yes' : 'no' ) . PHP_EOL;
echo 'export_path=' . $export . ' exists=' . ( is_dir( $export ) ? 'yes' : 'no' ) . PHP_EOL;

if ( is_dir( $import ) ) {
	$uploading = array();
	$it        = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $import, FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $it as $f ) {
		if ( ! $f->isFile() ) {
			continue;
		}
		$name = $f->getFilename();
		if ( str_ends_with( $name, '.uploading' ) ) {
			$uploading[] = $f->getPathname() . ' size=' . $f->getSize();
		}
	}
	echo 'partial_uploads=' . count( $uploading ) . PHP_EOL;
	foreach ( array_slice( $uploading, 0, 5 ) as $u ) {
		echo '  ' . $u . PHP_EOL;
	}
}

global $wpdb;
$table = $wpdb->prefix . 'tex_audit_log';
$rows  = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT level, message, created_at FROM {$table} WHERE migration_id = %s ORDER BY id DESC LIMIT 6",
		$id
	),
	ARRAY_A
);
echo PHP_EOL . '=== RECENT AUDIT ===' . PHP_EOL;
print_r( $rows );

exit( 0 );
