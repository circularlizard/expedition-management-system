<?php
namespace EMS\Modules\Signups\Services;

use EMS\Core\Audit_Logger;
use EMS\Core\Event_Bus;
use EMS\Data\Signup_Repository;

/**
 * Centralises valid processing stages and cross-entity status transitions across
 * participant sign-ups, expedition sign-ups, and team allocations.
 */
class SignupStatusTransitionService {

	public const PARTICIPANT_STAGES = array(
		'new',
		'on_hold',
		'place_allocated',
		'cancelled',
	);

	public const EXPEDITION_STAGES = array(
		'new',
		'team_allocated',
		'expedition_completed',
		'cancelled',
		'withdrawn',
	);

	/**
	 * Terminal or explicit admin-override stages that automatic team changes
	 * must never overwrite.
	 */
	public const PROTECTED_EXPEDITION_STAGES = array(
		'expedition_completed',
		'cancelled',
		'withdrawn',
	);

	private Signup_Repository $signups;
	private ?object $wpdb;

	public function __construct( ?Signup_Repository $signups = null, ?object $wpdb = null ) {
		$this->signups = $signups ?: new Signup_Repository( $wpdb );
		$this->wpdb    = $wpdb;
	}

	private function get_wpdb(): object {
		if ( $this->wpdb === null ) {
			global $wpdb;
			$this->wpdb = $wpdb;
		}
		return $this->wpdb;
	}

	/**
	 * @return string[]
	 */
	public function get_valid_stages( string $type ): array {
		return $type === 'participant' ? self::PARTICIPANT_STAGES : self::EXPEDITION_STAGES;
	}

	public function is_valid_stage( string $type, string $stage ): bool {
		if ( ! in_array( $type, array( 'participant', 'expedition' ), true ) ) {
			return false;
		}
		return in_array( $stage, $this->get_valid_stages( $type ), true );
	}

	/**
	 * Transition a Participant Place or Expedition sign-up stage and trigger any
	 * cross-entity side effects (e.g. syncing participant_place_allocated).
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function transition_stage( int $signup_id, string $type, string $new_stage, ?string $notes = null ) {
		if ( ! in_array( $type, array( 'participant', 'expedition' ), true ) ) {
			return new \WP_Error( 'ems_invalid_signup_type', 'Invalid signup type. Expected participant or expedition.', array( 'status' => 400 ) );
		}

		if ( ! $this->is_valid_stage( $type, $new_stage ) ) {
			return new \WP_Error( 'ems_invalid_kanban_stage', 'Invalid processing status for ' . $type . ' sign-up.', array( 'status' => 400 ) );
		}

		$signup = $type === 'participant'
			? $this->signups->get_participant_signup( $signup_id )
			: $this->signups->get_expedition_signup( $signup_id );

		if ( ! $signup ) {
			return new \WP_Error( 'ems_signup_not_found', 'Signup not found.', array( 'status' => 404 ) );
		}

		$previous_stage = $this->normalize_stage( (string) ( $signup['kanban_stage'] ?? 'new' ) );
		$ok             = $this->signups->update_kanban_stage( $signup_id, $type, $new_stage, $notes );

		if ( ! $ok ) {
			return new \WP_Error( 'ems_kanban_update_failed', 'Failed to update processing status.', array( 'status' => 500 ) );
		}

		$synced_expedition_ids = array();
		if ( $type === 'participant' ) {
			$synced_expedition_ids = $this->signups->sync_participant_place_allocation_to_expeditions(
				$signup_id,
				$new_stage === 'place_allocated'
			);
		}

		$scout_id = (int) ( $signup['scout_id'] ?? 0 );
		$this->dispatch_status_event( $signup_id, $type, $previous_stage, $new_stage, $scout_id );

		if ( $previous_stage !== $new_stage ) {
			Audit_Logger::log( "signup_{$type}_stage_{$new_stage}", $scout_id > 0 ? $scout_id : null );
		}

		return array(
			'updated'               => true,
			'id'                    => $signup_id,
			'type'                  => $type,
			'previous_stage'        => $previous_stage,
			'kanban_stage'          => $new_stage,
			'processing_notes'      => $notes,
			'synced_expedition_ids' => $synced_expedition_ids,
		);
	}

	/**
	 * When an explorer is added to a real team (excluding UNALLOCATED pool),
	 * automatically transition their matching expedition sign-up from 'new' to 'team_allocated'.
	 *
	 * @return array<string, mixed>|null Transition result, or null if no transition was needed.
	 */
	public function on_team_member_added( int $team_post_id, int $expedition_signup_id = 0, int $scout_id = 0 ): ?array {
		if ( $team_post_id <= 0 || $this->is_unallocated_pool( $team_post_id ) ) {
			return null;
		}

		if ( $expedition_signup_id <= 0 && $scout_id > 0 ) {
			$level                = $this->resolve_team_level( $team_post_id );
			$expedition_signup_id = $this->resolve_expedition_signup_id( $scout_id, $level );
		}

		if ( $expedition_signup_id <= 0 ) {
			return null;
		}

		$signup = $this->signups->get_expedition_signup( $expedition_signup_id );
		if ( ! $signup ) {
			return null;
		}

		$current_stage = $this->normalize_stage( (string) ( $signup['kanban_stage'] ?? 'new' ) );
		if ( in_array( $current_stage, self::PROTECTED_EXPEDITION_STAGES, true ) ) {
			return null;
		}

		if ( $current_stage === 'team_allocated' ) {
			return null;
		}

		$result = $this->transition_stage( $expedition_signup_id, 'expedition', 'team_allocated' );
		return is_wp_error( $result ) ? null : $result;
	}

