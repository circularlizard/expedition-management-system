<?php
declare(strict_types=1);

namespace EMS\Modules\Expeditions\Services;

use EMS\Data\Expedition_Repository;
use EMS\Data\Team_Repository;
use EMS\Modules\Expeditions\Repositories\CohortMemberRepository;
use EMS\Modules\Expeditions\Repositories\CohortRepository;

/**
 * Cohort intersection scoring and materialisation service.
 *
 * Guarantees:
 * - Zero Auto-Email: NEVER calls wp_mail() or dispatches notification emails.
 * - Driving Table Rule: Explorer hydration drives from ems_expedition_signups (s)
 *   with LEFT JOIN ems_osm_explorers (e) ON (s.scout_id > 0 AND s.scout_id = e.scout_id).
 * - Re-Notify Protection: Existing 'notified' team members whose team or expedition
 *   date changes are transitioned to 're_notify_needed'.
 */
class CohortSchedulerService {
	public const ALLOWED_RESOLUTIONS = array(
		'assign_pending',
		'split_date',
		'remove_from_cohort',
	);

	private ?object $wpdb;
	private CohortRepository $cohorts;
	private CohortMemberRepository $cohort_members;
	private Expedition_Repository $expeditions;
	private Team_Repository $teams;

	public function __construct(
		?object $wpdb = null,
		?CohortRepository $cohorts = null,
		?CohortMemberRepository $cohort_members = null,
		?Expedition_Repository $expeditions = null,
		?Team_Repository $teams = null
	) {
		$this->wpdb           = $wpdb;
		$this->cohorts        = $cohorts ?: new CohortRepository( $wpdb );
		$this->cohort_members = $cohort_members ?: new CohortMemberRepository( $wpdb );
		$this->expeditions    = $expeditions ?: new Expedition_Repository();
		$this->teams          = $teams ?: new Team_Repository();
	}

	private function get_wpdb(): object {
		if ( $this->wpdb === null ) {
			global $wpdb;
			$this->wpdb = $wpdb;
		}
		return $this->wpdb;
	}

	/**
	 * Score all candidate expeditions for a cohort's active members.
	 *
	 * @param int         $cohort_id Cohort ID.
	 * @param string|null $stage     Optional stage filter: 'practice' or 'qualifier' ('qualifying').
	 * @return array<int, array<string, mixed>> Scored candidate expeditions sorted best-first.
	 */
	public function scoreExpeditions( int $cohort_id, ?string $stage = null ): array {
		$cohort = $this->cohorts->find( $cohort_id );
		if ( $cohort === null ) {
			throw new \InvalidArgumentException( "Cohort {$cohort_id} not found." );
		}

		$members     = $this->getActiveCohortMembers( $cohort_id );
		$expeditions = $this->expeditions->list_all_chronological();

		return $this->scoreCandidates(
			$members,
			$expeditions,
			(string) $cohort['dofe_level'],
			(string) $cohort['transport_mode'],
			$stage
		);
	}

