<?php
namespace EMS\Modules\Notifications\Services;

use EMS\Core\Audit_Logger;
use EMS\Core\Event_Bus;
use EMS\Modules\Notifications\Email_Template_Registry;
use EMS\Modules\Notifications\Email_Template_Service;

/**
 * Two-step manual Place Offer dispatcher for Expedition Cohorts & Teams.
 *
 * Step 1: previewBatch() resolves recipients using the Driving Table Rule
 *         (ems_expedition_signups LEFT JOIN ems_osm_explorers), applies Section 2.3
 *         email fallbacks, renders a sample HTML email, and computes safety warnings.
 * Step 2: dispatchBatch() requires explicit confirm_dispatch === true, sends one
 *         unified Place Offer email per explorer (CC parent_email when present),
 *         updates ems_team_members.notification_status = 'notified', dispatches
 *         Event_Bus('ems.expedition.offers_dispatched'), and logs to ems_audit_logs.
 */
class ExpeditionOfferDispatcher {

	private ?object $wpdb;
	private Email_Template_Registry $registry;
	private Email_Template_Service $template_service;

	public function __construct(
		?object $wpdb = null,
		?Email_Template_Registry $registry = null,
		?Email_Template_Service $template_service = null
	) {
		$this->wpdb             = $wpdb;
		$this->registry         = $registry ?? new Email_Template_Registry();
		$this->template_service = $template_service ?? new Email_Template_Service();
	}

	private function get_wpdb(): object {
		if ( $this->wpdb === null ) {
			global $wpdb;
			$this->wpdb = $wpdb;
		}
		return $this->wpdb;
	}

	/**
	 * Preview a Place Offer notification batch without sending any emails.
	 *
	 * Accepted $params:
	 * - scope?: string ('cohort'|'selection'|'all')
	 * - cohort_id?: int
	 * - expedition_signup_ids?: int[]
	 * - dofe_level?: string
	 * - transport_mode?: string
	 * - acceptance_deadline?: string
	 *
	 * @param array<string, mixed> $params
	 * @return array<string, mixed>
	 */
	public function previewBatch( array $params ): array {
		$scope      = sanitize_key( (string) ( $params['scope'] ?? 'all' ) );
		$cohort_id  = isset( $params['cohort_id'] ) ? (int) $params['cohort_id'] : 0;
		$signup_ids = $this->sanitize_id_list( $params['expedition_signup_ids'] ?? array() );

		if (
			( $scope === 'cohort' && $cohort_id <= 0 ) ||
			( $scope === 'selection' && empty( $signup_ids ) )
		) {
			return array(
				'scope'        => $scope,
				'recipients'   => array(),
				'total_count'  => 0,
				'sample_email' => null,
				'warnings'     => array(),
			);
		}

		$explorers = $this->fetch_target_explorers( $params );
		if ( empty( $explorers ) ) {
			return array(
				'scope'        => $scope,
				'recipients'   => array(),
				'total_count'  => 0,
				'sample_email' => null,
				'warnings'     => array(),
			);
		}

		$cohort_ids       = $this->extract_cohort_ids( $explorers );
		$cohort_rosters   = $this->fetch_cohort_rosters( $cohort_ids );
		$expedition_dates = $this->resolve_expedition_dates_for_explorers( $explorers );
		$deadline         = $this->resolve_acceptance_deadline( $params );

		$recipients        = array();
		$sample_email      = null;
		$unmatched_ids     = array();
		$conflict_ids      = array();

		foreach ( $explorers as $explorer ) {
			$signup_id     = (int) $explorer['expedition_signup_id'];
			$scout_id      = (int) $explorer['scout_id'];
			$explorer_name = trim( $explorer['first_name'] . ' ' . $explorer['last_name'] );
			$exp_email     = (string) $explorer['resolved_explorer_email'];
			$parent_email  = (string) $explorer['resolved_parent_email'];
			$has_email     = $exp_email !== '' && (bool) is_email( $exp_email );

			$per_recipient_warnings = array();
			if ( $scout_id <= 0 ) {
				$per_recipient_warnings[] = 'unmatched_osm';
				$unmatched_ids[]          = $signup_id;
			}
			if ( $explorer['availability_override'] === 'date_conflict_pending' ) {
				$per_recipient_warnings[] = 'date_conflict_pending';
				$conflict_ids[]           = $signup_id;
			}
			if ( ! $has_email ) {
				$per_recipient_warnings[] = 'missing_email';
			}

			$cid       = (int) ( $explorer['cohort_id'] ?? 0 );
			$teammates = $this->get_teammates_for_explorer( $cid, $signup_id, $cohort_rosters );
			$dates     = $expedition_dates[ $signup_id ] ?? array(
				'practice_dates'  => 'TBC',
				'qualifier_dates' => 'TBC',
				'team_code'       => (string) ( $explorer['team_code'] ?? '' ),
			);

			if ( $sample_email === null ) {
				$replacements = $this->build_replacements(
					$explorer,
					$dates,
					$teammates,
					$deadline
				);
				$sample_email = $this->render_offer_email( $replacements );
			}

			$recipients[] = array(
				'expedition_signup_id'   => $signup_id,
				'scout_id'               => $scout_id,
				'explorer_name'          => $explorer_name,
				'explorer_email'         => $exp_email,
				'parent_email'           => $parent_email,
				'has_valid_email'        => $has_email,
				'notification_status'    => (string) $explorer['notification_status'],
				'availability_override'  => (string) $explorer['availability_override'],
				'cohort_id'              => $cid > 0 ? $cid : null,
				'cohort_name'            => $explorer['cohort_name'],
				'team_code'              => $dates['team_code'],
				'practice_team_post_id'  => $explorer['practice_team_post_id'],
				'qualifier_team_post_id' => $explorer['qualifier_team_post_id'],
				'dofe_level'             => (string) $explorer['dofe_level'],
				'transport_mode'         => (string) $explorer['transport_mode'],
				'warnings'               => $per_recipient_warnings,
			);
		}

		$warnings = $this->build_batch_warnings(
			$unmatched_ids,
			$conflict_ids,
			$cohort_rosters,
			$explorers
		);

		return array(
			'scope'        => $scope,
			'recipients'   => $recipients,
			'total_count'  => count( $recipients ),
			'sample_email' => $sample_email,
			'warnings'     => $warnings,
		);
	}

