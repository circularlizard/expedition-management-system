<?php
declare(strict_types=1);

namespace EMS\Integrations\RouteEngine;

use EMS\Data\Expedition_Repository;
use EMS\Data\Team_Member_Repository;
use EMS\Data\Team_Repository;

class EmsRouteBridgeAdapter {
	private Expedition_Repository $expeditionRepo;
	private Team_Repository $teamRepo;
	private Team_Member_Repository $teamMemberRepo;
	/** @var \wpdb|null */
	private $wpdb;

	/**
	 * @param Expedition_Repository|null  $expeditionRepo
	 * @param Team_Repository|null        $teamRepo
	 * @param Team_Member_Repository|null $teamMemberRepo
	 * @param \wpdb|object|null           $wpdb
	 */
	public function __construct(
		?Expedition_Repository $expeditionRepo = null,
		?Team_Repository $teamRepo = null,
		?Team_Member_Repository $teamMemberRepo = null,
		$wpdb = null
	) {
		if ( $wpdb === null ) {
			global $wpdb;
		}
		/** @var \wpdb|null $wpdb */
		$this->wpdb           = $wpdb;
		$this->expeditionRepo = $expeditionRepo ?: new Expedition_Repository();
		$this->teamRepo       = $teamRepo ?: new Team_Repository();
		$this->teamMemberRepo = $teamMemberRepo ?: new Team_Member_Repository( $this->wpdb, $this->teamRepo );
	}

	/**
	 * Register filter hooks for context provider.
	 */
	public function register(): void {
		add_filter( 'ems_route_engine/context_provider', [ $this, 'provideContext' ], 10, 4 );
		add_filter( 'ems_get_route_card_context', [ $this, 'provideContext' ], 10, 4 );
	}

	/**
	 * Alias for register().
	 */
	public function init_hooks(): void {
		$this->register();
	}

	/**
	 * Provides live expedition and team roster data to ems-route-card-engine.
	 *
	 * @param mixed $context      Incoming context filter value
	 * @param int   $expeditionId Expedition Post ID
	 * @param int   $teamId       Team Post ID
	 * @param int   $userId       User ID
	 * @return array<string, mixed>
	 */
	public function provideContext( mixed $context, int $expeditionId, int $teamId, int $userId = 0 ): array {
		if ( $expeditionId <= 0 && $teamId <= 0 ) {
			return is_array( $context ) ? $context : [];
		}

		$exp = null;
		$team = null;

		if ( $teamId > 0 ) {
			$team = $this->teamRepo->get_by_id( $teamId );
			if ( $expeditionId <= 0 && ! empty( $team['event_id'] ) ) {
				$expeditionId = (int) $team['event_id'];
			}
		}

		if ( $expeditionId > 0 ) {
			$exp = $this->expeditionRepo->get_by_id( $expeditionId );
		}

		// Expedition fields
		$expeditionName   = (string) ( $exp['post_title'] ?? 'DofE Expedition' );
		$level            = (string) ( $exp['ems_level'] ?? 'Bronze' );
		$modeOfTravel     = (string) ( $exp['ems_transport'] ?? 'Foot' );
		$supervisorName   = (string) ( $exp['ems_lic_name'] ?? '' );
		$supervisorPhone  = (string) ( $exp['ems_lic_phone'] ?? '' );
		$emergencyContact = (string) ( $exp['ems_lic_private_phone'] ?? $exp['ems_lic_phone'] ?? '' );

		// Dates resolution
		$startDate = (string) ( $exp['ems_start_date'] ?? '' );
		$endDate   = (string) ( $exp['ems_end_date'] ?? '' );
		$dates     = [];

		if ( ! empty( $startDate ) && ! empty( $endDate ) ) {
			try {
				$start = new \DateTime( $startDate );
				$end   = new \DateTime( $endDate );
				if ( $start <= $end ) {
					$period = new \DatePeriod( $start, new \DateInterval( 'P1D' ), ( clone $end )->modify( '+1 day' ) );
					foreach ( $period as $dt ) {
						$dates[] = $dt->format( 'Y-m-d' );
					}
				}
			} catch ( \Throwable $e ) {
				// Fall through to fallback
			}
		}

		if ( empty( $dates ) ) {
			if ( ! empty( $startDate ) ) {
				$dates = [ $startDate ];
			} else {
				$today = date( 'Y-m-d' );
				$dates = [ $today, date( 'Y-m-d', strtotime( '+1 day' ) ) ];
			}
		}

		$totalDays = count( $dates );

		// Team fields
		$teamName = '';
		if ( $team ) {
			$teamName = (string) ( $team['post_title'] ?? ( 'Team ' . ( $team['ems_team_number'] ?? $teamId ) ) );
		} elseif ( $teamId > 0 ) {
			$teamName = 'Team ' . $teamId;
		}

		// Team roster members
		$teamMembers = [];
		if ( $teamId > 0 ) {
			$members = $this->teamMemberRepo->list_by_team( $teamId );
			foreach ( $members as $member ) {
				$scoutId      = (int) ( $member['scout_id'] ?? 0 );
				$memberUserId = (int) ( $member['user_id'] ?? 0 );
				$memberName   = '';

				if ( $scoutId > 0 && $this->wpdb !== null ) {
					$prefix = $this->wpdb->prefix;
					$row    = $this->wpdb->get_row(
						$this->wpdb->prepare(
							"SELECT first_name, last_name FROM {$prefix}ems_osm_explorers WHERE scout_id = %d",
							$scoutId
						),
						ARRAY_A
					);
					if ( is_array( $row ) && ( ! empty( $row['first_name'] ) || ! empty( $row['last_name'] ) ) ) {
						$memberName = trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) );
					}
				}

				if ( $memberName === '' && $memberUserId > 0 && function_exists( 'get_userdata' ) ) {
					$userData = get_userdata( $memberUserId );
					if ( $userData && ! empty( $userData->display_name ) ) {
						$memberName = (string) $userData->display_name;
					}
				}

				if ( $memberName === '' ) {
					$memberName = $scoutId > 0 ? "Explorer #{$scoutId}" : "Member #{$memberUserId}";
				}

				$teamMembers[] = $memberName;
			}
		}

		return [
			'expedition_id'     => $expeditionId,
			'team_id'           => $teamId,
			'expedition_name'   => $expeditionName,
			'level'             => $level,
			'mode_of_travel'    => $modeOfTravel,
			'total_days'        => $totalDays,
			'dates'             => $dates,
			'team_name'         => $teamName,
			'team_members'      => $teamMembers,
			'supervisor_name'   => $supervisorName,
			'supervisor_phone'  => $supervisorPhone,
			'emergency_contact' => $emergencyContact,
			'is_standalone'     => false,
		];
	}
}