	/**
	 * Score candidate expeditions against a pre-loaded list of hydrated cohort members.
	 *
	 * @param array<int, array<string, mixed>> $members
	 * @param array<int, array<string, mixed>> $expeditions
	 * @param string                           $dofe_level
	 * @param string                           $transport_mode
	 * @param string|null                      $stage
	 * @return array<int, array<string, mixed>>
	 */
	public function scoreCandidates(
		array $members,
		array $expeditions,
		string $dofe_level,
		string $transport_mode = 'foot',
		?string $stage = null
	): array {
		$target_level     = strtolower( trim( $dofe_level ) );
		$target_transport = $this->normalizeTransportMode( $transport_mode );
		$target_stage     = $stage !== null && $stage !== '' ? $this->normalizeStage( $stage ) : null;
		$total_count      = count( $members );
		$asn_count        = 0;

		foreach ( $members as $member ) {
			if ( ! empty( trim( (string) ( $member['additional_support_needs'] ?? '' ) ) ) ) {
				++$asn_count;
			}
		}

		$scored = array();

		foreach ( $expeditions as $expedition ) {
			$status = strtolower( trim( (string) ( $expedition['ems_status'] ?? 'active' ) ) );
			if ( $status === 'archived' ) {
				continue;
			}

			$exp_level = strtolower( trim( (string) ( $expedition['ems_level'] ?? '' ) ) );
			if ( $exp_level !== $target_level ) {
				continue;
			}

			$exp_transport = $this->normalizeTransportMode( (string) ( $expedition['ems_transport'] ?? 'foot' ) );
			if ( $target_transport !== '' && $exp_transport !== $target_transport ) {
				continue;
			}

			$exp_stage = $this->normalizeStage( (string) ( $expedition['ems_type'] ?? '' ) );
			if ( $exp_stage === '' ) {
				continue;
			}
			if ( $target_stage !== null && $exp_stage !== $target_stage ) {
				continue;
			}

			$expedition_id   = (int) ( $expedition['ID'] ?? 0 );
			$expedition_code = (string) ( $expedition['ems_event_code'] ?? $expedition['ems_expedition_code'] ?? '' );
			$expedition_date = (string) ( $expedition['ems_start_date'] ?? '' );

			$unavailable_members = array();
			$first_aid_count     = 0;

			foreach ( $members as $member ) {
				if ( ! $this->isMemberAvailableForExpedition( $member, $expedition, $exp_stage ) ) {
					$unavailable_members[] = array(
						'expedition_signup_id' => (int) ( $member['expedition_signup_id'] ?? 0 ),
						'scout_id'             => (int) ( $member['scout_id'] ?? 0 ),
						'name'                 => $this->formatMemberName( $member ),
					);
				}

				if ( $this->hasValidFirstAidOnDate( $member, $expedition_date ) ) {
					++$first_aid_count;
				}
			}

			$available_count = $total_count - count( $unavailable_members );
			$size_valid      = $total_count >= 4 && $total_count <= 7;

			$scored[] = array(
				'expedition_id'       => $expedition_id,
				'expedition_code'     => $expedition_code,
				'title'               => (string) ( $expedition['post_title'] ?? '' ),
				'expedition_date'     => $expedition_date,
				'stage'               => $exp_stage,
				'available_count'     => $available_count,
				'total_count'         => $total_count,
				'unavailable_members' => $unavailable_members,
				'first_aid_count'     => $first_aid_count,
				'asn_count'           => $asn_count,
				'size_valid'          => $size_valid,
			);
		}

		usort(
			$scored,
			static function ( array $a, array $b ): int {
				if ( $a['available_count'] !== $b['available_count'] ) {
					return $b['available_count'] <=> $a['available_count'];
				}
				if ( $a['size_valid'] !== $b['size_valid'] ) {
					return $a['size_valid'] ? -1 : 1;
				}
				$a_fa_ok = $a['first_aid_count'] >= 2;
				$b_fa_ok = $b['first_aid_count'] >= 2;
				if ( $a_fa_ok !== $b_fa_ok ) {
					return $a_fa_ok ? -1 : 1;
				}
				if ( $a['first_aid_count'] !== $b['first_aid_count'] ) {
					return $b['first_aid_count'] <=> $a['first_aid_count'];
				}
				return strcmp( (string) $a['expedition_date'], (string) $b['expedition_date'] );
			}
		);

		return $scored;
	}

