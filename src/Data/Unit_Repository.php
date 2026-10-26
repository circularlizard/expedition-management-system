<?php
namespace EMS\Data;

class Unit_Repository {
	private ?object $wpdb;

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

		$now        = current_time( 'mysql' );
		$patrol_id  = (int) $data['patrol_id'];
		$section_id = (int) $data['section_id'];
		$name       = sanitize_text_field( $data['name'] ?? '' );
		$active     = isset( $data['active'] ) ? (int) $data['active'] : 1;
		$synced_at  = $data['synced_at'] ?? $now;

		// 1. Find all active units to run matching logic
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$units        = $this->wpdb->get_results( "SELECT * FROM {$this->wpdb->prefix}ems_units", ARRAY_A ) ?: array();
		$matched_unit = $this->find_matching_unit( $name, $units );
		$unit_id      = $matched_unit ? (int) $matched_unit['unit_id'] : null;

		// 2. Insert or update the patrol in ems_unit_patrols
		$sql = "INSERT INTO {$this->wpdb->prefix}ems_unit_patrols 
            (unit_id, section_id, patrol_id, name, active, synced_at) 
            VALUES (%d, %d, %d, %s, %d, %s) 
            ON DUPLICATE KEY UPDATE 
            unit_id = VALUES(unit_id),
            name = VALUES(name), 
            active = VALUES(active), 
            synced_at = VALUES(synced_at)";

		$prepared = $this->wpdb->prepare(
			$sql,
			$unit_id,
			$section_id,
			$patrol_id,
			$name,
			$active,
			$synced_at
		);