	/**
	 * When an explorer is removed from a team, revert their expedition sign-up
	 * from 'team_allocated' back to 'new' if they have no remaining real team memberships.
	 *
	 * @return array<string, mixed>|null Transition result, or null if still in another team or protected.
	 */
	public function on_team_member_removed( int $expedition_signup_id = 0, int $scout_id = 0, ?string $dofe_level = null ): ?array {
		if ( $expedition_signup_id <= 0 && $scout_id > 0 ) {
			$expedition_signup_id = $this->resolve_expedition_signup_id( $scout_id, $dofe_level );
		}

		if ( $expedition_signup_id <= 0 ) {
			return null;
		}

		$signup = $this->signups->get_expedition_signup( $expedition_signup_id );
		if ( ! $signup ) {
			return null;
		}

		$current_stage = $this->normalize_stage( (string) ( $signup['kanban_stage'] ?? 'new' ) );
		if ( $current_stage !== 'team_allocated' ) {
			return null;
		}

		$effective_scout_id = $scout_id > 0 ? $scout_id : (int) ( $signup['scout_id'] ?? 0 );
		if ( $this->count_active_real_team_memberships( $expedition_signup_id, $effective_scout_id ) > 0 ) {
			return null;
		}

		$result = $this->transition_stage( $expedition_signup_id, 'expedition', 'new' );
		return is_wp_error( $result ) ? null : $result;
	}

	private function normalize_stage( string $stage ): string {
		return $stage === 'to_process' || $stage === '' ? 'new' : $stage;
	}

	private function is_unallocated_pool( int $team_post_id ): bool {
		if ( ! function_exists( 'get_post_meta' ) ) {
			return false;
		}
		$code = strtoupper( trim( (string) get_post_meta( $team_post_id, 'ems_team_code', true ) ) );
		return $code === 'UNALLOCATED';
	}

	private function resolve_team_level( int $team_post_id ): ?string {
		if ( ! function_exists( 'get_post' ) || ! function_exists( 'get_post_meta' ) ) {
			return null;
		}
		$team = get_post( $team_post_id );
		if ( ! $team || empty( $team->post_parent ) ) {
			return null;
		}
		$level = strtolower( trim( (string) get_post_meta( (int) $team->post_parent, 'ems_level', true ) ) );
		return $level !== '' ? $level : null;
	}

	private function resolve_expedition_signup_id( int $scout_id, ?string $dofe_level = null ): int {
		if ( $scout_id <= 0 ) {
			return 0;
		}
		$wpdb  = $this->get_wpdb();
		$table = $wpdb->prefix . 'ems_expedition_signups';

		if ( $dofe_level !== null && $dofe_level !== '' ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE scout_id = %d AND LOWER(dofe_level) = %s AND signup_status = 'submitted' ORDER BY id DESC LIMIT 1",
					$scout_id,
					strtolower( $dofe_level )
				)
			);
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE scout_id = %d AND signup_status = 'submitted' ORDER BY id DESC LIMIT 1",
				$scout_id
			)
		);
	}

	private function count_active_real_team_memberships( int $expedition_signup_id, int $scout_id ): int {
		$wpdb     = $this->get_wpdb();
		$tm_table = $wpdb->prefix . 'ems_team_members';
		$posts    = $wpdb->posts ?? ( $wpdb->prefix . 'posts' );
		$postmeta = $wpdb->postmeta ?? ( $wpdb->prefix . 'postmeta' );

		if ( $scout_id > 0 ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*)
					 FROM {$tm_table} tm
					 INNER JOIN {$posts} t ON t.ID = tm.team_post_id
					 LEFT JOIN {$postmeta} pm ON pm.post_id = t.ID AND pm.meta_key = 'ems_team_code'
					 WHERE t.post_type = 'team'
					   AND t.post_status != 'trash'
					   AND UPPER(COALESCE(pm.meta_value, '')) != 'UNALLOCATED'
					   AND (tm.expedition_signup_id = %d OR tm.scout_id = %d)",
					$expedition_signup_id,
					$scout_id
				)
			);
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$tm_table} tm
				 INNER JOIN {$posts} t ON t.ID = tm.team_post_id
				 LEFT JOIN {$postmeta} pm ON pm.post_id = t.ID AND pm.meta_key = 'ems_team_code'
				 WHERE t.post_type = 'team'
				   AND t.post_status != 'trash'
				   AND UPPER(COALESCE(pm.meta_value, '')) != 'UNALLOCATED'
				   AND tm.expedition_signup_id = %d",
				$expedition_signup_id
			)
		);
	}

	private function dispatch_status_event( int $signup_id, string $type, string $previous_stage, string $new_stage, int $scout_id ): void {
		if ( ! class_exists( Event_Bus::class ) ) {
			return;
		}
		$event_name = in_array( $new_stage, array( 'place_allocated', 'team_allocated' ), true )
			? 'ems.signup.processed'
			: 'ems.signup.status_changed';
		Event_Bus::dispatch(
			$event_name,
			(object) array(
				'signup_id'      => $signup_id,
				'signup_type'    => $type,
				'previous_stage' => $previous_stage,
				'kanban_stage'   => $new_stage,
				'scout_id'       => $scout_id,
				'updated_by'     => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			)
		);
	}
}
