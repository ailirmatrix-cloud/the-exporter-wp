<?php
/**
 * Push plugins segment only for debugging.
 *
 * @package TheExporter
 */

defined( 'ABSPATH' ) || exit;

$migration_id = 'a67a7001-a704-48ab-afcd-74a374fc028f';
\TheExporter\Runtime::prepare_job();

$queue = \TheExporter\Transfer\RemotePusher::build_file_queue( $migration_id );
$entry = null;
foreach ( $queue as $e ) {
	if ( 'plugins/segments/segment-00001.tar' === $e['path'] ) {
		$entry = $e;
		break;
	}
}
if ( ! $entry ) {
	echo "entry not found\n";
	exit( 1 );
}

echo 'pushing ' . $entry['path'] . ' size=' . ( $entry['size'] ?? 0 ) . PHP_EOL;
echo 'push_url=' . \TheExporter\Settings::effective_remote_push_url() . PHP_EOL;

$result = \TheExporter\Transfer\RemotePusher::push_file_with_retry( $migration_id, $entry );
print_r( $result );
