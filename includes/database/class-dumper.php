<?php
/**
 * Database export via mysqldump or PHP fallback.
 *
 * @package TheExporter
 */

namespace TheExporter\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Class Dumper
 */
class Dumper {

	/**
	 * Export full database (schema + data) to a single gzip file.
	 *
	 * @param string $output_dir Output directory.
	 * @return array|false
	 */
	public static function export_full( $output_dir ) {
		wp_mkdir_p( $output_dir );
		$output_file = $output_dir . '/dump.sql.gz';

		$engine = \TheExporter\EnvironmentProfile::effective_database_engine();
		if ( 'mydumper' === $engine && self::has_mydumper() ) {
			$mydumper = self::export_mydumper( $output_dir );
			if ( $mydumper ) {
				return $mydumper;
			}
		}

		if ( self::has_mysqldump() && 'php' !== $engine ) {
			$cmd = self::build_mysqldump_cmd( array() );
			return self::run_dump_to_gz( $cmd, $output_file );
		}

		return self::export_full_php( $output_file );
	}

	/**
	 * Estimate total database export size in bytes.
	 *
	 * @return int
	 */
	public static function estimate_total_bytes() {
		$total = 0;
		foreach ( self::get_tables() as $table ) {
			$total += self::get_row_count( $table ) * 500;
		}
		// Schema overhead.
		$total += 50000;
		return $total;
	}

	/**
	 * Export database schema only.
	 *
	 * @param string $output_dir Output directory.
	 * @return array|false Result with file path and checksum.
	 */
	public static function export_schema( $output_dir ) {
		wp_mkdir_p( $output_dir );
		$output_file = $output_dir . '/schema.sql.gz';

		if ( self::has_mysqldump() ) {
			$cmd = self::build_mysqldump_cmd( array( '--no-data' ) );
			return self::run_dump_to_gz( $cmd, $output_file );
		}

		return self::export_schema_php( $output_file );
	}

	/**
	 * Export a single table.
	 *
	 * @param string $table      Table name.
	 * @param string $output_dir Output directory.
	 * @param string $where      Optional WHERE clause for chunking.
	 * @param int    $part       Part number for large tables.
	 * @return array|false
	 */
	public static function export_table( $table, $output_dir, $where = '', $part = 0 ) {
		wp_mkdir_p( $output_dir );

		$filename = $part > 0
			? $table . '.part' . str_pad( (string) $part, 3, '0', STR_PAD_LEFT ) . '.sql.gz'
			: $table . '.sql.gz';

		$output_file = $output_dir . '/' . $filename;

		if ( self::has_mysqldump() ) {
			$args = array( '--no-create-info', $table );
			if ( $where ) {
				$args[] = '--where=' . escapeshellarg( $where );
			}
			$cmd = self::build_mysqldump_cmd( $args );
			return self::run_dump_to_gz( $cmd, $output_file, $table, $where );
		}

		return self::export_table_php( $table, $output_file, $where );
	}

	/**
	 * Get all tables.
	 *
	 * @return array
	 */
	public static function get_tables() {
		global $wpdb;
		$exclude = \TheExporter\Settings::get( 'exclude_db_tables', array() );
		$tables  = $wpdb->get_col( 'SHOW TABLES' );

		return array_values( array_filter( $tables, function ( $t ) use ( $exclude ) {
			return ! in_array( $t, $exclude, true );
		} ) );
	}

	/**
	 * Get row count for table.
	 *
	 * @param string $table Table name.
	 * @return int
	 */
	public static function get_row_count( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
	}

	/**
	 * Get primary key column for table.
	 *
	 * @param string $table Table name.
	 * @return string|null
	 */
	public static function get_primary_key( $table ) {
		global $wpdb;
		$keys = $wpdb->get_results( "SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'" );
		return ! empty( $keys[0]->Column_name ) ? $keys[0]->Column_name : null;
	}

