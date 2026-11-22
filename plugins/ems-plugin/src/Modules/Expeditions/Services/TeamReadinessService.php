<?php
declare(strict_types=1);

namespace EMS\Modules\Expeditions\Services;

use EMS\Contracts\DTOs\TrainingStatusDTO;
use EMS\Contracts\TrainingComplianceInterface;
use EMS\Data\Expedition_Repository;
use EMS\Data\Route_Submission_Repository;
use EMS\Integrations\TutorLms\TutorLmsAdapter;
use EMS\Modules\Expeditions\Repositories\CohortMemberRepository;
use EMS\Modules\Expeditions\Repositories\CohortRepository;

/**
 * Aggregates operational readiness for every scheduled team CPT post and
 * unscheduled forming cohort.
 *
 * Enriches each entry with:
 * - Size & Place Offer status counts
 * - First Aid ("Rule of Two") compliance on the expedition date
 * - Tutor LMS training progress via TrainingComplianceInterface (gracefully degrading)
 * - Route Plan submission status and 14-day cutoff countdown
 */
class TeamReadinessService {
	private ?object $wpdb;
	private CohortRepository $cohorts;
	private CohortMemberRepository $cohort_members;
	private Expedition_Repository $expeditions;
	private Route_Submission_Repository $route_submissions;
	private TrainingComplianceInterface $training_compliance;

	/** @var array<int, TrainingStatusDTO> */
	private array $training_cache = array();

	public function __construct(
		?object $wpdb = null,
		?CohortRepository $cohorts = null,
		?CohortMemberRepository $cohort_members = null,
		?Expedition_Repository $expeditions = null,
		?Route_Submission_Repository $route_submissions = null,
		?TrainingComplianceInterface $training_compliance = null
	) {
		$this->wpdb                = $wpdb;
		$this->cohorts             = $cohorts ?: new CohortRepository( $wpdb );
		$this->cohort_members      = $cohort_members ?: new CohortMemberRepository( $wpdb );
		$this->expeditions         = $expeditions ?: new Expedition_Repository();
		$this->route_submissions   = $route_submissions ?: new Route_Submission_Repository( $wpdb );
		$this->training_compliance = $training_compliance ?: new TutorLmsAdapter();
	}

	private function get_wpdb(): object {
		if ( $this->wpdb === null ) {
			global $wpdb;
			$this->wpdb = $wpdb;
		}
		return $this->wpdb;
	}

