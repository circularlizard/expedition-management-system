<?php
namespace EMS\Admin;

use EMS\Data\Season_Repository;
use EMS\Data\Expedition_Repository;
use EMS\Data\Team_Repository;
use EMS\Data\Team_Member_Repository;
use EMS\Data\OSM_Explorer_Repository;
use EMS\Data\OSM_Event_Repository;
use EMS\Data\Signup_Repository;
use EMS\Core\CPT_Registry;

class Expedition_Admin_Controller {

	private Season_Repository $seasons;
	private Expedition_Repository $expeditions;
	private Team_Repository $teams;
	private Team_Member_Repository $team_members;
	private OSM_Explorer_Repository $explorers;
	private OSM_Event_Repository $osm_events;
	private CPT_Registry $cpt_registry;
	private Signup_Repository $signups;

	public function __construct(
		Season_Repository $seasons,
		Expedition_Repository $expeditions,
		Team_Repository $teams,
		Team_Member_Repository $team_members,
		?OSM_Explorer_Repository $explorers = null,
		?OSM_Event_Repository $osm_events = null,
		?CPT_Registry $cpt_registry = null,
		?Signup_Repository $signups = null
	) {
		$this->seasons      = $seasons;
		$this->expeditions  = $expeditions;
		$this->teams        = $teams;
		$this->team_members = $team_members;
		$this->explorers    = $explorers ?: new OSM_Explorer_Repository();
		$this->osm_events   = $osm_events ?: new OSM_Event_Repository();
		$this->cpt_registry = $cpt_registry ?: new CPT_Registry();
		$this->signups      = $signups ?: new Signup_Repository();
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

		register_rest_route(
			'ems/v1',
			'/events/(?P<source_id>\d+)/populate/(?P<target_id>\d+)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'populate_event' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'source_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'target_id' => array(
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

	public function create_event( \WP_REST_Request $request ): \WP_REST_Response {
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

	public function update_event( \WP_REST_Request $request ): \WP_REST_Response {
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

	public function delete_event( \WP_REST_Request $request ): \WP_REST_Response {
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

	public function create_team( \WP_REST_Request $request ): \WP_REST_Response {
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

	public function delete_team( \WP_REST_Request $request ): \WP_REST_Response {
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

	public function move_team( \WP_REST_Request $request ): \WP_REST_Response {
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

	public function duplicate_team( \WP_REST_Request $request ): \WP_REST_Response {
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

	public function populate_event( \WP_REST_Request $request ): \WP_REST_Response {
		$source_id = (int) $request->get_param( 'source_id' );
		$target_id = (int) $request->get_param( 'target_id' );

		$source = $this->expeditions->get_by_id( $source_id );
		$target = $this->expeditions->get_by_id( $target_id );

		if ( ! $source || ! $target ) {
			return $this->error( 'ems_event_not_found', 'Event not found.', 404 );
		}

		if ( $this->event_type( $source_id ) === $this->event_type( $target_id ) ) {
			return $this->error( 'ems_same_event_type', 'Populate only works between different event types.', 422 );
		}

		$created = $this->teams->populate_from_event( $source_id, $target_id, $target['ems_event_code'] );
		foreach ( $created as $new_team_id ) {
			$members = $this->team_members->list_by_team( $new_team_id );
			foreach ( $members as $member ) {
				$this->explorers->touch_last_local_update( (int) $member['scout_id'] );
			}
		}
		return new \WP_REST_Response( $this->teams->list_by_expedition( $target_id ), 201 );
	}

	public function add_member( \WP_REST_Request $request ): \WP_REST_Response {
		$team_id  = (int) $request->get_param( 'team_id' );
		$body     = $request->get_json_params() ?: array();
		$scout_id = (int) ( $body['scout_id'] ?? 0 );

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
			\EMS\Core\Audit_Logger::log( 'team_member_add', $scout_id );
			return new \WP_REST_Response( $this->hydrate_members( $this->team_members->list_by_team( $team_id ) ), 201 );
		} catch ( \InvalidArgumentException $e ) {
			return $this->error( 'ems_member_already_in_team', $e->getMessage(), 409 );
		} catch ( \RuntimeException $e ) {
			return $this->error( 'ems_database_error', $e->getMessage(), 500 );
		}
	}

	public function remove_member( \WP_REST_Request $request ): \WP_REST_Response {
		$team_id  = (int) $request->get_param( 'team_id' );
		$scout_id = (int) $request->get_param( 'scout_id' );

		$team = $this->teams->get_by_id( $team_id );
		if ( ! $team ) {
			return $this->error( 'ems_team_not_found', 'Team not found.', 404 );
		}

		$this->team_members->remove( $team_id, $scout_id );
		$this->explorers->touch_last_local_update( $scout_id );
		\EMS\Core\Audit_Logger::log( 'team_member_remove', $scout_id );

		// The team may have been auto-deleted if that was its last member.
		if ( ! $this->teams->get_by_id( $team_id ) ) {
			return new \WP_REST_Response( array( 'team_deleted' => true ) );
		}

		return new \WP_REST_Response( $this->hydrate_members( $this->team_members->list_by_team( $team_id ) ) );
	}

	public function move_explorer( \WP_REST_Request $request ): \WP_REST_Response {
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

	public function update_first_aid_level( \WP_REST_Request $request ): \WP_REST_Response {
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
			$event['teams']        = $teams;
			$event['member_count'] = array_sum( array_column( $teams, 'member_count' ) );
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
			$event['teams']        = $teams;
			$event['member_count'] = array_sum( array_column( $teams, 'member_count' ) );
			$events[]              = $event;
		}

		return new \WP_REST_Response( array( 'events' => $events ) );
	}

	public function get_whatsapp_links( \WP_REST_Request $request ): \WP_REST_Response {
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

	public function update_whatsapp_links( \WP_REST_Request $request ): \WP_REST_Response {
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

	private function error( string $code, string $message, int $status ): \WP_REST_Response {
		return new \WP_REST_Response( new \WP_Error( $code, $message, array( 'status' => $status ) ), $status );
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
			'/planning-board',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_planning_board' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

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
	}

	private function register_signup_routes(): void {
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
	}

	public function list_participant_signups( \WP_REST_Request $request ): \WP_REST_Response {
		$status = $request->get_param( 'status' ) ?: 'received';
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
				'explorer_first_name' => $row['explorer_first_name'],
				'explorer_last_name'  => $row['explorer_last_name'],
				'explorer_email'      => $row['explorer_email'],
				'parent_email'        => $row['parent_email'],
				'leader_email'        => $row['leader_email'] ?? '',
				'dofe_level'          => $row['dofe_level'],
				'dob'                 => $row['dob'],
				'dofe_registered'     => $row['dofe_registered'],
				'dofe_number'         => $row['dofe_number'],
				'dofe_org'            => $row['dofe_org'],
				'bronze_completion'   => $bronze,
				'silver_completion'   => $silver,
				'signup_status'       => $row['signup_status'],
				'payment_status'      => $row['payment_status'],
				'form_submission_id'  => (int) $row['form_submission_id'],
				'is_synced_osm'       => isset( $row['is_synced_osm'] ) && (int) $row['is_synced_osm'] === 1,
				'created_at'          => $row['created_at'],
				'updated_at'          => $row['updated_at'],
			);
		}

		return new \WP_REST_Response( $response_data );
	}

	public function process_participant_signup( \WP_REST_Request $request ): \WP_REST_Response {
		$id          = (int) $request->get_param( 'id' );
		$body        = $request->get_json_params() ?: array();
		$dofe_number = isset( $body['dofe_number'] ) ? sanitize_text_field( $body['dofe_number'] ) : null;

		$signup = $this->signups->get_participant_signup( $id );
		if ( ! $signup ) {
			return $this->error( 'ems_signup_not_found', 'Signup not found.', 404 );
		}

		if ( $signup['signup_status'] === 'allocated' ) {
			return $this->error( 'ems_signup_already_processed', 'Signup is already processed.', 400 );
		}

		$this->signups->process_participant_signup( $id, get_current_user_id(), $dofe_number );

		return new \WP_REST_Response( array( 'processed' => true ) );
	}

	public function archive_participant_signup( \WP_REST_Request $request ): \WP_REST_Response {
		$id = (int) $request->get_param( 'id' );

		$signup = $this->signups->get_participant_signup( $id );
		if ( ! $signup ) {
			return $this->error( 'ems_signup_not_found', 'Signup not found.', 404 );
		}

		$this->signups->archive_participant_signup( $id );

		return new \WP_REST_Response( array( 'archived' => true ) );
	}

	public function list_expedition_signups( \WP_REST_Request $request ): \WP_REST_Response {
		$status = $request->get_param( 'status' ) ?: 'pending';
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
				'explorer_first_name'      => $row['explorer_first_name'],
				'explorer_last_name'       => $row['explorer_last_name'],
				'explorer_email'           => $row['explorer_email'],
				'parent_email'             => $row['parent_email'],
				'leader_email'             => $row['leader_email'] ?? '',
				'dofe_level'               => $row['dofe_level'],
				'dofe_number'              => $row['dofe_number'],
				'expedition_preferences'   => $prefs,
				'additional_support_needs' => $row['additional_support_needs'],
				'first_aid_status'         => $row['first_aid_status'],
				'first_aid_expiry'         => $row['first_aid_expiry'],
				'signup_status'            => $row['signup_status'],
				'form_submission_id'       => (int) $row['form_submission_id'],
				'is_synced_osm'            => isset( $row['is_synced_osm'] ) && (int) $row['is_synced_osm'] === 1,
				'created_at'               => $row['created_at'],
				'updated_at'               => $row['updated_at'],
			);
		}

		return new \WP_REST_Response( $response_data );
	}



	public function archive_expedition_signup( \WP_REST_Request $request ): \WP_REST_Response {
		$id = (int) $request->get_param( 'id' );

		$signup = $this->signups->get_expedition_signup( $id );
		if ( ! $signup ) {
			return $this->error( 'ems_signup_not_found', 'Signup not found.', 404 );
		}

		$this->signups->archive_expedition_signup( $id );

		return new \WP_REST_Response( array( 'archived' => true ) );
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

	public function get_explorer_asn( \WP_REST_Request $request ): \WP_REST_Response {
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

	public function update_explorer_asn( \WP_REST_Request $request ): \WP_REST_Response {
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

	public function get_explorer_profile( \WP_REST_Request $request ): \WP_REST_Response {
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
					"SELECT leader_email FROM {$wpdb->prefix}ems_units 
                 WHERE name = %s AND section_id = %d AND active = 1 
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

	public function list_planning_board( \WP_REST_Request $request ): \WP_REST_Response {
		$raw_events = $this->expeditions->list_all_chronological();
		$filtered   = array();

		// Normalise query params
		$req_level = strtolower( trim( (string) $request->get_param( 'level' ) ) );
		$req_type  = strtolower( trim( (string) $request->get_param( 'type' ) ) );
		// The CPT stores 'qualifying'; the UI sends 'qualifier' — accept both.
		if ( $req_type === 'qualifier' ) {
			$req_type = 'qualifying';
		}

		foreach ( $raw_events as $event ) {
			$level  = strtolower( $event['ems_level'] ?? '' );
			$type   = strtolower( $event['ems_type'] ?? '' );
			$status = strtolower( $event['ems_status'] ?? '' );

			if ( $status === 'archived' ) {
				continue;
			}
			if ( $level !== 'silver' && $level !== 'gold' ) {
				continue;
			}
			// Apply level filter when provided
			if ( $req_level !== '' && $level !== $req_level ) {
				continue;
			}
			// Apply type filter when provided
			if ( $req_type !== '' && $type !== $req_type ) {
				continue;
			}

			$event_code = $event['ems_event_code'] ?? '';
			if ( empty( $event_code ) ) {
				continue;
			}

			// Get available count from signups
			$signups_list    = $this->signups->get_expedition_signups( 'pending' );
			$available_count = 0;
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

				if ( in_array( $event_code, $practice, true ) || in_array( $event_code, $qualifier, true ) ) {
					++$available_count;
				}
			}

			// Get allocated count: get all teams of this event, then count team members
			$allocated_count = 0;
			$teams_list      = $this->teams->list_by_expedition( $event['ID'] );

			$unallocated = $this->teams->get_unallocated_team( $event['ID'] );
			if ( $unallocated ) {
				$teams_list[] = $unallocated;
			}

			foreach ( $teams_list as $team ) {
				$members          = $this->team_members->list_by_team( $team['ID'] );
				$allocated_count += count( $members );
			}

			$filtered[] = array(
				'id'              => (int) $event['ID'],
				'title'           => $event['post_title'],
				'event_code'      => $event_code,
				'type'            => $event['ems_type'] ?? '',
				'level'           => $level,
				'start_date'      => $event['ems_start_date'] ?? '',
				'end_date'        => $event['ems_end_date'] ?? '',
				'available_count' => $available_count,
				'allocated_count' => $allocated_count,
				'first_aid_level' => get_post_meta( $event['ID'], 'ems_first_aid_level', true ) ?: 'none',
			);
		}

		return new \WP_REST_Response( $filtered );
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

	public function allocate_planning_explorers( \WP_REST_Request $request ): \WP_REST_Response {
		$scout_ids      = $request->get_param( 'scout_ids' ) ?? array();
		$event_code     = (string) ( $request->get_param( 'event_code' ) ?? '' );
		$mode           = (string) ( $request->get_param( 'mode' ) ?? 'unallocated' );
		$target_team_id = (int) ( $request->get_param( 'team_id' ) ?? 0 );

		// scout_ids may arrive as a JSON array or a comma-separated string
		if ( is_string( $scout_ids ) ) {
			$scout_ids = array_filter( array_map( 'intval', explode( ',', $scout_ids ) ) );
		}

		if ( empty( $scout_ids ) || empty( $event_code ) ) {
			return $this->error( 'ems_invalid_parameters', 'scout_ids and event_code are required.', 400 );
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

		foreach ( $scout_ids as $scout_id ) {
			$scout_id = (int) $scout_id;

			$allocations = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT team_post_id FROM {$table} WHERE scout_id = %d",
					$scout_id
				),
				ARRAY_A
			) ?: array();

			foreach ( $allocations as $alloc ) {
				$old_team_id = (int) $alloc['team_post_id'];
				$old_team    = get_post( $old_team_id );
				if ( $old_team && $old_team->post_type === 'team' ) {
					$old_event_id = $old_team->post_parent;
					$old_level    = get_post_meta( $old_event_id, 'ems_level', true );
					$old_type     = get_post_meta( $old_event_id, 'ems_type', true );

					if ( strtolower( $old_level ) === strtolower( $event['ems_level'] ?? '' ) && strtolower( $old_type ) === strtolower( $event['ems_type'] ?? '' ) ) {
						$this->team_members->remove( $old_team_id, $scout_id );
					}
				}
			}

			if ( $dest_team_id > 0 ) {
				try {
					$this->team_members->assign( $dest_team_id, $scout_id, $added_by, 0 );
				} catch ( \InvalidArgumentException $e ) {
					// Already assigned, ignore
				}
			}
		}

		return new \WP_REST_Response( array( 'success' => true ) );
	}

	public function add_planning_explorer( \WP_REST_Request $request ): \WP_REST_Response {
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
			)
		);

		foreach ( $signups as $signup ) {
			$linkage_status = 'unlinked';
			if ( ! empty( $signup['has_osm_record'] ) ) {
				$linkage_status = ! empty( $signup['osm_wp_user_id'] ) ? 'linked' : 'proposed';
			}

			fputcsv(
				$output,
				array(
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
				)
			);
		}

		fclose( $output );
		if ( ! defined( 'EMS_UNIT_TESTS' ) ) {
			exit;
		}
	}
}