	/**
	 * Materialise a cohort onto Practice and/or Qualifier expedition stages.
	 *
	 * @param int                         $cohort_id
	 * @param array<string, mixed>        $expedition_ids     Keys: 'practice_expedition_id', 'qualifier_expedition_id'
	 * @param array<int|string, string>   $member_resolutions Map of expedition_signup_id => 'assign_pending'|'split_date'|'remove_from_cohort'
	 * @return array<string, mixed>
	 */
	public function scheduleCohort( int $cohort_id, array $expedition_ids, array $member_resolutions = array() ): array {
		$cohort = $this->cohorts->find( $cohort_id );
		if ( $cohort === null ) {
			throw new \InvalidArgumentException( "Cohort {$cohort_id} not found." );
		}

		$normalized_resolutions = $this->normalizeResolutions( $member_resolutions );

		// 1. Process removals first so removed members are excluded from stage teams.
		$removed_signups = array();
		foreach ( $normalized_resolutions as $signup_id => $action ) {
			if ( $action === 'remove_from_cohort' ) {
				$this->cohort_members->deleteByCohortAndSignup( $cohort_id, $signup_id );
				$this->removeUnnotifiedTeamMembers( $cohort_id, $signup_id );
				$removed_signups[] = $signup_id;
			}
		}

		// 2. Reload active cohort members (Driving Table Rule via CohortMemberRepository).
		$members = $this->getActiveCohortMembers( $cohort_id );

		$practice_exp_id = array_key_exists( 'practice_expedition_id', $expedition_ids )
			? $this->nullablePositiveInt( $expedition_ids['practice_expedition_id'] )
			: $cohort['practice_expedition_id'];

		$qualifier_exp_id = array_key_exists( 'qualifier_expedition_id', $expedition_ids )
			? $this->nullablePositiveInt( $expedition_ids['qualifier_expedition_id'] )
			: $cohort['qualifier_expedition_id'];

		$practice_team_id  = $cohort['practice_team_post_id'];
		$qualifier_team_id = $cohort['qualifier_team_post_id'];

		$stage_results = array();

		if ( $practice_exp_id !== null ) {
			$stage_results['practice'] = $this->materialiseStage(
				$cohort_id,
				'practice',
				$practice_exp_id,
				$cohort['practice_expedition_id'],
				$practice_team_id,
				$members,
				$normalized_resolutions
			);
			$practice_team_id          = (int) $stage_results['practice']['team_post_id'];
		}

		if ( $qualifier_exp_id !== null ) {
			$stage_results['qualifier'] = $this->materialiseStage(
				$cohort_id,
				'qualifier',
				$qualifier_exp_id,
				$cohort['qualifier_expedition_id'],
				$qualifier_team_id,
				$members,
				$normalized_resolutions
			);
			$qualifier_team_id          = (int) $stage_results['qualifier']['team_post_id'];
		}

		$cohort_status = 'forming';
		if ( $practice_exp_id !== null && $qualifier_exp_id !== null ) {
			$cohort_status = 'scheduled';
		} elseif ( $practice_exp_id !== null || $qualifier_exp_id !== null ) {
			$cohort_status = 'partially_scheduled';
		}

		$this->cohorts->update(
			$cohort_id,
			array(
				'practice_expedition_id'  => $practice_exp_id,
				'qualifier_expedition_id' => $qualifier_exp_id,
				'practice_team_post_id'   => $practice_team_id,
				'qualifier_team_post_id'  => $qualifier_team_id,
				'cohort_status'           => $cohort_status,
			)
		);

		return array(
			'cohort_id'               => $cohort_id,
			'cohort_status'           => $cohort_status,
			'practice_expedition_id'  => $practice_exp_id,
			'qualifier_expedition_id' => $qualifier_exp_id,
			'practice_team_post_id'   => $practice_team_id,
			'qualifier_team_post_id'  => $qualifier_team_id,
			'removed_signups'         => $removed_signups,
			'stages'                  => $stage_results,
		);
	}

	/**
	 * Validate per-stage team size (4 <= count <= 7) and Rule of Two First Aid (>= 2).
	 *
	 * @return array{member_count: int, size_valid: bool, first_aid_count: int, first_aid_valid: bool, compliant: bool}
	 */
	public function validateStageCompliance( int $team_post_id, string $expedition_date = '' ): array {
		if ( $team_post_id <= 0 ) {
			return array(
				'member_count'    => 0,
				'size_valid'      => false,
				'first_aid_count' => 0,
				'first_aid_valid' => false,
				'compliant'       => false,
			);
		}

		if ( $expedition_date === '' ) {
			$team = $this->teams->get_by_id( $team_post_id );
			if ( $team !== null && ! empty( $team['event_id'] ) ) {
				$exp = $this->expeditions->get_by_id( (int) $team['event_id'] );
				if ( $exp !== null ) {
					$expedition_date = (string) ( $exp['ems_start_date'] ?? '' );
				}
			}
		}

		$members = $this->getHydratedTeamMembers( $team_post_id );
		return $this->evaluateMembersCompliance( $members, $expedition_date );
	}

