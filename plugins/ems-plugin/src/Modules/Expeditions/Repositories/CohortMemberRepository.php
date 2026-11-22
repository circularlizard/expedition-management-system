<?php
namespace EMS\Modules\Expeditions\Repositories;

/**
 * Repository for persistent cohort memberships ({$wpdb->prefix}ems_cohort_members).
 *
 * Member hydration drives from {$wpdb->prefix}ems_expedition_signups (s) with
 * LEFT JOIN {$wpdb->prefix}ems_osm_explorers (e) ON (s.scout_id > 0 AND s.scout_id = e.scout_id)
 * so unmatched explorers (scout_id = 0) never collide.
 */
class CohortMemberRepository {
	private ?object $wpdb;

	public function __construct( ?object $wpdb = null ) {
		$this->wpdb = $wpdb;
	}

	private function get_wpdb(): object {
		if ( $this->wpdb === null ) {
			global $wpdb;
			$this->wpdb = $wpdb;
		}
		return $this->wpdb;
	}

	public function get_table(): string {
		return $this->get_wpdb()->prefix . 'ems_cohort_members';
	}

	/**
	 * Find a hydrated cohort member row by membership ID.
	 */
	public function find( int $id ): ?array {
		if ( $id <= 0 ) {
			return null;
		}

		$wpdb = $this->get_wpdb();
		$sql  = $this->hydrated_select_sql() . ' WHERE cm.id = %d LIMIT 1';

		$row = $wpdb->get_row( $wpdb->prepare( $sql, $id ), ARRAY_A );
		return is_array( $row ) ? $this->normalize_hydrated_row( $row ) : null;
	}

	/**
	 * Find a hydrated cohort member by cohort_id and expedition_signup_id.
	 */
	public function findByCohortAndSignup( int $cohort_id, int $expedition_signup_id ): ?array {
		if ( $cohort_id <= 0 || $expedition_signup_id <= 0 ) {
			return null;
		}

		$wpdb = $this->get_wpdb();
		$sql  = $this->hydrated_select_sql() . ' WHERE cm.cohort_id = %d AND cm.expedition_signup_id = %d LIMIT 1';

		$row = $wpdb->get_row(
			$wpdb->prepare( $sql, $cohort_id, $expedition_signup_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $this->normalize_hydrated_row( $row ) : null;
	}

	/**
	 * List hydrated members for a cohort.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function listByCohort( int $cohort_id, ?string $member_status = null ): array {
		if ( $cohort_id <= 0 ) {
			return array();
		}

		$wpdb  = $this->get_wpdb();
		$where = array( 'cm.cohort_id = %d' );
		$args  = array( $cohort_id );

		if ( $member_status !== null && $member_status !== '' ) {
			$where[] = 'cm.member_status = %s';
			$args[]  = sanitize_text_field( $member_status );
		}

		$sql = $this->hydrated_select_sql()
			. ' WHERE ' . implode( ' AND ', $where )
			. ' ORDER BY cm.id ASC';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( $this, 'normalize_hydrated_row' ), $rows );
	}

	/**
	 * Batch-list hydrated members for multiple cohorts.
	 *
	 * @param array<int, int> $cohort_ids
	 * @return array<int, array<string, mixed>>
	 */
	public function listByCohorts( array $cohort_ids, ?string $member_status = null ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $cohort_ids ), static fn( int $v ): bool => $v > 0 ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$wpdb         = $this->get_wpdb();
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$where        = array( "cm.cohort_id IN ({$placeholders})" );
		$args         = $ids;

		if ( $member_status !== null && $member_status !== '' ) {
			$where[] = 'cm.member_status = %s';
			$args[]  = sanitize_text_field( $member_status );
		}

		$sql = $this->hydrated_select_sql()
			. ' WHERE ' . implode( ' AND ', $where )
			. ' ORDER BY cm.cohort_id ASC, cm.id ASC';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( $this, 'normalize_hydrated_row' ), $rows );
	}

