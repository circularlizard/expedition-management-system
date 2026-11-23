<?php
namespace EMS\Modules\Expeditions;

use EMS\Data\Season_Repository;
use EMS\Data\Expedition_Repository;
use EMS\Data\Team_Repository;
use EMS\Data\Team_Member_Repository;
use EMS\Data\OSM_Explorer_Repository;
use EMS\Data\OSM_Event_Repository;
use EMS\Data\Signup_Repository;
use EMS\Core\CPT_Registry;
use EMS\Modules\Signups\Services\SignupStatusTransitionService;

class Expedition_Admin_Controller {

	private Expedition_Repository $expeditions;
	private Team_Repository $teams;
	private Team_Member_Repository $team_members;
	private OSM_Explorer_Repository $explorers;
	private OSM_Event_Repository $osm_events;
	private Signup_Repository $signups;
	private ?SignupStatusTransitionService $transitions = null;

	public function __construct(
		Expedition_Repository $expeditions,
		Team_Repository $teams,
		Team_Member_Repository $team_members,
		?OSM_Explorer_Repository $explorers = null,
		?OSM_Event_Repository $osm_events = null,
		?Signup_Repository $signups = null,
		?SignupStatusTransitionService $transitions = null
	) {
		$this->expeditions  = $expeditions;
		$this->teams        = $teams;
		$this->team_members = $team_members;
		$this->explorers    = $explorers ?: new OSM_Explorer_Repository();
		$this->osm_events   = $osm_events ?: new OSM_Event_Repository();
		$this->signups      = $signups ?: new Signup_Repository();
		$this->transitions  = $transitions;
	}

	private function get_transitions(): SignupStatusTransitionService {
		if ( $this->transitions === null ) {
			$this->transitions = new SignupStatusTransitionService( $this->signups );
		}
		return $this->transitions;
	}

	public function register_routes(): void {
		$this->register_event_routes();
		$this->register_team_routes();
		$this->register_member_routes();
		$this->register_osm_event_route();
		$this->register_board_route();
		$this->register_signup_routes();
		$this->register_whatsapp_routes();
		$this->register_planning_routes();
	}