	/**
	 * Evaluate team size (4-7) and Rule of Two First Aid (>= 2) for a list of hydrated members.
	 *
	 * @param array<int, array<string, mixed>> $members
	 * @return array{member_count: int, size_valid: bool, first_aid_count: int, first_aid_valid: bool, compliant: bool}
	 */
	public function evaluateMembersCompliance( array $members, string $expedition_date = '' ): array {
		$member_count    = count( $members );
		$first_aid_count = 0;

		foreach ( $members as $member ) {
			if ( $this->hasValidFirstAidOnDate( $member, $expedition_date ) ) {
				++$first_aid_count;
			}
		}

		$size_valid      = $member_count >= 4 && $member_count <= 7;
		$first_aid_valid = $first_aid_count >= 2;

		return array(
			'member_count'    => $member_count,
			'size_valid'      => $size_valid,
			'first_aid_count' => $first_aid_count,
			'first_aid_valid' => $first_aid_valid,
			'compliant'       => $size_valid && $first_aid_valid,
		);
	}

	/**
	 * Materialise a single stage (practice or qualifier) for a cohort.
	 *
	 * @param array<int, array<string, mixed>> $members
	 * @param array<int, string>               $resolutions
	 * @return array<string, mixed>
	 */
	private function materialiseStage(
		int $cohort_id,
		string $stage,
		int $target_expedition_id,
		?int $previous_expedition_id,
		?int $existing_team_post_id,
		array $members,
		array $resolutions
	): array {
		$expedition = $this->expeditions->get_by_id( $target_expedition_id );
		if ( $expedition === null ) {
			throw new \InvalidArgumentException( "Target {$stage} expedition {$target_expedition_id} not found." );
		}

		$event_code      = (string) ( $expedition['ems_event_code'] ?? $expedition['ems_expedition_code'] ?? 'TEAM' );
		$expedition_date = (string) ( $expedition['ems_start_date'] ?? '' );

		$previous_date = '';
		if ( $previous_expedition_id !== null && $previous_expedition_id > 0 ) {
			$prev_exp = $this->expeditions->get_by_id( $previous_expedition_id );
			if ( $prev_exp !== null ) {
				$previous_date = (string) ( $prev_exp['ems_start_date'] ?? '' );
			}
		}

		$team_post_id = 0;
		$team_changed = false;

		if ( $existing_team_post_id !== null && $existing_team_post_id > 0 ) {
			$existing_team = $this->teams->get_by_id( $existing_team_post_id );
			if ( $existing_team !== null ) {
				$team_post_id       = (int) $existing_team['ID'];
				$current_parent_exp = (int) ( $existing_team['event_id'] ?? 0 );
				if ( $current_parent_exp !== $target_expedition_id ) {
					$this->teams->move( $team_post_id, $target_expedition_id, $event_code );
					$team_changed = true;
				}
			}
		}

		if ( $team_post_id === 0 ) {
			$team_post_id = $this->teams->create( $target_expedition_id, $event_code );
		}

		update_post_meta( $team_post_id, '_ems_cohort_id', $cohort_id );

		$date_changed = ( $previous_expedition_id !== null && $previous_expedition_id !== $target_expedition_id )
			|| ( $previous_date !== '' && $previous_date !== $expedition_date );

		$re_notified_count = 0;
		if ( $team_changed || $date_changed ) {
			$re_notified_count += $this->markTeamMembersForReNotify( $team_post_id );
		}

		$assigned_count = 0;
		$split_count    = 0;

		foreach ( $members as $member ) {
			$signup_id  = (int) ( $member['expedition_signup_id'] ?? 0 );
			$scout_id   = (int) ( $member['scout_id'] ?? 0 );
			$wp_user_id = (int) ( $member['wp_user_id'] ?? 0 );
			$resolution = $resolutions[ $signup_id ] ?? null;

			if ( $resolution === 'split_date' ) {
				$split_status = $stage === 'practice' ? 'split_practice' : 'split_qualifier';
				if ( ! empty( $member['id'] ) ) {
					$this->cohort_members->update(
						(int) $member['id'],
						array( 'member_status' => $split_status )
					);
				}
				$this->removeFromStageTeam( $team_post_id, $signup_id );
				++$split_count;
				continue;
			}

			$availability_override = $resolution === 'assign_pending' ? 'date_conflict_pending' : 'matched';
			$re_notified_count    += $this->upsertTeamMember(
				$team_post_id,
				$cohort_id,
				$signup_id,
				$scout_id,
				$wp_user_id,
				$availability_override,
				$team_changed || $date_changed
			);
			++$assigned_count;
		}

		$compliance = $this->validateStageCompliance( $team_post_id, $expedition_date );

		return array(
			'stage'             => $stage,
			'expedition_id'     => $target_expedition_id,
			'expedition_date'   => $expedition_date,
			'team_post_id'      => $team_post_id,
			'assigned_count'    => $assigned_count,
			'split_count'       => $split_count,
			're_notified_count' => $re_notified_count,
			'compliance'        => $compliance,
		);
	}

