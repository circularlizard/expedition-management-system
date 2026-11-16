<?php
namespace EMS\Modules\Expeditions\Repositories;

/**
 * Repository for persistent expedition cohorts ({$wpdb->prefix}ems_cohorts).
 */
class CohortRepository {
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
		return $this->get_wpdb()->prefix . 'ems_cohorts';
	}

	/**
	 * Find a single cohort by ID.
	 */
	public function find( int $id ): ?array {
		if ( $id <= 0 ) {
			return null;
		}

		$wpdb  = $this->get_wpdb();
		$table = $this->get_table();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, name, dofe_level, transport_mode, practice_expedition_id, qualifier_expedition_id, practice_team_post_id, qualifier_team_post_id, cohort_status, notes, created_by, created_at, updated_at
				FROM {$table}
				WHERE id = %d",
				$id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->normalize_row( $row ) : null;
	}

	/**
	 * Find a cohort linked to a given Practice or Qualifier team post ID.
	 */
	public function findByTeamPostId( int $team_post_id ): ?array {
		if ( $team_post_id <= 0 ) {
			return null;
		}

		$wpdb  = $this->get_wpdb();
		$table = $this->get_table();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, name, dofe_level, transport_mode, practice_expedition_id, qualifier_expedition_id, practice_team_post_id, qualifier_team_post_id, cohort_status, notes, created_by, created_at, updated_at
				FROM {$table}
				WHERE practice_team_post_id = %d OR qualifier_team_post_id = %d
				LIMIT 1",
				$team_post_id,
				$team_post_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->normalize_row( $row ) : null;
	}

	/**
	 * List all cohorts matching optional filters.
	 *
	 * @param array<string, mixed> $filters
	 * @return array<int, array<string, mixed>>
	 */
	public function findAll( array $filters = array() ): array {
		return $this->listByFilters( $filters );
	}

	/**
	 * List cohorts matching optional filters.
	 *
	 * Supported filters: dofe_level, transport_mode, cohort_status,
	 * practice_expedition_id, qualifier_expedition_id, expedition_id, search.
	 *
	 * @param array<string, mixed> $filters
	 * @return array<int, array<string, mixed>>
	 */
	public function listByFilters( array $filters = array() ): array {
		$wpdb  = $this->get_wpdb();
		$table = $this->get_table();

		$where = array();
		$args  = array();

		if ( ! empty( $filters['dofe_level'] ) ) {
			$where[] = 'dofe_level = %s';
			$args[]  = strtolower( sanitize_text_field( (string) $filters['dofe_level'] ) );
		}

		if ( ! empty( $filters['transport_mode'] ) ) {
			$where[] = 'transport_mode = %s';
			$args[]  = strtolower( sanitize_text_field( (string) $filters['transport_mode'] ) );
		}

		if ( ! empty( $filters['cohort_status'] ) ) {
			$where[] = 'cohort_status = %s';
			$args[]  = sanitize_text_field( (string) $filters['cohort_status'] );
		}

		if ( ! empty( $filters['practice_expedition_id'] ) ) {
			$where[] = 'practice_expedition_id = %d';
			$args[]  = (int) $filters['practice_expedition_id'];
		}

		if ( ! empty( $filters['qualifier_expedition_id'] ) ) {
			$where[] = 'qualifier_expedition_id = %d';
			$args[]  = (int) $filters['qualifier_expedition_id'];
		}

		if ( ! empty( $filters['expedition_id'] ) ) {
			$exp_id  = (int) $filters['expedition_id'];
			$where[] = '(practice_expedition_id = %d OR qualifier_expedition_id = %d)';
			$args[]  = $exp_id;
			$args[]  = $exp_id;
		}

		if ( ! empty( $filters['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( sanitize_text_field( (string) $filters['search'] ) ) . '%';
			$where[] = 'name LIKE %s';
			$args[]  = $like;
		}

		$sql = "SELECT id, name, dofe_level, transport_mode, practice_expedition_id, qualifier_expedition_id, practice_team_post_id, qualifier_team_post_id, cohort_status, notes, created_by, created_at, updated_at
			FROM {$table}";

		if ( ! empty( $where ) ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}

		$sql .= ' ORDER BY id DESC';

		if ( ! empty( $args ) ) {
			$sql = $wpdb->prepare( $sql, ...$args );
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( $this, 'normalize_row' ), $rows );
	}

	/**
	 * Create a new cohort and return its ID.
	 *
	 * @param array<string, mixed> $data
	 */
	public function create( array $data ): int {
		$name       = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
		$dofe_level = strtolower( sanitize_text_field( (string) ( $data['dofe_level'] ?? '' ) ) );

		if ( $name === '' ) {
			throw new \InvalidArgumentException( 'Cohort name is required.' );
		}
		if ( $dofe_level === '' ) {
			throw new \InvalidArgumentException( 'Cohort dofe_level is required.' );
		}

		$wpdb  = $this->get_wpdb();
		$table = $this->get_table();
		$now   = current_time( 'mysql', true );

		$insert_data = array(
			'name'                    => $name,
			'dofe_level'              => $dofe_level,
			'transport_mode'          => strtolower( sanitize_text_field( (string) ( $data['transport_mode'] ?? 'foot' ) ) ) ?: 'foot',
			'practice_expedition_id'  => $this->nullable_positive_int( $data['practice_expedition_id'] ?? null ),
			'qualifier_expedition_id' => $this->nullable_positive_int( $data['qualifier_expedition_id'] ?? null ),
			'practice_team_post_id'   => $this->nullable_positive_int( $data['practice_team_post_id'] ?? null ),
			'qualifier_team_post_id'  => $this->nullable_positive_int( $data['qualifier_team_post_id'] ?? null ),
			'cohort_status'           => sanitize_text_field( (string) ( $data['cohort_status'] ?? 'forming' ) ) ?: 'forming',
			'notes'                   => isset( $data['notes'] ) && $data['notes'] !== null ? sanitize_textarea_field( (string) $data['notes'] ) : null,
			'created_by'              => isset( $data['created_by'] ) ? (int) $data['created_by'] : (int) get_current_user_id(),
			'created_at'              => $now,
			'updated_at'              => $now,
		);

		$formats = array(
			'%s',
			'%s',
			'%s',
			'%d',
			'%d',
			'%d',
			'%d',
			'%s',
			'%s',
			'%d',
			'%s',
			'%s',
		);

		$result = $wpdb->insert( $table, $insert_data, $formats );
		if ( $result === false ) {
			throw new \RuntimeException( 'Failed to create cohort: ' . $wpdb->last_error );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update an existing cohort.
	 *
	 * @param array<string, mixed> $data
	 */
	public function update( int $id, array $data ): bool {
		if ( $id <= 0 || $this->find( $id ) === null ) {
			return false;
		}

		$update_data = array();
		$formats     = array();

		if ( array_key_exists( 'name', $data ) ) {
			$name = sanitize_text_field( (string) $data['name'] );
			if ( $name === '' ) {
				throw new \InvalidArgumentException( 'Cohort name cannot be empty.' );
			}
			$update_data['name'] = $name;
			$formats[]           = '%s';
		}

		if ( array_key_exists( 'dofe_level', $data ) ) {
			$level = strtolower( sanitize_text_field( (string) $data['dofe_level'] ) );
			if ( $level === '' ) {
				throw new \InvalidArgumentException( 'Cohort dofe_level cannot be empty.' );
			}
			$update_data['dofe_level'] = $level;
			$formats[]                 = '%s';
		}

		if ( array_key_exists( 'transport_mode', $data ) ) {
			$update_data['transport_mode'] = strtolower( sanitize_text_field( (string) $data['transport_mode'] ) ) ?: 'foot';
			$formats[]                     = '%s';
		}

		$nullable_int_fields = array(
			'practice_expedition_id',
			'qualifier_expedition_id',
			'practice_team_post_id',
			'qualifier_team_post_id',
		);
		foreach ( $nullable_int_fields as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$update_data[ $field ] = $this->nullable_positive_int( $data[ $field ] );
				$formats[]             = '%d';
			}
		}

		if ( array_key_exists( 'cohort_status', $data ) ) {
			$update_data['cohort_status'] = sanitize_text_field( (string) $data['cohort_status'] );
			$formats[]                    = '%s';
		}

		if ( array_key_exists( 'notes', $data ) ) {
			$update_data['notes'] = $data['notes'] !== null ? sanitize_textarea_field( (string) $data['notes'] ) : null;
			$formats[]            = '%s';
		}

		$update_data['updated_at'] = current_time( 'mysql', true );
		$formats[]                 = '%s';

		$wpdb   = $this->get_wpdb();
		$result = $wpdb->update(
			$this->get_table(),
			$update_data,
			array( 'id' => $id ),
			$formats,
			array( '%d' )
		);

		return $result !== false;
	}

	/**
	 * Delete a cohort by ID.
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

	private function nullable_positive_int( mixed $value ): ?int {
		if ( $value === null || $value === '' ) {
			return null;
		}
		$int = (int) $value;
		return $int > 0 ? $int : null;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function normalize_row( array $row ): array {
		return array(
			'id'                      => (int) $row['id'],
			'name'                    => (string) $row['name'],
			'dofe_level'              => (string) $row['dofe_level'],
			'transport_mode'          => (string) $row['transport_mode'],
			'practice_expedition_id'  => $this->nullable_positive_int( $row['practice_expedition_id'] ?? null ),
			'qualifier_expedition_id' => $this->nullable_positive_int( $row['qualifier_expedition_id'] ?? null ),
			'practice_team_post_id'   => $this->nullable_positive_int( $row['practice_team_post_id'] ?? null ),
			'qualifier_team_post_id'  => $this->nullable_positive_int( $row['qualifier_team_post_id'] ?? null ),
			'cohort_status'           => (string) $row['cohort_status'],
			'notes'                   => isset( $row['notes'] ) ? (string) $row['notes'] : null,
			'created_by'              => (int) ( $row['created_by'] ?? 0 ),
			'created_at'              => (string) ( $row['created_at'] ?? '' ),
			'updated_at'              => (string) ( $row['updated_at'] ?? '' ),
		);
	}
}