	/**
	 * Aggregate readiness across all scheduled team CPT posts and unscheduled forming cohorts.
	 *
	 * Supported filters:
	 * - dofe_level (string)
	 * - transport_mode (string)
	 * - stage ('practice'|'qualifier'|'qualifying')
	 * - expedition_id (int)
	 *
	 * @param array<string, mixed> $filters
	 * @return array<int, array<string, mixed>>
	 */
	public function getReadiness( array $filters = array() ): array {
		$filter_level     = ! empty( $filters['dofe_level'] ) ? strtolower( trim( (string) $filters['dofe_level'] ) ) : '';
		$filter_transport = ! empty( $filters['transport_mode'] ) ? $this->normalizeTransportMode( (string) $filters['transport_mode'] ) : '';
		$filter_stage     = ! empty( $filters['stage'] ) ? $this->normalizeStage( (string) $filters['stage'] ) : '';
		$filter_exp_id    = ! empty( $filters['expedition_id'] ) ? (int) $filters['expedition_id'] : 0;

		$tutor_active = $this->isTutorAvailable();
		$results      = array();

		// 1. Scheduled team CPT posts.
		$team_posts = get_posts(
			array(
				'post_type'   => 'team',
				'post_status' => 'publish',
				'numberposts' => -1,
				'orderby'     => 'ID',
				'order'       => 'ASC',
			)
		);

		$expedition_cache = array();
		$linked_cohort_ids = array();

		foreach ( $team_posts as $team_post ) {
			$team_id   = (int) $team_post->ID;
			$team_code = (string) get_post_meta( $team_id, 'ems_team_code', true );
			if ( $team_code === 'UNALLOCATED' ) {
				continue;
			}

			$expedition_id = (int) $team_post->post_parent;
			if ( $filter_exp_id > 0 && $expedition_id !== $filter_exp_id ) {
				continue;
			}

			if ( $expedition_id > 0 && ! isset( $expedition_cache[ $expedition_id ] ) ) {
				$expedition_cache[ $expedition_id ] = $this->expeditions->get_by_id( $expedition_id );
			}
			$expedition = $expedition_id > 0 ? ( $expedition_cache[ $expedition_id ] ?? null ) : null;

			$dofe_level     = strtolower( trim( (string) ( $expedition['ems_level'] ?? '' ) ) );
			$transport_mode = $this->normalizeTransportMode( (string) ( $expedition['ems_transport'] ?? 'foot' ) );
			$stage          = $this->normalizeStage( (string) ( $expedition['ems_type'] ?? '' ) );
			$expedition_date = (string) ( $expedition['ems_start_date'] ?? '' );

			$cohort_id = (int) get_post_meta( $team_id, '_ems_cohort_id', true );
			$cohort    = $cohort_id > 0 ? $this->cohorts->find( $cohort_id ) : $this->cohorts->findByTeamPostId( $team_id );
			if ( $cohort !== null ) {
				$cohort_id                        = (int) $cohort['id'];
				$linked_cohort_ids[ $cohort_id ]  = true;
				if ( $dofe_level === '' ) {
					$dofe_level = strtolower( (string) $cohort['dofe_level'] );
				}
				if ( $transport_mode === '' ) {
					$transport_mode = $this->normalizeTransportMode( (string) $cohort['transport_mode'] );
				}
			}

			if ( $filter_level !== '' && $dofe_level !== $filter_level ) {
				continue;
			}
			if ( $filter_transport !== '' && $transport_mode !== $filter_transport ) {
				continue;
			}
			if ( $filter_stage !== '' && $stage !== $filter_stage ) {
				continue;
			}

			$members = $this->getHydratedTeamMembers( $team_id );

			$results[] = $this->buildReadinessRecord(
				array(
					'record_type'      => 'team',
					'team_post_id'     => $team_id,
					'team_code'        => $team_code !== '' ? $team_code : (string) $team_post->post_title,
					'team_title'       => (string) $team_post->post_title,
					'cohort_id'        => $cohort_id > 0 ? $cohort_id : null,
					'cohort_name'      => $cohort !== null ? (string) $cohort['name'] : null,
					'cohort_status'    => $cohort !== null ? (string) $cohort['cohort_status'] : null,
					'dofe_level'       => $dofe_level,
					'transport_mode'   => $transport_mode,
					'stage'            => $stage,
					'expedition_id'    => $expedition_id > 0 ? $expedition_id : null,
					'expedition_code'  => $expedition !== null ? (string) ( $expedition['ems_event_code'] ?? $expedition['ems_expedition_code'] ?? '' ) : '',
					'expedition_title' => $expedition !== null ? (string) ( $expedition['post_title'] ?? '' ) : '',
					'expedition_date'  => $expedition_date,
				),
				$members,
				$tutor_active
			);
		}

		// 2. Unscheduled forming cohorts from ems_cohorts.
		$cohort_filters = array();
		if ( $filter_level !== '' ) {
			$cohort_filters['dofe_level'] = $filter_level;
		}
		if ( $filter_exp_id > 0 ) {
			$cohort_filters['expedition_id'] = $filter_exp_id;
		}

		$all_cohorts = $this->cohorts->listByFilters( $cohort_filters );
		foreach ( $all_cohorts as $cohort ) {
			$cohort_id = (int) $cohort['id'];
			$has_p     = ! empty( $cohort['practice_team_post_id'] );
			$has_q     = ! empty( $cohort['qualifier_team_post_id'] );

			// Skip cohorts that already have team CPT posts represented above unless forming with no teams.
			if ( $has_p && $has_q ) {
				continue;
			}
			if ( isset( $linked_cohort_ids[ $cohort_id ] ) && $cohort['cohort_status'] !== 'forming' ) {
				continue;
			}
			if ( $has_p || $has_q ) {
				continue;
			}

			$cohort_transport = $this->normalizeTransportMode( (string) $cohort['transport_mode'] );
			if ( $filter_transport !== '' && $cohort_transport !== $filter_transport ) {
				continue;
			}

			$members = $this->getActiveCohortMembers( $cohort_id );

			$results[] = $this->buildReadinessRecord(
				array(
					'record_type'      => 'forming_cohort',
					'team_post_id'     => null,
					'team_code'        => (string) $cohort['name'],
					'team_title'       => (string) $cohort['name'],
					'cohort_id'        => $cohort_id,
					'cohort_name'      => (string) $cohort['name'],
					'cohort_status'    => (string) $cohort['cohort_status'],
					'dofe_level'       => strtolower( (string) $cohort['dofe_level'] ),
					'transport_mode'   => $cohort_transport,
					'stage'            => $filter_stage !== '' ? $filter_stage : 'unscheduled',
					'expedition_id'    => null,
					'expedition_code'  => '',
					'expedition_title' => '',
					'expedition_date'  => '',
				),
				$members,
				$tutor_active
			);
		}

		return $results;
	}