	/**
	 * Dispatch unified Place Offer emails for the confirmed expedition_signup_ids.
	 *
	 * Requires:
	 * - confirm_dispatch === true
	 * - expedition_signup_ids: non-empty int[]
	 *
	 * @param array<string, mixed> $params
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException When confirm_dispatch !== true or expedition_signup_ids is empty.
	 */
	public function dispatchBatch( array $params ): array {
		if ( ( $params['confirm_dispatch'] ?? false ) !== true ) {
			throw new \InvalidArgumentException( 'Dispatch must be explicitly confirmed with confirm_dispatch === true.' );
		}

		$signup_ids = $this->sanitize_id_list( $params['expedition_signup_ids'] ?? array() );
		if ( empty( $signup_ids ) ) {
			throw new \InvalidArgumentException( 'expedition_signup_ids must be a non-empty array of positive integers.' );
		}

		$cohort_id_filter = isset( $params['cohort_id'] ) ? (int) $params['cohort_id'] : 0;

		$fetch_params = array(
			'expedition_signup_ids' => $signup_ids,
		);
		if ( $cohort_id_filter > 0 ) {
			$fetch_params['cohort_id'] = $cohort_id_filter;
		}

		$explorers = $this->fetch_target_explorers( $fetch_params );
		$def       = $this->get_template_definition();

		$cohort_ids       = $this->extract_cohort_ids( $explorers );
		$cohort_rosters   = $this->fetch_cohort_rosters( $cohort_ids );
		$expedition_dates = $this->resolve_expedition_dates_for_explorers( $explorers );
		$deadline         = $this->resolve_acceptance_deadline( $params );

		$wpdb            = $this->get_wpdb();
		$team_table      = $wpdb->prefix . 'ems_team_members';
		$current_user_id = (int) get_current_user_id();
		$now             = current_time( 'mysql' ) ?: gmdate( 'Y-m-d H:i:s' );

		$sent_ids    = array();
		$failed_ids  = array();
		$skipped_ids = array();
		$errors      = array();

		foreach ( $explorers as $explorer ) {
			$signup_id    = (int) $explorer['expedition_signup_id'];
			$to           = trim( (string) $explorer['resolved_explorer_email'] );
			$parent_email = trim( (string) $explorer['resolved_parent_email'] );

			if ( $to === '' || ! is_email( $to ) ) {
				$skipped_ids[] = $signup_id;
				$errors[]      = array(
					'expedition_signup_id' => $signup_id,
					'reason'               => 'Missing or invalid explorer email address.',
				);
				continue;
			}

			$cid       = (int) ( $explorer['cohort_id'] ?? 0 );
			$teammates = $this->get_teammates_for_explorer( $cid, $signup_id, $cohort_rosters );
			$dates     = $expedition_dates[ $signup_id ] ?? array(
				'practice_dates'  => 'TBC',
				'qualifier_dates' => 'TBC',
				'team_code'       => (string) ( $explorer['team_code'] ?? '' ),
			);

			$replacements = $this->build_replacements(
				$explorer,
				$dates,
				$teammates,
				$deadline
			);

			$rendered = $this->render_offer_email( $replacements );
			$headers  = $this->template_service->build_headers(
				(string) $def['reply_to_option'],
				$replacements,
				array(),
				true
			);

			if ( ! empty( $explorer['cc_emails'] ) && is_array( $explorer['cc_emails'] ) ) {
				$clean_ccs = array();
				foreach ( $explorer['cc_emails'] as $cc_addr ) {
					$cc_addr = trim( (string) $cc_addr );
					if ( $cc_addr !== '' && is_email( $cc_addr ) && strcasecmp( $cc_addr, $to ) !== 0 ) {
						$clean_ccs[ strtolower( $cc_addr ) ] = (string) preg_replace( '/[\r\n\t]+/', '', $cc_addr );
					}
				}
				if ( ! empty( $clean_ccs ) ) {
					$headers[] = 'Cc: ' . implode( ', ', array_values( $clean_ccs ) );
				}
			} elseif ( $parent_email !== '' && is_email( $parent_email ) && strcasecmp( $parent_email, $to ) !== 0 ) {
				$clean_cc  = (string) preg_replace( '/[\r\n\t]+/', '', $parent_email );
				$headers[] = 'Cc: ' . $clean_cc;
			}

			$sent = (bool) wp_mail( $to, $rendered['subject'], $rendered['message'], $headers );

			if ( ! $sent ) {
				$failed_ids[] = $signup_id;
				$errors[]     = array(
					'expedition_signup_id' => $signup_id,
					'reason'               => 'wp_mail() returned false.',
				);
				continue;
			}

			$this->mark_team_members_notified(
				$wpdb,
				$team_table,
				$signup_id,
				$cohort_id_filter > 0 ? $cohort_id_filter : null,
				$now,
				$current_user_id
			);

			$sent_ids[] = $signup_id;
		}

		$event_payload = (object) array(
			'expedition_signup_ids' => $sent_ids,
			'failed_ids'            => $failed_ids,
			'skipped_ids'           => $skipped_ids,
			'cohort_id'             => $cohort_id_filter > 0 ? $cohort_id_filter : null,
			'dispatched_by'         => $current_user_id,
			'dispatched_at'         => $now,
		);
		Event_Bus::dispatch( 'ems.expedition.offers_dispatched', $event_payload );

		Audit_Logger::log( 'expedition_offers_dispatched', null, $current_user_id );

		return array(
			'sent_count'    => count( $sent_ids ),
			'failed_count'  => count( $failed_ids ),
			'skipped_count' => count( $skipped_ids ),
			'sent_ids'      => $sent_ids,
			'failed_ids'    => $failed_ids,
			'skipped_ids'   => $skipped_ids,
			'errors'        => $errors,
		);
	}

