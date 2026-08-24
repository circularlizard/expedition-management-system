<?php
namespace EMS\Data;

class Unit_Repository {
	private object $wpdb;

	public function __construct( ?object $wpdb = null ) {
		if ( $wpdb === null ) {
			global $wpdb;
		}
		$this->wpdb = $wpdb;
	}

	/**
	 * Sync patrol from OSM (Insert or update metadata while preserving admin configurations)
	 */
	public function sync_patrol( array $data ): int {
		if ( empty( $data['patrol_id'] ) || empty( $data['section_id'] ) ) {
			throw new \InvalidArgumentException( 'patrol_id and section_id are required for syncing' );
		}

		$now = current_time( 'mysql' );
		$sql = "INSERT INTO {$this->wpdb->prefix}ems_units 
            (patrol_id, section_id, name, active, synced_at, short_code) 
            VALUES (%d, %d, %s, %d, %s, %s) 
            ON DUPLICATE KEY UPDATE 
            name = VALUES(name), 
            active = VALUES(active), 
            synced_at = VALUES(synced_at)";

		$prepared = $this->wpdb->prepare(
			$sql,
			$data['patrol_id'],
			$data['section_id'],
			$data['name'] ?? '',
			isset( $data['active'] ) ? (int) $data['active'] : 1,
			$data['synced_at'] ?? $now,
			$data['name'] ?? ''
		);

		$this->wpdb->query( $prepared );

		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT id FROM {$this->wpdb->prefix}ems_units WHERE patrol_id = %d AND section_id = %d",
				$data['patrol_id'],
				$data['section_id']
			)
		);
	}

	/**
	 * Update custom mapping configurations manually set by administrator
	 */
	public function update_custom_mappings( int $id, array $data ): bool {
		if ( isset( $data['leader_email'] ) && ! empty( $data['leader_email'] ) && ! is_email( $data['leader_email'] ) ) {
			throw new \InvalidArgumentException( 'Invalid leader email format' );
		}

		$update_data = array(
			'updated_at' => current_time( 'mysql' ),
		);

		$format = array( '%s' );

		if ( array_key_exists( 'unit_id', $data ) ) {
			$update_data['unit_id'] = empty( $data['unit_id'] ) ? null : (int) $data['unit_id'];
			$format[]               = empty( $data['unit_id'] ) ? '%d' : '%d'; // handles null correctly in wpdb
		}
		if ( isset( $data['short_code'] ) ) {
			$update_data['short_code'] = $data['short_code'];
			$format[]                  = '%s';
		}
		if ( isset( $data['leader_first_name'] ) ) {
			$update_data['leader_first_name'] = $data['leader_first_name'];
			$format[]                         = '%s';
		}
		if ( isset( $data['leader_last_name'] ) ) {
			$update_data['leader_last_name'] = $data['leader_last_name'];
			$format[]                        = '%s';
		}
		if ( isset( $data['leader_email'] ) ) {
			$update_data['leader_email'] = $data['leader_email'];
			$format[]                    = '%s';
		}
		if ( isset( $data['name'] ) ) {
			$update_data['name'] = $data['name'];
			$format[]            = '%s';
		}

		$updated = $this->wpdb->update(
			$this->wpdb->prefix . 'ems_units',
			$update_data,
			array( 'id' => $id ),
			$format,
			array( '%d' )
		);

		return $updated !== false;
	}

	/**
	 * Adds a custom/manual unit (not synced from OSM)
	 *
	 * @param array $data The unit data.
	 * @return int The generated unit database ID.
	 */
	public function add_custom_unit( array $data ): int {
		$name = sanitize_text_field( $data['name'] ?? '' );
		if ( empty( $name ) ) {
			throw new \InvalidArgumentException( 'Unit name is required.' );
		}

		// Find the minimum patrol_id in the database to generate a unique negative patrol_id
		$min_patrol_id = (int) $this->wpdb->get_var( "SELECT MIN(patrol_id) FROM {$this->wpdb->prefix}ems_units" );
		$patrol_id = $min_patrol_id < 0 ? $min_patrol_id - 1 : -1;

		$insert_data = array(
			'patrol_id'         => $patrol_id,
			'section_id'        => 0, // 0 indicates manual/non-OSM
			'name'              => $name,
			'active'            => 1,
			'synced_at'         => current_time( 'mysql' ),
			'unit_id'           => empty( $data['unit_id'] ) ? null : (int) $data['unit_id'],
			'short_code'        => sanitize_text_field( $data['short_code'] ?? $name ),
			'leader_first_name' => sanitize_text_field( $data['leader_first_name'] ?? '' ),
			'leader_last_name'  => sanitize_text_field( $data['leader_last_name'] ?? '' ),
			'leader_email'      => sanitize_text_field( $data['leader_email'] ?? '' ),
			'updated_at'        => current_time( 'mysql' ),
		);

		$format = array(
			'%d', // patrol_id
			'%d', // section_id
			'%s', // name
			'%d', // active
			'%s', // synced_at
			$insert_data['unit_id'] === null ? '%d' : '%d', // unit_id
			'%s', // short_code
			'%s', // leader_first_name
			'%s', // leader_last_name
			'%s', // leader_email
			'%s', // updated_at
		);

		$this->wpdb->insert( $this->wpdb->prefix . 'ems_units', $insert_data, $format );

		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT id FROM {$this->wpdb->prefix}ems_units WHERE patrol_id = %d AND section_id = 0",
				$patrol_id
			)
		);
	}

	/**
	 * Deletes a custom/manual unit (not synced from OSM)
	 *
	 * @param int $id The unit database ID.
	 * @return bool True if deleted successfully, false otherwise.
	 */
	public function delete_custom_unit( int $id ): bool {
		$unit = $this->find_by_id( $id );
		if ( ! $unit || (int) $unit['patrol_id'] >= 0 ) {
			return false; // Prevent deleting synced units
		}

		$deleted = $this->wpdb->delete(
			$this->wpdb->prefix . 'ems_units',
			array( 'id' => $id ),
			array( '%d' )
		);

		return $deleted !== false;
	}


	public function find_by_id( int $id ): ?array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->wpdb->prefix}ems_units WHERE id = %d",
				$id
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	public function find_by_patrol_section( int $patrol_id, int $section_id ): ?array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->wpdb->prefix}ems_units WHERE patrol_id = %d AND section_id = %d",
				$patrol_id,
				$section_id
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	public function list_active_units(): array {
		$rows = $this->wpdb->get_results(
			"SELECT * FROM {$this->wpdb->prefix}ems_units WHERE active = 1 ORDER BY name, section_id",
			ARRAY_A
		);
		return $rows ?: array();
	}

	public function find_by_short_code( string $short_code ): ?array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->wpdb->prefix}ems_units WHERE short_code = %s AND active = 1 LIMIT 1",
				$short_code
			),
			ARRAY_A
		);
		return $row ?: null;
	}
}
