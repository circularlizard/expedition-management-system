<?php
namespace EMS\Data;

class Signup_Repository {
	private object $wpdb;

	public function __construct( ?object $wpdb = null ) {
		if ( $wpdb === null ) {
			global $wpdb;
		}
		$this->wpdb = $wpdb;
	}

	/**
	 * Create a new Participant Place signup
	 */
	public function create_participant_signup( array $data ): int {
		$now = current_time( 'mysql' );

		$insert_data = array(
			'scout_id'            => (int) ( $data['scout_id'] ?? 0 ),
			'parent_user_id'      => (int) ( $data['parent_user_id'] ?? get_current_user_id() ),
			'unit_id'             => ! empty( $data['unit_id'] ) ? (int) $data['unit_id'] : null,
			'unit_name'           => sanitize_text_field( $data['unit_name'] ?? '' ),
			'explorer_first_name' => sanitize_text_field( $data['explorer_first_name'] ?? '' ),
			'explorer_last_name'  => sanitize_text_field( $data['explorer_last_name'] ?? '' ),
			'explorer_email'      => sanitize_email( $data['explorer_email'] ?? '' ),
			'parent_email'        => sanitize_email( $data['parent_email'] ?? '' ),
			'leader_email'        => sanitize_email( $data['leader_email'] ?? '' ),
			'dofe_level'          => strtolower( sanitize_text_field( $data['dofe_level'] ?? '' ) ),
			'dob'                 => ! empty( $data['dob'] ) ? sanitize_text_field( $data['dob'] ) : null,
			'dofe_registered'     => sanitize_text_field( $data['dofe_registered'] ?? 'n' ),
			'dofe_number'         => ! empty( $data['dofe_number'] ) ? sanitize_text_field( $data['dofe_number'] ) : null,
			'dofe_org'            => ! empty( $data['dofe_org'] ) ? sanitize_text_field( $data['dofe_org'] ) : null,
			'bronze_completion'   => ! empty( $data['bronze_completion'] ) ? ( is_array( $data['bronze_completion'] ) ? json_encode( $data['bronze_completion'] ) : $data['bronze_completion'] ) : null,
			'silver_completion'   => ! empty( $data['silver_completion'] ) ? ( is_array( $data['silver_completion'] ) ? json_encode( $data['silver_completion'] ) : $data['silver_completion'] ) : null,
			'signup_status'       => sanitize_text_field( $data['signup_status'] ?? 'submitted' ),
			'payment_status'      => sanitize_text_field( $data['payment_status'] ?? 'pending' ),
			'form_submission_id'  => (int) ( $data['form_submission_id'] ?? 0 ),
			'created_at'          => $now,
			'updated_at'          => $now,
		);

		$format = array(
			'%d',
			'%d',
			'%d',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%d',
			'%s',
			'%s',
		);

		$result = $this->wpdb->insert(
			"{$this->wpdb->prefix}ems_participant_signups",
			$insert_data,
			$format
		);

		if ( $result === false ) {
			return 0;
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Create a new Expedition signup
	 */
	public function create_expedition_signup( array $data ): int {
		$now = current_time( 'mysql' );

		$insert_data = array(
			'scout_id'                 => (int) ( $data['scout_id'] ?? 0 ),
			'parent_user_id'           => (int) ( $data['parent_user_id'] ?? get_current_user_id() ),
			'unit_id'                  => ! empty( $data['unit_id'] ) ? (int) $data['unit_id'] : null,
			'unit_name'                => sanitize_text_field( $data['unit_name'] ?? '' ),
			'explorer_first_name'      => sanitize_text_field( $data['explorer_first_name'] ?? '' ),
			'explorer_last_name'       => sanitize_text_field( $data['explorer_last_name'] ?? '' ),
			'explorer_email'           => sanitize_email( $data['explorer_email'] ?? '' ),
			'parent_email'             => sanitize_email( $data['parent_email'] ?? '' ),
			'leader_email'             => sanitize_email( $data['leader_email'] ?? '' ),
			'dofe_level'               => strtolower( sanitize_text_field( $data['dofe_level'] ?? '' ) ),
			'expedition_preferences'   => ! empty( $data['expedition_preferences'] ) ? ( is_array( $data['expedition_preferences'] ) ? json_encode( $data['expedition_preferences'] ) : $data['expedition_preferences'] ) : null,
			'additional_support_needs' => ! empty( $data['additional_support_needs'] ) ? sanitize_textarea_field( $data['additional_support_needs'] ) : null,
			'first_aid_status'         => sanitize_text_field( $data['first_aid_status'] ?? 'none' ),
			'first_aid_expiry'         => ! empty( $data['first_aid_expiry'] ) ? sanitize_text_field( $data['first_aid_expiry'] ) : null,
			'signup_status'            => sanitize_text_field( $data['signup_status'] ?? 'submitted' ),
			'form_submission_id'       => (int) ( $data['form_submission_id'] ?? 0 ),
			'created_at'               => $now,
			'updated_at'               => $now,
		);

		$format = array(
			'%d',
			'%d',
			'%d',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%d',
			'%s',
			'%s',
		);

		$result = $this->wpdb->insert(
			"{$this->wpdb->prefix}ems_expedition_signups",
			$insert_data,
			$format
		);

		if ( $result === false ) {
			return 0;
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Get a participant signup record by ID
	 */
	public function get_participant_signup( int $id ): ?array {
		$sql = "SELECT * FROM {$this->wpdb->prefix}ems_participant_signups WHERE id = %d";
		$row = $this->wpdb->get_row( $this->wpdb->prepare( $sql, $id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Get an expedition signup record by ID
	 */
	public function get_expedition_signup( int $id ): ?array {
		$sql = "SELECT e.*, p.dofe_number
                FROM {$this->wpdb->prefix}ems_expedition_signups e
                LEFT JOIN (
                    SELECT p1.scout_id, p1.dofe_number
                    FROM {$this->wpdb->prefix}ems_participant_signups p1
                    INNER JOIN (
                        SELECT scout_id, MAX(id) as max_id
                        FROM {$this->wpdb->prefix}ems_participant_signups
                        WHERE dofe_number IS NOT NULL AND dofe_number != ''
                        GROUP BY scout_id
                    ) p2 ON p1.id = p2.max_id
                ) p ON e.scout_id = p.scout_id
                WHERE e.id = %d";
		$row = $this->wpdb->get_row( $this->wpdb->prepare( $sql, $id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Update payment status of a signup by Fluent Form submission entry ID
	 */
	public function update_payment_status_by_submission_id( int $form_submission_id, string $status ): bool {
		$now              = current_time( 'mysql' );
		$sanitized_status = sanitize_text_field( $status );

		$p_result = $this->wpdb->update(
			"{$this->wpdb->prefix}ems_participant_signups",
			array(
				'payment_status' => $sanitized_status,
				'updated_at'     => $now,
			),
			array( 'form_submission_id' => $form_submission_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return $p_result !== false;
	}

	public function get_participant_signups( string $status = 'all' ): array {
		if ( $status === 'all' ) {
			$sql = "SELECT p.*, CASE WHEN o.scout_id IS NOT NULL THEN 1 ELSE 0 END AS is_synced_osm 
                    FROM {$this->wpdb->prefix}ems_participant_signups p
                    LEFT JOIN {$this->wpdb->prefix}ems_osm_explorers o ON p.scout_id = o.scout_id
                    ORDER BY p.created_at DESC";
			return $this->wpdb->get_results( $sql, ARRAY_A ) ?: array();
		}

		$db_status = 'submitted';
		if ( $status === 'archived' ) {
			$db_status = 'archived';
		}

		$sql = "SELECT p.*, CASE WHEN o.scout_id IS NOT NULL THEN 1 ELSE 0 END AS is_synced_osm 
                FROM {$this->wpdb->prefix}ems_participant_signups p
                LEFT JOIN {$this->wpdb->prefix}ems_osm_explorers o ON p.scout_id = o.scout_id
                WHERE p.signup_status = %s 
                ORDER BY p.created_at DESC";
		return $this->wpdb->get_results( $this->wpdb->prepare( $sql, $db_status ), ARRAY_A ) ?: array();
	}

	/**
	 * Get expedition signups filtered by status
	 */
	public function get_expedition_signups( string $status = 'all' ): array {
		$join_sql = "LEFT JOIN (
            SELECT p1.scout_id, p1.dofe_number
            FROM {$this->wpdb->prefix}ems_participant_signups p1
            INNER JOIN (
                SELECT scout_id, MAX(id) as max_id
                FROM {$this->wpdb->prefix}ems_participant_signups
                WHERE dofe_number IS NOT NULL AND dofe_number != ''
                GROUP BY scout_id
            ) p2 ON p1.id = p2.max_id
        ) p ON e.scout_id = p.scout_id
        LEFT JOIN {$this->wpdb->prefix}ems_osm_explorers o ON e.scout_id = o.scout_id";

		if ( $status === 'all' ) {
			$sql = "SELECT e.*, p.dofe_number, CASE WHEN o.scout_id IS NOT NULL THEN 1 ELSE 0 END AS is_synced_osm 
                    FROM {$this->wpdb->prefix}ems_expedition_signups e 
                    {$join_sql}
                    ORDER BY e.created_at DESC";
			return $this->wpdb->get_results( $sql, ARRAY_A ) ?: array();
		}

		$db_status = 'submitted';
		if ( $status === 'archived' ) {
			$db_status = 'archived';
		}

		$sql = "SELECT e.*, p.dofe_number, CASE WHEN o.scout_id IS NOT NULL THEN 1 ELSE 0 END AS is_synced_osm 
                FROM {$this->wpdb->prefix}ems_expedition_signups e 
                {$join_sql}
                WHERE e.signup_status = %s 
                ORDER BY e.created_at DESC";
		return $this->wpdb->get_results( $this->wpdb->prepare( $sql, $db_status ), ARRAY_A ) ?: array();
	}

	/**
	 * Process (allocate) a participant place signup
	 */
	public function process_participant_signup( int $id, int $user_id, ?string $dofe_number = null ): bool {
		$update_data = array(
			'processed_by'  => $user_id,
			'processed_at'  => current_time( 'mysql' ),
			'updated_at'    => current_time( 'mysql' ),
		);

		$format = array( '%d', '%s', '%s' );

		if ( ! empty( $dofe_number ) ) {
			$update_data['dofe_number'] = sanitize_text_field( $dofe_number );
			$format[]                   = '%s';
		}

		$result = $this->wpdb->update(
			"{$this->wpdb->prefix}ems_participant_signups",
			$update_data,
			array( 'id' => $id ),
			$format,
			array( '%d' )
		);
		return $result !== false;
	}

	/**
	 * Archive a participant signup
	 */
	public function archive_participant_signup( int $id ): bool {
		$result = $this->wpdb->update(
			"{$this->wpdb->prefix}ems_participant_signups",
			array(
				'signup_status' => 'archived',
				'updated_at'    => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		return $result !== false;
	}

	/**
	 * Archive an expedition signup
	 */
	public function archive_expedition_signup( int $id ): bool {
		$result = $this->wpdb->update(
			"{$this->wpdb->prefix}ems_expedition_signups",
			array(
				'signup_status' => 'archived',
				'updated_at'    => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		return $result !== false;
	}

	/**
	 * Reconcile / match unmatched signups (scout_id = 0) to a synced OSM explorer.
	 */
	public function reconcile_signup( int $signup_id, string $signup_type, int $scout_id ): bool {
		if ( $signup_type === 'participant' ) {
			$signup = $this->get_participant_signup( $signup_id );
		} else {
			$signup = $this->get_expedition_signup( $signup_id );
		}

		if ( ! $signup ) {
			return false;
		}

		$first_name   = $signup['explorer_first_name'];
		$last_name    = $signup['explorer_last_name'];
		$email        = $signup['explorer_email'];
		$parent_email = $signup['parent_email'];

		$this->wpdb->query( 'START TRANSACTION' );

		// Batch update participant places
		$p_sql = $this->wpdb->prepare(
			"UPDATE {$this->wpdb->prefix}ems_participant_signups 
			SET scout_id = %d 
			WHERE scout_id = 0 AND signup_status = 'submitted' AND (
				(explorer_email = %s AND explorer_email != '') OR 
				(LOWER(explorer_first_name) = LOWER(%s) AND LOWER(explorer_last_name) = LOWER(%s) AND parent_email = %s)
			)",
			$scout_id,
			$email,
			$first_name,
			$last_name,
			$parent_email
		);
		$this->wpdb->query( $p_sql );

		// Batch update expedition preferences
		$e_sql = $this->wpdb->prepare(
			"UPDATE {$this->wpdb->prefix}ems_expedition_signups 
			SET scout_id = %d 
			WHERE scout_id = 0 AND signup_status = 'submitted' AND (
				(explorer_email = %s AND explorer_email != '') OR 
				(LOWER(explorer_first_name) = LOWER(%s) AND LOWER(explorer_last_name) = LOWER(%s) AND parent_email = %s)
			)",
			$scout_id,
			$email,
			$first_name,
			$last_name,
			$parent_email
		);
		$this->wpdb->query( $e_sql );

		$this->wpdb->query( 'COMMIT' );
		return true;
	}

	/**
	 * Unlink a matched signup back to guest status (scout_id = 0).
	 */
	public function unlink_signup( int $signup_id, string $signup_type ): bool {
		if ( $signup_type === 'participant' ) {
			$signup = $this->get_participant_signup( $signup_id );
		} else {
			$signup = $this->get_expedition_signup( $signup_id );
		}

		if ( ! $signup || (int) $signup['scout_id'] === 0 ) {
			return false;
		}

		$scout_id     = (int) $signup['scout_id'];
		$first_name   = $signup['explorer_first_name'];
		$last_name    = $signup['explorer_last_name'];
		$email        = $signup['explorer_email'];
		$parent_email = $signup['parent_email'];

		// Enforce safety constraint: explorer must not be assigned to any teams
		$assigned = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->wpdb->prefix}ems_team_members WHERE scout_id = %d",
				$scout_id
			)
		);
		if ( $assigned > 0 ) {
			throw new \RuntimeException( 'Cannot unlink explorer who is currently assigned to a team.' );
		}

		$this->wpdb->query( 'START TRANSACTION' );

		// Batch update participant places back to 0
		$p_sql = $this->wpdb->prepare(
			"UPDATE {$this->wpdb->prefix}ems_participant_signups 
			SET scout_id = 0 
			WHERE scout_id = %d AND signup_status = 'submitted' AND (
				(explorer_email = %s AND explorer_email != '') OR 
				(LOWER(explorer_first_name) = LOWER(%s) AND LOWER(explorer_last_name) = LOWER(%s) AND parent_email = %s)
			)",
			$scout_id,
			$email,
			$first_name,
			$last_name,
			$parent_email
		);
		$this->wpdb->query( $p_sql );

		// Batch update expedition preferences back to 0
		$e_sql = $this->wpdb->prepare(
			"UPDATE {$this->wpdb->prefix}ems_expedition_signups 
			SET scout_id = 0 
			WHERE scout_id = %d AND signup_status = 'submitted' AND (
				(explorer_email = %s AND explorer_email != '') OR 
				(LOWER(explorer_first_name) = LOWER(%s) AND LOWER(explorer_last_name) = LOWER(%s) AND parent_email = %s)
			)",
			$scout_id,
			$email,
			$first_name,
			$last_name,
			$parent_email
		);
		$this->wpdb->query( $e_sql );

		$this->wpdb->query( 'COMMIT' );
		return true;
	}

	public function has_additional_support_needs( int $scout_id ): bool {
		$exp_asn = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT additional_support_needs FROM {$this->wpdb->prefix}ems_expedition_signups WHERE scout_id = %d AND additional_support_needs != '' LIMIT 1",
				$scout_id
			)
		);
		return ! empty( $exp_asn );
	}

	/**
	 * Get participant signups for CSV export
	 */
	public function get_participant_signups_for_export( string $status = 'all', string $level = 'all' ): array {
		$conditions = array();
		$params     = array();

		if ( $status !== 'all' ) {
			$conditions[] = 'p.signup_status = %s';
			$params[]     = sanitize_text_field( $status );
		}
		if ( $level !== 'all' ) {
			$conditions[] = 'p.dofe_level = %s';
			$params[]     = sanitize_text_field( $level );
		}

		$where = '';
		if ( ! empty( $conditions ) ) {
			$where = 'WHERE ' . implode( ' AND ', $conditions );
		}

		$sql = "SELECT p.*, 
                       u1.display_name AS processed_by_name,
                       o.wp_user_id AS osm_wp_user_id,
                       CASE WHEN o.scout_id IS NOT NULL THEN 1 ELSE 0 END AS has_osm_record
                FROM {$this->wpdb->prefix}ems_participant_signups p
                LEFT JOIN {$this->wpdb->base_prefix}users u1 ON p.processed_by = u1.ID
                LEFT JOIN {$this->wpdb->prefix}ems_osm_explorers o ON p.scout_id = o.scout_id
                {$where}
                ORDER BY p.created_at DESC";

		if ( ! empty( $params ) ) {
			$sql = $this->wpdb->prepare( $sql, $params );
		}

		return $this->wpdb->get_results( $sql, ARRAY_A ) ?: array();
	}
}
