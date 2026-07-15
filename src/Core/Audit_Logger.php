<?php
namespace EMS\Core;

class Audit_Logger {

	/**
	 * Write an audit log entry to the database.
	 *
	 * @param string   $action          The action slug being logged.
	 * @param int|null $target_scout_id Optional target scout ID.
	 * @param int|null $user_id         Optional user ID.
	 */
	public static function log( string $action, ?int $target_scout_id = null, ?int $user_id = null ): void {
		global $wpdb;

		if ( ! isset( $wpdb ) ) {
			return;
		}

		if ( $user_id === null ) {
			$user_id = (int) get_current_user_id();
		}

		// Safely resolve IP address
		$ip_address = '0.0.0.0';
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ips = explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] );
			$ip  = trim( reset( $ips ) );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				$ip_address = $ip;
			}
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = trim( $_SERVER['REMOTE_ADDR'] );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				$ip_address = $ip;
			}
		}

		// User agent string limit to 255
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '';

		$timestamp = current_time( 'mysql' ) ?: gmdate( 'Y-m-d H:i:s' );

		$wpdb->insert(
			$wpdb->prefix . 'ems_audit_logs',
			array(
				'user_id'         => $user_id,
				'action'          => sanitize_text_field( $action ),
				'target_scout_id' => $target_scout_id,
				'ip_address'      => $ip_address,
				'user_agent'      => $user_agent,
				'timestamp'       => $timestamp,
			),
			array(
				'%d',
				'%s',
				$target_scout_id !== null ? '%d' : null,
				'%s',
				'%s',
				'%s',
			)
		);
	}
}
