<?php
/**
 * Database import from chunked SQL dumps.
 *
 * @package TheExporter
 */

namespace TheExporter\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Class Importer
 */
class Importer {

	/**
	 * Import schema file.
	 *
	 * @param string $file_path SQL gz file.
	 * @param bool   $dry_run   Dry run.
	 * @return array Result.
	 */
	public static function import_schema( $file_path, $dry_run = false ) {
		if ( $dry_run ) {
			return array( 'success' => true, 'dry_run' => true, 'file' => $file_path );
		}

		return self::import_sql_file( $file_path );
	}

	/**
	 * Import table data chunk.
	 *
	 * @param string $file_path SQL gz file.
	 * @param string $table     Expected table name.
	 * @param int    $expected_rows Expected row count from inventory.
	 * @param bool   $dry_run   Dry run.
	 * @param bool   $force     Force on row mismatch.
	 * @return array
	 */
	public static function import_table_chunk( $file_path, $table, $expected_rows = 0, $dry_run = false, $force = false ) {
		if ( $dry_run ) {
			return array( 'success' => true, 'dry_run' => true, 'file' => $file_path, 'table' => $table );
		}

		$result = self::import_sql_file( $file_path );
		if ( ! $result['success'] ) {
			return $result;
		}

		if ( $expected_rows > 0 && $table ) {
			$actual = Dumper::get_row_count( $table );
			if ( $actual < $expected_rows && ! $force ) {
				return array(
					'success' => false,
					'error'   => sprintf(
						'Row count mismatch for %s: expected >= %d, got %d',
						$table,
						$expected_rows,
						$actual
					),
				);
			}
		}

		return array( 'success' => true, 'table' => $table );
	}

	/**
	 * Import SQL file via mysql CLI or PHP fallback.
	 *
	 * @param string $file_path Gzipped SQL file.
	 * @return array
	 */
	private static function import_sql_file( $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			return array( 'success' => false, 'error' => 'File not found' );
		}

		if ( self::has_mysql_cli() && self::has_gunzip() ) {
			$db_name = defined( 'DB_NAME' ) ? DB_NAME : '';
			$db_user = defined( 'DB_USER' ) ? DB_USER : '';
			$db_pass = defined( 'DB_PASSWORD' ) ? DB_PASSWORD : '';
			$db_host = defined( 'DB_HOST' ) ? DB_HOST : 'localhost';

			$cmd = sprintf(
				'gunzip -c %s | mysql -h %s -u %s %s %s 2>&1',
				escapeshellarg( $file_path ),
				escapeshellarg( $db_host ),
				escapeshellarg( $db_user ),
				$db_pass ? '-p' . escapeshellarg( $db_pass ) : '',
				escapeshellarg( $db_name )
			);

			exec( $cmd, $output, $code );
			if ( 0 === $code ) {
				return array( 'success' => true );
			}
			return array( 'success' => false, 'error' => implode( "\n", $output ) );
		}

		return self::import_sql_php( $file_path );
	}

	/**
	 * PHP fallback SQL import.
	 *
	 * @param string $file_path File path.
	 * @return array
	 */
	private static function import_sql_php( $file_path ) {
		global $wpdb;

		$fp = gzopen( $file_path, 'rb' );
		if ( ! $fp ) {
			return array( 'success' => false, 'error' => 'Cannot open file' );
		}

		$buffer = '';
		while ( ! gzeof( $fp ) ) {
			$buffer .= gzread( $fp, 65536 );
			while ( ( $pos = strpos( $buffer, ";\n" ) ) !== false ) {
				$statement = trim( substr( $buffer, 0, $pos ) );
				$buffer    = substr( $buffer, $pos + 2 );
				if ( '' === $statement || strpos( $statement, '--' ) === 0 ) {
					continue;
				}
				$result = self::execute_sql_statement( $statement );
				if ( false === $result ) {
					gzclose( $fp );
					global $wpdb;
					return array(
						'success' => false,
						'error'   => $wpdb->last_error ? $wpdb->last_error : 'SQL import failed',
					);
				}
			}
		}
		gzclose( $fp );

		$tail = trim( $buffer );
		if ( '' !== $tail && strpos( $tail, '--' ) !== 0 ) {
			$result = self::execute_sql_statement( $tail );
			if ( false === $result ) {
				global $wpdb;
				return array(
					'success' => false,
					'error'   => $wpdb->last_error ? $wpdb->last_error : 'SQL import failed',
				);
			}
		}

		return array( 'success' => true );
	}

	/**
	 * Execute one SQL statement with batched INSERT optimization.
	 *
	 * @param string $statement SQL statement.
	 * @return int|false Rows affected or false on error.
	 */
	private static function execute_sql_statement( $statement ) {
		global $wpdb;

		if ( preg_match( '/^INSERT\s+INTO\s+/i', $statement ) ) {
			return self::execute_insert_batch( $statement );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->query( $statement );
	}

	/**
	 * Run INSERT; multi-row batches are passed through unchanged.
	 *
	 * @param string $statement INSERT statement.
	 * @return int|false
	 */
	private static function execute_insert_batch( $statement ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->query( $statement );
	}

	/**
	 * Import mydumper directory via myloader when available (VPS path).
	 *
	 * @param string $dump_dir Directory with mydumper output.
	 * @return array
	 */
	public static function import_mydumper( $dump_dir ) {
		if ( ! \TheExporter\Runtime::command_exists( 'myloader' ) ) {
			return array( 'success' => false, 'error' => 'myloader not available on this host' );
		}
		$db_name = defined( 'DB_NAME' ) ? DB_NAME : '';
		$db_user = defined( 'DB_USER' ) ? DB_USER : '';
		$db_pass = defined( 'DB_PASSWORD' ) ? DB_PASSWORD : '';
		$db_host = defined( 'DB_HOST' ) ? DB_HOST : 'localhost';
		$cmd     = sprintf(
			'myloader -h %s -u %s %s -B %s -d %s -o 2>&1',
			escapeshellarg( $db_host ),
			escapeshellarg( $db_user ),
			$db_pass ? '-p' . escapeshellarg( $db_pass ) : '',
			escapeshellarg( $db_name ),
			escapeshellarg( $dump_dir )
		);
		exec( $cmd, $output, $code );
		if ( 0 === $code ) {
			return array( 'success' => true );
		}
		return array( 'success' => false, 'error' => implode( "\n", $output ) );
	}

	/**
	 * Check mysql CLI availability.
	 *
	 * @return bool
	 */
	public static function has_mysql_cli() {
		return \TheExporter\Runtime::command_exists( 'mysql' );
	}

	/**
	 * Check gunzip availability.
	 *
	 * @return bool
	 */
	public static function has_gunzip() {
		return \TheExporter\Runtime::command_exists( 'gunzip' );
	}
}
