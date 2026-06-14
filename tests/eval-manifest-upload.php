<?php
$id = 'd97936ef-d205-4d24-b059-46ec37036183';
$export = \TheExporter\Settings::migration_path( $id, 'export' );
$manifest_path = $export . '/manifest.json';
if ( ! file_exists( $manifest_path ) ) {
	echo "no manifest\n";
	exit( 1 );
}
$data = json_decode( file_get_contents( $manifest_path ), true );
$expected = $data['checksums']['manifest_sha256'] ?? '';
$actual = \TheExporter\Manifest\ManifestBuilder::file_checksum_without_embedded_hash( $manifest_path );
echo "expected: $expected\n";
echo "actual:   $actual\n";
echo "match: " . ( $actual && hash_equals( strtolower( $expected ), strtolower( (string) $actual ) ) ? 'yes' : 'no' ) . "\n";

// Simulate validate_manifest_upload
try {
	$fp = \TheExporter\Transfer\PackageIndex::catalog_fingerprint( $data );
	echo "fingerprint ok: " . strlen( $fp ) . "\n";
	\TheExporter\Transfer\TransferStatus::prune();
	echo "prune ok\n";
} catch ( Throwable $e ) {
	echo "ERROR: " . $e->getMessage() . "\n";
}
