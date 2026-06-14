<?php
/**
 * Basic checksum unit test (run via PHPUnit or wp eval).
 *
 * @package TheExporter
 */

use TheExporter\Validation\ChecksumService;

// PHPUnit-style test for CI integration.
class Test_Checksum_Service extends WP_UnitTestCase {

	/**
	 * Test string hash known vector.
	 */
	public function test_hash_string() {
		$hash = ChecksumService::hash_string( 'test' );
		$this->assertSame(
			'9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08',
			$hash
		);
	}
}
