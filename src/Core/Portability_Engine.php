<?php
namespace EMS\Core;

class Portability_Engine {

	private const OPTIONS_TO_EXPORT = array(
		'ems_managed_sections',
		'ems_writeback_section_id',
		'ems_api_mode',
		'ems_sync_limit',
		'ems_osm_client_id',
		'ems_osm_client_secret',
		'ems_osm_api_base_url',
		'ems_osm_scope',
		'ems_flexirecord_column_map',
		'ems_form_mappings',
		'ems_fluent_form_id',
		'ems_debug_log_guard',
	);

	private const TABLES_TO_EXPORT = array(
		'ems_team_members',
		'ems_volunteer_availability',
		'ems_route_submissions',
		'ems_osm_explorers',
		'ems_osm_events',
		'ems_osm_event_attendance',
		'ems_units',
		'ems_signups',
	);

	/**
	 * Exports all EMS settings and database table contents as a JSON string.
	 *
	 * @return string
	 */
	public function export_data(): string {
		global $wpdb;

		$payload = array(
			'version'     => '0.1.x',
			'exported_at' => current_time( 'mysql' ),
			'options'     => array(),
			'tables'      => array(),
		);

		// 1. Export options
		foreach ( self::OPTIONS_TO_EXPORT as $option ) {
			$payload['options'][ $option ] = get_option( $option, null );
		}

		// 2. Export custom table rows
		foreach ( self::TABLES_TO_EXPORT as $table_name ) {
			$table = $wpdb->prefix . $table_name;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A ) ?: array();
			$payload['tables'][ $table_name ] = $rows;
		}

		return (string) wp_json_encode( $payload, JSON_PRETTY_PRINT );
	}

	/**
	 * Imports EMS settings and table data from a JSON string.
	 *
	 * @param string $json_content
	 * @return bool
	 * @throws \Exception
	 */
	public function import_data( string $json_content ): bool {
		global $wpdb;

		$data = json_decode( $json_content, true );
		if ( ! is_array( $data ) || ! isset( $data['options'] ) || ! isset( $data['tables'] ) ) {
			throw new \Exception( 'Invalid backup file structure.' );
		}

		// 1. Ensure tables exist
		$installer = new Table_Installer();
		$installer->install();

		// 2. Restore Options
		foreach ( $data['options'] as $option => $value ) {
			if ( in_array( $option, self::OPTIONS_TO_EXPORT, true ) ) {
				if ( $value === null ) {
					delete_option( $option );
				} else {
					update_option( $option, $value );
				}
			}
		}

		// 3. Restore Tables
		foreach ( $data['tables'] as $table_name => $rows ) {
			if ( ! in_array( $table_name, self::TABLES_TO_EXPORT, true ) ) {
				continue;
			}

			$table = $wpdb->prefix . $table_name;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "TRUNCATE TABLE {$table}" );

			if ( empty( $rows ) ) {
				continue;
			}

			foreach ( $rows as $row ) {
				$wpdb->insert( $table, $row );
			}
		}

		return true;
	}
}
