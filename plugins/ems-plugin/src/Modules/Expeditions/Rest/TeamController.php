<?php
declare(strict_types=1);

namespace EMS\Modules\Expeditions\Rest;

use EMS\Data\Expedition_Repository;
use EMS\Data\Team_Member_Repository;
use EMS\Data\Team_Repository;
use EMS\Modules\Expeditions\Services\TeamReadinessService;
use EMS\Modules\Notifications\Services\ExpeditionOfferDispatcher;
use EMS\Modules\Signups\Services\SignupStatusTransitionService;

/**
 * REST Controller for Event-Team Builder, Team Readiness, Offer Queries, and Notifications.
 */
class TeamController {

	private const NAMESPACE = 'ems/v1';

	private Expedition_Repository $expeditions;
	private Team_Repository $teams;
	private Team_Member_Repository $team_members;
	private ?SignupStatusTransitionService $transitions;

	public function __construct(
		?Expedition_Repository $expeditions = null,
		?Team_Repository $teams = null,
		?Team_Member_Repository $team_members = null,
		?SignupStatusTransitionService $transitions = null
	) {
		$this->expeditions  = $expeditions ?? new Expedition_Repository();
		$this->teams        = $teams ?? new Team_Repository();
		$this->team_members = $team_members ?? new Team_Member_Repository();
		$this->transitions  = $transitions;
	}