	private function register_event_routes(): void {
		register_rest_route(
			'ems/v1',
			'/events',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_events' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'ems/v1',
			'/events',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_event' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'ems/v1',
			'/events/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_event' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'ems/v1',
			'/events/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_event' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	private function register_team_routes(): void {
		register_rest_route(
			'ems/v1',
			'/events/(?P<event_id>\d+)/teams',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_team' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'event_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'ems/v1',
			'/events/(?P<event_id>\d+)/teams',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_event_teams' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'event_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'ems/v1',
			'/teams/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_team' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'ems/v1',
			'/teams/(?P<id>\d+)/move',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'move_team' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'ems/v1',
			'/teams/(?P<id>\d+)/duplicate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'duplicate_team' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	private function register_member_routes(): void {
		register_rest_route(
			'ems/v1',
			'/teams/(?P<team_id>\d+)/members',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'add_member' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'team_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'ems/v1',
			'/teams/(?P<team_id>\d+)/members/(?P<scout_id>\d+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'remove_member' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'team_id'  => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'scout_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'ems/v1',
			'/explorers/(?P<scout_id>\d+)/move-team',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'move_explorer' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'scout_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'ems/v1',
			'/explorers/(?P<scout_id>\d+)/first-aid',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_first_aid_level' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'scout_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'ems/v1',
			'/explorers/(?P<scout_id>\d+)/asn',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_explorer_asn' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'scout_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'ems/v1',
			'/explorers/(?P<scout_id>\d+)/asn',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_explorer_asn' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'scout_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'ems/v1',
			'/explorers/(?P<scout_id>\d+)/profile',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_explorer_profile' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'scout_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	private function register_board_route(): void {
		register_rest_route(
			'ems/v1',
			'/expedition-board',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_board' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	private function register_osm_event_route(): void {
		register_rest_route(
			'ems/v1',
			'/osm-events',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_osm_events' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	public function create_event( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body  = $request->get_json_params() ?: array();
		$valid = $this->validate_event( $body );
		if ( is_wp_error( $valid ) ) {
			return $this->error( $valid->get_error_code(), $valid->get_error_message(), 400 );
		}

		try {
			$id = $this->expeditions->create( $body );
			$this->teams->create( $id, $body['ems_event_code'], 'UNALLOCATED' );
			return new \WP_REST_Response( $this->expeditions->get_by_id( $id ), 201 );
		} catch ( \InvalidArgumentException $e ) {
			return $this->error( 'ems_event_code_exists', $e->getMessage(), 409 );
		}
	}

	public function update_event( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id   = (int) $request->get_param( 'id' );
		$body = $request->get_json_params() ?: array();

		if ( ! $this->expeditions->get_by_id( $id ) ) {
			return $this->error( 'ems_event_not_found', 'Event not found.', 404 );
		}

		$valid = $this->validate_event( $body, false );
		if ( is_wp_error( $valid ) ) {
			return $this->error( $valid->get_error_code(), $valid->get_error_message(), 400 );
		}

		$this->expeditions->update( $id, $body );
		return new \WP_REST_Response( $this->expeditions->get_by_id( $id ) );
	}

	public function delete_event( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id = (int) $request->get_param( 'id' );

		if ( ! $this->expeditions->get_by_id( $id ) ) {
			return $this->error( 'ems_event_not_found', 'Event not found.', 404 );
		}

		if ( $this->expeditions->has_teams( $id ) ) {
			return $this->error( 'ems_event_has_teams', 'Cannot delete event with teams.', 409 );
		}

		$this->expeditions->delete( $id );
		return new \WP_REST_Response( array( 'deleted' => true ) );
	}

	public function create_team( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$event_id = (int) $request->get_param( 'event_id' );
		$event    = $this->expeditions->get_by_id( $event_id );

		if ( ! $event ) {
			return $this->error( 'ems_event_not_found', 'Event not found.', 404 );
		}

		$code = $event['ems_event_code'] ?? '';
		if ( empty( $code ) ) {
			return $this->error( 'ems_event_code_missing', 'Event has no code.', 500 );
		}

		try {
			$id = $this->teams->create( $event_id, $code );
			\EMS\Core\Audit_Logger::log( 'team_create' );
			return new \WP_REST_Response( $this->teams->get_by_id( $id ), 201 );
		} catch ( \RuntimeException $e ) {
			return $this->error( 'ems_team_creation_failed', $e->getMessage(), 500 );
		}
	}

	public function delete_team( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id = (int) $request->get_param( 'id' );

		$team = $this->teams->get_by_id( $id );
		if ( ! $team ) {
			return $this->error( 'ems_team_not_found', 'Team not found.', 404 );
		}

		$members = $this->team_members->list_by_team( $id );
		if ( ! empty( $members ) ) {
			return $this->error( 'ems_team_has_members', 'Cannot delete team with members.', 409 );
		}

		$this->teams->delete( $id );
		$this->teams->renumber_event( $team['event_id'] );
		\EMS\Core\Audit_Logger::log( 'team_delete' );
		return new \WP_REST_Response( array( 'deleted' => true ) );
	}

	public function move_team( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id        = (int) $request->get_param( 'id' );
		$body      = $request->get_json_params() ?: array();
		$target_id = (int) ( $body['target_event_id'] ?? 0 );

		$team   = $this->teams->get_by_id( $id );
		$target = $this->expeditions->get_by_id( $target_id );

		if ( ! $team ) {
			return $this->error( 'ems_team_not_found', 'Team not found.', 404 );
		}
		if ( ! $target ) {
			return $this->error( 'ems_event_not_found', 'Target event not found.', 404 );
		}

		if ( $this->event_type( $team['event_id'] ) !== $this->event_type( $target_id ) ) {
			return $this->error( 'ems_incompatible_event_type', 'Cannot move between different event types.', 422 );
		}

		try {
			$this->teams->move( $id, $target_id, $target['ems_event_code'] );
			return new \WP_REST_Response( $this->teams->get_by_id( $id ) );
		} catch ( \InvalidArgumentException $e ) {
			return $this->error( 'ems_team_already_in_event', $e->getMessage(), 409 );
		}
	}

	public function duplicate_team( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id        = (int) $request->get_param( 'id' );
		$body      = $request->get_json_params() ?: array();
		$target_id = (int) ( $body['target_event_id'] ?? 0 );

		$team   = $this->teams->get_by_id( $id );
		$target = $this->expeditions->get_by_id( $target_id );

		if ( ! $team ) {
			return $this->error( 'ems_team_not_found', 'Team not found.', 404 );
		}
		if ( ! $target ) {
			return $this->error( 'ems_event_not_found', 'Target event not found.', 404 );
		}

		try {
			$new_id  = $this->teams->duplicate( $id, $target_id, $target['ems_event_code'] );
			$members = $this->team_members->list_by_team( $new_id );
			foreach ( $members as $member ) {
				$this->explorers->touch_last_local_update( (int) $member['scout_id'] );
			}
			return new \WP_REST_Response( $this->teams->get_by_id( $new_id ), 201 );
		} catch ( \RuntimeException $e ) {
			return $this->error( 'ems_team_duplicate_failed', $e->getMessage(), 500 );
		}
	}

	public function add_member( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$team_id = (int) $request->get_param( 'team_id' );
		$body    = $request->get_json_params() ?: array();

		// Team Builder sends { expedition_signup_ids: [...] } to /teams/{id}/members.
		// Because this route shares the same path pattern as Rest\TeamController, delegate cleanly.
		if ( ! empty( $body['expedition_signup_ids'] ) || ! empty( $body['expedition_signup_id'] ) ) {
			if ( empty( $body['expedition_signup_ids'] ) && ! empty( $body['expedition_signup_id'] ) ) {
				$body['expedition_signup_ids'] = array( (int) $body['expedition_signup_id'] );
			}
			$request->set_param( 'id', $team_id );
			$team_controller = new Rest\TeamController(
				$this->expeditions,
				$this->teams,
				$this->team_members,
				$this->get_transitions()
			);
			return $team_controller->add_team_members( $request );
		}

		$scout_id = (int) ( $body['scout_id'] ?? 0 );

		if ( $scout_id === 0 ) {
			return $this->error( 'ems_invalid_scout_id', 'Cannot allocate unmatched explorer without a Scout ID.', 400 );
		}

		$team = $this->teams->get_by_id( $team_id );
		if ( ! $team ) {
			return $this->error( 'ems_team_not_found', 'Team not found.', 404 );
		}

		$explorer = $this->explorers->find_by_scout_id( $scout_id );
		if ( ! $explorer ) {
			return $this->error( 'ems_explorer_not_found', 'Explorer not found.', 404 );
		}

		$user_id = (int) ( $explorer['wp_user_id'] ?? 0 );

		try {
			$this->team_members->assign( $team_id, $scout_id, get_current_user_id(), $user_id );
			$this->explorers->touch_last_local_update( $scout_id );
			$this->get_transitions()->on_team_member_added( $team_id, 0, $scout_id );
			\EMS\Core\Audit_Logger::log( 'team_member_add', $scout_id );
			return new \WP_REST_Response( $this->hydrate_members( $this->team_members->list_by_team( $team_id ) ), 201 );
		} catch ( \InvalidArgumentException $e ) {
			return $this->error( 'ems_member_already_in_team', $e->getMessage(), 409 );
		} catch ( \RuntimeException $e ) {
			return $this->error( 'ems_database_error', $e->getMessage(), 500 );
		}
	}

	public function remove_member( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$team_id  = (int) $request->get_param( 'team_id' );
		$id_param = (int) $request->get_param( 'scout_id' );

		$team = $this->teams->get_by_id( $team_id );
		if ( ! $team ) {
			return $this->error( 'ems_team_not_found', 'Team not found.', 404 );
		}

		global $wpdb;
		$tm_table = $wpdb->prefix . 'ems_team_members';
		$matched  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, scout_id, expedition_signup_id FROM {$tm_table} WHERE team_post_id = %d AND (expedition_signup_id = %d OR (%d > 0 AND scout_id = %d))",
				$team_id,
				$id_param,
				$id_param,
				$id_param
			),
			ARRAY_A
		) ?: array();

		$level = strtolower( trim( (string) get_post_meta( (int) ( $team['post_parent'] ?? 0 ), 'ems_level', true ) ) );

		if ( empty( $matched ) ) {
			$this->team_members->remove( $team_id, $id_param );
			$this->explorers->touch_last_local_update( $id_param );
			$this->get_transitions()->on_team_member_removed( 0, $id_param, $level !== '' ? $level : null );
			\EMS\Core\Audit_Logger::log( 'team_member_remove', $id_param );
		} else {
			foreach ( $matched as $row ) {
				$esid = (int) ( $row['expedition_signup_id'] ?? 0 );
				$sid  = (int) ( $row['scout_id'] ?? 0 );
				if ( $esid > 0 ) {
					$this->team_members->remove_by_signup( $team_id, $esid );
				} elseif ( $sid > 0 ) {
					$this->team_members->remove( $team_id, $sid );
				}
				if ( $sid > 0 ) {
					$this->explorers->touch_last_local_update( $sid );
				}
				$this->get_transitions()->on_team_member_removed( $esid, $sid, $level !== '' ? $level : null );
				\EMS\Core\Audit_Logger::log( 'team_member_remove', $sid > 0 ? $sid : null );
			}
		}

		// The team may have been auto-deleted if that was its last member.
		if ( ! $this->teams->get_by_id( $team_id ) ) {
			return new \WP_REST_Response( array( 'team_deleted' => true, 'removed' => true ) );
		}

		return new \WP_REST_Response( $this->hydrate_members( $this->team_members->list_by_team( $team_id ) ) );
	}

	public function move_explorer( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$scout_id       = (int) $request->get_param( 'scout_id' );
		$body           = $request->get_json_params() ?: array();
		$target_team_id = (int) ( $body['target_team_id'] ?? 0 );

		$explorer = $this->explorers->find_by_scout_id( $scout_id );
		if ( ! $explorer ) {
			return $this->error( 'ems_explorer_not_found', 'Explorer not found.', 404 );
		}

		$user_id = (int) ( $explorer['wp_user_id'] ?? 0 );

		$target_team = $this->teams->get_by_id( $target_team_id );
		if ( ! $target_team ) {
			return $this->error( 'ems_team_not_found', 'Target team not found.', 404 );
		}

		// Find current team of the explorer in any event of the same type.
		$current_team_id = $this->find_current_team( $scout_id, $target_team['event_id'] );
		if ( ! $current_team_id ) {
			return $this->error( 'ems_explorer_not_in_team', 'Explorer is not assigned to a team in a compatible event.', 422 );
		}

		if ( $this->event_type( $current_team_id ) !== $this->event_type( $target_team_id ) ) {
			return $this->error( 'ems_incompatible_event_type', 'Cannot move between different event types.', 422 );
		}

		$this->team_members->move( $scout_id, $current_team_id, $target_team_id, get_current_user_id(), $user_id );
		$this->explorers->touch_last_local_update( $scout_id );
		\EMS\Core\Audit_Logger::log( 'team_member_move', $scout_id );
		return new \WP_REST_Response( $this->hydrate_members( $this->team_members->list_by_team( $target_team_id ) ) );
	}

	public function update_first_aid_level( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$scout_id = (int) $request->get_param( 'scout_id' );
		$body     = $request->get_json_params() ?: array();
		$level    = $body['first_aid_level'] ?? '';

		$allowed = array( 'none', 'first_response', 'full_first_aid' );
		if ( ! in_array( $level, $allowed, true ) ) {
			return $this->error( 'ems_invalid_first_aid_level', 'Invalid first aid level.', 400 );
		}

		if ( ! $this->explorers->find_by_scout_id( $scout_id ) ) {
			return $this->error( 'ems_explorer_not_found', 'Explorer not found.', 404 );
		}

		$updated = $this->explorers->update_first_aid_level( $scout_id, $level );
		if ( ! $updated ) {
			return $this->error( 'ems_first_aid_update_failed', 'Could not update first aid level. Try deactivating and reactivating the plugin to update the database schema.', 500 );
		}
		\EMS\Core\Audit_Logger::log( 'explorer_update', $scout_id );
		return new \WP_REST_Response(
			array(
				'scout_id'        => $scout_id,
				'first_aid_level' => $level,
			)
		);
	}

	public function get_board(): \WP_REST_Response {
		$events_data = $this->expeditions->list_all_chronological();
		$events      = array();

		foreach ( $events_data as $event ) {
			$teams = array();
			foreach ( $this->teams->list_by_expedition( $event['ID'] ) as $team ) {
				$members              = $this->team_members->list_by_team( $team['ID'] );
				$team['member_count'] = count( $members );
				$team['size_warning'] = $team['member_count'] < 4 || $team['member_count'] > 7;
				$team['members']      = $this->hydrate_members( $members );
				$teams[]              = $team;
			}
			$unallocated_team = $this->teams->get_unallocated_team( $event['ID'] );
			$pool_members     = $unallocated_team ? $this->team_members->list_by_team( (int) $unallocated_team['ID'] ) : array();
			$pool_count       = count( $pool_members );

			$event['teams']        = $teams;
			$event['pool_count']   = $pool_count;
			$event['member_count'] = array_sum( array_column( $teams, 'member_count' ) ) + $pool_count;
			$events[]              = $event;
		}

		$synthetic_season = array(
			'ID'                => 0,
			'post_title'        => 'All Events',
			'ems_season_year'   => '',
			'ems_season_status' => 'active',
			'events'            => $events,
		);

		return new \WP_REST_Response(
			array(
				'seasons'   => array( $synthetic_season ),
				'explorers' => $this->list_explorers(),
				'last_sync' => get_option( 'ems_osm_last_sync' ) ?: null,
			)
		);
	}

	public function list_events( \WP_REST_Request $request ): \WP_REST_Response {
		$tab              = $request->get_param( 'tab' ) ?: 'upcoming';
		$include_archived = (bool) $request->get_param( 'include_archived' );

		switch ( $tab ) {
			case 'past':
				$raw_events = $this->expeditions->list_past();
				break;
			case 'archived':
				$raw_events = $this->expeditions->list_all_chronological();
				break;
			case 'all':
				$raw_events = $this->expeditions->list_all_chronological();
				break;
			default:
				$raw_events = $this->expeditions->list_upcoming();
				break;
		}

		if ( $tab === 'archived' ) {
			$raw_events = array_values(
				array_filter(
					$raw_events,
					static function ( $e ) {
						return ( $e['ems_status'] ?? '' ) === 'archived';
					}
				)
			);
		} elseif ( ! $include_archived ) {
			$raw_events = array_values(
				array_filter(
					$raw_events,
					static function ( $e ) {
						return ( $e['ems_status'] ?? '' ) !== 'archived';
					}
				)
			);
		}

		$events = array();
		foreach ( $raw_events as $event ) {
			$teams = array();
			foreach ( $this->teams->list_by_expedition( $event['ID'] ) as $team ) {
				$members              = $this->team_members->list_by_team( $team['ID'] );
				$team['member_count'] = count( $members );
				$team['size_warning'] = $team['member_count'] < 4 || $team['member_count'] > 7;
				$teams[]              = $team;
			}
			$unallocated_team = $this->teams->get_unallocated_team( $event['ID'] );
			$pool_members     = $unallocated_team ? $this->team_members->list_by_team( (int) $unallocated_team['ID'] ) : array();
			$pool_count       = count( $pool_members );

			$event['teams']        = $teams;
			$event['pool_count']   = $pool_count;
			$event['member_count'] = array_sum( array_column( $teams, 'member_count' ) ) + $pool_count;
			$events[]              = $event;
		}

		return new \WP_REST_Response( array( 'events' => $events ) );
	}

	public function get_whatsapp_links( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id = (int) $request->get_param( 'id' );

		if ( ! $this->expeditions->get_by_id( $id ) ) {
			return $this->error( 'ems_event_not_found', 'Event not found.', 404 );
		}

		return new \WP_REST_Response(
			array(
				'explorer_link' => get_post_meta( $id, 'ems_expedition_whatsapp_explorers', true ) ?: null,
				'parent_link'   => get_post_meta( $id, 'ems_expedition_whatsapp_parents', true ) ?: null,
			)
		);
	}

	public function update_whatsapp_links( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id   = (int) $request->get_param( 'id' );
		$body = $request->get_json_params() ?: array();

		if ( ! $this->expeditions->get_by_id( $id ) ) {
			return $this->error( 'ems_event_not_found', 'Event not found.', 404 );
		}

		$explorer_link = esc_url_raw( $body['explorer_link'] ?? '' );
		$parent_link   = esc_url_raw( $body['parent_link'] ?? '' );

		update_post_meta( $id, 'ems_expedition_whatsapp_explorers', $explorer_link );
		update_post_meta( $id, 'ems_expedition_whatsapp_parents', $parent_link );

		return new \WP_REST_Response(
			array(
				'explorer_link' => $explorer_link ?: null,
				'parent_link'   => $parent_link ?: null,
			)
		);
	}

	public function list_osm_events(): \WP_REST_Response {
		$events = array();
		foreach ( $this->osm_events->list_all() as $row ) {
			$events[] = array(
				'id'         => (int) ( $row['id'] ?? 0 ),
				'event_id'   => (int) ( $row['event_id'] ?? 0 ),
				'section_id' => (int) ( $row['section_id'] ?? 0 ),
				'name'       => $row['name'] ?? '',
				'start_date' => $row['start_date'] ?? null,
				'end_date'   => $row['end_date'] ?? null,
				'location'   => $row['location'] ?? '',
			);
		}
		return new \WP_REST_Response( $events );
	}

	private function validate_event( array $data, bool $require_all = true ): bool|\WP_Error {
		$valid_enums = array(
			'ems_type'            => array( 'training', 'practice', 'qualifying' ),
			'ems_transport'       => array( 'hillwalking', 'biking', 'paddling' ),
			'ems_level'           => array( 'bronze', 'silver', 'gold', 'multiple' ),
			'ems_first_aid_level' => array( 'none', 'first_response', 'full_first_aid' ),
			'ems_status'          => array( 'active', 'archived' ),
			'ems_route_status'    => array( 'draft', 'confirmed' ),
		);

		if ( $require_all ) {
			$required = array( 'ems_event_code', 'ems_type', 'ems_transport', 'ems_level', 'ems_start_date', 'ems_end_date' );
			foreach ( $required as $key ) {
				if ( empty( $data[ $key ] ) ) {
					return new \WP_Error( 'ems_missing_required_field', "Missing required field: {$key}." );
				}
			}
		}

		foreach ( $valid_enums as $key => $values ) {
			if ( ! empty( $data[ $key ] ) && ! in_array( $data[ $key ], $values, true ) ) {
				return new \WP_Error( 'ems_invalid_field_value', "Invalid value for {$key}." );
			}
		}

		return true;
	}

	private function event_type( int $event_id ): string {
		$event = $this->expeditions->get_by_id( $event_id );
		return $event['ems_type'] ?? '';
	}

	private function find_current_team( int $scout_id, int $target_event_id ): int {
		$target_type = $this->event_type( $target_event_id );
		$all_events  = $this->expeditions->list_all();

		foreach ( $all_events as $event ) {
			if ( $event['ems_type'] !== $target_type ) {
				continue;
			}
			foreach ( $this->teams->list_by_expedition( $event['ID'] ) as $team ) {
				$members = $this->team_members->list_by_team( $team['ID'] );
				foreach ( $members as $member ) {
					if ( (int) ( $member['scout_id'] ?? 0 ) === $scout_id ) {
						return (int) $team['ID'];
					}
				}
			}
		}

		return 0;
	}

	private function hydrate_members( array $members ): array {
		$hydrated = array();
		foreach ( $members as $member ) {
			$member['scout_id'] = (int) ( $member['scout_id'] ?? 0 );
			$explorer           = $this->explorers->find_by_scout_id( $member['scout_id'] );
			if ( $explorer ) {
				$member['first_name']      = $explorer['first_name'] ?? '';
				$member['last_name']       = $explorer['last_name'] ?? '';
				$member['patrol']          = $explorer['patrol'] ?? '';
				$member['first_aid_level'] = $explorer['first_aid_level'] ?? 'none';
				$member['has_asn']         = ! empty( $explorer['additional_support_needs'] ) || $this->has_signup_asn( $member['scout_id'] );
			}
			$hydrated[] = $member;
		}
		return $hydrated;
	}

	private function has_signup_asn( int $scout_id ): bool {
		return $this->signups->has_additional_support_needs( $scout_id );
	}

	private function list_explorers(): array {
		$explorers = array();
		foreach ( $this->explorers->list_all() as $row ) {
			$explorers[] = array(
				'scout_id'             => (int) ( $row['scout_id'] ?? 0 ),
				'first_name'           => $row['first_name'] ?? '',
				'last_name'            => $row['last_name'] ?? '',
				'patrol'               => $row['patrol'] ?? '',
				'first_aid_level'      => $row['first_aid_level'] ?? 'none',
				'synced_at'            => $row['synced_at'] ?: null,
				'last_local_update_at' => $row['last_local_update_at'] ?: null,
			);
		}
		return $explorers;
	}

	public function get_explorers_roster( \WP_REST_Request $request ): \WP_REST_Response {
		$explorers = array();
		foreach ( $this->explorers->list_all() as $row ) {
			$explorers[] = array(
				'scout_id'             => (int) ( $row['scout_id'] ?? 0 ),
				'first_name'           => $row['first_name'] ?? '',
				'last_name'            => $row['last_name'] ?? '',
				'patrol'               => $row['patrol'] ?? '',
				'email1'               => $row['email1'] ?? '',
				'email2'               => $row['email2'] ?? '',
				'p1_email1'            => $row['p1_email1'] ?? '',
				'p1_email2'            => $row['p1_email2'] ?? '',
				'p2_email1'            => $row['p2_email1'] ?? '',
				'p2_email2'            => $row['p2_email2'] ?? '',
				// Compatibility
				'email'                => $row['email1'] ?? $row['email'] ?? '',
				'parent_email'         => $row['p1_email1'] ?? $row['parent_email'] ?? '',
			);
		}
		return new \WP_REST_Response( $explorers );
	}

	private function error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	private function register_whatsapp_routes(): void {
		register_rest_route(
			'ems/v1',
			'/events/(?P<id>\d+)/whatsapp',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_whatsapp_links' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'ems/v1',
			'/events/(?P<id>\d+)/whatsapp',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_whatsapp_links' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	private function register_planning_routes(): void {
		register_rest_route(
			'ems/v1',
			'/planning-board/availability/(?P<event_code>[a-zA-Z0-9\-]+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_planning_availability' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'event_code' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			'ems/v1',
			'/planning-board/allocate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'allocate_planning_explorers' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'scout_ids'  => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array( 'type' => 'integer' ),
					),
					'event_code' => array(
						'type'     => 'string',
						'required' => true,
					),
					'mode'       => array(
						'type'     => 'string',
						'required' => false,
						'default'  => 'unallocated',
						'enum'     => array( 'unallocated', 'new_team', 'existing_team', 'remove' ),
					),
					'team_id'    => array(
						'type'     => 'integer',
						'required' => false,
						'default'  => 0,
					),
				),
			)
		);

		register_rest_route(
			'ems/v1',
			'/planning-board/add-explorer',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'add_planning_explorer' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'scout_id'   => array(
						'type'     => 'integer',
						'required' => true,
					),
					'event_code' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			'ems/v1',
			'/planning-board/synced-explorers',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_planning_synced_explorers' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'ems/v1',
			'/planning-board/matrix',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_planning_matrix' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'ems/v1',
			'/planning-board/explorers/(?P<scout_id>\d+)/asn-validation',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_scout_asn_validation' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'scout_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	private function register_signup_routes(): void {
		register_rest_route(
			'ems/v1',
			'/signups',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_all_signups' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'ems/v1',
			'/signups/reconcile',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reconcile_signup' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'ems/v1',
			'/signups/unlink',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'unlink_signup' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'ems/v1',
			'/signups/unit',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_signup_unit' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'ems/v1',
			'/signups/level',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_signup_level' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'ems/v1',
			'/signups/anomalies',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_signup_anomalies' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'ems/v1',
			'/signups/(?P<id>\d+)/status',
			array(
				'methods'             => 'PATCH,POST',
				'callback'            => array( $this, 'update_signup_kanban' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'ems/v1',
			'/signups/bulk-status',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk_update_signup_kanban' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'ems/v1',
			'/signups/expeditions/(?P<id>\d+)/place-allocated',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_expedition_place_allocated' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'ems/v1',
			'/explorers/(?P<scout_id>\d+)/sync-contacts-from-osm',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'sync_explorer_contacts_from_osm' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'ems/v1',
			'/signups/(?P<type>participant|expedition)/(?P<id>\d+)/kanban',
			array(
				'methods'             => 'PATCH,POST',
				'callback'            => array( $this, 'update_signup_kanban' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Participant Places Endpoints
		register_rest_route(
			'ems/v1',
			'/signups/participants',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_participant_signups' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'ems/v1',
			'/explorers',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_explorers_roster' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'ems/v1',
			'/signups/participants/export',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export_participant_signups' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'ems/v1',
			'/signups/participants/(?P<id>\d+)/process',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'process_participant_signup' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'ems/v1',
			'/signups/participants/(?P<id>\d+)/archive',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'archive_participant_signup' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Expedition Entries Endpoints
		register_rest_route(
			'ems/v1',
			'/signups/expeditions',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_expedition_signups' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'ems/v1',
			'/signups/expeditions/(?P<id>\d+)/archive',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'archive_expedition_signup' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'ems/v1',
			'/signups/expeditions/(?P<id>\d+)/asn-validation',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_expedition_signup_asn_validation' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	public function list_participant_signups( \WP_REST_Request $request ): \WP_REST_Response {
		$status = $request->get_param( 'status' ) ?: 'submitted';
		$rows   = $this->signups->get_participant_signups( $status );

		$response_data = array();
		foreach ( $rows as $row ) {
			$bronze = ! empty( $row['bronze_completion'] ) ? json_decode( $row['bronze_completion'], true ) : null;
			$silver = ! empty( $row['silver_completion'] ) ? json_decode( $row['silver_completion'], true ) : null;

			$response_data[] = array(
				'id'                  => (int) $row['id'],
				'scout_id'            => (int) $row['scout_id'],
				'parent_user_id'      => (int) $row['parent_user_id'],
				'unit_id'             => ! empty( $row['unit_id'] ) ? (int) $row['unit_id'] : null,
				'unit_name'           => $row['unit_name'] ?: 'Unassigned',
				'district'            => $row['district'] ?? '',
				'explorer_first_name' => $row['explorer_first_name'],
				'explorer_last_name'  => $row['explorer_last_name'],
				'explorer_email'      => $row['explorer_email'],
				'parent_email'        => $row['parent_email'],
				'leader_email'        => $row['leader_email'] ?? '',
				'osm_email1'          => $row['osm_email1'] ?? '',
				'osm_email2'          => $row['osm_email2'] ?? '',
				'osm_p1_email1'       => $row['osm_p1_email1'] ?? '',
				'osm_p1_email2'       => $row['osm_p1_email2'] ?? '',
				'osm_p2_email1'       => $row['osm_p2_email1'] ?? '',
				'osm_p2_email2'       => $row['osm_p2_email2'] ?? '',
				'dofe_level'          => $row['dofe_level'],
				'dob'                 => $row['dob'],
				'dofe_registered'     => $row['dofe_registered'],
				'dofe_number'         => $row['dofe_number'],
				'dofe_org'            => $row['dofe_org'],
				'bronze_completion'   => $bronze,
				'silver_completion'   => $silver,
				'signup_status'       => $row['signup_status'],
				'kanban_stage'        => ( $row['kanban_stage'] ?? 'new' ) === 'to_process' ? 'new' : ( $row['kanban_stage'] ?? 'new' ),
				'processing_notes'    => $row['processing_notes'] ?? '',
				'payment_status'      => $row['payment_status'],
				'form_submission_id'  => (int) $row['form_submission_id'],
				'is_synced_osm'       => isset( $row['is_synced_osm'] ) && (int) $row['is_synced_osm'] === 1,
				'created_at'          => $row['created_at'],
				'updated_at'          => $row['updated_at'],
			);
		}

		return new \WP_REST_Response( $response_data );
	}

	public function process_participant_signup( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id          = (int) $request->get_param( 'id' );
		$body        = $request->get_json_params() ?: array();
		$dofe_number = isset( $body['dofe_number'] ) ? sanitize_text_field( $body['dofe_number'] ) : null;

		$signup = $this->signups->get_participant_signup( $id );
		if ( ! $signup ) {
			return $this->error( 'ems_signup_not_found', 'Signup not found.', 404 );
		}

		if ( ! empty( $signup['dofe_number'] ) ) {
			return $this->error( 'ems_signup_already_processed', 'Signup is already processed.', 400 );
		}

		$this->signups->process_participant_signup( $id, get_current_user_id(), $dofe_number );

		return new \WP_REST_Response( array( 'processed' => true ) );
	}

	public function archive_participant_signup( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id = (int) $request->get_param( 'id' );

		$signup = $this->signups->get_participant_signup( $id );
		if ( ! $signup ) {
			return $this->error( 'ems_signup_not_found', 'Signup not found.', 404 );
		}

		$this->signups->archive_participant_signup( $id );

		return new \WP_REST_Response( array( 'archived' => true ) );
	}

	public function list_expedition_signups( \WP_REST_Request $request ): \WP_REST_Response {
		$status = $request->get_param( 'status' ) ?: 'submitted';
		$rows   = $this->signups->get_expedition_signups( $status );

		$response_data = array();
		foreach ( $rows as $row ) {
			$prefs = ! empty( $row['expedition_preferences'] ) ? json_decode( $row['expedition_preferences'], true ) : null;

			$response_data[] = array(
				'id'                       => (int) $row['id'],
				'scout_id'                 => (int) $row['scout_id'],
				'parent_user_id'           => (int) $row['parent_user_id'],
				'unit_id'                  => ! empty( $row['unit_id'] ) ? (int) $row['unit_id'] : null,
				'unit_name'                => $row['unit_name'] ?: 'Unassigned',
				'district'                 => $row['district'] ?? '',
				'explorer_first_name'      => $row['explorer_first_name'],
				'explorer_last_name'       => $row['explorer_last_name'],
				'explorer_email'           => $row['explorer_email'],
				'parent_email'             => $row['parent_email'],
				'leader_email'             => $row['leader_email'] ?? '',
				'osm_email1'               => $row['osm_email1'] ?? '',
				'osm_email2'               => $row['osm_email2'] ?? '',
				'osm_p1_email1'            => $row['osm_p1_email1'] ?? '',
				'osm_p1_email2'            => $row['osm_p1_email2'] ?? '',
				'osm_p2_email1'            => $row['osm_p2_email1'] ?? '',
				'osm_p2_email2'            => $row['osm_p2_email2'] ?? '',
				'dofe_level'               => $row['dofe_level'],
				'dob'                      => $row['dob'] ?? null,
				'dofe_registered'          => $row['dofe_registered'] ?? null,
				'dofe_number'              => $row['dofe_number'],
				'expedition_preferences'   => $prefs,
				'additional_support_needs' => $row['additional_support_needs'],
				'asn_validated'            => isset( $row['asn_validated'] ) && $row['asn_validated'] !== null ? (int) $row['asn_validated'] : null,
				'first_aid_status'         => $row['first_aid_status'],
				'first_aid_expiry'         => $row['first_aid_expiry'],
				'signup_status'               => $row['signup_status'],
				'kanban_stage'                => ( $row['kanban_stage'] ?? 'new' ) === 'to_process' ? 'new' : ( $row['kanban_stage'] ?? 'new' ),
				'processing_notes'            => $row['processing_notes'] ?? '',
				'participant_place_allocated' => ! empty( $row['participant_place_allocated'] ),
				'participant_signup_id'       => ! empty( $row['participant_signup_id'] ) ? (int) $row['participant_signup_id'] : null,
				'form_submission_id'       => (int) $row['form_submission_id'],
				'is_synced_osm'            => isset( $row['is_synced_osm'] ) && (int) $row['is_synced_osm'] === 1,
				'created_at'               => $row['created_at'],
				'updated_at'               => $row['updated_at'],
			);
		}

		return new \WP_REST_Response( $response_data );
	}

	public function archive_expedition_signup( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id = (int) $request->get_param( 'id' );

		$signup = $this->signups->get_expedition_signup( $id );
		if ( ! $signup ) {
			return $this->error( 'ems_signup_not_found', 'Signup not found.', 404 );
		}

		$this->signups->archive_expedition_signup( $id );

		return new \WP_REST_Response( array( 'archived' => true ) );
	}

	public function reconcile_signup( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body        = $request->get_json_params() ?: array();
		$signup_id   = isset( $body['signup_id'] ) ? (int) $body['signup_id'] : 0;
		$signup_type = isset( $body['signup_type'] ) ? sanitize_text_field( $body['signup_type'] ) : '';
		$scout_id    = isset( $body['scout_id'] ) ? (int) $body['scout_id'] : 0;

		if ( ! $signup_id || ! $signup_type || ! $scout_id ) {
			return $this->error( 'ems_missing_parameters', 'Missing required parameters.', 400 );
		}

		$success = $this->signups->reconcile_signup( $signup_id, $signup_type, $scout_id );
		if ( ! $success ) {
			return $this->error( 'ems_reconcile_failed', 'Reconcile failed.', 400 );
		}

		return new \WP_REST_Response( array( 'reconciled' => true ) );
	}

	public function unlink_signup( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body        = $request->get_json_params() ?: array();
		$signup_id   = isset( $body['signup_id'] ) ? (int) $body['signup_id'] : 0;
		$signup_type = isset( $body['signup_type'] ) ? sanitize_text_field( $body['signup_type'] ) : '';

		if ( ! $signup_id || ! $signup_type ) {
			return $this->error( 'ems_missing_parameters', 'Missing required parameters.', 400 );
		}

		try {
			$success = $this->signups->unlink_signup( $signup_id, $signup_type );
			if ( ! $success ) {
				return $this->error( 'ems_unlink_failed', 'Unlink failed.', 400 );
			}
		} catch ( \RuntimeException $e ) {
			return $this->error( 'ems_unlink_conflict', $e->getMessage(), 409 );
		}

		return new \WP_REST_Response( array( 'unlinked' => true ) );
	}

	public function update_signup_unit( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body        = $request->get_json_params() ?: array();
		$signup_id   = isset( $body['signup_id'] ) ? (int) $body['signup_id'] : 0;
		$signup_type = isset( $body['signup_type'] ) ? sanitize_text_field( (string) $body['signup_type'] ) : '';
		$unit_id     = isset( $body['unit_id'] ) ? (int) $body['unit_id'] : 0;

		if ( $signup_id <= 0 || ! in_array( $signup_type, array( 'participant', 'expedition' ), true ) || $unit_id <= 0 ) {
			return $this->error( 'ems_invalid_parameters', 'Invalid signup or unit parameters.', 400 );
		}

		$result = $this->signups->update_signup_unit( $signup_id, $signup_type, $unit_id );
		if ( ! $result ) {
			return $this->error( 'ems_signup_or_unit_not_found', 'Signup or unit not found.', 404 );
		}

		return new \WP_REST_Response(
			array(
				'updated'      => true,
				'unit_id'      => $result['unit_id'],
				'unit_name'    => $result['unit_name'],
				'leader_email' => $result['leader_email'],
			),
			200
		);
	}

	public function update_signup_level( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body        = $request->get_json_params() ?: array();
		$signup_id   = isset( $body['signup_id'] ) ? (int) $body['signup_id'] : 0;
		$signup_type = isset( $body['signup_type'] ) ? sanitize_text_field( (string) $body['signup_type'] ) : '';
		$dofe_level  = isset( $body['dofe_level'] ) ? sanitize_text_field( (string) $body['dofe_level'] ) : '';

		if ( $signup_id <= 0 || ! in_array( $signup_type, array( 'participant', 'expedition' ), true ) || ! in_array( strtolower( $dofe_level ), array( 'bronze', 'silver', 'gold' ), true ) ) {
			return $this->error( 'ems_invalid_parameters', 'Invalid signup or level parameters.', 400 );
		}

		$result = $this->signups->update_signup_level( $signup_id, $signup_type, $dofe_level );
		if ( ! $result ) {
			return $this->error( 'ems_signup_not_found', 'Signup not found.', 404 );
		}

		return new \WP_REST_Response(
			array(
				'updated'    => true,
				'dofe_level' => $result['dofe_level'],
			),
			200
		);
	}

	public function update_expedition_signup_asn_validation( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id   = (int) $request->get_param( 'id' );
		$body = $request->get_json_params() ?: array();

		$raw_val = $body['asn_validated'] ?? $request->get_param( 'asn_validated' );
		$val     = null;
		if ( $raw_val !== null && $raw_val !== '' ) {
			$val = (int) $raw_val;
		}

		$success = $this->signups->update_expedition_signup_asn_validation( $id, $val );
		if ( ! $success ) {
			return $this->error( 'ems_asn_validation_failed', 'Failed to update ASN validation.', 500 );
		}

		return new \WP_REST_Response(
			array(
				'updated'       => true,
				'id'            => $id,
				'asn_validated' => $val,
			)
		);
	}

	public function update_scout_asn_validation( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$scout_id = (int) $request->get_param( 'scout_id' );
		$body     = $request->get_json_params() ?: array();

		$raw_val = $body['asn_validated'] ?? $request->get_param( 'asn_validated' );
		$val     = null;
		if ( $raw_val !== null && $raw_val !== '' ) {
			$val = (int) $raw_val;
		}

		$success = $this->signups->update_scout_asn_validation( $scout_id, $val );
		if ( ! $success ) {
			return $this->error( 'ems_asn_validation_failed', 'Failed to update ASN validation.', 500 );
		}

		return new \WP_REST_Response(
			array(
				'updated'       => true,
				'scout_id'      => $scout_id,
				'asn_validated' => $val,
			)
		);
	}

	public function list_all_signups( \WP_REST_Request $request ): \WP_REST_Response {
		$status = $request->get_param( 'status' ) ?: 'submitted';

		// Get both lists. Also fetch archived participant signups so confirmed/archived
		// Participant Places still satisfy Expedition pairing in SignupAnomalyService.
		$participant_response = $this->list_participant_signups( $request );
		$expedition_response  = $this->list_expedition_signups( $request );

		$participants = $participant_response->get_data();
		$expeditions  = $expedition_response->get_data();

		$all_participants_for_pairing = $status === 'all'
			? $participants
			: $this->signups->get_participant_signups( 'all' );

		$anomaly_service = new \EMS\Modules\Signups\Services\SignupAnomalyService();
		$anomaly_result  = $anomaly_service->computeAnomalies( $all_participants_for_pairing, $expeditions );
		$by_key          = $anomaly_result['by_key'];

		$merged = array();
		foreach ( $participants as $p ) {
			$p['type']      = 'participant';
			$p['anomalies'] = $by_key[ 'participant_' . $p['id'] ] ?? array();
			$merged[]       = $p;
		}
		foreach ( $expeditions as $e ) {
			$e['type']      = 'expedition';
			$e['anomalies'] = $by_key[ 'expedition_' . $e['id'] ] ?? array();
			$merged[]       = $e;
		}

		// Sort by created_at DESC
		usort(
			$merged,
			function ( $a, $b ) {
				return strcmp( $b['created_at'], $a['created_at'] );
			}
		);

		return new \WP_REST_Response( $merged );
	}

	public function get_signup_anomalies( \WP_REST_Request $request ): \WP_REST_Response {
		$participants = $this->signups->get_participant_signups( 'all' );
		$expeditions  = $this->signups->get_expedition_signups( 'submitted' );

		$anomaly_service = new \EMS\Modules\Signups\Services\SignupAnomalyService();
		$result          = $anomaly_service->computeAnomalies( $participants, $expeditions );

		return new \WP_REST_Response( $result, 200 );
	}

	public function update_signup_kanban( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body  = $request->get_json_params() ?: array();
		$type  = sanitize_key( (string) ( $request->get_param( 'type' ) ?: ( $body['type'] ?? ( $body['signup_type'] ?? 'participant' ) ) ) );
		$id    = (int) $request->get_param( 'id' );
		$stage = sanitize_key( (string) ( $body['kanban_stage'] ?? '' ) );
		$notes = array_key_exists( 'processing_notes', $body ) ? (string) $body['processing_notes'] : null;

		$result = $this->get_transitions()->transition_stage( $id, $type, $stage, $notes );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response(
			array(
				'updated'               => true,
				'id'                    => $id,
				'type'                  => $type,
				'kanban_stage'          => $stage,
				'processing_notes'      => $notes,
				'synced_expedition_ids' => $result['synced_expedition_ids'] ?? array(),
			),
			200
		);
	}

	public function bulk_update_signup_kanban( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body  = $request->get_json_params() ?: array();
		$items = is_array( $body['items'] ?? null ) ? $body['items'] : array();
		$stage = sanitize_key( (string) ( $body['kanban_stage'] ?? '' ) );

		if ( empty( $items ) || $stage === '' ) {
			return $this->error( 'ems_invalid_bulk_request', 'items and kanban_stage are required.', 400 );
		}

		$updated     = 0;
		$skipped     = 0;
		$transitions = $this->get_transitions();
		foreach ( $items as $item ) {
			$id   = (int) ( $item['id'] ?? 0 );
			$type = sanitize_key( (string) ( $item['type'] ?? '' ) );
			if ( $id <= 0 || ! in_array( $type, array( 'participant', 'expedition' ), true ) ) {
				$skipped++;
				continue;
			}
			$res = $transitions->transition_stage( $id, $type, $stage );
			if ( ! is_wp_error( $res ) ) {
				$updated++;
			} else {
				$skipped++;
			}
		}

		return new \WP_REST_Response(
			array(
				'updated' => $updated,
				'skipped' => $skipped,
				'stage'   => $stage,
			),
			200
		);
	}

	public function update_expedition_place_allocated( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id                    = (int) $request->get_param( 'id' );
		$body                  = $request->get_json_params() ?: array();
		$allocated             = ! empty( $body['participant_place_allocated'] );
		$participant_signup_id = isset( $body['participant_signup_id'] ) ? (int) $body['participant_signup_id'] : null;

		$signup = $this->signups->get_expedition_signup( $id );
		if ( ! $signup ) {
			return $this->error( 'ems_signup_not_found', 'Expedition signup not found.', 404 );
		}

		$ok = $this->signups->set_participant_place_allocated( $id, $allocated, $participant_signup_id );
		if ( ! $ok ) {
			return $this->error( 'ems_place_allocated_update_failed', 'Failed to update Participant Place Allocated flag.', 500 );
		}

		return new \WP_REST_Response(
			array(
				'updated'                     => true,
				'id'                          => $id,
				'participant_place_allocated' => $allocated,
				'participant_signup_id'       => $participant_signup_id,
			),
			200
		);
	}

	public function sync_explorer_contacts_from_osm( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$scout_id = (int) $request->get_param( 'scout_id' );
		if ( $scout_id <= 0 ) {
			return $this->error( 'ems_invalid_scout_id', 'A valid scout_id > 0 is required.', 400 );
		}

		$explorer = $this->explorers->find_by_scout_id( $scout_id );
		if ( ! $explorer ) {
			return $this->error( 'ems_explorer_not_found', 'Synced OSM Explorer not found.', 404 );
		}

		$explorer_email = trim( (string) ( $explorer['email1'] ?? $explorer['email'] ?? '' ) );
		$parent_email   = trim( (string) ( $explorer['p1_email1'] ?? $explorer['parent_email'] ?? '' ) );

		if ( $explorer_email === '' && $parent_email === '' ) {
			return $this->error( 'ems_no_osm_emails', 'No email addresses are recorded in OSM for this Explorer.', 400 );
		}

		$updated_rows = $this->signups->sync_contacts_from_osm( $scout_id, $explorer_email, $parent_email );

		if ( class_exists( \EMS\Core\Audit_Logger::class ) && method_exists( \EMS\Core\Audit_Logger::class, 'log' ) ) {
			\EMS\Core\Audit_Logger::log( 'sync_contacts_from_osm', $scout_id );
		}

		return new \WP_REST_Response(
			array(
				'synced'         => true,
				'scout_id'       => $scout_id,
				'updated_rows'   => $updated_rows,
				'explorer_email' => $explorer_email,
				'parent_email'   => $parent_email,
			),
			200
		);
	}

	public function list_event_teams( \WP_REST_Request $request ): \WP_REST_Response {
		$event_id = (int) $request->get_param( 'event_id' );
		$teams    = $this->teams->list_by_expedition( $event_id );

		$unallocated = $this->teams->get_unallocated_team( $event_id );
		if ( $unallocated ) {
			$teams[] = $unallocated;
		}

		foreach ( $teams as &$team ) {
			$members              = $this->team_members->list_by_team( $team['ID'] );
			$team['member_count'] = count( $members );
			$team['size_warning'] = $team['member_count'] < 4 || $team['member_count'] > 7;
			$team['members']      = $this->hydrate_members( $members );
		}

		return new \WP_REST_Response( $teams );
	}

	public function get_explorer_asn( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$scout_id = (int) $request->get_param( 'scout_id' );

		$explorer = $this->explorers->find_by_scout_id( $scout_id );
		if ( ! $explorer ) {
			return $this->error( 'ems_explorer_not_found', 'Explorer not found.', 404 );
		}

		global $wpdb;
		$parent_asn = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT additional_support_needs FROM {$wpdb->prefix}ems_expedition_signups WHERE scout_id = %d AND additional_support_needs != '' LIMIT 1",
				$scout_id
			)
		) ?: '';

		$wpdb->insert(
			$wpdb->prefix . 'ems_audit_logs',
			array(
				'user_id'         => get_current_user_id() ?: 1,
				'action'          => 'view_asn',
				'target_scout_id' => $scout_id,
				'ip_address'      => $_SERVER['REMOTE_ADDR'] ?? '',
				'user_agent'      => $_SERVER['HTTP_USER_AGENT'] ?? '',
				'timestamp'       => current_time( 'mysql' ),
			)
		);

		return new \WP_REST_Response(
			array(
				'scout_id'        => $scout_id,
				'first_name'      => $explorer['first_name'] ?? '',
				'last_name'       => $explorer['last_name'] ?? '',
				'parent_asn'      => $parent_asn,
				'organiser_notes' => $explorer['additional_support_needs'] ?? '',
			)
		);
	}

	public function update_explorer_asn( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$scout_id = (int) $request->get_param( 'scout_id' );
		$body     = $request->get_json_params() ?: array();
		$notes    = isset( $body['organiser_notes'] ) ? sanitize_textarea_field( $body['organiser_notes'] ) : '';

		$explorer = $this->explorers->find_by_scout_id( $scout_id );
		if ( ! $explorer ) {
			return $this->error( 'ems_explorer_not_found', 'Explorer not found.', 404 );
		}

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'ems_osm_explorers',
			array( 'additional_support_needs' => $notes ),
			array( 'scout_id' => $scout_id )
		);

		return new \WP_REST_Response(
			array(
				'success'         => true,
				'organiser_notes' => $notes,
			)
		);
	}

	public function get_explorer_profile( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$scout_id = (int) $request->get_param( 'scout_id' );

		$explorer = $this->explorers->find_by_scout_id( $scout_id );
		if ( ! $explorer ) {
			return $this->error( 'ems_explorer_not_found', 'Explorer not found.', 404 );
		}

		global $wpdb;

		// 1. Leader email from ems_units
		$leader_email = '';
		if ( ! empty( $explorer['patrol'] ) ) {
			$leader_email = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT u.leader_email FROM {$wpdb->prefix}ems_units u
                 JOIN {$wpdb->prefix}ems_unit_patrols p ON u.unit_id = p.unit_id
                 WHERE p.name = %s AND p.section_id = %d AND p.active = 1 
                 LIMIT 1",
					$explorer['patrol'],
					(int) $explorer['section_id']
				)
			) ?: '';
		}

		// 2. Teams/events assignments
		$assigned_teams = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tm.team_post_id as team_id,
                    t.post_title as team_name,
                    e.ID as event_id,
                    e.post_title as event_title,
                    t_meta_code.meta_value as team_code,
                    e_meta_type.meta_value as event_type,
                    e_meta_start.meta_value as start_date,
                    e_meta_end.meta_value as end_date,
                    e_meta_osm.meta_value as osm_event_id
             FROM {$wpdb->prefix}ems_team_members tm
             JOIN {$wpdb->posts} t ON tm.team_post_id = t.ID AND t.post_type = 'team'
             JOIN {$wpdb->posts} e ON t.post_parent = e.ID AND e.post_type = 'expedition'
             LEFT JOIN {$wpdb->postmeta} t_meta_code ON t.ID = t_meta_code.post_id AND t_meta_code.meta_key = 'ems_team_code'
             LEFT JOIN {$wpdb->postmeta} e_meta_type ON e.ID = e_meta_type.post_id AND e_meta_type.meta_key = 'ems_type'
             LEFT JOIN {$wpdb->postmeta} e_meta_start ON e.ID = e_meta_start.post_id AND e_meta_start.meta_key = 'ems_start_date'
             LEFT JOIN {$wpdb->postmeta} e_meta_end ON e.ID = e_meta_end.post_id AND e_meta_end.meta_key = 'ems_end_date'
             LEFT JOIN {$wpdb->postmeta} e_meta_osm ON e.ID = e_meta_osm.post_id AND e_meta_osm.meta_key = 'ems_osm_event_id'
             WHERE tm.scout_id = %d",
				$scout_id
			),
			ARRAY_A
		);

		$training_events   = array();
		$practice_events   = array();
		$qualifiers_events = array();

		foreach ( $assigned_teams as $t ) {
			$osm_status = 'Not synced';
			$osm_ev_id  = (int) ( $t['osm_event_id'] ?? 0 );
			if ( $osm_ev_id > 0 ) {
				$status = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT status FROM {$wpdb->prefix}ems_osm_event_attendance 
                     WHERE event_id = %d AND scout_id = %d 
                     LIMIT 1",
						$osm_ev_id,
						$scout_id
					)
				);
				if ( $status ) {
					$osm_status = $status;
				}
			}

			$formatted = array(
				'team_id'     => (int) $t['team_id'],
				'team_name'   => $t['team_name'],
				'event_id'    => (int) $t['event_id'],
				'event_title' => $t['event_title'],
				'team_code'   => $t['team_code'] ?: '',
				'event_type'  => $t['event_type'] ?: '',
				'start_date'  => $t['start_date'] ?: '',
				'end_date'    => $t['end_date'] ?: '',
				'osm_status'  => $osm_status,
			);

			$type = strtolower( $t['event_type'] );
			if ( $type === 'training' ) {
				$training_events[] = $formatted;
			} elseif ( $type === 'practice' ) {
				$practice_events[] = $formatted;
			} elseif ( $type === 'qualifying' || $type === 'qualifier' ) {
				$qualifiers_events[] = $formatted;
			}
		}

		// 3. Expedition signups (dates and team preferences)
		$preferences       = null;
		$parent_asn        = '';
		$expedition_signup = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}ems_expedition_signups 
             WHERE scout_id = %d 
             ORDER BY created_at DESC 
             LIMIT 1",
				$scout_id
			),
			ARRAY_A
		);

		if ( $expedition_signup ) {
			$preferences = ! empty( $expedition_signup['expedition_preferences'] )
				? json_decode( $expedition_signup['expedition_preferences'], true )
				: null;
			$parent_asn  = $expedition_signup['additional_support_needs'] ?: '';
		}

		// 4. Participant places signups
		$participant_signups = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, dofe_level, created_at, signup_status, form_submission_id 
             FROM {$wpdb->prefix}ems_participant_signups 
             WHERE scout_id = %d OR (explorer_email = %s AND explorer_email != '')
             ORDER BY created_at DESC",
				$scout_id,
				$explorer['email'] ?? ''
			),
			ARRAY_A
		);

		// Convert types/clean values
		foreach ( $participant_signups as &$ps ) {
			$ps['id']                 = (int) $ps['id'];
			$ps['form_submission_id'] = (int) $ps['form_submission_id'];
		}

		// 5. Tutor LMS completions
		$training_records = array();
		$wp_user_id       = (int) ( $explorer['wp_user_id'] ?? 0 );
		if ( $wp_user_id > 0 ) {
			$tutor_client = new \EMS\Integrations\TutorLMS_Client();
			$courses      = $tutor_client->get_all_courses() ?: array();
			$course_ids   = array_map( fn( $c ) => $c->ID, $courses );

			$matrix = array();
			if ( ! empty( $course_ids ) ) {
				$matrix = $tutor_client->get_enrollment_matrix( array( $wp_user_id ), $course_ids ) ?: array();
			}
			$user_matrix = $matrix[ $wp_user_id ] ?? array();

			foreach ( $courses as $course ) {
				$training_records[] = array(
					'id'     => (int) $course->ID,
					'title'  => $course->post_title,
					'status' => $user_matrix[ $course->ID ] ?? 'not_enrolled',
				);
			}
		}

		return new \WP_REST_Response(
			array(
				'scout_id'            => $scout_id,
				'wp_user_id'          => $wp_user_id,
				'first_name'          => $explorer['first_name'] ?? '',
				'last_name'           => $explorer['last_name'] ?? '',
				'email'               => $explorer['email'] ?? '',
				'email1'              => $explorer['email1'] ?? '',
				'email2'              => $explorer['email2'] ?? '',
				'p1_email1'           => $explorer['p1_email1'] ?? '',
				'p1_email2'           => $explorer['p1_email2'] ?? '',
				'p2_email1'           => $explorer['p2_email1'] ?? '',
				'p2_email2'           => $explorer['p2_email2'] ?? '',
				'unit'                => $explorer['patrol'] ?? '',
				'leader_email'        => $leader_email,
				'organiser_notes'     => $explorer['additional_support_needs'] ?? '',
				'parent_asn'          => $parent_asn,
				'training_events'     => $training_events,
				'practice_events'     => $practice_events,
				'qualifiers_events'   => $qualifiers_events,
				'preferences'         => $preferences,
				'participant_signups' => $participant_signups,
				'training_records'    => $training_records,
			),
			200
		);
	}

	public function get_planning_matrix( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$req_level     = strtolower( trim( (string) ( $request->get_param( 'level' ) ?: 'silver' ) ) );
		$req_type      = strtolower( trim( (string) ( $request->get_param( 'type' ) ?: 'practice' ) ) );
		$req_transport = strtolower( trim( (string) ( $request->get_param( 'transport' ) ?: 'hillwalking' ) ) );

		if ( $req_type === 'qualifier' ) {
			$req_type = 'qualifying';
		}

		// 1. Fetch matching events
		$raw_events     = $this->expeditions->list_all_chronological();
		$matched_events = array();
		$event_ids      = array();
		$event_map      = array();

		foreach ( $raw_events as $event ) {
			$level     = strtolower( $event['ems_level'] ?? '' );
			$type      = strtolower( $event['ems_type'] ?? '' );
			$transport = strtolower( $event['ems_transport'] ?? '' );
			$status    = strtolower( $event['ems_status'] ?? '' );

			if ( $status === 'archived' ) {
				continue;
			}
			if ( $level !== $req_level || $type !== $req_type ) {
				continue;
			}
			if ( ! empty( $req_transport ) && $transport !== $req_transport ) {
				continue;
			}

			$event_id   = (int) $event['ID'];
			$event_code = $event['ems_event_code'] ?? '';
			if ( empty( $event_code ) ) {
				continue;
			}

			$event_ids[]            = $event_id;
			$event_map[ $event_id ] = $event_code;

			$teams_list  = $this->teams->list_by_expedition( $event_id );
			$unallocated = $this->teams->get_unallocated_team( $event_id );
			if ( $unallocated ) {
				$teams_list[] = $unallocated;
			}

			$teams_data = array();
			foreach ( $teams_list as $t ) {
				$members      = $this->team_members->list_by_team( (int) $t['ID'] );
				$teams_data[] = array(
					'id'           => (int) $t['ID'],
					'team_code'    => $t['ems_team_code'] ?? '',
					'member_count' => count( $members ),
					'scout_ids'    => array_map( 'intval', array_column( $members, 'scout_id' ) ),
				);
			}

			$pool_count      = 0;
			$total_allocated = 0;
			foreach ( $teams_data as $td ) {
				if ( $td['team_code'] === 'UNALLOCATED' ) {
					$pool_count += (int) $td['member_count'];
				}
				$total_allocated += (int) $td['member_count'];
			}

			$matched_events[] = array(
				'id'           => $event_id,
				'event_code'   => $event_code,
				'title'        => $event['post_title'] ?? '',
				'level'        => $level,
				'type'         => $type,
				'transport'    => $transport,
				'start_date'   => $event['ems_start_date'] ?? '',
				'end_date'     => $event['ems_end_date'] ?? '',
				'pool_count'   => $pool_count,
				'member_count' => $total_allocated,
				'teams'        => $teams_data,
			);
		}

		// 2. Fetch cohort explorers matching level and transport
		$signups_list      = $this->signups->get_expedition_signups( 'pending' );
		$matched_explorers = array();
		$table             = $wpdb->prefix . 'ems_team_members';

		foreach ( $signups_list as $signup ) {
			if ( ( $signup['signup_status'] ?? '' ) === 'archived' ) {
				continue;
			}
			if ( strtolower( $signup['dofe_level'] ?? '' ) !== $req_level ) {
				continue;
			}

			$prefs = ! empty( $signup['expedition_preferences'] ) ? json_decode( $signup['expedition_preferences'], true ) : null;
			if ( ! is_array( $prefs ) ) {
				$prefs = array();
			}

			$pref_transport = strtolower( (string) ( $prefs['exped_type'] ?? $prefs['exped_type_bronze'] ?? 'hillwalking' ) );
			if ( ! empty( $req_transport ) && $pref_transport !== $req_transport ) {
				continue;
			}

			if ( $req_type === 'practice' ) {
				$available_dates = $prefs['exped_practice_dates'] ?? $prefs['exped_silver_practice_dates'] ?? $prefs['exped_gold_practice_dates'] ?? array();
			} elseif ( $req_type === 'qualifying' ) {
				$available_dates = $prefs['exped_qualifier_dates'] ?? $prefs['exped_silver_qualifier_dates'] ?? $prefs['exped_gold_qualifier_dates'] ?? array();
			} else {
				$available_dates = array();
			}
			if ( ! is_array( $available_dates ) ) {
				$available_dates = array( $available_dates );
			}
			if ( $req_level === 'bronze' && empty( $available_dates ) ) {
				$available_dates = array_column( $matched_events, 'event_code' );
			}

			$scout_id = (int) $signup['scout_id'];

			$allocations = array();
			if ( ! empty( $event_ids ) && $wpdb ) {
				$allocations = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT team_post_id FROM {$table} WHERE scout_id = %d",
						$scout_id
					),
					ARRAY_A
				) ?: array();
			}

			$allocated_event_code = null;
			$allocated_team_code  = null;
			$allocated_team_id    = null;

			foreach ( $allocations as $alloc ) {
				$team_id = (int) $alloc['team_post_id'];
				$team    = get_post( $team_id );
				if ( $team && $team->post_type === 'team' ) {
					$parent_event_id = (int) $team->post_parent;
					if ( in_array( $parent_event_id, $event_ids, true ) ) {
						$allocated_event_code = $event_map[ $parent_event_id ] ?? get_post_meta( $parent_event_id, 'ems_event_code', true );
						$allocated_team_code  = get_post_meta( $team_id, 'ems_team_code', true );
						$allocated_team_id    = $team_id;
						break;
					}
				}
			}

			$matched_explorers[] = array(
				'expedition_signup_id'     => (int) ( $signup['id'] ?? 0 ),
				'scout_id'                 => $scout_id,
				'first_name'               => $signup['explorer_first_name'] ?? '',
				'last_name'                => $signup['explorer_last_name'] ?? '',
				'unit_id'                  => ! empty( $signup['unit_id'] ) ? (int) $signup['unit_id'] : null,
				'unit_name'                => $signup['unit_name'] ?: 'Unassigned',
				'district'                 => $signup['district'] ?? '',
				'team_preferences'         => $prefs['exped_team_names'] ?? '',
				'available_events'         => array_values( array_unique( $available_dates ) ),
				'allocated_event_code'     => $allocated_event_code,
				'allocated_team_code'      => $allocated_team_code,
				'allocated_team_id'        => $allocated_team_id,
				'has_asn'                  => ! empty( $signup['additional_support_needs'] ),
				'additional_support_needs' => $signup['additional_support_needs'] ?? '',
				'asn_validated'            => isset( $signup['asn_validated'] ) && $signup['asn_validated'] !== null ? (int) $signup['asn_validated'] : null,
				'first_aid_status'         => $signup['first_aid_status'] ?? 'none',
				'first_aid_expiry'         => $signup['first_aid_expiry'] ?? null,
			);
		}

		return new \WP_REST_Response(
			array(
				'events'    => $matched_events,
				'explorers' => $matched_explorers,
			)
		);
	}

	public function list_planning_availability( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$event_code = $request->get_param( 'event_code' );

		$signups_list = $this->signups->get_expedition_signups( 'pending' );
		$available    = array();

		foreach ( $signups_list as $signup ) {
			if ( ( $signup['signup_status'] ?? '' ) === 'archived' ) {
				continue;
			}
			$prefs = ! empty( $signup['expedition_preferences'] ) ? json_decode( $signup['expedition_preferences'], true ) : null;
			if ( ! is_array( $prefs ) ) {
				continue;
			}

			$practice  = $prefs['exped_practice_dates'] ?? array();
			$qualifier = $prefs['exped_qualifier_dates'] ?? array();
			if ( ! is_array( $practice ) ) {
				$practice = array( $practice );
			}
			if ( ! is_array( $qualifier ) ) {
				$qualifier = array( $qualifier );
			}

			if ( in_array( $event_code, $practice, true ) || in_array( $event_code, $qualifier, true ) ) {
				$scout_id = (int) $signup['scout_id'];

				global $wpdb;
				$table       = $wpdb->prefix . 'ems_team_members';
				$allocations = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT team_post_id FROM {$table} WHERE scout_id = %d",
						$scout_id
					),
					ARRAY_A
				) ?: array();

				$allocated_event_code = null;
				$allocated_team_code  = null;

				foreach ( $allocations as $alloc ) {
					$team_id = (int) $alloc['team_post_id'];
					$team    = get_post( $team_id );
					if ( $team && $team->post_type === 'team' ) {
						$team_code = get_post_meta( $team_id, 'ems_team_code', true );

						$event_id    = $team->post_parent;
						$event_level = get_post_meta( $event_id, 'ems_level', true );
						$event_type  = get_post_meta( $event_id, 'ems_type', true );
						$e_code      = get_post_meta( $event_id, 'ems_event_code', true );

						$query_event_id = $wpdb->get_var(
							$wpdb->prepare(
								"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'ems_event_code' AND meta_value = %s LIMIT 1",
								$event_code
							)
						);
						if ( $query_event_id ) {
							$query_level = get_post_meta( $query_event_id, 'ems_level', true );
							$query_type  = get_post_meta( $query_event_id, 'ems_type', true );

							if ( strtolower( $event_level ) === strtolower( $query_level ) && strtolower( $event_type ) === strtolower( $query_type ) ) {
								$allocated_event_code = $e_code;
								$allocated_team_code  = $team_code;
								break;
							}
						}
					}
				}

				$all_interested = array_unique( array_merge( $practice, $qualifier ) );
				$other_events   = array_values(
					array_filter(
						$all_interested,
						function ( $code ) use ( $event_code ) {
							return $code !== $event_code;
						}
					)
				);

				$available[] = array(
					'scout_id'             => $scout_id,
					'first_name'           => $signup['explorer_first_name'] ?? '',
					'last_name'            => $signup['explorer_last_name'] ?? '',
					'unit_name'            => $signup['unit_name'] ?: 'Unassigned',
					'allocated_event_code' => $allocated_event_code,
					'allocated_team_code'  => $allocated_team_code,
					'team_preferences'     => $prefs['exped_team_names'] ?? '',
					'other_events'         => $other_events,
					'first_aid_level'      => $signup['first_aid_status'] ?? 'none',
					'has_asn'              => ! empty( $signup['additional_support_needs'] ),
				);
			}
		}

		// Fetch teams for this event so the UI can populate the "existing team" dropdown
		$query_event_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'ems_event_code' AND meta_value = %s LIMIT 1",
				$event_code
			)
		);

		if ( $query_event_id ) {
			$team_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'team'",
					$query_event_id
				)
			);
			if ( ! empty( $team_ids ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $team_ids ), '%d' ) );
				$assigned_members = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT tm.scout_id, tm.team_post_id, t.meta_value as team_code 
						 FROM {$wpdb->prefix}ems_team_members tm
						 JOIN {$wpdb->postmeta} t ON tm.team_post_id = t.post_id AND t.meta_key = 'ems_team_code'
						 WHERE tm.team_post_id IN ($placeholders)",
						...$team_ids
					),
					ARRAY_A
				) ?: array();

				$scout_to_team = array();
				foreach ( $assigned_members as $m ) {
					$scout_to_team[ (int) $m['scout_id'] ] = $m['team_code'];
				}

				$scout_ids = array_keys( $scout_to_team );
				$loaded_scout_ids = array_column( $available, 'scout_id' );

				$missing_scout_ids = array_diff( $scout_ids, $loaded_scout_ids );
				if ( ! empty( $missing_scout_ids ) ) {
					$missing_placeholders = implode( ',', array_fill( 0, count( $missing_scout_ids ), '%d' ) );
					$explorers_data = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT * FROM {$wpdb->prefix}ems_osm_explorers WHERE scout_id IN ($missing_placeholders)",
							...$missing_scout_ids
						),
						ARRAY_A
					) ?: array();

					foreach ( $explorers_data as $exp ) {
						$s_id = (int) $exp['scout_id'];
						$available[] = array(
							'scout_id'             => $s_id,
							'first_name'           => $exp['first_name'] ?? '',
							'last_name'            => $exp['last_name'] ?? '',
							'unit_name'            => $exp['patrol'] ?: 'Unassigned',
							'allocated_event_code' => $event_code,
							'allocated_team_code'  => $scout_to_team[$s_id] ?? 'UNALLOCATED',
							'team_preferences'     => '',
							'other_events'         => array(),
							'first_aid_level'      => $exp['first_aid_level'] ?? 'none',
							'has_asn'              => false,
						);
					}
				}
			}
		}
		$teams_out      = array();
		if ( $query_event_id ) {
			$raw_teams = $this->teams->list_by_expedition( (int) $query_event_id );
			foreach ( $raw_teams as $t ) {
				$teams_out[] = array(
					'ID'            => (int) $t['ID'],
					'ems_team_code' => $t['ems_team_code'] ?? '',
				);
			}
		}

		return new \WP_REST_Response(
			array(
				'explorers' => $available,
				'teams'     => $teams_out,
			)
		);
	}

	public function allocate_planning_explorers( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$scout_ids             = $request->get_param( 'scout_ids' ) ?? array();
		$expedition_signup_ids = $request->get_param( 'expedition_signup_ids' ) ?? array();
		$event_code            = (string) ( $request->get_param( 'event_code' ) ?? '' );
		$mode                  = (string) ( $request->get_param( 'mode' ) ?? 'unallocated' );
		$target_team_id        = (int) ( $request->get_param( 'team_id' ) ?? 0 );

		// scout_ids / expedition_signup_ids may arrive as a JSON array or a comma-separated string
		if ( is_string( $scout_ids ) ) {
			$scout_ids = array_filter( array_map( 'intval', explode( ',', $scout_ids ) ) );
		}
		if ( is_string( $expedition_signup_ids ) ) {
			$expedition_signup_ids = array_filter( array_map( 'intval', explode( ',', $expedition_signup_ids ) ) );
		}

		$scout_ids             = array_values( array_filter( array_map( 'intval', (array) $scout_ids ), fn( $v ) => $v > 0 ) );
		$expedition_signup_ids = array_values( array_filter( array_map( 'intval', (array) $expedition_signup_ids ), fn( $v ) => $v > 0 ) );

		if ( ( empty( $scout_ids ) && empty( $expedition_signup_ids ) ) || empty( $event_code ) ) {
			return $this->error( 'ems_invalid_parameters', 'scout_ids or expedition_signup_ids and event_code are required.', 400 );
		}

		global $wpdb;
		$event_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'ems_event_code' AND meta_value = %s LIMIT 1",
				$event_code
			)
		);

		if ( ! $event_id ) {
			return $this->error( 'ems_event_not_found', "No expedition found with event_code '{$event_code}'.", 404 );
		}

		$event = $this->expeditions->get_by_id( $event_id );
		if ( ! $event ) {
			return $this->error( 'ems_event_not_found', 'Event not found.', 404 );
		}

		if ( $mode === 'unallocated' ) {
			$unallocated = $this->teams->get_unallocated_team( $event_id );
			if ( ! $unallocated ) {
				$dest_team_id = $this->teams->create( $event_id, $event['ems_event_code'], 'UNALLOCATED' );
			} else {
				$dest_team_id = (int) $unallocated['ID'];
			}
		} elseif ( $mode === 'existing_team' ) {
			if ( $target_team_id <= 0 ) {
				return $this->error( 'ems_invalid_team', 'target_team_id is required for existing_team allocation.', 400 );
			}
			$dest_team_id = $target_team_id;
		} elseif ( $mode === 'new_team' ) {
			$dest_team_id = $this->teams->create( $event_id, $event['ems_event_code'] );
		} elseif ( $mode === 'remove' ) {
			$dest_team_id = 0;
		} else {
			return $this->error( 'ems_invalid_mode', 'Invalid allocation_mode.', 400 );
		}

		$table    = $wpdb->prefix . 'ems_team_members';
		$added_by = get_current_user_id() ?: 1;
		$level    = strtolower( (string) ( $event['ems_level'] ?? 'bronze' ) );

		// Build unified targets list supporting both expedition_signup_ids (including scout_id = 0) and legacy scout_ids.
		$targets         = array();
		$seen_signup_ids = array();
		$seen_scout_ids  = array();

		foreach ( $expedition_signup_ids as $esid ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, scout_id FROM {$wpdb->prefix}ems_expedition_signups WHERE id = %d LIMIT 1",
					$esid
				),
				ARRAY_A
			);
			if ( ! $row ) {
				continue;
			}
			$sid               = (int) ( $row['scout_id'] ?? 0 );
			$seen_signup_ids[] = $esid;
			if ( $sid > 0 ) {
				$seen_scout_ids[] = $sid;
			}
			$targets[] = array(
				'scout_id'             => $sid,
				'expedition_signup_id' => $esid,
			);
		}

		foreach ( $scout_ids as $sid ) {
			if ( in_array( $sid, $seen_scout_ids, true ) ) {
				continue;
			}
			$targets[] = array(
				'scout_id'             => $sid,
				'expedition_signup_id' => 0,
			);
		}

		foreach ( $targets as $target ) {
			$scout_id            = (int) $target['scout_id'];
			$explicit_signup_id  = (int) $target['expedition_signup_id'];

			if ( $scout_id > 0 && $explicit_signup_id > 0 ) {
				$allocations = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT team_post_id, cohort_id, expedition_signup_id, notification_status FROM {$table} WHERE scout_id = %d OR expedition_signup_id = %d",
						$scout_id,
						$explicit_signup_id
					),
					ARRAY_A
				) ?: array();
			} elseif ( $scout_id > 0 ) {
				$allocations = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT team_post_id, cohort_id, expedition_signup_id, notification_status FROM {$table} WHERE scout_id = %d",
						$scout_id
					),
					ARRAY_A
				) ?: array();
			} elseif ( $explicit_signup_id > 0 ) {
				$allocations = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT team_post_id, cohort_id, expedition_signup_id, notification_status FROM {$table} WHERE expedition_signup_id = %d",
						$explicit_signup_id
					),
					ARRAY_A
				) ?: array();
			} else {
				continue;
			}

			$preserved_cohort_id = 0;
			$preserved_signup_id = $explicit_signup_id;
			$preserved_notif     = 'draft';

			foreach ( $allocations as $alloc ) {
				if ( (int) ( $alloc['cohort_id'] ?? 0 ) > 0 && $preserved_cohort_id === 0 ) {
					$preserved_cohort_id = (int) $alloc['cohort_id'];
				}
				if ( (int) ( $alloc['expedition_signup_id'] ?? 0 ) > 0 && $preserved_signup_id === 0 ) {
					$preserved_signup_id = (int) $alloc['expedition_signup_id'];
				}
				$old_team_id = (int) $alloc['team_post_id'];
				$old_team    = get_post( $old_team_id );
				if ( $old_team && $old_team->post_type === 'team' ) {
					$old_event_id = $old_team->post_parent;
					$old_level    = get_post_meta( $old_event_id, 'ems_level', true );
					$old_type     = get_post_meta( $old_event_id, 'ems_type', true );

					if ( strtolower( $old_level ) === $level && strtolower( $old_type ) === strtolower( $event['ems_type'] ?? '' ) ) {
						$notif = (string) ( $alloc['notification_status'] ?? 'draft' );
						if ( $notif === 'notified' || $notif === 're_notify_needed' ) {
							$preserved_notif = 're_notify_needed';
						}
						if ( $scout_id > 0 ) {
							$this->team_members->remove( $old_team_id, $scout_id );
						} elseif ( $preserved_signup_id > 0 ) {
							$this->team_members->remove_by_signup( $old_team_id, $preserved_signup_id );
						}
					}
				}
			}

			if ( $preserved_signup_id <= 0 && $scout_id > 0 ) {
				$preserved_signup_id = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$wpdb->prefix}ems_expedition_signups WHERE scout_id = %d AND dofe_level = %s AND signup_status = 'submitted' ORDER BY id DESC LIMIT 1",
						$scout_id,
						$level
					)
				);
			}

			if ( $dest_team_id > 0 ) {
				if ( $scout_id > 0 ) {
					try {
						$this->team_members->assign( $dest_team_id, $scout_id, $added_by, 0 );
					} catch ( \InvalidArgumentException $e ) {
						// Already assigned, ignore
					}

					$wpdb->query(
						$wpdb->prepare(
							"UPDATE {$table}
							SET expedition_signup_id = %s,
								notification_status = %s
							WHERE team_post_id = %d AND scout_id = %d",
							$preserved_signup_id > 0 ? $preserved_signup_id : null,
							$preserved_notif,
							$dest_team_id,
							$scout_id
						)
					);
				} elseif ( $preserved_signup_id > 0 ) {
					$exists = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT id FROM {$table} WHERE team_post_id = %d AND expedition_signup_id = %d LIMIT 1",
							$dest_team_id,
							$preserved_signup_id
						)
					);
					if ( ! $exists ) {
						$wpdb->insert(
							$table,
							array(
								'team_post_id'         => $dest_team_id,
								'scout_id'             => 0,
								'user_id'              => 0,
								'expedition_signup_id' => $preserved_signup_id,
								'notification_status'  => $preserved_notif,
								'added_by'             => $added_by,
								'added_at'             => current_time( 'mysql', true ),
							),
							array( '%d', '%d', '%d', '%d', '%s', '%d', '%s' )
						);
					}
				}

				if ( $mode === 'unallocated' ) {
					$this->get_transitions()->on_team_member_removed( $preserved_signup_id, $scout_id, $level );
				} else {
					$this->get_transitions()->on_team_member_added( $dest_team_id, $preserved_signup_id, $scout_id );
				}
			} else {
				$this->get_transitions()->on_team_member_removed( $preserved_signup_id, $scout_id, $level );
			}
		}

		return new \WP_REST_Response( array( 'success' => true ) );
	}

	public function add_planning_explorer( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$scout_id   = (int) $request->get_param( 'scout_id' );
		$event_code = (string) $request->get_param( 'event_code' );

		global $wpdb;
		$event_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'ems_event_code' AND meta_value = %s LIMIT 1",
				$event_code
			)
		);

		if ( ! $event_id ) {
			return $this->error( 'ems_event_not_found', "No expedition found with event_code '{$event_code}'.", 404 );
		}

		$unallocated = $this->teams->get_unallocated_team( $event_id );
		if ( ! $unallocated ) {
			$unallocated_team_id = $this->teams->create( $event_id, $event_code, 'UNALLOCATED' );
		} else {
			$unallocated_team_id = (int) $unallocated['ID'];
		}

		$team_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'team'",
				$event_id
			)
		);

		if ( ! empty( $team_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $team_ids ), '%d' ) );
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}ems_team_members WHERE team_post_id IN ($placeholders) AND scout_id = %d LIMIT 1",
					array_merge( $team_ids, array( $scout_id ) )
				)
			);
			if ( $existing ) {
				return $this->error( 'ems_explorer_already_assigned', 'Explorer is already assigned to this expedition.', 400 );
			}
		}

		$explorer_row = $this->explorers->find_by_scout_id( $scout_id );
		$wp_user_id = $explorer_row ? (int) ($explorer_row['wp_user_id'] ?? 0) : 0;

		try {
			$this->team_members->assign( $unallocated_team_id, $scout_id, get_current_user_id(), $wp_user_id );
		} catch ( \Exception $e ) {
			return $this->error( 'ems_assign_failed', $e->getMessage(), 500 );
		}

		return new \WP_REST_Response( array( 'success' => true ) );
	}

	public function get_planning_synced_explorers( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( $this->list_explorers() );
	}

	public function export_participant_signups( \WP_REST_Request $request ) {
		$status = $request->get_param( 'status' ) ?: 'all';
		$level  = $request->get_param( 'level' ) ?: 'all';

		$signups = $this->signups->get_participant_signups_for_export( $status, $level );

		// Generate filename
		$filename = 'ems-participant-signups-' . current_time( 'Y-m-d' ) . '.csv';

		// Set up response headers for download
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );

		// Header columns
		fputcsv(
			$output,
			array(
				'ID',
				'Scout ID',
				'Explorer First Name',
				'Explorer Last Name',
				'Email',
				'Parent Email',
				'DofE Level',
				'DofE Number',
				'First Aid Status',
				'ESU Unit',
				'Payment Status',
				'Signup Status',
				'Linkage Status',
				'Processed By',
				'Processed At',
				'Reconciled By',
				'Reconciled At',
				'Created At',
			),
			',',
			'"',
			"\\"
		);

		foreach ( $signups as $signup ) {
			$linkage_status = 'unlinked';
			if ( ! empty( $signup['has_osm_record'] ) ) {
				$linkage_status = ! empty( $signup['osm_wp_user_id'] ) ? 'linked' : 'proposed';
			}

			$row = array(
				$signup['id'],
				$signup['scout_id'],
				$signup['explorer_first_name'],
				$signup['explorer_last_name'],
				$signup['explorer_email'],
				$signup['parent_email'] ?: '',
				ucfirst( $signup['dofe_level'] ),
				$signup['dofe_number'] ?: '',
				'', // First Aid Status is not on participant signups, output empty
				$signup['unit_name'] ?: 'Unassigned',
				ucfirst( $signup['payment_status'] ),
				ucfirst( $signup['signup_status'] ),
				$linkage_status,
				$signup['processed_by_name'] ?: '',
				$signup['processed_at'] ?: '',
				'', // Reconciled By
				'', // Reconciled At
				$signup['created_at'],
			);

			fputcsv(
				$output,
				array_map( array( self::class, 'escape_csv_cell' ), $row ),
				',',
				'"',
				"\\"
			);
		}

		fclose( $output );
		if ( ! defined( 'EMS_UNIT_TESTS' ) ) {
			exit;
		}
	}

	public static function escape_csv_cell( mixed $value ): mixed {
		if ( ! is_string( $value ) || $value === '' ) {
			return $value;
		}

		$first = $value[0];
		if ( in_array( $first, array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}

		return $value;
	}
}
