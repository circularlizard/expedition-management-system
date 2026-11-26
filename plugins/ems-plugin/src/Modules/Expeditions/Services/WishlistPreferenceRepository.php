<?php
declare(strict_types=1);

namespace EMS\Modules\Expeditions\Services;

/**
 * Repository for {$wpdb->prefix}ems_teammate_preferences.
 *
 * Stores parsed, auto-matched, ambiguous, cross-bucket, not-found, confirmed,
 * and ignored teammate preference edges keyed by source_signup_id and wishlist_hash.
 */
class WishlistPreferenceRepository {

	private const GENDERS_OPTION = 'ems_explorer_genders';

	private ?\wpdb $wpdb;

	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb = $wpdb;
	}

	private function get_wpdb(): \wpdb {
		if ( $this->wpdb === null ) {
			global $wpdb;
			$this->wpdb = $wpdb;
		}
		return $this->wpdb;
	}

	public function getTable(): string {
		return $this->get_wpdb()->prefix . 'ems_teammate_preferences';
	}

	/**
	 * Find all preference rows for a single source_signup_id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function findBySourceSignupId( int $sourceSignupId ): array {
		if ( $sourceSignupId <= 0 ) {
			return array();
		}

		$wpdb  = $this->get_wpdb();
		$table = $this->getTable();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, source_signup_id, target_signup_id, wishlist_hash, raw_token, unit_hint,
					preference_type, match_status, confidence_score, candidate_ids_json,
					flag_negation, reviewed_by, reviewed_at, created_at, updated_at
				FROM {$table}
				WHERE source_signup_id = %d
				ORDER BY id ASC",
				$sourceSignupId
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( $this, 'normalizeRow' ), $rows );
	}

	/**
	 * Find all preference rows for a list of source_signup_ids.
	 *
	 * @param array<int, int> $sourceSignupIds
	 * @return array<int, array<string, mixed>>
	 */
	public function findBySourceSignupIds( array $sourceSignupIds ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $sourceSignupIds ), static fn( int $id ): bool => $id > 0 ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$wpdb         = $this->get_wpdb();
		$table        = $this->getTable();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, source_signup_id, target_signup_id, wishlist_hash, raw_token, unit_hint,
					preference_type, match_status, confidence_score, candidate_ids_json,
					flag_negation, reviewed_by, reviewed_at, created_at, updated_at
				FROM {$table}
				WHERE source_signup_id IN ({$placeholders})
				ORDER BY source_signup_id ASC, id ASC",
				...$ids
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( $this, 'normalizeRow' ), $rows );
	}

	/**
	 * Find a single preference row by ID.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		if ( $id <= 0 ) {
			return null;
		}

		$wpdb  = $this->get_wpdb();
		$table = $this->getTable();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, source_signup_id, target_signup_id, wishlist_hash, raw_token, unit_hint,
					preference_type, match_status, confidence_score, candidate_ids_json,
					flag_negation, reviewed_by, reviewed_at, created_at, updated_at
				FROM {$table}
				WHERE id = %d
				LIMIT 1",
				$id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->normalizeRow( $row ) : null;
	}

	/**
	 * Purge all preference rows for a source_signup_id (used on wishlist_hash drift).
	 */
	public function purgeForSource( int $sourceSignupId ): int {
		if ( $sourceSignupId <= 0 ) {
			return 0;
		}

		$wpdb    = $this->get_wpdb();
		$table   = $this->getTable();
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE source_signup_id = %d",
				$sourceSignupId
			)
		);

		return is_int( $deleted ) ? $deleted : 0;
	}

	/**
	 * Purge only unreviewed (non-confirmed, non-ignored) rows for a source_signup_id.
	 */
	public function purgeUnreviewedForSource( int $sourceSignupId ): int {
		if ( $sourceSignupId <= 0 ) {
			return 0;
		}

		$wpdb    = $this->get_wpdb();
		$table   = $this->getTable();
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE source_signup_id = %d AND match_status NOT IN ('confirmed', 'ignored')",
				$sourceSignupId
			)
		);

		return is_int( $deleted ) ? $deleted : 0;
	}

	/**
	 * Insert a new preference row.
	 *
	 * @param array<string, mixed> $data
	 */
	public function insertPreference( array $data ): int {
		$wpdb  = $this->get_wpdb();
		$table = $this->getTable();
		$now   = current_time( 'mysql', true );

		$insert = array(
			'source_signup_id'   => (int) ( $data['source_signup_id'] ?? 0 ),
			'target_signup_id'   => isset( $data['target_signup_id'] ) && (int) $data['target_signup_id'] > 0
				? (int) $data['target_signup_id']
				: null,
			'wishlist_hash'      => substr( (string) ( $data['wishlist_hash'] ?? '' ), 0, 32 ),
			'raw_token'          => substr( sanitize_text_field( (string) ( $data['raw_token'] ?? '' ) ), 0, 255 ),
			'unit_hint'          => isset( $data['unit_hint'] ) && $data['unit_hint'] !== ''
				? substr( sanitize_text_field( (string) $data['unit_hint'] ), 0, 120 )
				: null,
			'preference_type'    => in_array( (string) ( $data['preference_type'] ?? 'prefer' ), array( 'prefer', 'avoid' ), true )
				? (string) $data['preference_type']
				: 'prefer',
			'match_status'       => (string) ( $data['match_status'] ?? 'not_found' ),
			'confidence_score'   => max( 0, min( 100, (int) ( $data['confidence_score'] ?? 0 ) ) ),
			'candidate_ids_json' => isset( $data['candidate_ids_json'] ) && $data['candidate_ids_json'] !== ''
				? (string) $data['candidate_ids_json']
				: null,
			'flag_negation'      => ! empty( $data['flag_negation'] ) ? 1 : 0,
			'reviewed_by'        => isset( $data['reviewed_by'] ) && (int) $data['reviewed_by'] > 0
				? (int) $data['reviewed_by']
				: null,
			'reviewed_at'        => ! empty( $data['reviewed_at'] ) ? (string) $data['reviewed_at'] : null,
			'created_at'         => (string) ( $data['created_at'] ?? $now ),
			'updated_at'         => (string) ( $data['updated_at'] ?? $now ),
		);

		$result = $wpdb->insert(
			$table,
			$insert,
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s' )
		);

		if ( $result === false ) {
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update an existing preference row with an admin decision.
	 *
	 * @param array<string, mixed> $data
	 */
	public function updateDecision( int $id, array $data ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		$wpdb  = $this->get_wpdb();
		$table = $this->getTable();
		$now   = current_time( 'mysql', true );

		$update = array(
			'updated_at'  => $now,
			'reviewed_by' => isset( $data['reviewed_by'] ) && (int) $data['reviewed_by'] > 0
				? (int) $data['reviewed_by']
				: (int) get_current_user_id(),
			'reviewed_at' => (string) ( $data['reviewed_at'] ?? $now ),
		);
		$format = array( '%s', '%d', '%s' );

		if ( array_key_exists( 'target_signup_id', $data ) ) {
			$target                     = (int) $data['target_signup_id'];
			$update['target_signup_id'] = $target > 0 ? $target : null;
			$format[]                   = '%d';
		}

		if ( isset( $data['preference_type'] ) && in_array( (string) $data['preference_type'], array( 'prefer', 'avoid' ), true ) ) {
			$update['preference_type'] = (string) $data['preference_type'];
			$format[]                  = '%s';
		}

		if ( isset( $data['match_status'] ) ) {
			$update['match_status'] = sanitize_text_field( (string) $data['match_status'] );
			$format[]               = '%s';
		}

		if ( isset( $data['raw_token'] ) && trim( (string) $data['raw_token'] ) !== '' ) {
			$update['raw_token'] = substr( sanitize_text_field( (string) $data['raw_token'] ), 0, 255 );
			$format[]            = '%s';
		}

		if ( isset( $data['confidence_score'] ) ) {
			$update['confidence_score'] = max( 0, min( 100, (int) $data['confidence_score'] ) );
			$format[]                   = '%d';
		}

		$result = $wpdb->update(
			$table,
			$update,
			array( 'id' => $id ),
			$format,
			array( '%d' )
		);

		return $result !== false;
	}

	/**
	 * Fetch all active preference edges (confirmed, auto_matched, or unit_preference) for the solver.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getActiveEdges(): array {
		$wpdb  = $this->get_wpdb();
		$table = $this->getTable();

		$rows = $wpdb->get_results(
			"SELECT id, source_signup_id, target_signup_id, unit_hint, preference_type, match_status, confidence_score
			FROM {$table}
			WHERE (
				match_status IN ('confirmed', 'auto_matched')
				AND target_signup_id IS NOT NULL
				AND target_signup_id > 0
			) OR (
				match_status = 'unit_preference'
				AND unit_hint IS NOT NULL
				AND unit_hint != ''
			)
			ORDER BY id ASC",
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			static function ( array $row ): array {
				return array(
					'id'               => (int) ( $row['id'] ?? 0 ),
					'source_signup_id' => (int) ( $row['source_signup_id'] ?? 0 ),
					'target_signup_id' => isset( $row['target_signup_id'] ) && $row['target_signup_id'] !== null
						? (int) $row['target_signup_id']
						: 0,
					'unit_hint'        => isset( $row['unit_hint'] ) && $row['unit_hint'] !== ''
						? (string) $row['unit_hint']
						: null,
					'preference_type'  => (string) ( $row['preference_type'] ?? 'prefer' ),
					'match_status'     => (string) ( $row['match_status'] ?? 'auto_matched' ),
					'confidence_score' => (int) ( $row['confidence_score'] ?? 0 ),
				);
			},
			$rows
		);
	}

	/**
	 * Load explorer genders keyed by expedition_signup_id from wp_options.
	 *
	 * @return array<int, string>
	 */
	public function getGenderMap(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return array();
		}

		$raw = get_option( self::GENDERS_OPTION, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$map = array();
		foreach ( $raw as $signupId => $gender ) {
			$id = (int) $signupId;
			if ( $id <= 0 ) {
				continue;
			}
			$norm = $this->normalizeGender( (string) $gender );
			if ( $norm !== '' ) {
				$map[ $id ] = $norm;
			}
		}

		return $map;
	}

	/**
	 * Batch-update explorer genders in wp_options (additive, no DB schema changes).
	 *
	 * @param array<int|string, mixed> $genders Map of expedition_signup_id => 'male'|'female'|'other'|''
	 */
	public function setGenders( array $genders ): void {
		if ( empty( $genders ) || ! function_exists( 'update_option' ) ) {
			return;
		}

		$map = $this->getGenderMap();
		foreach ( $genders as $signupId => $gender ) {
			$id = (int) $signupId;
			if ( $id <= 0 ) {
				continue;
			}
			$norm = $this->normalizeGender( (string) $gender );
			if ( $norm === '' ) {
				unset( $map[ $id ] );
			} else {
				$map[ $id ] = $norm;
			}
		}

		update_option( self::GENDERS_OPTION, $map, false );
	}

	private function normalizeGender( string $gender ): string {
		$g = strtolower( trim( $gender ) );
		return in_array( $g, array( 'male', 'female', 'other' ), true ) ? $g : '';
	}

	/**
	 * Build a map of source_signup_id => resolved preference edges for Team Builder pool cards.
	 *
	 * @param array<int, int> $sourceSignupIds
	 * @return array<int, array<int, array<string, mixed>>>
	 */
	public function getEdgesMapForSignups( array $sourceSignupIds ): array {
		$rows = $this->findBySourceSignupIds( $sourceSignupIds );
		$map  = array();

		foreach ( $rows as $row ) {
			$status = (string) ( $row['match_status'] ?? '' );
			$target = (int) ( $row['target_signup_id'] ?? 0 );
			if ( ! in_array( $status, array( 'confirmed', 'auto_matched' ), true ) || $target <= 0 ) {
				continue;
			}
			$src = (int) $row['source_signup_id'];
			if ( ! isset( $map[ $src ] ) ) {
				$map[ $src ] = array();
			}
			$map[ $src ][] = array(
				'target_signup_id' => $target,
				'preference_type'  => (string) $row['preference_type'],
				'match_status'     => $status,
				'confidence_score' => (int) $row['confidence_score'],
			);
		}

		return $map;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function normalizeRow( array $row ): array {
		$candidates = array();
		if ( ! empty( $row['candidate_ids_json'] ) ) {
			$decoded = json_decode( (string) $row['candidate_ids_json'], true );
			if ( is_array( $decoded ) ) {
				$candidates = $decoded;
			}
		}

		return array(
			'id'                 => (int) ( $row['id'] ?? 0 ),
			'source_signup_id'   => (int) ( $row['source_signup_id'] ?? 0 ),
			'target_signup_id'   => isset( $row['target_signup_id'] ) && $row['target_signup_id'] !== null
				? (int) $row['target_signup_id']
				: null,
			'wishlist_hash'      => (string) ( $row['wishlist_hash'] ?? '' ),
			'raw_token'          => (string) ( $row['raw_token'] ?? '' ),
			'unit_hint'          => $row['unit_hint'] !== null && $row['unit_hint'] !== '' ? (string) $row['unit_hint'] : null,
			'preference_type'    => (string) ( $row['preference_type'] ?? 'prefer' ),
			'match_status'       => (string) ( $row['match_status'] ?? 'not_found' ),
			'confidence_score'   => (int) ( $row['confidence_score'] ?? 0 ),
			'candidate_ids_json' => $row['candidate_ids_json'] ?? null,
			'candidates'         => $candidates,
			'flag_negation'      => (int) ( $row['flag_negation'] ?? 0 ),
			'reviewed_by'        => isset( $row['reviewed_by'] ) && $row['reviewed_by'] !== null ? (int) $row['reviewed_by'] : null,
			'reviewed_at'        => $row['reviewed_at'] ?? null,
			'created_at'         => (string) ( $row['created_at'] ?? '' ),
			'updated_at'         => (string) ( $row['updated_at'] ?? '' ),
		);
	}
}