	/**
	 * List all cohort memberships for a given expedition_signup_id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function listByExpeditionSignup( int $expedition_signup_id ): array {
		if ( $expedition_signup_id <= 0 ) {
			return array();
		}

		$wpdb = $this->get_wpdb();
		$sql  = $this->hydrated_select_sql()
			. ' WHERE cm.expedition_signup_id = %d ORDER BY cm.id ASC';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $expedition_signup_id ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( $this, 'normalize_hydrated_row' ), $rows );
	}

	/**
	 * Add an explorer signup to a cohort and return the new membership ID.
	 *
	 * @param array<string, mixed> $data
	 */
	public function create( array $data ): int {
		$cohort_id            = (int) ( $data['cohort_id'] ?? 0 );
		$expedition_signup_id = (int) ( $data['expedition_signup_id'] ?? 0 );

		if ( $cohort_id <= 0 ) {
			throw new \InvalidArgumentException( 'cohort_id must be a positive integer.' );
		}
		if ( $expedition_signup_id <= 0 ) {
			throw new \InvalidArgumentException( 'expedition_signup_id must be a positive integer.' );
		}

		$wpdb  = $this->get_wpdb();
		$table = $this->get_table();

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE cohort_id = %d AND expedition_signup_id = %d",
				$cohort_id,
				$expedition_signup_id
			)
		);
		if ( $existing ) {
			throw new \InvalidArgumentException(
				"Signup {$expedition_signup_id} is already a member of cohort {$cohort_id}."
			);
		}

		$availability_override = null;
		if ( array_key_exists( 'availability_override', $data ) && $data['availability_override'] !== null ) {
			$availability_override = is_array( $data['availability_override'] )
				? (string) wp_json_encode( $data['availability_override'] )
				: (string) $data['availability_override'];
		}

		$now         = current_time( 'mysql', true );
		$insert_data = array(
			'cohort_id'             => $cohort_id,
			'expedition_signup_id'  => $expedition_signup_id,
			'scout_id'              => max( 0, (int) ( $data['scout_id'] ?? 0 ) ),
			'availability_override' => $availability_override,
			'member_status'         => sanitize_text_field( (string) ( $data['member_status'] ?? 'active' ) ) ?: 'active',
			'notes'                 => isset( $data['notes'] ) && $data['notes'] !== null ? sanitize_textarea_field( (string) $data['notes'] ) : null,
			'added_by'              => isset( $data['added_by'] ) ? (int) $data['added_by'] : (int) get_current_user_id(),
			'created_at'            => $now,
			'updated_at'            => $now,
		);

		$formats = array( '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s' );

		$result = $wpdb->insert( $table, $insert_data, $formats );
		if ( $result === false ) {
			throw new \RuntimeException( 'Failed to add cohort member: ' . $wpdb->last_error );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update an existing cohort member record.
	 *
	 * @param array<string, mixed> $data
	 */
	public function update( int $id, array $data ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		$wpdb  = $this->get_wpdb();
		$table = $this->get_table();

		$exists = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $id )
		);
		if ( ! $exists ) {
			return false;
		}

		$update_data = array();
		$formats     = array();

		if ( array_key_exists( 'scout_id', $data ) ) {
			$update_data['scout_id'] = max( 0, (int) $data['scout_id'] );
			$formats[]               = '%d';
		}

		if ( array_key_exists( 'availability_override', $data ) ) {
			$override = $data['availability_override'];
			if ( $override === null ) {
				$update_data['availability_override'] = null;
			} else {
				$update_data['availability_override'] = is_array( $override )
					? (string) wp_json_encode( $override )
					: (string) $override;
			}
			$formats[] = '%s';
		}

		if ( array_key_exists( 'member_status', $data ) ) {
			$update_data['member_status'] = sanitize_text_field( (string) $data['member_status'] );
			$formats[]                    = '%s';
		}

		if ( array_key_exists( 'notes', $data ) ) {
			$update_data['notes'] = $data['notes'] !== null ? sanitize_textarea_field( (string) $data['notes'] ) : null;
			$formats[]            = '%s';
		}

		$update_data['updated_at'] = current_time( 'mysql', true );
		$formats[]                 = '%s';

		$result = $wpdb->update(
			$table,
			$update_data,
			array( 'id' => $id ),
			$formats,
			array( '%d' )
		);

		return $result !== false;
	}

	/**
	 * Remove a cohort member by membership ID.
	 */
	public function delete( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		$wpdb   = $this->get_wpdb();
		$result = $wpdb->delete(
			$this->get_table(),
			array( 'id' => $id ),
			array( '%d' )
		);

		return $result !== false && $result > 0;
	}

	/**
	 * Remove a cohort member by cohort_id and expedition_signup_id.
	 */
	public function deleteByCohortAndSignup( int $cohort_id, int $expedition_signup_id ): bool {
		if ( $cohort_id <= 0 || $expedition_signup_id <= 0 ) {
			return false;
		}

		$wpdb   = $this->get_wpdb();
		$result = $wpdb->delete(
			$this->get_table(),
			array(
				'cohort_id'            => $cohort_id,
				'expedition_signup_id' => $expedition_signup_id,
			),
			array( '%d', '%d' )
		);

		return $result !== false && $result > 0;
	}

	/**
	 * Remove all members of a cohort.
	 */
	public function deleteByCohort( int $cohort_id ): bool {
		if ( $cohort_id <= 0 ) {
			return false;
		}

		$wpdb   = $this->get_wpdb();
		$result = $wpdb->delete(
			$this->get_table(),
			array( 'cohort_id' => $cohort_id ),
			array( '%d' )
		);

		return $result !== false;
	}

	/**
	 * Reconcile scout_id on all cohort member rows anchored to an expedition_signup_id.
	 */
	public function reconcileScoutIdBySignup( int $expedition_signup_id, int $scout_id ): int {
		if ( $expedition_signup_id <= 0 || $scout_id <= 0 ) {
			return 0;
		}

		$wpdb  = $this->get_wpdb();
		$table = $this->get_table();
		$now   = current_time( 'mysql', true );

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET scout_id = %d, updated_at = %s WHERE expedition_signup_id = %d",
				$scout_id,
				$now,
				$expedition_signup_id
			)
		);

		if ( $updated === false ) {
			throw new \RuntimeException( 'Failed to reconcile cohort member scout_id: ' . $wpdb->last_error );
		}

		return (int) $updated;
	}

	/**
	 * Base SELECT/FROM/JOIN driving from ems_expedition_signups (s) with
	 * LEFT JOIN ems_osm_explorers (e) ON (s.scout_id > 0 AND s.scout_id = e.scout_id).
	 */
	private function hydrated_select_sql(): string {
		$wpdb            = $this->get_wpdb();
		$signups_table   = $wpdb->prefix . 'ems_expedition_signups';
		$members_table   = $this->get_table();
		$explorers_table = $wpdb->prefix . 'ems_osm_explorers';

		return "SELECT
			cm.id,
			cm.cohort_id,
			cm.expedition_signup_id,
			COALESCE(NULLIF(s.scout_id, 0), cm.scout_id) AS scout_id,
			cm.availability_override,
			cm.member_status,
			cm.practice_team_post_id_override,
			cm.qualifier_team_post_id_override,
			cm.notes,
			cm.added_by,
			cm.created_at,
			cm.updated_at,
			s.parent_user_id,
			s.unit_id,
			s.unit_name,
			COALESCE(NULLIF(e.first_name, ''), s.explorer_first_name) AS first_name,
			COALESCE(NULLIF(e.last_name, ''), s.explorer_last_name) AS last_name,
			s.explorer_first_name,
			s.explorer_last_name,
			s.explorer_email,
			s.parent_email,
			s.leader_email,
			s.dofe_level,
			s.dob,
			s.dofe_registered,
			COALESCE(NULLIF(e.dofe_number, ''), s.dofe_number) AS dofe_number,
			s.expedition_preferences,
			COALESCE(NULLIF(e.additional_support_needs, ''), s.additional_support_needs) AS additional_support_needs,
			s.asn_validated,
			s.first_aid_status,
			s.first_aid_expiry,
			s.signup_status,
			s.form_submission_id,
			e.wp_user_id,
			e.section_id,
			e.patrol,
			e.first_aid_level,
			e.email AS osm_email
		FROM {$signups_table} s
		INNER JOIN {$members_table} cm ON cm.expedition_signup_id = s.id
		LEFT JOIN {$explorers_table} e ON (s.scout_id > 0 AND s.scout_id = e.scout_id)";
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function normalize_hydrated_row( array $row ): array {
		return array(
			'id'                       => (int) $row['id'],
			'cohort_id'                => (int) $row['cohort_id'],
			'expedition_signup_id'     => (int) $row['expedition_signup_id'],
			'scout_id'                 => (int) ( $row['scout_id'] ?? 0 ),
			'availability_override'    => isset( $row['availability_override'] ) ? (string) $row['availability_override'] : null,
			'member_status'            => (string) ( $row['member_status'] ?? 'active' ),
			'practice_team_post_id_override'  => isset( $row['practice_team_post_id_override'] ) && (int) $row['practice_team_post_id_override'] > 0 ? (int) $row['practice_team_post_id_override'] : null,
			'qualifier_team_post_id_override' => isset( $row['qualifier_team_post_id_override'] ) && (int) $row['qualifier_team_post_id_override'] > 0 ? (int) $row['qualifier_team_post_id_override'] : null,
			'notes'                    => isset( $row['notes'] ) ? (string) $row['notes'] : null,
			'added_by'                 => (int) ( $row['added_by'] ?? 0 ),
			'created_at'               => (string) ( $row['created_at'] ?? '' ),
			'updated_at'               => (string) ( $row['updated_at'] ?? '' ),
			'parent_user_id'           => (int) ( $row['parent_user_id'] ?? 0 ),
			'unit_id'                  => isset( $row['unit_id'] ) && $row['unit_id'] !== null ? (int) $row['unit_id'] : null,
			'unit_name'                => isset( $row['unit_name'] ) ? (string) $row['unit_name'] : null,
			'first_name'               => (string) ( $row['first_name'] ?? '' ),
			'last_name'                => (string) ( $row['last_name'] ?? '' ),
			'explorer_first_name'      => (string) ( $row['explorer_first_name'] ?? '' ),
			'explorer_last_name'       => (string) ( $row['explorer_last_name'] ?? '' ),
			'explorer_email'           => (string) ( $row['explorer_email'] ?? '' ),
			'parent_email'             => isset( $row['parent_email'] ) ? (string) $row['parent_email'] : null,
			'leader_email'             => isset( $row['leader_email'] ) ? (string) $row['leader_email'] : null,
			'dofe_level'               => (string) ( $row['dofe_level'] ?? '' ),
			'dob'                      => isset( $row['dob'] ) ? (string) $row['dob'] : null,
			'dofe_registered'          => (string) ( $row['dofe_registered'] ?? 'n' ),
			'dofe_number'              => isset( $row['dofe_number'] ) ? (string) $row['dofe_number'] : null,
			'expedition_preferences'   => isset( $row['expedition_preferences'] ) ? (string) $row['expedition_preferences'] : null,
			'additional_support_needs' => isset( $row['additional_support_needs'] ) ? (string) $row['additional_support_needs'] : null,
			'asn_validated'            => isset( $row['asn_validated'] ) && $row['asn_validated'] !== null ? (int) $row['asn_validated'] : null,
			'first_aid_status'         => (string) ( $row['first_aid_status'] ?? 'none' ),
			'first_aid_expiry'         => isset( $row['first_aid_expiry'] ) ? (string) $row['first_aid_expiry'] : null,
			'signup_status'            => (string) ( $row['signup_status'] ?? 'submitted' ),
			'form_submission_id'       => (int) ( $row['form_submission_id'] ?? 0 ),
			'wp_user_id'               => isset( $row['wp_user_id'] ) && $row['wp_user_id'] !== null ? (int) $row['wp_user_id'] : null,
			'section_id'               => isset( $row['section_id'] ) && $row['section_id'] !== null ? (int) $row['section_id'] : null,
			'patrol'                   => isset( $row['patrol'] ) ? (string) $row['patrol'] : null,
			'first_aid_level'          => isset( $row['first_aid_level'] ) ? (string) $row['first_aid_level'] : null,
			'osm_email'                => isset( $row['osm_email'] ) ? (string) $row['osm_email'] : null,
		);
	}
}