	/**
	 * Alias for getReadiness().
	 *
	 * @param array<string, mixed> $filters
	 * @return array<int, array<string, mixed>>
	 */
	public function getTeamsReadiness( array $filters = array() ): array {
		return $this->getReadiness( $filters );
	}

	/**
	 * Build a complete readiness record for a team or forming cohort.
	 *
	 * @param array<string, mixed>             $meta
	 * @param array<int, array<string, mixed>> $members
	 * @return array<string, mixed>
	 */
	private function buildReadinessRecord( array $meta, array $members, bool $tutor_active ): array {
		$expedition_date = (string) ( $meta['expedition_date'] ?? '' );
		$team_post_id    = $meta['team_post_id'] !== null ? (int) $meta['team_post_id'] : null;

		$offer_counts = array(
			'draft'            => 0,
			'ready_to_notify'  => 0,
			'notified'         => 0,
			're_notify_needed' => 0,
			'accepted'         => 0,
			'queried'          => 0,
			'declined'         => 0,
		);

		$training_summary = array(
			'complete'      => 0,
			'in_progress'   => 0,
			'not_started'   => 0,
			'unlinked_oidc' => 0,
			'unavailable'   => 0,
		);

		$first_aid_count  = 0;
		$enriched_members = array();

		foreach ( $members as $member ) {
			$notif_status    = strtolower( trim( (string) ( $member['notification_status'] ?? 'draft' ) ) );
			$offer_status    = strtolower( trim( (string) ( $member['offer_status'] ?? 'pending' ) ) );
			$effective_offer = in_array( $offer_status, array( 'accepted', 'queried', 'declined' ), true ) ? $offer_status : $notif_status;
			if ( array_key_exists( $effective_offer, $offer_counts ) ) {
				++$offer_counts[ $effective_offer ];
			} else {
				++$offer_counts['draft'];
			}

			$fa_info = $this->evaluateFirstAid( $member, $expedition_date );
			if ( $fa_info['valid'] ) {
				++$first_aid_count;
			}

			$training_info = $this->evaluateTraining( $member, $tutor_active );
			$t_status      = $training_info['status'];
			if ( array_key_exists( $t_status, $training_summary ) ) {
				++$training_summary[ $t_status ];
			}

			$enriched_members[] = array(
				'expedition_signup_id'  => (int) ( $member['expedition_signup_id'] ?? 0 ),
				'scout_id'              => (int) ( $member['scout_id'] ?? 0 ),
				'wp_user_id'            => ! empty( $member['wp_user_id'] ) ? (int) $member['wp_user_id'] : null,
				'name'                  => $this->formatMemberName( $member ),
				'availability_override' => $member['availability_override'] ?? null,
				'notification_status'   => $notif_status,
				'offer_status'          => $offer_status,
				'offer_query_message'   => $member['offer_query_message'] ?? null,
				'offer_responded_at'    => $member['offer_responded_at'] ?? null,
				'first_aid_level'       => $fa_info['level'],
				'first_aid_expiry'      => $fa_info['expiry'],
				'first_aid_valid'       => $fa_info['valid'],
				'training_status'       => $training_info['status'],
				'completed_modules'     => $training_info['completed_modules'],
				'total_modules'         => $training_info['total_modules'],
				'last_completed_date'   => $training_info['last_completed_date'],
			);
		}

		$member_count    = count( $enriched_members );
		$size_valid      = $member_count >= 4 && $member_count <= 7;
		$first_aid_valid = $first_aid_count >= 2;
		$route_plan      = $this->evaluateRoutePlan( $team_post_id, $expedition_date );

		return array_merge(
			$meta,
			array(
				'member_count'       => $member_count,
				'size_valid'         => $size_valid,
				'offer_counts'       => $offer_counts,
				'first_aid_count'    => $first_aid_count,
				'first_aid_valid'    => $first_aid_valid,
				'training_available' => $tutor_active,
				'training_summary'   => $training_summary,
				'route_plan'         => $route_plan,
				'members'            => $enriched_members,
			)
		);
	}