	/**
	 * Fetch target explorers driving from ems_expedition_signups (s) with
	 * LEFT JOIN ems_osm_explorers (e) ON (s.scout_id > 0 AND s.scout_id = e.scout_id).
	 *
	 * @param array<string, mixed> $params
	 * @return array<int, array<string, mixed>>
	 */
	private function fetch_target_explorers( array $params ): array {
		$wpdb               = $this->get_wpdb();
		$signups_table      = $wpdb->prefix . 'ems_expedition_signups';
		$explorers_table    = $wpdb->prefix . 'ems_osm_explorers';
		$team_members_table = $wpdb->prefix . 'ems_team_members';
		$posts_table        = $wpdb->posts;
		$postmeta_table     = $wpdb->postmeta;

		$where = array( "tm.team_post_id > 0", "t.post_type = 'team'", "t.post_status = 'publish'" );
		$args  = array();

		$signup_ids = $this->sanitize_id_list( $params['expedition_signup_ids'] ?? array() );
		if ( ! empty( $signup_ids ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $signup_ids ), '%d' ) );
			$where[]      = "s.id IN ({$placeholders})";
			$args         = array_merge( $args, $signup_ids );
		}

		$team_filter_id = isset( $params['team_post_id'] ) ? (int) $params['team_post_id'] : ( isset( $params['cohort_id'] ) ? (int) $params['cohort_id'] : 0 );
		if ( $team_filter_id > 0 ) {
			$where[] = 'tm.team_post_id = %d';
			$args[]  = $team_filter_id;
		}

		if ( ! empty( $params['dofe_level'] ) ) {
			$where[] = 's.dofe_level = %s';
			$args[]  = strtolower( sanitize_text_field( (string) $params['dofe_level'] ) );
		}

		$sql = "SELECT
				s.id AS expedition_signup_id,
				s.scout_id,
				COALESCE(NULLIF(e.first_name, ''), s.explorer_first_name) AS first_name,
				COALESCE(NULLIF(e.last_name, ''), s.explorer_last_name) AS last_name,
				COALESCE(NULLIF(e.email, ''), NULLIF(s.explorer_email, ''), '') AS resolved_explorer_email,
				COALESCE(NULLIF(e.parent_email, ''), NULLIF(s.parent_email, ''), '') AS resolved_parent_email,
				e.email1 AS osm_email1,
				e.email2 AS osm_email2,
				e.p1_email1 AS osm_p1_email1,
				e.p1_email2 AS osm_p1_email2,
				e.p2_email1 AS osm_p2_email1,
				e.p2_email2 AS osm_p2_email2,
				s.parent_email AS signup_parent_email,
				COALESCE(NULLIF(e.first_aid_level, ''), NULLIF(s.first_aid_status, ''), 'none') AS resolved_first_aid_level,
				s.first_aid_expiry,
				s.dofe_level,
				tm.team_post_id,
				t.post_title AS team_title,
				exp.ID AS expedition_id,
				pm_trans.meta_value AS expedition_transport,
				pm_type.meta_value AS expedition_type,
				pm_tcode.meta_value AS team_code
			FROM {$team_members_table} tm
			INNER JOIN {$posts_table} t ON t.ID = tm.team_post_id
			INNER JOIN {$posts_table} exp ON exp.ID = t.post_parent
			INNER JOIN {$signups_table} s ON (s.id = tm.expedition_signup_id OR (tm.expedition_signup_id IS NULL AND tm.scout_id > 0 AND s.scout_id = tm.scout_id))
			LEFT JOIN {$explorers_table} e ON (s.scout_id > 0 AND s.scout_id = e.scout_id)
			LEFT JOIN {$postmeta_table} pm_trans ON pm_trans.post_id = exp.ID AND pm_trans.meta_key = 'ems_transport'
			LEFT JOIN {$postmeta_table} pm_type ON pm_type.post_id = exp.ID AND pm_type.meta_key = 'ems_type'
			LEFT JOIN {$postmeta_table} pm_tcode ON pm_tcode.post_id = t.ID AND pm_tcode.meta_key = 'ems_team_code'";

		$sql .= ' WHERE ' . implode( ' AND ', $where );
		$sql .= ' ORDER BY s.id ASC, tm.id ASC';

		if ( ! empty( $args ) ) {
			$sql = $wpdb->prepare( $sql, ...$args );
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return array();
		}

		$normalize_tr = static function ( string $raw ): string {
			$n = strtolower( trim( $raw ) );
			if ( $n === 'hillwalking' || $n === 'walking' ) return 'foot';
			if ( $n === 'canoe' || $n === 'paddle' ) return 'paddling';
			if ( $n === 'bike' || $n === 'cycling' ) return 'biking';
			return $n !== '' ? $n : 'foot';
		};
		$filter_transport = ! empty( $params['transport_mode'] )
			? $normalize_tr( sanitize_text_field( (string) $params['transport_mode'] ) )
			: '';

		// Deduplicate by expedition_signup_id and aggregate practice/qualifier team & expedition IDs.
		$by_signup = array();
		foreach ( $rows as $row ) {
			$tcode = strtoupper( trim( (string) ( $row['team_code'] ?? $row['team_title'] ?? '' ) ) );
			if ( $tcode === 'UNALLOCATED' ) {
				continue;
			}
			$tr = $normalize_tr( (string) ( $row['expedition_transport'] ?? '' ) );
			if ( $filter_transport !== '' && $tr !== $filter_transport ) {
				continue;
			}
			$sid = (int) $row['expedition_signup_id'];
			if ( ! isset( $by_signup[ $sid ] ) ) {
				$row['transport_mode']          = $tr;
				$row['practice_expedition_id']  = null;
				$row['qualifier_expedition_id'] = null;
				$row['practice_team_post_id']   = null;
				$row['qualifier_team_post_id']  = null;
				$row['cohort_id']               = (int) $row['team_post_id'];
				$row['cohort_name']             = (string) ( $row['team_code'] ?: $row['team_title'] );
				$by_signup[ $sid ]              = $row;
			}
			$ev_type = strtolower( trim( (string) ( $row['expedition_type'] ?? '' ) ) );
			if ( $ev_type === 'practice' ) {
				$by_signup[ $sid ]['practice_expedition_id'] = (int) $row['expedition_id'];
				$by_signup[ $sid ]['practice_team_post_id']  = (int) $row['team_post_id'];
			} else {
				$by_signup[ $sid ]['qualifier_expedition_id'] = (int) $row['expedition_id'];
				$by_signup[ $sid ]['qualifier_team_post_id']  = (int) $row['team_post_id'];
			}
		}

		if ( empty( $by_signup ) ) {
			return array();
		}

		$target_ids   = array_keys( $by_signup );
		$team_status  = $this->fetch_team_member_statuses( $wpdb, $team_members_table, $target_ids );

		$result = array();
		foreach ( $by_signup as $sid => $row ) {
			$tm_info = $team_status[ $sid ] ?? array(
				'notification_status'   => 'draft',
				'availability_override' => 'matched',
				'team_post_ids'         => array(),
			);

			$avail = $tm_info['availability_override'];
			if ( $avail === '' ) {
				$avail = 'matched';
			}

			$cc_emails = array_values(
				array_filter(
					array(
						trim( (string) ( $row['osm_email2'] ?? '' ) ),
						trim( (string) ( $row['osm_p1_email1'] ?? '' ) ),
						trim( (string) ( $row['osm_p1_email2'] ?? '' ) ),
						trim( (string) ( $row['osm_p2_email1'] ?? '' ) ),
						trim( (string) ( $row['osm_p2_email2'] ?? '' ) ),
						trim( (string) ( $row['resolved_parent_email'] ?? '' ) ),
						trim( (string) ( $row['signup_parent_email'] ?? '' ) ),
					),
					static fn( string $addr ): bool => $addr !== ''
				)
			);

			$result[] = array(
				'expedition_signup_id'     => $sid,
				'scout_id'                 => (int) ( $row['scout_id'] ?? 0 ),
				'first_name'               => (string) ( $row['first_name'] ?? '' ),
				'last_name'                => (string) ( $row['last_name'] ?? '' ),
				'resolved_explorer_email'  => (string) ( $row['resolved_explorer_email'] ?? '' ),
				'resolved_parent_email'    => (string) ( $row['resolved_parent_email'] ?? '' ),
				'cc_emails'                => $cc_emails,
				'resolved_first_aid_level' => (string) ( $row['resolved_first_aid_level'] ?? 'none' ),
				'first_aid_expiry'         => isset( $row['first_aid_expiry'] ) ? (string) $row['first_aid_expiry'] : null,
				'dofe_level'               => (string) ( $row['dofe_level'] ?? '' ),
				'transport_mode'           => (string) ( $row['transport_mode'] ?? 'foot' ),
				'member_status'            => 'active',
				'cohort_id'                => (int) $row['cohort_id'],
				'cohort_name'              => (string) $row['cohort_name'],
				'practice_expedition_id'   => $row['practice_expedition_id'],
				'qualifier_expedition_id'  => $row['qualifier_expedition_id'],
				'practice_team_post_id'    => $row['practice_team_post_id'],
				'qualifier_team_post_id'   => $row['qualifier_team_post_id'],
				'notification_status'      => $tm_info['notification_status'],
				'availability_override'    => $avail,
				'team_post_ids'            => $tm_info['team_post_ids'],
			);
		}

		return $result;
	}

	/**
	 * Fetch aggregated notification_status, availability_override, and team_post_ids from ems_team_members.
	 *
	 * @param array<int, int> $signup_ids
	 * @return array<int, array{notification_status: string, availability_override: string, team_post_ids: array<int, int>}>
	 */
	private function fetch_team_member_statuses( object $wpdb, string $team_table, array $signup_ids ): array {
		if ( empty( $signup_ids ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $signup_ids ), '%d' ) );
		$sql          = $wpdb->prepare(
			"SELECT expedition_signup_id, team_post_id, notification_status, availability_override
			FROM {$team_table}
			WHERE expedition_signup_id IN ({$placeholders})
			ORDER BY id ASC",
			...$signup_ids
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$map = array();
		foreach ( $rows as $row ) {
			$sid = (int) $row['expedition_signup_id'];
			if ( ! isset( $map[ $sid ] ) ) {
				$map[ $sid ] = array(
					'notification_status'   => (string) ( $row['notification_status'] ?? 'draft' ),
					'availability_override' => (string) ( $row['availability_override'] ?? 'matched' ),
					'team_post_ids'         => array(),
				);
			} else {
				// Prioritize re_notify_needed or draft over notified if mixed across stages.
				$curr = (string) ( $row['notification_status'] ?? 'draft' );
				if ( $curr === 're_notify_needed' || ( $curr === 'draft' && $map[ $sid ]['notification_status'] === 'notified' ) ) {
					$map[ $sid ]['notification_status'] = $curr;
				}
				if ( ( $row['availability_override'] ?? '' ) === 'date_conflict_pending' ) {
					$map[ $sid ]['availability_override'] = 'date_conflict_pending';
				}
			}

			$tpid = (int) ( $row['team_post_id'] ?? 0 );
			if ( $tpid > 0 ) {
				$map[ $sid ]['team_post_ids'][] = $tpid;
			}
		}

		return $map;
	}

	/**
	 * Fetch active cohort members for teammate lists & cohort-level safety checks.
	 *
	 * @param array<int, int> $cohort_ids
	 * @return array<int, array<int, array<string, mixed>>>
	 */
	private function fetch_cohort_rosters( array $cohort_ids ): array {
		if ( empty( $cohort_ids ) ) {
			return array();
		}

		$wpdb                 = $this->get_wpdb();
		$signups_table      = $wpdb->prefix . 'ems_expedition_signups';
		$explorers_table    = $wpdb->prefix . 'ems_osm_explorers';
		$team_members_table = $wpdb->prefix . 'ems_team_members';

		$placeholders = implode( ', ', array_fill( 0, count( $cohort_ids ), '%d' ) );
		$sql          = $wpdb->prepare(
			"SELECT
				tm.team_post_id AS cohort_id,
				tm.expedition_signup_id,
				'active' AS member_status,
				COALESCE(NULLIF(e.first_name, ''), s.explorer_first_name) AS first_name,
				COALESCE(NULLIF(e.last_name, ''), s.explorer_last_name) AS last_name,
				COALESCE(NULLIF(e.email, ''), NULLIF(s.explorer_email, ''), '') AS resolved_explorer_email,
				COALESCE(NULLIF(e.parent_email, ''), NULLIF(s.parent_email, ''), '') AS resolved_parent_email,
				e.email1 AS osm_email1,
				e.email2 AS osm_email2,
				e.p1_email1 AS osm_p1_email1,
				e.p1_email2 AS osm_p1_email2,
				e.p2_email1 AS osm_p2_email1,
				e.p2_email2 AS osm_p2_email2,
				s.parent_email AS signup_parent_email,
				COALESCE(NULLIF(e.first_aid_level, ''), NULLIF(s.first_aid_status, ''), 'none') AS resolved_first_aid_level,
				s.first_aid_expiry
			FROM {$team_members_table} tm
			INNER JOIN {$signups_table} s ON (s.id = tm.expedition_signup_id OR (tm.expedition_signup_id IS NULL AND tm.scout_id > 0 AND s.scout_id = tm.scout_id))
			LEFT JOIN {$explorers_table} e ON (s.scout_id > 0 AND s.scout_id = e.scout_id)
			WHERE tm.team_post_id IN ({$placeholders})
			ORDER BY first_name ASC, last_name ASC",
			...$cohort_ids
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$rosters = array();
		foreach ( $rows as $row ) {
			$cid = (int) $row['cohort_id'];
			$rosters[ $cid ][] = array(
				'expedition_signup_id'     => (int) $row['expedition_signup_id'],
				'member_status'            => (string) ( $row['member_status'] ?? 'active' ),
				'first_name'               => (string) ( $row['first_name'] ?? '' ),
				'last_name'                => (string) ( $row['last_name'] ?? '' ),
				'resolved_first_aid_level' => (string) ( $row['resolved_first_aid_level'] ?? 'none' ),
				'first_aid_expiry'         => isset( $row['first_aid_expiry'] ) ? (string) $row['first_aid_expiry'] : null,
			);
		}

		return $rosters;
	}

	/**
	 * Resolve Practice/Qualifier expedition dates and team code per explorer.
	 *
	 * @param array<int, array<string, mixed>> $explorers
	 * @return array<int, array{practice_dates: string, qualifier_dates: string, team_code: string, earliest_date: ?string}>
	 */
	private function resolve_expedition_dates_for_explorers( array $explorers ): array {
		$resolved = array();

		foreach ( $explorers as $exp ) {
			$sid              = (int) $exp['expedition_signup_id'];
			$practice_exp_id  = (int) ( $exp['practice_expedition_id'] ?? 0 );
			$qualifier_exp_id = (int) ( $exp['qualifier_expedition_id'] ?? 0 );
			$practice_team_id = (int) ( $exp['practice_team_post_id'] ?? 0 );
			$qualifier_team_id = (int) ( $exp['qualifier_team_post_id'] ?? 0 );

			$team_codes = array();
			foreach ( array( $practice_team_id, $qualifier_team_id ) as $tpid ) {
				if ( $tpid > 0 ) {
					$code = (string) get_post_meta( $tpid, 'ems_team_code', true );
					if ( $code !== '' && ! in_array( $code, $team_codes, true ) ) {
						$team_codes[] = $code;
					}
				}
			}

			if ( empty( $team_codes ) && ! empty( $exp['team_post_ids'] ) ) {
				foreach ( $exp['team_post_ids'] as $tpid ) {
					$code = (string) get_post_meta( (int) $tpid, 'ems_team_code', true );
					if ( $code !== '' && ! in_array( $code, $team_codes, true ) ) {
						$team_codes[] = $code;
					}
				}
			}

			$team_code = ! empty( $team_codes )
				? implode( ' / ', $team_codes )
				: (string) ( $exp['cohort_name'] ?? 'TBC' );

			$practice_dates  = $practice_exp_id > 0 ? $this->format_expedition_date_range( $practice_exp_id ) : 'Not assigned';
			$qualifier_dates = $qualifier_exp_id > 0 ? $this->format_expedition_date_range( $qualifier_exp_id ) : 'Not assigned';

			$earliest = null;
			foreach ( array( $practice_exp_id, $qualifier_exp_id ) as $eid ) {
				if ( $eid > 0 ) {
					$start = (string) get_post_meta( $eid, 'ems_start_date', true );
					if ( $start !== '' && ( $earliest === null || $start < $earliest ) ) {
						$earliest = $start;
					}
				}
			}

			$resolved[ $sid ] = array(
				'practice_dates'  => $practice_dates,
				'qualifier_dates' => $qualifier_dates,
				'team_code'       => $team_code,
				'earliest_date'   => $earliest,
			);
		}

		return $resolved;
	}

	private function format_expedition_date_range( int $expedition_post_id ): string {
		$start = trim( (string) get_post_meta( $expedition_post_id, 'ems_start_date', true ) );
		$end   = trim( (string) get_post_meta( $expedition_post_id, 'ems_end_date', true ) );

		if ( $start === '' && $end === '' ) {
			return 'TBC';
		}
		if ( $start !== '' && ( $end === '' || $end === $start ) ) {
			$ts = strtotime( $start );
			return $ts !== false ? gmdate( 'j M Y', $ts ) : $start;
		}

		$ts_start = strtotime( $start );
		$ts_end   = strtotime( $end );
		if ( $ts_start === false || $ts_end === false ) {
			return trim( $start . ' – ' . $end, ' –' );
		}

		if ( gmdate( 'Y-m', $ts_start ) === gmdate( 'Y-m', $ts_end ) ) {
			return gmdate( 'j', $ts_start ) . '–' . gmdate( 'j M Y', $ts_end );
		}
		if ( gmdate( 'Y', $ts_start ) === gmdate( 'Y', $ts_end ) ) {
			return gmdate( 'j M', $ts_start ) . ' – ' . gmdate( 'j M Y', $ts_end );
		}

		return gmdate( 'j M Y', $ts_start ) . ' – ' . gmdate( 'j M Y', $ts_end );
	}

	/**
	 * @param array<int, array<int, array<string, mixed>>> $cohort_rosters
	 * @return string
	 */
	private function get_teammates_for_explorer( int $cohort_id, int $exclude_signup_id, array $cohort_rosters ): string {
		if ( $cohort_id <= 0 || empty( $cohort_rosters[ $cohort_id ] ) ) {
			return '<p><em>Teammates to be confirmed</em></p>';
		}

		$items = array();
		foreach ( $cohort_rosters[ $cohort_id ] as $member ) {
			if ( (int) $member['expedition_signup_id'] === $exclude_signup_id ) {
				continue;
			}
			$name = trim( $member['first_name'] . ' ' . $member['last_name'] );
			if ( $name === '' ) {
				continue;
			}
			$escaped = function_exists( 'esc_html' ) ? esc_html( $name ) : htmlspecialchars( $name, ENT_QUOTES, 'UTF-8' );
			$items[] = '<li>' . $escaped . '</li>';
		}

		if ( empty( $items ) ) {
			return '<p><em>Teammates to be confirmed</em></p>';
		}

		return '<ul>' . implode( '', $items ) . '</ul>';
	}

	/**
	 * @param array<string, mixed>                         $explorer
	 * @param array{practice_dates: string, qualifier_dates: string, team_code: string, earliest_date?: ?string} $dates
	 * @return array<string, string>
	 */
	private function build_replacements(
		array $explorer,
		array $dates,
		string $teammates_html,
		string $deadline
	): array {
		$explorer_name = trim( (string) $explorer['first_name'] . ' ' . (string) $explorer['last_name'] );
		$site_name     = function_exists( 'get_bloginfo' ) ? trim( (string) get_bloginfo( 'name' ) ) : 'SE Explorer Expeditions';
		if ( $site_name === '' ) {
			$site_name = 'SE Explorer Expeditions';
		}

		$date_conflict_notice = '';
		if ( $explorer['availability_override'] === 'date_conflict_pending' ) {
			$date_conflict_notice = '<p><strong>Date Conflict Notice:</strong> You currently have a pending date conflict recorded for one of the expedition stages. Please review the dates above and confirm your availability via the Explorer Portal.</p>';
		}

		$base_url         = function_exists( 'home_url' ) ? home_url( '/explorer-portal/' ) : '/explorer-portal/';
		$portal_offer_url = (string) apply_filters( 'ems_portal_offer_url', $base_url, $explorer );

		return array(
			'{{explorer_name}}'        => $explorer_name,
			'{{team_code}}'            => $dates['team_code'],
			'{{practice_dates}}'       => $dates['practice_dates'],
			'{{qualifier_dates}}'      => $dates['qualifier_dates'],
			'{{teammates_list}}'       => $teammates_html,
			'{{date_conflict_notice}}' => $date_conflict_notice,
			'{{portal_offer_url}}'     => $portal_offer_url,
			'{{acceptance_deadline}}'  => $deadline,
			'{{site_name}}'            => $site_name,
		);
	}

	/**
	 * @param array<string, string> $replacements
	 * @return array{subject: string, message: string}
	 */
	private function render_offer_email( array $replacements ): array {
		$def = $this->get_template_definition();

		return $this->template_service->resolve_template(
			(string) $def['subject_option'],
			(string) $def['default_subject'],
			(string) $def['body_option'],
			(string) $def['default_body'],
			$replacements
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function get_template_definition(): array {
		$def = $this->registry->get( 'expedition_place_offer' );
		if ( is_array( $def ) ) {
			return $def;
		}

		return array(
			'slug'            => 'expedition_place_offer',
			'subject_option'  => Email_Template_Registry::OPTION_PLACE_OFFER_SUBJECT,
			'reply_to_option' => Email_Template_Registry::OPTION_PLACE_OFFER_REPLY_TO,
			'body_option'     => Email_Template_Registry::OPTION_PLACE_OFFER_BODY,
			'default_subject' => Email_Template_Registry::DEFAULT_PLACE_OFFER_SUBJECT,
			'default_body'    => Email_Template_Registry::DEFAULT_PLACE_OFFER_BODY,
		);
	}

	/**
	 * @param array<int, int>                             $unmatched_ids
	 * @param array<int, int>                             $conflict_ids
	 * @param array<int, array<int, array<string, mixed>>> $cohort_rosters
	 * @param array<int, array<string, mixed>>            $explorers
	 * @return array<int, array<string, mixed>>
	 */
	private function build_batch_warnings(
		array $unmatched_ids,
		array $conflict_ids,
		array $cohort_rosters,
		array $explorers
	): array {
		$warnings = array();

		if ( ! empty( $unmatched_ids ) ) {
			$warnings[] = array(
				'code'                  => 'unmatched_osm',
				'severity'              => 'warning',
				'message'               => sprintf( '%d explorer(s) are not yet matched to an OSM record (scout_id = 0).', count( $unmatched_ids ) ),
				'count'                 => count( $unmatched_ids ),
				'expedition_signup_ids' => $unmatched_ids,
			);
		}

		if ( ! empty( $conflict_ids ) ) {
			$warnings[] = array(
				'code'                  => 'date_conflict_pending',
				'severity'              => 'warning',
				'message'               => sprintf( '%d explorer(s) have a pending date conflict.', count( $conflict_ids ) ),
				'count'                 => count( $conflict_ids ),
				'expedition_signup_ids' => $conflict_ids,
			);
		}

		// Map earliest expedition date and display label per team for readiness checks.
		$cohort_exp_dates = array();
		$team_labels      = array();
		foreach ( $explorers as $exp ) {
			$cid = (int) ( $exp['cohort_id'] ?? 0 );
			if ( $cid <= 0 ) {
				continue;
			}
			if ( empty( $team_labels[ $cid ] ) && ! empty( $exp['cohort_name'] ) ) {
				$team_labels[ $cid ] = trim( (string) $exp['cohort_name'] );
			}
			foreach ( array( (int) ( $exp['practice_expedition_id'] ?? 0 ), (int) ( $exp['qualifier_expedition_id'] ?? 0 ) ) as $eid ) {
				if ( $eid > 0 ) {
					$start = trim( (string) get_post_meta( $eid, 'ems_start_date', true ) );
					if ( $start !== '' && ( ! isset( $cohort_exp_dates[ $cid ] ) || $start < $cohort_exp_dates[ $cid ] ) ) {
						$cohort_exp_dates[ $cid ] = $start;
					}
				}
			}
		}

		foreach ( $cohort_rosters as $cid => $members ) {
			$size            = count( $members );
			$first_aid_count = 0;
			$exp_date        = $cohort_exp_dates[ $cid ] ?? null;
			$team_label      = ! empty( $team_labels[ $cid ] ) ? "Team {$team_labels[ $cid ]}" : sprintf( 'Team #%d', $cid );

			foreach ( $members as $member ) {
				$fa_level = strtolower( trim( (string) $member['resolved_first_aid_level'] ) );
				if ( $fa_level === '' || $fa_level === 'none' ) {
					continue;
				}
				$expiry = $member['first_aid_expiry'];
				if ( $exp_date !== null && $expiry !== null && $expiry !== '' && $expiry < $exp_date ) {
					continue;
				}
				++$first_aid_count;
			}

			if ( $size < 4 || $size > 7 ) {
				$warnings[] = array(
					'code'      => 'team_size_out_of_range',
					'severity'  => 'warning',
					'message'   => sprintf( '%s has %d member(s), outside the DofE 4–7 range.', $team_label, $size ),
					'cohort_id' => $cid,
					'count'     => $size,
				);
			}

			if ( $first_aid_count < 2 ) {
				$warnings[] = array(
					'code'      => 'insufficient_first_aid',
					'severity'  => 'warning',
					'message'   => sprintf( '%s has only %d valid First Aider(s) (minimum 2 required).', $team_label, $first_aid_count ),
					'cohort_id' => $cid,
					'count'     => $first_aid_count,
				);
			}
		}

		return $warnings;
	}

	private function mark_team_members_notified(
		object $wpdb,
		string $team_table,
		int $expedition_signup_id,
		?int $cohort_id,
		string $notified_at,
		int $notified_by
	): void {
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$team_table}
				SET notification_status = 'notified',
					notified_at = %s,
					notified_by = %d,
					offer_status = 'pending',
					offer_query_message = NULL,
					offer_responded_at = NULL,
					offer_responded_by = NULL
				WHERE expedition_signup_id = %d",
				$notified_at,
				$notified_by,
				$expedition_signup_id
			)
		);
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function resolve_acceptance_deadline( array $params ): string {
		$override = trim( (string) ( $params['acceptance_deadline'] ?? '' ) );
		if ( $override !== '' ) {
			return sanitize_text_field( $override );
		}

		$saved = trim( (string) get_option( Email_Template_Registry::OPTION_ACCEPTANCE_DEADLINE, '' ) );
		return $saved !== '' ? $saved : Email_Template_Registry::DEFAULT_ACCEPTANCE_DEADLINE;
	}

	/**
	 * @param mixed $raw
	 * @return array<int, int>
	 */
	private function sanitize_id_list( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		return array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $raw ),
					static fn( int $v ): bool => $v > 0
				)
			)
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $explorers
	 * @return array<int, int>
	 */
	private function extract_cohort_ids( array $explorers ): array {
		$ids = array();
		foreach ( $explorers as $exp ) {
			$cid = (int) ( $exp['cohort_id'] ?? 0 );
			if ( $cid > 0 ) {
				$ids[ $cid ] = $cid;
			}
		}
		return array_values( $ids );
	}
}