	/**
	 * Generate PK range batches for large table.
	 *
	 * @param string $table      Table name.
	 * @param int    $batch_size Rows per batch.
	 * @return array Array of WHERE clauses.
	 */
	public static function get_pk_batches( $table, $batch_size = 100000 ) {
		$pk = self::get_primary_key( $table );
		if ( ! $pk ) {
			return array( '' );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$min = (int) $wpdb->get_var( "SELECT MIN(`{$pk}`) FROM `{$table}`" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$max = (int) $wpdb->get_var( "SELECT MAX(`{$pk}`) FROM `{$table}`" );

		if ( $min === 0 && $max === 0 && self::get_row_count( $table ) === 0 ) {
			return array( '' );
		}

		$batches = array();
		for ( $start = $min; $start <= $max; $start += $batch_size ) {
			$end       = $start + $batch_size - 1;
			$batches[] = "`{$pk}` >= {$start} AND `{$pk}` <= {$end}";
		}
		return $batches;
	}

	/**
	 * Check if mysqldump is available.
	 *
	 * @return bool
	 */
	public static function has_mysqldump() {
		return \TheExporter\Runtime::command_exists( 'mysqldump' );
	}

	/**
	 * Check if mydumper is available.
	 *
	 * @return bool
	 */
	public static function has_mydumper() {
		return \TheExporter\Runtime::command_exists( 'mydumper' );
	}

	/**
	 * Export database with mydumper (per-table chunks under mydumper/).
	 *
	 * @param string $output_dir Output directory.
	 * @return array|false Summary with engine and chunk inventory.
	 */
	public static function export_mydumper( $output_dir ) {
		if ( ! self::has_mydumper() ) {
			return false;
		}

		$dump_dir = $output_dir . '/mydumper';
		wp_mkdir_p( $dump_dir );

		$db_name = defined( 'DB_NAME' ) ? DB_NAME : '';
		$db_user = defined( 'DB_USER' ) ? DB_USER : '';
		$db_pass = defined( 'DB_PASSWORD' ) ? DB_PASSWORD : '';
		$db_host = defined( 'DB_HOST' ) ? DB_HOST : 'localhost';

		$cmd = sprintf(
			'mydumper -h %s -u %s %s -B %s -o %s --threads 2 --compress --less-locking 2>/dev/null',
			escapeshellarg( $db_host ),
			escapeshellarg( $db_user ),
			$db_pass ? '-p ' . escapeshellarg( $db_pass ) : '',
			escapeshellarg( $db_name ),
			escapeshellarg( $dump_dir )
		);
		exec( $cmd, $output, $code );
		if ( 0 !== $code ) {
			return false;
		}

		$chunks  = array();
		$total   = 0;
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dump_dir, \RecursiveDirectoryIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$rel = ltrim( str_replace( wp_normalize_path( $dump_dir ), '', wp_normalize_path( $file->getPathname() ) ), '/\\' );
			$size = $file->getSize();
			$total += $size;
			$chunks[] = array(
				'path'     => 'mydumper/' . str_replace( '\\', '/', $rel ),
				'size'     => $size,
				'checksum' => \TheExporter\Validation\ChecksumService::hash_file( $file->getPathname() ),
			);
		}

		if ( empty( $chunks ) ) {
			return false;
		}

		$manifest = array(
			'engine'  => 'mydumper',
			'threads' => 2,
			'chunks'  => $chunks,
		);
		file_put_contents( $dump_dir . '/inventory.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );

		return array(
			'engine'      => 'mydumper',
			'path'        => $dump_dir,
			'size'        => $total,
			'checksum'    => '',
			'chunk_count' => count( $chunks ),
			'chunks'      => $chunks,
		);
	}

	/**
	 * Build mysqldump command.
	 *
	 * @param array $extra_args Extra arguments.
	 * @return string
	 */
	private static function build_mysqldump_cmd( array $extra_args = array() ) {
		$db_name = defined( 'DB_NAME' ) ? DB_NAME : '';
		$db_user = defined( 'DB_USER' ) ? DB_USER : '';
		$db_pass = defined( 'DB_PASSWORD' ) ? DB_PASSWORD : '';
		$db_host = defined( 'DB_HOST' ) ? DB_HOST : 'localhost';

		$base = sprintf(
			'mysqldump --single-transaction --quick --extended-insert -h %s -u %s %s %s',
			escapeshellarg( $db_host ),
			escapeshellarg( $db_user ),
			$db_pass ? '-p' . escapeshellarg( $db_pass ) : '',
			escapeshellarg( $db_name )
		);

		foreach ( $extra_args as $arg ) {
			$base .= ' ' . $arg;
		}

		return $base;
	}

	/**
	 * Run mysqldump piped to gzip.
	 *
	 * @param string $cmd         Command.
	 * @param string $output_file Output file.
	 * @param string $table       Table name for meta.
	 * @param string $where       WHERE clause.
	 * @return array|false
	 */
	private static function run_dump_to_gz( $cmd, $output_file, $table = '', $where = '' ) {
		$full_cmd = $cmd . ' 2>/dev/null | gzip > ' . escapeshellarg( $output_file );
		exec( $full_cmd, $output, $code );

		if ( 0 !== $code || ! file_exists( $output_file ) ) {
			return false;
		}

		$checksum = \TheExporter\Validation\ChecksumService::write_sidecar( $output_file );
		return array(
			'path'     => $output_file,
			'size'     => filesize( $output_file ),
			'checksum' => $checksum,
			'table'    => $table,
			'where'    => $where,
		);
	}

	/**
	 * PHP fallback: export full database to one gzip file.
	 *
	 * @param string $output_file Output file.
	 * @return array|false
	 */
	private static function export_full_php( $output_file ) {
		global $wpdb;

		$gz = gzopen( $output_file, 'wb9' );
		if ( ! $gz ) {
			return false;
		}

		foreach ( self::get_tables() as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
			if ( $create ) {
				gzwrite( $gz, "DROP TABLE IF EXISTS `{$table}`;\n" . $create[1] . ";\n\n" );
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( "SELECT * FROM `{$table}`", ARRAY_A );
			foreach ( $rows as $row ) {
				$values = array();
				foreach ( $row as $v ) {
					$values[] = null === $v ? 'NULL' : "'" . esc_sql( $v ) . "'";
				}
				gzwrite( $gz, "INSERT INTO `{$table}` VALUES (" . implode( ',', $values ) . ");\n" );
			}
			gzwrite( $gz, "\n" );
		}

		gzclose( $gz );

		$checksum = \TheExporter\Validation\ChecksumService::write_sidecar( $output_file );
		return array(
			'path'     => $output_file,
			'size'     => filesize( $output_file ),
			'checksum' => $checksum,
		);
	}

	/**
	 * PHP fallback: export schema.
	 *
	 * @param string $output_file Output file.
	 * @return array|false
	 */
	private static function export_schema_php( $output_file ) {
		global $wpdb;
		$sql = '';

		foreach ( self::get_tables() as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
			if ( $create ) {
				$sql .= "DROP TABLE IF EXISTS `{$table}`;\n" . $create[1] . ";\n\n";
			}
		}

		$gz = gzopen( $output_file, 'wb9' );
		gzwrite( $gz, $sql );
		gzclose( $gz );

		$checksum = \TheExporter\Validation\ChecksumService::write_sidecar( $output_file );
		return array(
			'path'     => $output_file,
			'size'     => filesize( $output_file ),
			'checksum' => $checksum,
		);
	}

	/**
	 * PHP fallback: export table data.
	 *
	 * @param string $table       Table name.
	 * @param string $output_file Output file.
	 * @param string $where       WHERE clause.
	 * @return array|false
	 */
	private static function export_table_php( $table, $output_file, $where = '' ) {
		global $wpdb;

		$where_sql = $where ? ' WHERE ' . $where : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM `{$table}`{$where_sql}", ARRAY_A );

		$sql = '';
		foreach ( $rows as $row ) {
			$values = array();
			foreach ( $row as $v ) {
				$values[] = null === $v ? 'NULL' : "'" . esc_sql( $v ) . "'";
			}
			$sql .= "INSERT INTO `{$table}` VALUES (" . implode( ',', $values ) . ");\n";
		}

		$gz = gzopen( $output_file, 'wb9' );
		gzwrite( $gz, $sql );
		gzclose( $gz );

		$checksum = \TheExporter\Validation\ChecksumService::write_sidecar( $output_file );
		return array(
			'path'     => $output_file,
			'size'     => filesize( $output_file ),
			'checksum' => $checksum,
			'table'    => $table,
			'where'    => $where,
		);
	}
}
