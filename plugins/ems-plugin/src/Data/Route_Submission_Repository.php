<?php
namespace EMS\Data;

class Route_Submission_Repository {

	public const ALLOWED_FILE_TYPES = array( 'pdf', 'gpx' );
	public const ALLOWED_STATUSES   = array( 'pending', 'approved', 'rejected', 'changes_requested' );

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

	private function get_table(): string {
		$wpdb = $this->get_wpdb();
		return $wpdb->prefix . 'ems_route_submissions';
	}

	/**
	 * Creates a new route submission record.
	 *
	 * @throws \InvalidArgumentException If file_type is invalid.
	 * @throws \RuntimeException If database insertion fails.
	 */
	public function create( int $team_post_id, string $file_type, int $wp_media_id, int $submitted_by, ?int $version = null ): int {
		$normalized_type = strtolower( trim( $file_type ) );
		if ( ! in_array( $normalized_type, self::ALLOWED_FILE_TYPES, true ) ) {
			throw new \InvalidArgumentException( "Invalid file type '{$file_type}'. Allowed types: " . implode( ', ', self::ALLOWED_FILE_TYPES ) );
		}

		$wpdb  = $this->get_wpdb();
		$table = $this->get_table();

		if ( $version === null ) {
			$max_version = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT MAX(version) FROM {$table} WHERE team_post_id = %d AND file_type = %s",
					$team_post_id,
					$normalized_type
				)
			);
			$version = $max_version !== null ? ( (int) $max_version + 1 ) : 1;
		}

		$submitted_at = current_time( 'mysql', true );

		$result = $wpdb->insert(
			$table,
			array(
				'team_post_id' => $team_post_id,
				'version'      => $version,
				'file_type'    => $normalized_type,
				'wp_media_id'  => $wp_media_id,
				'submitted_by' => $submitted_by,
				'submitted_at' => $submitted_at,
				'feedback'     => null,
				'status'       => 'pending',
			),
			array( '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s' )
		);

		if ( $result === false ) {
			throw new \RuntimeException( 'Failed to create route submission: ' . ( $wpdb->last_error ?? 'unknown error' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Retrieves a route submission by ID.
	 */
	public function get( int $id ): ?array {
		$wpdb  = $this->get_wpdb();
		$table = $this->get_table();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d",
				$id
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Lists all route submissions for a team, optionally filtered by file type.
	 *
	 * @return array<int, array>
	 */
	public function list_by_team( int $team_post_id, ?string $file_type = null ): array {
		$wpdb  = $this->get_wpdb();
		$table = $this->get_table();

		if ( $file_type !== null ) {
			$normalized_type = strtolower( trim( $file_type ) );
			$rows            = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE team_post_id = %d AND file_type = %s ORDER BY version DESC, id DESC",
					$team_post_id,
					$normalized_type
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE team_post_id = %d ORDER BY version DESC, id DESC",
					$team_post_id
				),
				ARRAY_A
			);
		}

		return $rows ?: array();
	}

	/**
	 * Retrieves the latest route submission for a team and optional file type.
	 */
	public function get_latest( int $team_post_id, ?string $file_type = null ): ?array {
		$wpdb  = $this->get_wpdb();
		$table = $this->get_table();

		if ( $file_type !== null ) {
			$normalized_type = strtolower( trim( $file_type ) );
			$row             = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE team_post_id = %d AND file_type = %s ORDER BY version DESC, id DESC LIMIT 1",
					$team_post_id,
					$normalized_type
				),
				ARRAY_A
			);
		} else {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE team_post_id = %d ORDER BY version DESC, id DESC LIMIT 1",
					$team_post_id
				),
				ARRAY_A
			);
		}

		return $row ?: null;
	}

	/**
	 * Updates the status and optional feedback of a route submission.
	 *
	 * @throws \InvalidArgumentException If status is invalid.
	 */
	public function update_status( int $id, string $status, ?string $feedback = null ): bool {
		$normalized_status = strtolower( trim( $status ) );
		if ( ! in_array( $normalized_status, self::ALLOWED_STATUSES, true ) ) {
			throw new \InvalidArgumentException( "Invalid status '{$status}'. Allowed statuses: " . implode( ', ', self::ALLOWED_STATUSES ) );
		}

		$wpdb  = $this->get_wpdb();
		$table = $this->get_table();

		$data = array( 'status' => $normalized_status );
		if ( $feedback !== null ) {
			$data['feedback'] = $feedback;
		}

		$format = array( '%s' );
		if ( $feedback !== null ) {
			$format[] = '%s';
		}

		$updated = $wpdb->update(
			$table,
			$data,
			array( 'id' => $id ),
			$format,
			array( '%d' )
		);

		return $updated !== false;
	}
}
