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
		'ems_fluent_participant_form_id',
		'ems_fluent_expedition_form_id',
		'ems_participant_form_mappings',
		'ems_expedition_form_mappings',
		'ems_page_roles',
		'ems_protect_tutor_lms',
		'ems_osm_auth_url',
		'ems_osm_token_url',
		'ems_osm_resource_url',
		'ems_debug_log_guard',
	);

	private const TABLES_TO_EXPORT = array(
		'ems_volunteers',
		'ems_team_members',
		'ems_volunteer_availability',
		'ems_route_submissions',
		'ems_osm_explorers',
		'ems_osm_events',
		'ems_osm_event_attendance',
		'ems_units',
		'ems_unit_patrols',
		'ems_participant_signups',
		'ems_expedition_signups',
		'ems_audit_logs',
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

	public function export_units(): string {
		global $wpdb;

		$units_table = $wpdb->prefix . 'ems_units';
		$patrols_table = $wpdb->prefix . 'ems_unit_patrols';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$units = $wpdb->get_results( "SELECT * FROM {$units_table}", ARRAY_A );
		if ( ! is_array( $units ) ) {
			$units = array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$patrols = $wpdb->get_results( "SELECT * FROM {$patrols_table}", ARRAY_A );
		if ( ! is_array( $patrols ) ) {
			$patrols = array();
		}

		$payload = array(
			'type'         => 'ems_units_export',
			'version'      => '0.1.x',
			'exported_at'  => current_time( 'mysql' ),
			'units'        => $units,
			'unit_patrols' => $patrols,
		);

		return (string) wp_json_encode( $payload, JSON_PRETTY_PRINT );
	}

	/**
	 * Imports only the EMS units lookup table from a JSON string.
	 *
	 * @param string $json_content The JSON backup data.
	 * @return bool True on success.
	 * @throws \Exception When the backup structure is invalid.
	 */
	public function import_units( string $json_content ): bool {
		global $wpdb;

		$data = json_decode( $json_content, true );
		if ( ! is_array( $data ) || ! isset( $data['type'] ) || 'ems_units_export' !== $data['type'] || ! isset( $data['units'] ) ) {
			throw new \Exception( 'Invalid units backup file structure.' );
		}

		$units_table = $wpdb->prefix . 'ems_units';
		$patrols_table = $wpdb->prefix . 'ems_unit_patrols';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "TRUNCATE TABLE {$units_table}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "TRUNCATE TABLE {$patrols_table}" );

		$units = $data['units'];

		// Check if this is a legacy export (which had patrol_id in the units array)
		$is_legacy = false;
		if ( ! empty( $units ) && is_array( $units ) ) {
			$first_row = reset( $units );
			if ( isset( $first_row['patrol_id'] ) ) {
				$is_legacy = true;
			}
		}

		if ( $is_legacy ) {
			// Migrate legacy units array
			$master_units = array();
			$patrol_mappings = array();
			$next_generated_unit_id = 900000;

			foreach ( $units as $row ) {
				$patrol_id = isset( $row['patrol_id'] ) ? (int) $row['patrol_id'] : 0;
				$section_id = isset( $row['section_id'] ) ? (int) $row['section_id'] : 0;
				$unit_id = ! empty( $row['unit_id'] ) ? (int) $row['unit_id'] : null;

				$has_unit_info = ! empty( $row['unit_id'] ) || ! empty( $row['short_code'] ) || ! empty( $row['leader_email'] );

				if ( $patrol_id < 0 ) {
					if ( ! $unit_id ) {
						$unit_id = $next_generated_unit_id++;
					}
					$master_key = (string) $unit_id;
					$master_units[ $master_key ] = array(
						'unit_id'      => $unit_id,
						'district'     => '',
						'name'         => $row['name'] ?? '',
						'short_code'   => $row['short_code'] ?: ( $row['name'] ?? '' ),
						'leader_email' => $row['leader_email'] ?? '',
						'created_at'   => $row['synced_at'] ?? current_time( 'mysql' ),
						'updated_at'   => $row['updated_at'] ?? null,
					);
				} else {
					if ( $has_unit_info ) {
						if ( ! $unit_id ) {
							$unit_id = $next_generated_unit_id++;
						}
						$master_key = (string) $unit_id;
						if ( ! isset( $master_units[ $master_key ] ) ) {
							$master_units[ $master_key ] = array(
								'unit_id'      => $unit_id,
								'district'     => '',
								'name'         => $row['name'] ?? '',
								'short_code'   => $row['short_code'] ?: ( $row['name'] ?? '' ),
								'leader_email' => $row['leader_email'] ?? '',
								'created_at'   => current_time( 'mysql' ),
								'updated_at'   => $row['updated_at'] ?? null,
							);
						}
					}

					$patrol_mappings[] = array(
						'unit_id'    => $unit_id,
						'section_id' => $section_id,
						'patrol_id'  => $patrol_id,
						'name'       => $row['name'] ?? '',
						'active'     => isset( $row['active'] ) ? (int) $row['active'] : 1,
						'synced_at'  => $row['synced_at'] ?? current_time( 'mysql' ),
					);
				}
			}

			// Insert master units
			foreach ( $master_units as $u ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->insert( $units_table, $u );
			}

			// Insert patrol mappings
			foreach ( $patrol_mappings as $mapping ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->insert( $patrols_table, $mapping );
			}
		} else {
			// Modern import
			if ( ! empty( $units ) ) {
				foreach ( $units as $row ) {
					unset( $row['patrol_id'], $row['section_id'], $row['active'], $row['synced_at'], $row['leader_first_name'], $row['leader_last_name'] );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					$wpdb->insert( $units_table, $row );
				}
			}

			$patrols = $data['unit_patrols'] ?? array();
			if ( ! empty( $patrols ) ) {
				foreach ( $patrols as $row ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					$wpdb->insert( $patrols_table, $row );
				}
			}
		}

		return true;
	}
}
