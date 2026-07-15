<?php
namespace EMS\Core;

class Log_Rotator {

	private const MAX_ROWS = 50000;
	private const RETENTION_DAYS = 365;

	/**
	 * Purges logs older than 365 days and trims the table to MAX_ROWS if exceeded.
	 */
	public static function purge_old_logs(): void {
		global $wpdb;

		if ( ! isset( $wpdb ) ) {
			return;
		}

		$table = $wpdb->prefix . 'ems_audit_logs';

		// 1. Purge records older than 365 days
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is dynamic prefix
		$wpdb->query( "DELETE FROM {$table} WHERE timestamp < DATE_SUB(NOW(), INTERVAL " . self::RETENTION_DAYS . ' DAY)' );

		// 2. Cap the total count of rows
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is dynamic prefix
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		if ( $count > self::MAX_ROWS ) {
			// Find the boundary ID at offset self::MAX_ROWS when sorted descending (newest to oldest)
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is dynamic prefix
			$boundary_id = $wpdb->get_var( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET " . self::MAX_ROWS );

			if ( $boundary_id ) {
				// Delete anything older than or equal to the boundary ID
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is dynamic prefix
				$delete_sql = $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", (int) $boundary_id );
				$wpdb->query( $delete_sql );
			}
		}
	}

	/**
	 * Registers the daily cron event for cleanup if not already scheduled.
	 */
	public static function register_cron(): void {
		if ( ! wp_next_scheduled( 'ems_daily_log_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'ems_daily_log_cleanup' );
		}
	}
}
