<?php
/**
 * Database table installer.
 *
 * @package TheExporter
 */

namespace TheExporter\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Class Installer
 */
class Installer {

	/**
	 * Install custom tables.
	 */
	public static function install() {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$prefix  = $wpdb->prefix;

		$sql = array(
			"CREATE TABLE {$prefix}tex_jobs (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				migration_id VARCHAR(36) NOT NULL,
				type VARCHAR(20) NOT NULL DEFAULT 'export',
				status VARCHAR(20) NOT NULL DEFAULT 'pending',
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
				meta LONGTEXT NULL,
				PRIMARY KEY (id),
				KEY migration_id (migration_id),
				KEY status (status)
			) $charset;",
			"CREATE TABLE {$prefix}tex_job_steps (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				job_id BIGINT UNSIGNED NOT NULL,
				component VARCHAR(50) NOT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'pending',
				total_chunks INT UNSIGNED NOT NULL DEFAULT 0,
				completed_chunks INT UNSIGNED NOT NULL DEFAULT 0,
				total_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				meta LONGTEXT NULL,
				PRIMARY KEY (id),
				KEY job_id (job_id),
				KEY component (component)
			) $charset;",
			"CREATE TABLE {$prefix}tex_chunks (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				step_id BIGINT UNSIGNED NOT NULL,
				chunk_index INT UNSIGNED NOT NULL DEFAULT 0,
				file_path TEXT NOT NULL,
				file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
				checksum VARCHAR(64) NOT NULL DEFAULT '',
				status VARCHAR(20) NOT NULL DEFAULT 'pending',
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				checksum_verified_at DATETIME NULL,
				meta LONGTEXT NULL,
				PRIMARY KEY (id),
				KEY step_id (step_id),
				KEY status (status)
			) $charset;",
			"CREATE TABLE {$prefix}tex_audit_log (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				job_id BIGINT UNSIGNED NULL,
				migration_id VARCHAR(36) NULL,
				actor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				action VARCHAR(50) NOT NULL,
				component VARCHAR(50) NULL,
				chunk_id BIGINT UNSIGNED NULL,
				checksum VARCHAR(64) NULL,
				result VARCHAR(20) NOT NULL DEFAULT 'info',
				message TEXT NULL,
				context LONGTEXT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				KEY job_id (job_id),
				KEY migration_id (migration_id),
				KEY action (action)
			) $charset;",
		);

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( $sql as $query ) {
			dbDelta( $query );
		}

		update_option( 'te_db_version', TE_VERSION );
	}
}