	/**
	 * Insert or update an ems_team_members row, preserving Re-Notify Protection.
	 */
	private function upsertTeamMember(
		int $team_post_id,
		int $cohort_id,
		int $expedition_signup_id,
		int $scout_id,
		int $wp_user_id,
		string $availability_override,
		bool $schedule_changed
	): int {
		$wpdb  = $this->get_wpdb();
		$table = $wpdb->prefix . 'ems_team_members';

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, team_post_id, notification_status FROM {$table} WHERE team_post_id = %d AND expedition_signup_id = %d LIMIT 1",
				$team_post_id,
				$expedition_signup_id
			),
			ARRAY_A
		);

		if ( ! is_array( $existing ) && $scout_id > 0 ) {
			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, team_post_id, notification_status FROM {$table} WHERE team_post_id = %d AND scout_id = %d AND (expedition_signup_id IS NULL OR expedition_signup_id = 0) LIMIT 1",
					$team_post_id,
					$scout_id
				),
				ARRAY_A
			);
		}

		if ( is_array( $existing ) ) {
			$update_data = array(
				'cohort_id'             => $cohort_id,
				'expedition_signup_id'  => $expedition_signup_id,
				'scout_id'              => $scout_id,
				'availability_override' => $availability_override,
			);
			$formats = array( '%d', '%d', '%d', '%s' );

			$re_notified = 0;
			if ( $schedule_changed && (string) ( $existing['notification_status'] ?? '' ) === 'notified' ) {
				$update_data['notification_status'] = 're_notify_needed';
				$formats[]                          = '%s';
				$re_notified                        = 1;
			}

			$wpdb->update(
				$table,
				$update_data,
				array( 'id' => (int) $existing['id'] ),
				$formats,
				array( '%d' )
			);

			return $re_notified;
		}

		$wpdb->insert(
			$table,
			array(
				'team_post_id'          => $team_post_id,
				'scout_id'              => $scout_id,
				'user_id'               => $wp_user_id,
				'expedition_signup_id'  => $expedition_signup_id,
				'cohort_id'             => $cohort_id,
				'availability_override' => $availability_override,
				'notification_status'   => 'draft',
				'added_by'              => (int) get_current_user_id(),
				'added_at'              => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s' )
		);

		return 0;
	}

	/**
	 * Transition all 'notified' members of a team to 're_notify_needed'.
	 */
	private function markTeamMembersForReNotify( int $team_post_id ): int {
		$wpdb  = $this->get_wpdb();
		$table = $wpdb->prefix . 'ems_team_members';

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET notification_status = 're_notify_needed' WHERE team_post_id = %d AND notification_status = 'notified'",
				$team_post_id
			)
		);

		return is_numeric( $updated ) ? (int) $updated : 0;
	}

	/**
	 * Remove unnotified rows from a stage team when a member is split_date.
	 */
	private function removeFromStageTeam( int $team_post_id, int $expedition_signup_id ): void {
		$wpdb  = $this->get_wpdb();
		$table = $wpdb->prefix . 'ems_team_members';

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE team_post_id = %d AND expedition_signup_id = %d AND notification_status IN ('draft', 'ready_to_notify')",
				$team_post_id,
				$expedition_signup_id
			)
		);
	}

	/**
	 * Remove unnotified ems_team_members rows for a cohort + signup when removed from cohort.
	 */
	private function removeUnnotifiedTeamMembers( int $cohort_id, int $expedition_signup_id ): void {
		$wpdb  = $this->get_wpdb();
		$table = $wpdb->prefix . 'ems_team_members';

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE cohort_id = %d AND expedition_signup_id = %d AND notification_status IN ('draft', 'ready_to_notify')",
				$cohort_id,
				$expedition_signup_id
			)
		);
	}

	/**
	 * Fetch active cohort members (includes 'active', 'split_practice', 'split_qualifier').
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function getActiveCohortMembers( int $cohort_id ): array {
		$all = $this->cohort_members->listByCohort( $cohort_id );
		return array_values(
			array_filter(
				$all,
				static function ( array $member ): bool {
					$status = strtolower( (string) ( $member['member_status'] ?? 'active' ) );
					return ! in_array( $status, array( 'removed', 'inactive', 'declined' ), true );
				}
			)
		);
	}

	/**
	 * Hydrate team members using the Driving Table Rule from Section 2.3.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function getHydratedTeamMembers( int $team_post_id ): array {
		$wpdb            = $this->get_wpdb();
		$tm_table        = $wpdb->prefix . 'ems_team_members';
		$signups_table   = $wpdb->prefix . 'ems_expedition_signups';
		$explorers_table = $wpdb->prefix . 'ems_osm_explorers';

		$sql = $wpdb->prepare(
			"SELECT
				tm.id,
				tm.team_post_id,
				tm.expedition_signup_id,
				COALESCE(NULLIF(s.scout_id, 0), tm.scout_id) AS scout_id,
				tm.availability_override,
				tm.notification_status,
				tm.offer_status,
				tm.offer_query_message,
				tm.offer_responded_at,
				s.first_aid_status,
				s.first_aid_expiry,
				e.first_aid_level,
				COALESCE(NULLIF(e.first_name, ''), s.explorer_first_name, '') AS first_name,
				COALESCE(NULLIF(e.last_name, ''), s.explorer_last_name, '') AS last_name
			FROM {$tm_table} tm
			LEFT JOIN {$signups_table} s ON tm.expedition_signup_id = s.id
			LEFT JOIN {$explorers_table} e ON (COALESCE(NULLIF(s.scout_id, 0), tm.scout_id) > 0 AND COALESCE(NULLIF(s.scout_id, 0), tm.scout_id) = e.scout_id)
			WHERE tm.team_post_id = %d
			ORDER BY tm.id ASC",
			$team_post_id
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Determine whether a member's expedition_preferences allow the given expedition.
	 *
	 * @param array<string, mixed> $member
	 * @param array<string, mixed> $expedition
	 */
	private function isMemberAvailableForExpedition( array $member, array $expedition, string $stage ): bool {
		$raw_prefs = $member['expedition_preferences'] ?? null;
		$prefs     = is_string( $raw_prefs ) && $raw_prefs !== '' ? json_decode( $raw_prefs, true ) : ( is_array( $raw_prefs ) ? $raw_prefs : null );

		if ( ! is_array( $prefs ) ) {
			return strtolower( (string) ( $member['dofe_level'] ?? '' ) ) === 'bronze';
		}

		if ( $stage === 'practice' ) {
			$dates = $prefs['exped_practice_dates'] ?? $prefs['exped_silver_practice_dates'] ?? $prefs['exped_gold_practice_dates'] ?? array();
		} else {
			$dates = $prefs['exped_qualifier_dates'] ?? $prefs['exped_silver_qualifier_dates'] ?? $prefs['exped_gold_qualifier_dates'] ?? array();
		}

		if ( ! is_array( $dates ) ) {
			$dates = $dates !== '' && $dates !== null ? array( $dates ) : array();
		}

		$dates = array_values( array_filter( array_map( static fn( mixed $d ): string => trim( (string) $d ), $dates ) ) );

		if ( empty( $dates ) ) {
			return strtolower( (string) ( $member['dofe_level'] ?? '' ) ) === 'bronze';
		}

		$event_code = trim( (string) ( $expedition['ems_event_code'] ?? '' ) );
		$exp_code   = trim( (string) ( $expedition['ems_expedition_code'] ?? '' ) );

		foreach ( $dates as $preferred ) {
			if ( $event_code !== '' && strcasecmp( $preferred, $event_code ) === 0 ) {
				return true;
			}
			if ( $exp_code !== '' && strcasecmp( $preferred, $exp_code ) === 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Section 2.3 First Aid fallback & expiry check:
	 * COALESCE(NULLIF(e.first_aid_level, ''), NULLIF(s.first_aid_status, ''), 'none')
	 * and s.first_aid_expiry >= expedition_date.
	 *
	 * @param array<string, mixed> $member
	 */
	private function hasValidFirstAidOnDate( array $member, string $expedition_date ): bool {
		$osm_level    = trim( (string) ( $member['first_aid_level'] ?? '' ) );
		$signup_level = trim( (string) ( $member['first_aid_status'] ?? '' ) );

		$level = ( $osm_level !== '' && strtolower( $osm_level ) !== 'none' )
			? $osm_level
			: ( $signup_level !== '' ? $signup_level : 'none' );
		$norm  = strtolower( $level );

		$valid_values = array(
			'first-aid-yes',
			'first_response',
			'first-response',
			'full_first_aid',
			'first_aider',
			'first_aid_plus',
		);

		if ( ! in_array( $norm, $valid_values, true ) ) {
			return false;
		}

		$expiry = trim( (string) ( $member['first_aid_expiry'] ?? '' ) );
		if ( $expiry === '' ) {
			return $norm === 'first-aid-yes';
		}

		$expiry_ymd = substr( $expiry, 0, 10 );
		$target_ymd = $expedition_date !== '' ? substr( trim( $expedition_date ), 0, 10 ) : gmdate( 'Y-m-d' );

		return $expiry_ymd >= $target_ymd;
	}

	/**
	 * @param array<string, mixed> $member
	 */
	private function formatMemberName( array $member ): string {
		$first = trim( (string) ( $member['first_name'] ?? $member['explorer_first_name'] ?? '' ) );
		$last  = trim( (string) ( $member['last_name'] ?? $member['explorer_last_name'] ?? '' ) );
		return trim( $first . ' ' . $last );
	}

	private function normalizeTransportMode( string $mode ): string {
		$norm = strtolower( trim( $mode ) );
		if ( $norm === 'hillwalking' ) {
			return 'foot';
		}
		return $norm;
	}

	private function normalizeStage( string $stage ): string {
		$norm = strtolower( trim( $stage ) );
		if ( $norm === 'qualifying' || $norm === 'qualifier' ) {
			return 'qualifier';
		}
		if ( $norm === 'practice' ) {
			return 'practice';
		}
		return '';
	}

	/**
	 * @param array<int|string, string> $resolutions
	 * @return array<int, string>
	 */
	private function normalizeResolutions( array $resolutions ): array {
		$out = array();
		foreach ( $resolutions as $signup_id => $action ) {
			$id = (int) $signup_id;
			if ( $id <= 0 ) {
				continue;
			}
			$norm_action = strtolower( trim( (string) $action ) );
			if ( ! in_array( $norm_action, self::ALLOWED_RESOLUTIONS, true ) ) {
				throw new \InvalidArgumentException( "Invalid member resolution '{$action}' for signup {$id}." );
			}
			$out[ $id ] = $norm_action;
		}
		return $out;
	}

	private function nullablePositiveInt( mixed $value ): ?int {
		if ( $value === null || $value === '' ) {
			return null;
		}
		$int = (int) $value;
		return $int > 0 ? $int : null;
	}
}