	private function get_transitions(): SignupStatusTransitionService {
		if ( $this->transitions === null ) {
			$this->transitions = new SignupStatusTransitionService();
		}
		return $this->transitions;
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/teams-builder',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_teams' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/teams',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_team' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/teams/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_team' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array( 'id' => $this->id_arg() ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/teams/(?P<id>\d+)/members',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'add_team_members' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array( 'id' => $this->id_arg() ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/teams/(?P<id>\d+)/members/(?P<expedition_signup_id>\d+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'remove_team_member' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id'                   => $this->id_arg(),
					'expedition_signup_id' => $this->id_arg(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/teams/(?P<id>\d+)/copy-to-event',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'copy_team_to_event' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array( 'id' => $this->id_arg() ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/teams/resolve-query',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'resolve_member_query' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/teams-readiness',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_teams_readiness' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/expeditions/teams-readiness',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_teams_readiness' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/notifications/expedition-offers/preview',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'preview_notifications' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/notifications/expedition-offers/dispatch',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'dispatch_notifications' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/expeditions/notifications/preview',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'preview_notifications' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/expeditions/notifications/dispatch',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'dispatch_notifications' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	public function list_teams( \WP_REST_Request $request ): \WP_REST_Response {
		$filter_level     = strtolower( sanitize_text_field( (string) ( $request->get_param( 'dofe_level' ) ?? '' ) ) );
		$filter_transport = $this->normalize_transport( (string) ( $request->get_param( 'transport_mode' ) ?? '' ) );

		$team_posts = get_posts(
			array(
				'post_type'      => 'team',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		if ( ! is_array( $team_posts ) || empty( $team_posts ) ) {
			return new \WP_REST_Response( array(), 200 );
		}

		global $wpdb;
		$tm_table        = $wpdb->prefix . 'ems_team_members';
		$signups_table   = $wpdb->prefix . 'ems_expedition_signups';
		$explorers_table = $wpdb->prefix . 'ems_osm_explorers';

		$results = array();
		foreach ( $team_posts as $tp ) {
			$tid       = (int) $tp->ID;
			$team_code = trim( (string) get_post_meta( $tid, 'ems_team_code', true ) );
			if ( strtoupper( $team_code ) === 'UNALLOCATED' || strtoupper( trim( (string) $tp->post_title ) ) === 'UNALLOCATED' ) {
				continue;
			}

			$exp_id = (int) $tp->post_parent;
			if ( $exp_id <= 0 ) {
				continue;
			}

			$ev_level     = strtolower( trim( (string) get_post_meta( $exp_id, 'ems_level', true ) ) );
			$ev_transport = $this->normalize_transport( (string) ( get_post_meta( $exp_id, 'ems_transport', true ) ?: 'foot' ) );
			$ev_type      = strtolower( trim( (string) get_post_meta( $exp_id, 'ems_type', true ) ) );
			$stage        = ( $ev_type === 'practice' ) ? 'practice' : 'qualifier';

			if ( $filter_level !== '' && $ev_level !== $filter_level && $ev_level !== 'multiple' ) {
				continue;
			}
			if ( $filter_transport !== '' && $ev_transport !== $filter_transport ) {
				continue;
			}

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT tm.id, tm.team_post_id, tm.expedition_signup_id,
						COALESCE(NULLIF(s.scout_id, 0), tm.scout_id) AS scout_id,
						tm.availability_override, tm.notification_status, tm.offer_status, tm.offer_query_message, tm.offer_responded_at,
						s.unit_id, s.unit_name, s.dofe_level, s.first_aid_status, s.first_aid_expiry,
						s.expedition_preferences, s.additional_support_needs, s.asn_validated, s.explorer_email, s.parent_email,
						COALESCE(NULLIF(e.first_name, ''), s.explorer_first_name, '') AS first_name,
						COALESCE(NULLIF(e.last_name, ''), s.explorer_last_name, '') AS last_name,
						e.first_aid_level, e.wp_user_id
					FROM {$tm_table} tm
					LEFT JOIN {$signups_table} s ON tm.expedition_signup_id = s.id
					LEFT JOIN {$explorers_table} e ON (COALESCE(NULLIF(s.scout_id, 0), tm.scout_id) > 0 AND COALESCE(NULLIF(s.scout_id, 0), tm.scout_id) = e.scout_id)
					WHERE tm.team_post_id = %d
					ORDER BY tm.id ASC",
					$tid
				),
				ARRAY_A
			) ?: array();

			$members = array();
			foreach ( $rows as $r ) {
				$members[] = array(
					'id'                       => (int) $r['id'],
					'team_post_id'             => $tid,
					'expedition_signup_id'     => (int) ( $r['expedition_signup_id'] ?? 0 ),
					'scout_id'                 => (int) ( $r['scout_id'] ?? 0 ),
					'availability_override'    => $r['availability_override'] ?? null,
					'notification_status'      => (string) ( $r['notification_status'] ?? 'draft' ),
					'offer_status'             => (string) ( $r['offer_status'] ?? 'pending' ),
					'offer_query_message'      => $r['offer_query_message'] ?? null,
					'offer_responded_at'       => $r['offer_responded_at'] ?? null,
					'unit_id'                  => isset( $r['unit_id'] ) ? (int) $r['unit_id'] : null,
					'unit_name'                => $r['unit_name'] ?? null,
					'first_name'               => (string) ( $r['first_name'] ?? '' ),
					'last_name'                => (string) ( $r['last_name'] ?? '' ),
					'explorer_email'           => (string) ( $r['explorer_email'] ?? '' ),
					'parent_email'             => $r['parent_email'] ?? null,
					'dofe_level'               => $ev_level,
					'expedition_preferences'   => $r['expedition_preferences'] ?? null,
					'additional_support_needs' => $r['additional_support_needs'] ?? null,
					'asn_validated'            => isset( $r['asn_validated'] ) ? (int) $r['asn_validated'] : null,
					'first_aid_status'         => (string) ( $r['first_aid_status'] ?? 'none' ),
					'first_aid_expiry'         => $r['first_aid_expiry'] ?? null,
					'first_aid_level'          => $r['first_aid_level'] ?? null,
					'wp_user_id'               => isset( $r['wp_user_id'] ) ? (int) $r['wp_user_id'] : null,
				);
			}

			$results[] = array(
				'id'               => $tid,
				'team_post_id'     => $tid,
				'name'             => $team_code !== '' ? $team_code : (string) $tp->post_title,
				'team_code'        => $team_code !== '' ? $team_code : (string) $tp->post_title,
				'expedition_id'    => $exp_id,
				'expedition_code'  => (string) ( get_post_meta( $exp_id, 'ems_event_code', true ) ?: get_post_meta( $exp_id, 'ems_expedition_code', true ) ?: '' ),
				'expedition_title' => (string) get_the_title( $exp_id ),
				'start_date'       => (string) get_post_meta( $exp_id, 'ems_start_date', true ),
				'end_date'         => (string) get_post_meta( $exp_id, 'ems_end_date', true ),
				'stage'            => $stage,
				'dofe_level'       => $ev_level,
				'transport_mode'   => $ev_transport,
				'members'          => $members,
				'member_count'     => count( $members ),
			);
		}

		return new \WP_REST_Response( $results, 200 );
	}

	public function create_team( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body          = $this->get_request_body( $request );
		$expedition_id = (int) ( $body['expedition_id'] ?? 0 );
		$team_code     = sanitize_text_field( (string) ( $body['team_code'] ?? '' ) );

		if ( $expedition_id <= 0 ) {
			return $this->error( 'ems_invalid_expedition', 'expedition_id is required.', 400 );
		}

		$event = $this->expeditions->get_by_id( $expedition_id );
		if ( ! $event ) {
			return $this->error( 'ems_event_not_found', 'Expedition not found.', 404 );
		}

		$event_code = (string) ( $event['ems_event_code'] ?? $event['ems_expedition_code'] ?? '' );
		$new_id     = $this->teams->create( $expedition_id, $event_code, $team_code !== '' ? $team_code : null );

		return new \WP_REST_Response(
			array(
				'id'           => $new_id,
				'team_post_id' => $new_id,
				'name'         => (string) get_post_meta( $new_id, 'ems_team_code', true ) ?: (string) get_the_title( $new_id ),
			),
			201
		);
	}

	public function delete_team( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$team_post_id = absint( $request->get_param( 'id' ) );
		$post         = get_post( $team_post_id );
		if ( ! $post || $post->post_type !== 'team' ) {
			return $this->error( 'ems_team_not_found', 'Team not found.', 404 );
		}

		global $wpdb;
		$tm_table = $wpdb->prefix . 'ems_team_members';
		$members  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT scout_id, expedition_signup_id FROM {$tm_table} WHERE team_post_id = %d",
				$team_post_id
			),
			ARRAY_A
		) ?: array();

		$wpdb->delete( $tm_table, array( 'team_post_id' => $team_post_id ), array( '%d' ) );
		wp_trash_post( $team_post_id );

		foreach ( $members as $m ) {
			$this->get_transitions()->on_team_member_removed(
				(int) ( $m['expedition_signup_id'] ?? 0 ),
				(int) ( $m['scout_id'] ?? 0 )
			);
		}

		return new \WP_REST_Response( array( 'deleted' => true, 'id' => $team_post_id ), 200 );
	}

	public function add_team_members( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$team_post_id = absint( $request->get_param( 'id' ) );
		$post         = get_post( $team_post_id );
		if ( ! $post || $post->post_type !== 'team' ) {
			return $this->error( 'ems_team_not_found', 'Team not found.', 404 );
		}

		$body       = $this->get_request_body( $request );
		$signup_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $body['expedition_signup_ids'] ?? array() ) ) ) ) );
		if ( empty( $signup_ids ) ) {
			return $this->error( 'ems_missing_members', 'expedition_signup_ids is required.', 400 );
		}

		global $wpdb;
		$tm_table = $wpdb->prefix . 'ems_team_members';
		$added    = 0;
		$user_id  = (int) get_current_user_id();

		foreach ( $signup_ids as $signup_id ) {
			$signup = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, scout_id FROM {$wpdb->prefix}ems_expedition_signups WHERE id = %d LIMIT 1",
					$signup_id
				),
				ARRAY_A
			);
			if ( ! $signup ) {
				continue;
			}
			$scout_id = (int) ( $signup['scout_id'] ?? 0 );
			$exists   = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$tm_table} WHERE team_post_id = %d AND (expedition_signup_id = %d OR (%d > 0 AND scout_id = %d)) LIMIT 1",
					$team_post_id,
					$signup_id,
					$scout_id,
					$scout_id
				)
			);
			if ( $exists ) {
				continue;
			}
			$wpdb->insert(
				$tm_table,
				array(
					'team_post_id'         => $team_post_id,
					'scout_id'             => $scout_id,
					'user_id'              => 0,
					'expedition_signup_id' => $signup_id,
					'notification_status'  => 'draft',
					'added_by'             => $user_id,
					'added_at'             => current_time( 'mysql', true ),
				),
				array( '%d', '%d', '%d', '%d', '%s', '%d', '%s' )
			);
			$this->get_transitions()->on_team_member_added( $team_post_id, $signup_id, $scout_id );
			++$added;
		}

		return new \WP_REST_Response( array( 'added' => $added ), 200 );
	}

	public function remove_team_member( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$team_post_id         = absint( $request->get_param( 'id' ) );
		$expedition_signup_id = absint( $request->get_param( 'expedition_signup_id' ) );

		global $wpdb;
		$tm_table = $wpdb->prefix . 'ems_team_members';
		$removed  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT scout_id, expedition_signup_id FROM {$tm_table} WHERE team_post_id = %d AND (expedition_signup_id = %d OR scout_id = %d)",
				$team_post_id,
				$expedition_signup_id,
				$expedition_signup_id
			),
			ARRAY_A
		) ?: array();

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$tm_table} WHERE team_post_id = %d AND (expedition_signup_id = %d OR scout_id = %d)",
				$team_post_id,
				$expedition_signup_id,
				$expedition_signup_id
			)
		);

		if ( (int) $deleted > 0 ) {
			foreach ( $removed as $r ) {
				$this->get_transitions()->on_team_member_removed(
					(int) ( $r['expedition_signup_id'] ?? 0 ),
					(int) ( $r['scout_id'] ?? 0 )
				);
			}
		}

		return new \WP_REST_Response( array( 'removed' => (int) $deleted > 0 ), 200 );
	}

	public function copy_team_to_event( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$source_team_id       = absint( $request->get_param( 'id' ) );
		$body                 = $this->get_request_body( $request );
		$target_expedition_id = (int) ( $body['target_expedition_id'] ?? 0 );
		$team_code            = sanitize_text_field( (string) ( $body['team_code'] ?? '' ) );

		if ( $target_expedition_id <= 0 ) {
			return $this->error( 'ems_invalid_expedition', 'target_expedition_id is required.', 400 );
		}

		$event = $this->expeditions->get_by_id( $target_expedition_id );
		if ( ! $event ) {
			return $this->error( 'ems_event_not_found', 'Target expedition not found.', 404 );
		}

		$event_code  = (string) ( $event['ems_event_code'] ?? $event['ems_expedition_code'] ?? '' );
		$new_team_id = $this->teams->create( $target_expedition_id, $event_code, $team_code !== '' ? $team_code : null );

		global $wpdb;
		$tm_table = $wpdb->prefix . 'ems_team_members';
		$members  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT scout_id, user_id, expedition_signup_id FROM {$tm_table} WHERE team_post_id = %d ORDER BY id ASC",
				$source_team_id
			),
			ARRAY_A
		) ?: array();

		$copied  = 0;
		$user_id = (int) get_current_user_id();
		foreach ( $members as $m ) {
			$wpdb->insert(
				$tm_table,
				array(
					'team_post_id'         => $new_team_id,
					'scout_id'             => (int) ( $m['scout_id'] ?? 0 ),
					'user_id'              => (int) ( $m['user_id'] ?? 0 ),
					'expedition_signup_id' => ! empty( $m['expedition_signup_id'] ) ? (int) $m['expedition_signup_id'] : null,
					'notification_status'  => 'draft',
					'added_by'             => $user_id,
					'added_at'             => current_time( 'mysql', true ),
				),
				array( '%d', '%d', '%d', '%d', '%s', '%d', '%s' )
			);
			$this->get_transitions()->on_team_member_added(
				$new_team_id,
				(int) ( $m['expedition_signup_id'] ?? 0 ),
				(int) ( $m['scout_id'] ?? 0 )
			);
			++$copied;
		}

		return new \WP_REST_Response(
			array(
				'created'      => true,
				'team_post_id' => $new_team_id,
				'name'         => (string) get_post_meta( $new_team_id, 'ems_team_code', true ) ?: (string) get_the_title( $new_team_id ),
				'copied_count' => $copied,
			),
			201
		);
	}

	public function resolve_member_query( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body                 = $this->get_request_body( $request );
		$expedition_signup_id = (int) ( $body['expedition_signup_id'] ?? 0 );
		$team_post_id         = (int) ( $body['team_post_id'] ?? 0 );
		$scout_id             = (int) ( $body['scout_id'] ?? 0 );
		$reply_message        = sanitize_textarea_field( (string) ( $body['reply_message'] ?? '' ) );
		$new_status           = sanitize_key( (string) ( $body['new_status'] ?? 'pending' ) );
		if ( ! in_array( $new_status, array( 'pending', 'accepted' ), true ) ) {
			$new_status = 'pending';
		}

		global $wpdb;
		$tm_table = $wpdb->prefix . 'ems_team_members';
		if ( $expedition_signup_id > 0 ) {
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$tm_table} SET offer_status = %s, offer_query_message = NULL WHERE expedition_signup_id = %d",
					$new_status,
					$expedition_signup_id
				)
			);
		} elseif ( $team_post_id > 0 && $scout_id > 0 ) {
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$tm_table} SET offer_status = %s, offer_query_message = NULL WHERE team_post_id = %d AND scout_id = %d",
					$new_status,
					$team_post_id,
					$scout_id
				)
			);
		} else {
			return $this->error( 'ems_invalid_params', 'Missing expedition_signup_id or team_post_id/scout_id.', 400 );
		}

		if ( $reply_message !== '' && $expedition_signup_id > 0 && function_exists( 'wp_mail' ) ) {
			$signup = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT scout_id, explorer_email, parent_email, explorer_first_name, explorer_last_name FROM {$wpdb->prefix}ems_expedition_signups WHERE id = %d",
					$expedition_signup_id
				),
				ARRAY_A
			);
			if ( $signup ) {
				$candidates = array(
					(string) ( $signup['explorer_email'] ?? '' ),
					(string) ( $signup['parent_email'] ?? '' ),
				);
				$s_scout_id = (int) ( $signup['scout_id'] ?? 0 );
				if ( $s_scout_id > 0 ) {
					$osm_row = $wpdb->get_row(
						$wpdb->prepare(
							"SELECT email1, email2, p1_email1, p1_email2, p2_email1, p2_email2 FROM {$wpdb->prefix}ems_osm_explorers WHERE scout_id = %d LIMIT 1",
							$s_scout_id
						),
						ARRAY_A
					);
					if ( is_array( $osm_row ) ) {
						$candidates = array_merge(
							array(
								(string) ( $osm_row['email1'] ?? '' ),
								(string) ( $osm_row['email2'] ?? '' ),
								(string) ( $osm_row['p1_email1'] ?? '' ),
								(string) ( $osm_row['p1_email2'] ?? '' ),
								(string) ( $osm_row['p2_email1'] ?? '' ),
								(string) ( $osm_row['p2_email2'] ?? '' ),
							),
							$candidates
						);
					}
				}
				$to = array();
				foreach ( $candidates as $cand ) {
					$clean = sanitize_email( $cand );
					if ( $clean !== '' && ! isset( $to[ strtolower( $clean ) ] ) ) {
						$to[ strtolower( $clean ) ] = $clean;
					}
				}
				$to = array_values( $to );
				if ( ! empty( $to ) ) {
					$name    = trim( ( $signup['explorer_first_name'] ?? '' ) . ' ' . ( $signup['explorer_last_name'] ?? '' ) );
					$subject = 'Re: Your DofE Expedition Place Offer Query';
					$body_tx = "Hi {$name},\n\n{$reply_message}\n\nYou can now log back into the Explorer Portal to respond to your Place Offer.\n";
					wp_mail( $to, $subject, $body_tx );
				}
			}
		}

		return new \WP_REST_Response(
			array(
				'resolved'     => true,
				'new_status'   => $new_status,
				'rows_updated' => (int) $updated,
			),
			200
		);
	}

	public function get_teams_readiness( \WP_REST_Request $request ): \WP_REST_Response {
		$filters = array();
		foreach ( array( 'dofe_level', 'transport_mode', 'stage' ) as $key ) {
			$val = $request->get_param( $key );
			if ( is_string( $val ) && $val !== '' ) {
				$filters[ $key ] = sanitize_text_field( $val );
			}
		}
		$exp_id = absint( $request->get_param( 'expedition_id' ) );
		if ( $exp_id > 0 ) {
			$filters['expedition_id'] = $exp_id;
		}

		$readiness = new TeamReadinessService();
		return new \WP_REST_Response( $readiness->getReadiness( $filters ), 200 );
	}

	public function preview_notifications( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$dispatcher = new ExpeditionOfferDispatcher();
		try {
			$result = $dispatcher->previewBatch( $this->get_request_body( $request ) );
			return new \WP_REST_Response( $result, 200 );
		} catch ( \InvalidArgumentException $e ) {
			return $this->error( 'ems_invalid_notification_request', $e->getMessage(), 400 );
		}
	}

	public function dispatch_notifications( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$dispatcher = new ExpeditionOfferDispatcher();
		try {
			$result = $dispatcher->dispatchBatch( $this->get_request_body( $request ) );
			return new \WP_REST_Response( $result, 200 );
		} catch ( \InvalidArgumentException $e ) {
			return $this->error( 'ems_invalid_notification_request', $e->getMessage(), 400 );
		}
	}

	private function normalize_transport( string $raw ): string {
		$norm = strtolower( trim( $raw ) );
		if ( $norm === 'walking' || $norm === 'hillwalking' ) {
			return 'foot';
		}
		if ( $norm === 'canoe' || $norm === 'paddle' ) {
			return 'paddling';
		}
		if ( $norm === 'bike' || $norm === 'cycling' ) {
			return 'biking';
		}
		return $norm;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function get_request_body( \WP_REST_Request $request ): array {
		$json = $request->get_json_params();
		if ( is_array( $json ) && ! empty( $json ) ) {
			return $json;
		}
		$params = $request->get_params();
		return is_array( $params ) ? $params : array();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function id_arg(): array {
		return array(
			'type'              => 'integer',
			'required'          => true,
			'sanitize_callback' => 'absint',
		);
	}

	private function error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