	/**
	 * Evaluate First Aid using Section 2.3 fallbacks and expiry check.
	 *
	 * @param array<string, mixed> $member
	 * @return array{level: string, expiry: ?string, valid: bool}
	 */
	private function evaluateFirstAid( array $member, string $expedition_date ): array {
		$osm_level    = trim( (string) ( $member['first_aid_level'] ?? '' ) );
		$signup_level = trim( (string) ( $member['first_aid_status'] ?? '' ) );
		$level        = ( $osm_level !== '' && strtolower( $osm_level ) !== 'none' )
			? $osm_level
			: ( $signup_level !== '' ? $signup_level : 'none' );
		$norm         = strtolower( $level );

		$valid_values = array(
			'first-aid-yes',
			'first_response',
			'first-response',
			'full_first_aid',
			'first_aider',
			'first_aid_plus',
		);

		$expiry_raw = trim( (string) ( $member['first_aid_expiry'] ?? '' ) );
		$expiry     = $expiry_raw !== '' ? substr( $expiry_raw, 0, 10 ) : null;

		if ( ! in_array( $norm, $valid_values, true ) ) {
			return array(
				'level'  => $level,
				'expiry' => $expiry,
				'valid'  => false,
			);
		}

		if ( $expiry === null ) {
			return array(
				'level'  => $level,
				'expiry' => null,
				'valid'  => $norm === 'first-aid-yes',
			);
		}

		$target_ymd = $expedition_date !== '' ? substr( trim( $expedition_date ), 0, 10 ) : gmdate( 'Y-m-d' );

		return array(
			'level'  => $level,
			'expiry' => $expiry,
			'valid'  => $expiry >= $target_ymd,
		);
	}

