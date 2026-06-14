<?php
/**
 * Per-component transfer status tracking.
 *
 * @package TheExporter
 */

namespace TheExporter\Transfer;

defined( 'ABSPATH' ) || exit;

/**
 * Class TransferStatus
 */
class TransferStatus {

	const OPTION_KEY = 'te_transfer_status';

	/**
	 * Get all status for migration.
	 *
	 * @param string $migration_id Migration ID.
	 * @return array
	 */
	public static function get_all( $migration_id ) {
		$all = get_option( self::OPTION_KEY, array() );
		return isset( $all[ $migration_id ] ) ? $all[ $migration_id ] : array();
	}

	/**
	 * Get component status.
	 *
	 * @param string $migration_id Migration ID.
	 * @param string $component    Component.
	 * @return array
	 */
	public static function get( $migration_id, $component ) {
		$all = self::get_all( $migration_id );
		$defaults = array(
			'state'        => 'not_started',
			'validated_at' => null,
			'imported_at'  => null,
			'last_report'  => null,
		);
		return isset( $all[ $component ] ) ? wp_parse_args( $all[ $component ], $defaults ) : $defaults;
	}

	/**
	 * Update component status.
	 *
	 * @param string $migration_id Migration ID.
	 * @param string $component    Component.
	 * @param array  $data         Data to merge.
	 */
	public static function update( $migration_id, $component, array $data ) {
		$all = get_option( self::OPTION_KEY, array() );
		if ( ! isset( $all[ $migration_id ] ) ) {
			$all[ $migration_id ] = array();
		}
		$all[ $migration_id ][ $component ] = array_merge(
			self::get( $migration_id, $component ),
			$data
		);
		update_option( self::OPTION_KEY, $all, false );
	}

	/**
	 * Mark component validated.
	 *
	 * @param string $migration_id Migration ID.
	 * @param string $component    Component.
	 * @param array  $report       Validation report.
	 */
	public static function mark_validated( $migration_id, $component, array $report ) {
		self::update( $migration_id, $component, array(
			'state'        => $report['passed'] ? 'validated' : 'uploaded',
			'validated_at' => gmdate( 'c' ),
			'last_report'  => $report,
		) );
	}

	/**
	 * Mark component imported.
	 *
	 * @param string $migration_id Migration ID.
	 * @param string $component    Component.
	 */
	public static function mark_imported( $migration_id, $component ) {
		self::update( $migration_id, $component, array(
			'state'       => 'imported',
			'imported_at' => gmdate( 'c' ),
		) );
	}

	/**
	 * Reset all transfer status for migration.
	 *
	 * @param string $migration_id Migration ID.
	 */
	public static function reset_migration( $migration_id ) {
		$all = get_option( self::OPTION_KEY, array() );
		unset( $all[ $migration_id ] );
		update_option( self::OPTION_KEY, $all, false );
	}

	/**
	 * Prune old migration status entries.
	 *
	 * @param int $keep_max Max migrations to keep.
	 */
	public static function prune( $keep_max = 20 ) {
		$all = get_option( self::OPTION_KEY, array() );
		if ( count( $all ) <= $keep_max ) {
			return;
		}
		$keys = array_keys( $all );
		$drop = count( $keys ) - $keep_max;
		for ( $i = 0; $i < $drop; $i++ ) {
			unset( $all[ $keys[ $i ] ] );
		}
		update_option( self::OPTION_KEY, $all, false );
	}
}
