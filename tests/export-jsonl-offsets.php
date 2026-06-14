<?php
/**
 * Inspect jsonl byte offsets near stuck cursor.
 *
 * @package TheExporter
 */

defined( 'ABSPATH' ) || exit;

$id = sanitize_text_field( $args[0] ?? '' );
$component = sanitize_key( $args[1] ?? 'wp-content-other' );
$jsonl = \TheExporter\Settings::migration_path( $id, 'export' ) . '/' . $component . '/inventory.jsonl';
$handle = fopen( $jsonl, 'rb' );
$line = 0;
$positions = array();
while ( ( $row = fgets( $handle ) ) !== false ) {
	$positions[] = array(
		'line' => $line,
		'start' => ftell( $handle ) - strlen( $row ),
		'end' => ftell( $handle ),
		'len' => strlen( $row ),
		'path' => json_decode( trim( $row ), true )['path'] ?? '',
	);
	$line++;
	if ( $line >= 262 ) {
		break;
	}
}
fclose( $handle );
echo 'total_lines=' . count( $positions ) . PHP_EOL;
echo 'file_size=' . filesize( $jsonl ) . PHP_EOL;
foreach ( array_slice( $positions, -5 ) as $p ) {
	echo wp_json_encode( $p ) . PHP_EOL;
}
