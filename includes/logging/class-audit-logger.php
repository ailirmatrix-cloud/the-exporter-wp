<?php
/**
 * Audit logging.
 *
 * @package TheExporter
 */

namespace TheExporter\Logging;

defined( 'ABSPATH' ) || exit;

/**
 * Class AuditLogger
 */
class AuditLogger {

	/**
	 * Init hooks.
	 */
	public static function init() {
		// Reserved for future cron cleanup.
	}

	/**
	 * Log an audit event.
	 *
	 * @param string $action     Action name.
	 * @param string $message    Human-readable message.
	 * @param array  $context    Additional context.
	 * @param string $result     info|success|warning|error.
	 */
	public static function log( $action, $message, array $context = array(), $result = 'info' ) {
		global $wpdb;

		$entry = array(
			'ts'      => gmdate( 'c' ),
			'action'  => $action,
			'message' => $message,
			'result'  => $result,
			'actor'   => get_current_user_id(),
			'context' => $context,
		);

		$wpdb->insert(
			$wpdb->prefix . 'tex_audit_log',
			array(
				'job_id'        => isset( $context['job_id'] ) ? absint( $context['job_id'] ) : null,
				'migration_id'  => isset( $context['migration_id'] ) ? sanitize_text_field( $context['migration_id'] ) : null,
				'actor_id'      => get_current_user_id(),
				'action'        => sanitize_key( $action ),
				'component'     => isset( $context['component'] ) ? sanitize_key( $context['component'] ) : null,
				'chunk_id'      => isset( $context['chunk_id'] ) ? absint( $context['chunk_id'] ) : null,
				'checksum'      => isset( $context['checksum'] ) ? sanitize_text_field( $context['checksum'] ) : null,
				'result'        => sanitize_key( $result ),
				'message'       => sanitize_text_field( $message ),
				'context'       => wp_json_encode( $context ),
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! empty( $context['migration_id'] ) ) {
			self::append_file_log( $context['migration_id'], $entry );
		}
	}

	/**
	 * Append to migration audit log file.
	 *
	 * @param string $migration_id Migration ID.
	 * @param array  $entry        Log entry.
	 */
	private static function append_file_log( $migration_id, array $entry ) {
		$path = \TheExporter\Settings::migration_path( $migration_id ) . '/audit/export.log';
		$dir  = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $path, wp_json_encode( $entry ) . "\n", FILE_APPEND );
	}

	/**
	 * Get recent logs.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	public static function get_logs( array $args = array() ) {
		global $wpdb;

		$defaults = array(
			'migration_id' => '',
			'job_id'       => 0,
			'limit'        => 100,
			'offset'       => 0,
		);
		$args = wp_parse_args( $args, $defaults );

		$where  = array( '1=1' );
		$params = array();

		if ( $args['migration_id'] ) {
			$where[]  = 'migration_id = %s';
			$params[] = $args['migration_id'];
		}
		if ( $args['job_id'] ) {
			$where[]  = 'job_id = %d';
			$params[] = $args['job_id'];
		}

		$sql = "SELECT * FROM {$wpdb->prefix}tex_audit_log WHERE " . implode( ' AND ', $where )
			. ' ORDER BY id DESC LIMIT %d OFFSET %d';

		$params[] = absint( $args['limit'] );
		$params[] = absint( $args['offset'] );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
	}
}