		$this->wpdb->query( $prepared );

		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT id FROM {$this->wpdb->prefix}ems_unit_patrols WHERE patrol_id = %d AND section_id = %d",
				$patrol_id,
				$section_id
			)
		);
	}

	/**
	 * Find a master unit matching a patrol name.
	 */
	public function find_matching_unit( string $patrol_name, array $master_units ): ?array {
		$patrol_clean = strtolower( trim( $patrol_name ) );
		if ( empty( $patrol_clean ) ) {
			return null;
		}

		// 1. Exact match on short_code (case-insensitive)
		foreach ( $master_units as $unit ) {
			if ( ! empty( $unit['short_code'] ) && strtolower( trim( $unit['short_code'] ) ) === $patrol_clean ) {
				return $unit;
			}
		}

		// 2. Exact match on unit name (case-insensitive)
		foreach ( $master_units as $unit ) {
			if ( ! empty( $unit['name'] ) && strtolower( trim( $unit['name'] ) ) === $patrol_clean ) {
				return $unit;
			}
		}

		// 3. Substring match: unit name contains patrol name, or vice versa
		foreach ( $master_units as $unit ) {
			$unit_name_clean  = ! empty( $unit['name'] ) ? strtolower( trim( $unit['name'] ) ) : '';
			$short_code_clean = ! empty( $unit['short_code'] ) ? strtolower( trim( $unit['short_code'] ) ) : '';

			if ( ( $unit_name_clean !== '' && str_contains( $unit_name_clean, $patrol_clean ) ) ||
				 ( $short_code_clean !== '' && str_contains( $short_code_clean, $patrol_clean ) ) ) {
				return $unit;
			}
		}

		// 4. Substring match: patrol name contains unit name or short code
		foreach ( $master_units as $unit ) {
			$unit_name_clean  = ! empty( $unit['name'] ) ? strtolower( trim( $unit['name'] ) ) : '';
			$short_code_clean = ! empty( $unit['short_code'] ) ? strtolower( trim( $unit['short_code'] ) ) : '';

			if ( ( $unit_name_clean !== '' && str_contains( $patrol_clean, $unit_name_clean ) ) ||
				 ( $short_code_clean !== '' && str_contains( $patrol_clean, $short_code_clean ) ) ) {
				return $unit;
			}
		}

		return null;
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
			$format[]               = '%d';
		}
		if ( isset( $data['short_code'] ) ) {
			$update_data['short_code'] = sanitize_text_field( $data['short_code'] );
			$format[]                  = '%s';
		}
		if ( isset( $data['name'] ) ) {
			$update_data['name'] = sanitize_text_field( $data['name'] );
			$format[]            = '%s';
		}
		if ( isset( $data['district'] ) ) {
			$update_data['district'] = sanitize_text_field( $data['district'] );
			$format[]                = '%s';
		}
		if ( isset( $data['leader_email'] ) ) {
			$update_data['leader_email'] = sanitize_text_field( $data['leader_email'] );
			$format[]                    = '%s';
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
	 * Adds a master unit
	 *
	 * @param array $data The unit data.
	 * @return int The generated unit database ID.
	 */
	public function add_custom_unit( array $data ): int {
		$name = sanitize_text_field( $data['name'] ?? '' );
		if ( empty( $name ) ) {
			throw new \InvalidArgumentException( 'Unit name is required.' );
		}

		$unit_id = isset( $data['unit_id'] ) ? (int) $data['unit_id'] : null;
		if ( ! $unit_id ) {
			throw new \InvalidArgumentException( 'Unit ID is required.' );
		}

		$insert_data = array(
			'unit_id'      => $unit_id,
			'district'     => sanitize_text_field( $data['district'] ?? '' ),
			'name'         => $name,
			'short_code'   => sanitize_text_field( $data['short_code'] ?? $name ),
			'leader_email' => sanitize_text_field( $data['leader_email'] ?? '' ),
			'created_at'   => current_time( 'mysql' ),
			'updated_at'   => current_time( 'mysql' ),
		);

		$format = array(
			'%d', // unit_id
			'%s', // district
			'%s', // name
			'%s', // short_code
			'%s', // leader_email
			'%s', // created_at
			'%s', // updated_at
		);

		$this->wpdb->insert( $this->wpdb->prefix . 'ems_units', $insert_data, $format );

		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT id FROM {$this->wpdb->prefix}ems_units WHERE unit_id = %d",
				$unit_id
			)
		);
	}

	/**
	 * Deletes a master unit
	 *
	 * @param int $id The unit database ID.
	 * @return bool True if deleted successfully, false otherwise.
	 */
	public function delete_custom_unit( int $id ): bool {
		$unit = $this->find_by_id( $id );
		if ( ! $unit ) {
			return false;
		}

		$unit_id = (int) $unit['unit_id'];

		if ( $unit_id ) {
			$this->wpdb->update(
				$this->wpdb->prefix . 'ems_unit_patrols',
				array( 'unit_id' => null ),
				array( 'unit_id' => $unit_id ),
				array( '%d' ),
				array( '%d' )
			);
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
		$patrol = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT unit_id FROM {$this->wpdb->prefix}ems_unit_patrols WHERE patrol_id = %d AND section_id = %d LIMIT 1",
				$patrol_id,
				$section_id
			),
			ARRAY_A
		);

		if ( empty( $patrol ) || empty( $patrol['unit_id'] ) ) {
			return null;
		}

		return $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->wpdb->prefix}ems_units WHERE unit_id = %d LIMIT 1",
				$patrol['unit_id']
			),
			ARRAY_A
		) ?: null;
	}

	public function list_active_units(): array {
		/** @var array<int, array<string, mixed>> $units */
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$units = $this->wpdb->get_results(
			"SELECT * FROM {$this->wpdb->prefix}ems_units ORDER BY name",
			ARRAY_A
		) ?: array();

		foreach ( $units as &$u ) {
			$u['matched_patrols'] = array();
			$unit_id              = (int) ( $u['unit_id'] ?? 0 );
			if ( $unit_id ) {
				$patrols = $this->wpdb->get_results(
					$this->wpdb->prepare(
						"SELECT * FROM {$this->wpdb->prefix}ems_unit_patrols WHERE unit_id = %d AND active = 1",
						$unit_id
					),
					ARRAY_A
				) ?: array();
				$u['matched_patrols'] = $patrols;
			}
		}

		return $units;
	}

	public function find_by_short_code( string $short_code ): ?array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->wpdb->prefix}ems_units WHERE short_code = %s LIMIT 1",
				$short_code
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * Consolidates duplicate master units sharing the same name or short code.
	 *
	 * Merges all matched patrols from duplicate units into the primary unit
	 * and removes duplicate master unit records.
	 *
	 * @return array{merged_count: int, details: array<string>}
	 */
	public function consolidate_duplicate_units(): array {
		$units  = $this->list_active_units();
		$groups = array();

		// Group by normalized name
		foreach ( $units as $u ) {
			$norm_name = strtolower( trim( (string) $u['name'] ) );
			$groups[ $norm_name ][] = $u;
		}

		$merged_count = 0;
		$details      = array();

		foreach ( $groups as $norm_name => $unit_list ) {
			if ( count( $unit_list ) <= 1 ) {
				continue;
			}

			// Choose primary unit: prefer one with district / leader_email or lowest ID
			usort(
				$unit_list,
				function( $a, $b ) {
					$score_a = ( ! empty( $a['district'] ) ? 2 : 0 ) + ( ! empty( $a['leader_email'] ) ? 1 : 0 );
					$score_b = ( ! empty( $b['district'] ) ? 2 : 0 ) + ( ! empty( $b['leader_email'] ) ? 1 : 0 );
					if ( $score_a !== $score_b ) {
						return $score_b <=> $score_a;
					}
					return (int) $a['id'] <=> (int) $b['id'];
				}
			);

			$primary            = $unit_list[0];
			$primary_unit_id    = (int) $primary['unit_id'];
			$duplicates         = array_slice( $unit_list, 1 );
			$duplicate_ids      = array();
			$duplicate_unit_ids = array();

			foreach ( $duplicates as $dup ) {
				$duplicate_ids[] = (int) $dup['id'];
				if ( ! empty( $dup['unit_id'] ) ) {
					$duplicate_unit_ids[] = (int) $dup['unit_id'];
				}
			}

			// 1. Reassign all patrols pointing to duplicate unit_ids to primary_unit_id
			if ( ! empty( $duplicate_unit_ids ) ) {
				$in_unit_ids = implode( ',', array_map( 'intval', $duplicate_unit_ids ) );
				$this->wpdb->query(
					$this->wpdb->prepare(
						"UPDATE {$this->wpdb->prefix}ems_unit_patrols SET unit_id = %d WHERE unit_id IN ({$in_unit_ids})",
						$primary_unit_id
					)
				);
			}

			// 2. Delete duplicate ems_units rows
			if ( ! empty( $duplicate_ids ) ) {
				$in_ids = implode( ',', array_map( 'intval', $duplicate_ids ) );
				$this->wpdb->query( "DELETE FROM {$this->wpdb->prefix}ems_units WHERE id IN ({$in_ids})" );
			}

			$merged_count += count( $duplicates );
			$details[]     = sprintf(
				'%s: Merged %d duplicate(s) into Unit ID %d',
				$primary['name'],
				count( $duplicates ),
				$primary_unit_id
			);
		}

		return array(
			'merged_count' => $merged_count,
			'details'      => $details,
		);
	}

	/**
	 * Returns invitation and login status for a unit leader email.
	 *
	 * @param string $email The leader's email address.
	 * @return array{invited: bool, invited_at: ?string, logged_in: bool, last_login_at: ?string, user_id: ?int}
	 */
	public function get_leader_access_status( string $email ): array {
		$email = trim( $email );
		if ( $email === '' || ! is_email( $email ) ) {
			return array(
				'invited'       => false,
				'invited_at'    => null,
				'logged_in'     => false,
				'last_login_at' => null,
				'user_id'       => null,
			);
		}

		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'invited'       => false,
				'invited_at'    => null,
				'logged_in'     => false,
				'last_login_at' => null,
				'user_id'       => null,
			);
		}

		$user_id       = (int) $user->ID;
		$invited_at    = (string) get_user_meta( $user_id, 'ems_leader_invited_at', true );
		$last_login_at = (string) get_user_meta( $user_id, 'ems_last_login_at', true );

		return array(
			'invited'       => $invited_at !== '' || $last_login_at !== '',
			'invited_at'    => $invited_at !== '' ? $invited_at : null,
			'logged_in'     => $last_login_at !== '',
			'last_login_at' => $last_login_at !== '' ? $last_login_at : null,
			'user_id'       => $user_id,
		);
	}

	/**
	 * Bulk-invites unit leaders (or invites a single unit's leader) by creating/updating
	 * WordPress user accounts with the ems_leader role and sending the WP password setup email.
	 *
	 * @param int|null $only_unit_row_id Optional unit row ID to invite a single unit.
	 * @param bool     $force_resend     Whether to resend the invitation email even if already invited.
	 * @return array{invited: int, already_invited: int, skipped_no_email: int, errors: array<string>}
	 */
	public function invite_unit_leaders( ?int $only_unit_row_id = null, bool $force_resend = false ): array {
		if ( $only_unit_row_id !== null ) {
			$unit  = $this->find_by_id( $only_unit_row_id );
			$units = $unit ? array( $unit ) : array();
		} else {
			$units = $this->list_active_units();
		}

		$invited          = 0;
		$already_invited  = 0;
		$skipped_no_email = 0;
		$errors           = array();
		$seen_emails      = array();
		$now              = current_time( 'mysql' );

		foreach ( $units as $u ) {
			$email = strtolower( trim( (string) ( $u['leader_email'] ?? '' ) ) );
			if ( $email === '' || ! is_email( $email ) ) {
				++$skipped_no_email;
				continue;
			}

			if ( isset( $seen_emails[ $email ] ) ) {
				continue;
			}
			$seen_emails[ $email ] = true;

			$user = get_user_by( 'email', $email );
			if ( $user ) {
				if ( is_callable( array( $user, 'add_role' ) ) ) {
					$user->add_role( 'ems_leader' );
				}
				$user_id       = (int) $user->ID;
				$invited_at    = (string) get_user_meta( $user_id, 'ems_leader_invited_at', true );
				$last_login_at = (string) get_user_meta( $user_id, 'ems_last_login_at', true );

				if ( ! $force_resend && ( $invited_at !== '' || $last_login_at !== '' ) ) {
					++$already_invited;
					continue;
				}

				wp_new_user_notification( $user_id, null, 'user' );
				update_user_meta( $user_id, 'ems_leader_invited_at', $now );
				++$invited;
				continue;
			}

			$base_login = sanitize_user( current( explode( '@', $email ) ), true );
			if ( $base_login === '' ) {
				$base_login = 'ems_leader';
			}
			$user_login = $base_login;
			$suffix     = 1;
			while ( username_exists( $user_login ) ) {
				$user_login = $base_login . '_' . $suffix;
				++$suffix;
			}

			$new_user_id = wp_insert_user(
				array(
					'user_login' => $user_login,
					'user_email' => $email,
					'user_pass'  => wp_generate_password( 24, true, true ),
					'role'       => 'ems_leader',
				)
			);

			if ( is_wp_error( $new_user_id ) ) {
				$errors[] = $email . ': ' . $new_user_id->get_error_message();
				continue;
			}

			wp_new_user_notification( (int) $new_user_id, null, 'user' );
			update_user_meta( (int) $new_user_id, 'ems_leader_invited_at', $now );
			++$invited;
		}

		return array(
			'invited'          => $invited,
			'already_invited'  => $already_invited,
			'skipped_no_email' => $skipped_no_email,
			'errors'           => $errors,
		);
	}
}