	/**
	 * Evaluate Tutor LMS training progress via TrainingComplianceInterface.
	 *
	 * @param array<string, mixed> $member
	 * @return array{status: string, completed_modules: int, total_modules: int, last_completed_date: ?string}
	 */
	private function evaluateTraining( array $member, bool $tutor_active ): array {
		if ( ! $tutor_active ) {
			return array(
				'status'              => 'unavailable',
				'completed_modules'   => 0,
				'total_modules'       => 0,
				'last_completed_date' => null,
			);
		}

		$scout_id   = (int) ( $member['scout_id'] ?? 0 );
		$wp_user_id = (int) ( $member['wp_user_id'] ?? 0 );

		if ( $scout_id <= 0 || ( $wp_user_id <= 0 && ! $this->hasLinkedWordPressUser( $scout_id ) ) ) {
			return array(
				'status'              => 'unlinked_oidc',
				'completed_modules'   => 0,
				'total_modules'       => 0,
				'last_completed_date' => null,
			);
		}

		if ( ! isset( $this->training_cache[ $scout_id ] ) ) {
			$this->training_cache[ $scout_id ] = $this->training_compliance->getExplorerCourseProgress( $scout_id );
		}

		$dto = $this->training_cache[ $scout_id ];
		if ( $dto->status === 'unavailable' ) {
			return array(
				'status'              => 'unavailable',
				'completed_modules'   => 0,
				'total_modules'       => 0,
				'last_completed_date' => null,
			);
		}

		$mapped_status = match ( true ) {
			$dto->isComplete || $dto->status === 'completed' => 'complete',
			$dto->status === 'in_progress' => 'in_progress',
			default => 'not_started',
		};

		return array(
			'status'              => $mapped_status,
			'completed_modules'   => $dto->completedModules,
			'total_modules'       => $dto->totalModules,
			'last_completed_date' => $dto->lastCompletedDate,
		);
	}

	/**
	 * Evaluate Route Plan submission status and 14-day cutoff countdown.
	 *
	 * @return array{version: int, status: string, cutoff_date: ?string, days_until_cutoff: ?int, is_past_cutoff: bool}
	 */
	private function evaluateRoutePlan( ?int $team_post_id, string $expedition_date ): array {
		$version = 0;
		$status  = 'not_started';

		if ( $team_post_id !== null && $team_post_id > 0 ) {
			$latest = $this->route_submissions->get_latest( $team_post_id );
			if ( is_array( $latest ) ) {
				$version    = (int) ( $latest['version'] ?? 1 );
				$raw_status = strtolower( trim( (string) ( $latest['status'] ?? 'pending' ) ) );
				$status     = match ( $raw_status ) {
					'approved' => 'approved',
					'changes_requested', 'rejected' => 'revisions_requested',
					'draft' => 'draft',
					default => 'submitted',
				};
			} else {
				$meta_status = strtolower( trim( (string) get_post_meta( $team_post_id, 'ems_route_status', true ) ) );
				if ( $meta_status === 'draft' ) {
					$status = 'draft';
				}
			}
		}

		$cutoff_date       = null;
		$days_until_cutoff = null;
		$is_past_cutoff    = false;

		$exp_ymd = substr( trim( $expedition_date ), 0, 10 );
		if ( $exp_ymd !== '' && strtotime( $exp_ymd ) !== false ) {
			$cutoff_ts         = strtotime( $exp_ymd . ' -14 days' );
			$today_ts          = strtotime( gmdate( 'Y-m-d' ) );
			if ( $cutoff_ts !== false && $today_ts !== false ) {
				$cutoff_date       = gmdate( 'Y-m-d', $cutoff_ts );
				$days_until_cutoff = (int) floor( ( $cutoff_ts - $today_ts ) / 86400 );
				$is_past_cutoff    = $days_until_cutoff < 0;
			}
		}

		return array(
			'version'           => $version,
			'status'            => $status,
			'cutoff_date'       => $cutoff_date,
			'days_until_cutoff' => $days_until_cutoff,
			'is_past_cutoff'    => $is_past_cutoff,
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
				COALESCE(NULLIF(e.wp_user_id, 0), NULLIF(tm.user_id, 0)) AS wp_user_id,
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

	private function isTutorAvailable(): bool {
		if ( $this->training_compliance instanceof TutorLmsAdapter ) {
			return $this->training_compliance->isTutorActive();
		}
		return true;
	}

	private function hasLinkedWordPressUser( int $scout_id ): bool {
		return \EMS\Core\ExplorerUserResolver::findWpUserIdByScoutId( $scout_id ) !== null;
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
}
