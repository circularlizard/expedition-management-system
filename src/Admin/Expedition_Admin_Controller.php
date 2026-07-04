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
        $this->register_season_routes();
        $this->register_event_routes();
        $this->register_team_routes();
        $this->register_member_routes();
        $this->register_osm_event_route();
        $this->register_board_route();
        $this->register_signup_routes();
        $this->register_whatsapp_routes();
        $this->register_planning_routes();
    }

    private function register_season_routes(): void {
        register_rest_route( 'ems/v1', '/seasons', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'create_season' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( 'ems/v1', '/seasons', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'list_seasons' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( 'ems/v1', '/seasons/(?P<id>\d+)/archive', [
            'methods'             => \WP_REST_Server::EDITABLE,
            'callback'            => [ $this, 'archive_season' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'id' => [ 'type' => 'integer', 'required' => true ],
            ],
        ] );

        register_rest_route( 'ems/v1', '/seasons/(?P<id>\d+)', [
            'methods'             => \WP_REST_Server::DELETABLE,
            'callback'            => [ $this, 'delete_season' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'id' => [ 'type' => 'integer', 'required' => true ],
            ],
        ] );
    }

    private function register_event_routes(): void {
        register_rest_route( 'ems/v1', '/events', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'list_events' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( 'ems/v1', '/events', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'create_event' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( 'ems/v1', '/events/(?P<id>\d+)', [
            'methods'             => \WP_REST_Server::EDITABLE,
            'callback'            => [ $this, 'update_event' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
            ],
        ] );

        register_rest_route( 'ems/v1', '/events/(?P<id>\d+)', [
            'methods'             => \WP_REST_Server::DELETABLE,
            'callback'            => [ $this, 'delete_event' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
            ],
        ] );
    }

    private function register_team_routes(): void {
        register_rest_route( 'ems/v1', '/events/(?P<event_id>\d+)/teams', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'create_team' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'event_id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
            ],
        ] );

        register_rest_route( 'ems/v1', '/events/(?P<event_id>\d+)/teams', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'list_event_teams' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'event_id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
            ],
        ] );

        register_rest_route( 'ems/v1', '/teams/(?P<id>\d+)', [
            'methods'             => \WP_REST_Server::DELETABLE,
            'callback'            => [ $this, 'delete_team' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
            ],
        ] );

        register_rest_route( 'ems/v1', '/teams/(?P<id>\d+)/move', [
            'methods'             => \WP_REST_Server::EDITABLE,
            'callback'            => [ $this, 'move_team' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
            ],
        ] );

        register_rest_route( 'ems/v1', '/teams/(?P<id>\d+)/duplicate', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'duplicate_team' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
            ],
        ] );

        register_rest_route( 'ems/v1', '/events/(?P<source_id>\d+)/populate/(?P<target_id>\d+)', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'populate_event' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'source_id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
                'target_id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
            ],
        ] );
    }

    private function register_member_routes(): void {
        register_rest_route( 'ems/v1', '/teams/(?P<team_id>\d+)/members', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'add_member' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'team_id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
            ],
        ] );

        register_rest_route( 'ems/v1', '/teams/(?P<team_id>\d+)/members/(?P<scout_id>\d+)', [
            'methods'             => \WP_REST_Server::DELETABLE,
            'callback'            => [ $this, 'remove_member' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'team_id'  => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
                'scout_id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
            ],
        ] );

        register_rest_route( 'ems/v1', '/explorers/(?P<scout_id>\d+)/move-team', [
            'methods'             => \WP_REST_Server::EDITABLE,
            'callback'            => [ $this, 'move_explorer' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'scout_id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
            ],
        ] );

        register_rest_route( 'ems/v1', '/explorers/(?P<scout_id>\d+)/first-aid', [
            'methods'             => \WP_REST_Server::EDITABLE,
            'callback'            => [ $this, 'update_first_aid_level' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'scout_id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
            ],
        ] );

        register_rest_route( 'ems/v1', '/explorers/(?P<scout_id>\d+)/asn', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_explorer_asn' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'scout_id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
            ],
        ] );

        register_rest_route( 'ems/v1', '/explorers/(?P<scout_id>\d+)/asn', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'update_explorer_asn' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'scout_id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
            ],
        ] );
    }

    private function register_board_route(): void {
        register_rest_route( 'ems/v1', '/expedition-board', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_board' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );
    }

    private function register_osm_event_route(): void {
        register_rest_route( 'ems/v1', '/osm-events', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'list_osm_events' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );
    }

    public function check_permission(): bool {
        return current_user_can( 'manage_options' );
    }

    public function create_season( \WP_REST_Request $request ): \WP_REST_Response {
        $body = $request->get_json_params() ?: [];
        try {
            $id = $this->seasons->create( [
                'post_title' => $body['post_title'] ?? '',
                'year'       => $body['year'] ?? '',
                'status'     => $body['status'] ?? 'active',
            ] );
            return new \WP_REST_Response( $this->seasons->get_by_id( $id ), 201 );
        } catch ( \InvalidArgumentException $e ) {
            return $this->error( 'ems_season_year_exists', $e->getMessage(), 409 );
        }
    }

    public function list_seasons(): \WP_REST_Response {
        return new \WP_REST_Response( $this->seasons->list_all() );
    }

    public function archive_season( \WP_REST_Request $request ): \WP_REST_Response {
        $id = (int) $request->get_param( 'id' );
        if ( ! $this->seasons->archive( $id ) ) {
            return $this->error( 'ems_season_not_found', 'Season not found.', 404 );
        }
        return new \WP_REST_Response( $this->seasons->get_by_id( $id ) );
    }

    public function delete_season( \WP_REST_Request $request ): \WP_REST_Response {
        $id = (int) $request->get_param( 'id' );

        if ( ! $this->seasons->get_by_id( $id ) ) {
            return $this->error( 'ems_season_not_found', 'Season not found.', 404 );
        }

        if ( $this->seasons->has_events( $id ) ) {
            return $this->error( 'ems_season_has_events', 'Cannot delete season with events.', 409 );
        }

        $this->seasons->delete( $id );
        return new \WP_REST_Response( [ 'deleted' => true ] );
    }

    public function create_event( \WP_REST_Request $request ): \WP_REST_Response {
        $body = $request->get_json_params() ?: [];
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
        $body = $request->get_json_params() ?: [];

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
        return new \WP_REST_Response( [ 'deleted' => true ] );
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
        return new \WP_REST_Response( [ 'deleted' => true ] );
    }

    public function move_team( \WP_REST_Request $request ): \WP_REST_Response {
        $id      = (int) $request->get_param( 'id' );
        $body    = $request->get_json_params() ?: [];
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
        $body      = $request->get_json_params() ?: [];
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
            $new_id = $this->teams->duplicate( $id, $target_id, $target['ems_event_code'] );
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
        $body     = $request->get_json_params() ?: [];
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

        // The team may have been auto-deleted if that was its last member.
        if ( ! $this->teams->get_by_id( $team_id ) ) {
            return new \WP_REST_Response( [ 'team_deleted' => true ] );
        }

        return new \WP_REST_Response( $this->hydrate_members( $this->team_members->list_by_team( $team_id ) ) );
    }

    public function move_explorer( \WP_REST_Request $request ): \WP_REST_Response {
        $scout_id      = (int) $request->get_param( 'scout_id' );
        $body          = $request->get_json_params() ?: [];
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
        return new \WP_REST_Response( $this->hydrate_members( $this->team_members->list_by_team( $target_team_id ) ) );
    }

    public function update_first_aid_level( \WP_REST_Request $request ): \WP_REST_Response {
        $scout_id = (int) $request->get_param( 'scout_id' );
        $body     = $request->get_json_params() ?: [];
        $level    = $body['first_aid_level'] ?? '';

        $allowed = [ 'none', 'first_response', 'full_first_aid' ];
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
        return new \WP_REST_Response( [ 'scout_id' => $scout_id, 'first_aid_level' => $level ] );
    }

    public function get_board(): \WP_REST_Response {
        $events_data = $this->expeditions->list_all_chronological();
        $events      = [];

        foreach ( $events_data as $event ) {
            $teams = [];
            foreach ( $this->teams->list_by_expedition( $event['ID'] ) as $team ) {
                $members = $this->team_members->list_by_team( $team['ID'] );
                $team['member_count'] = count( $members );
                $team['size_warning'] = $team['member_count'] < 4 || $team['member_count'] > 7;
                $team['members']      = $this->hydrate_members( $members );
                $teams[]              = $team;
            }
            $event['teams']        = $teams;
            $event['member_count'] = array_sum( array_column( $teams, 'member_count' ) );
            $events[]              = $event;
        }

        $synthetic_season = [
            'ID'                => 0,
            'post_title'        => 'All Events',
            'ems_season_year'   => '',
            'ems_season_status' => 'active',
            'events'            => $events,
        ];

        return new \WP_REST_Response( [
            'seasons'   => [ $synthetic_season ],
            'explorers' => $this->list_explorers(),
            'last_sync' => get_option( 'ems_osm_last_sync' ) ?: null,
        ] );
    }

    public function list_events( \WP_REST_Request $request ): \WP_REST_Response {
        $tab             = $request->get_param( 'tab' ) ?: 'upcoming';
        $include_archived = (bool) $request->get_param( 'include_archived' );

        switch ( $tab ) {
            case 'past':
                $raw_events = $this->expeditions->list_past();
                break;
            case 'all':
                $raw_events = $this->expeditions->list_all_chronological();
                break;
            default:
                $raw_events = $this->expeditions->list_upcoming();
                break;
        }

        if ( ! $include_archived ) {
            $raw_events = array_values( array_filter( $raw_events, static function ( $e ) {
                return ( $e['ems_status'] ?? '' ) !== 'archived';
            } ) );
        }

        $events = [];
        foreach ( $raw_events as $event ) {
            $teams = [];
            foreach ( $this->teams->list_by_expedition( $event['ID'] ) as $team ) {
                $members = $this->team_members->list_by_team( $team['ID'] );
                $team['member_count'] = count( $members );
                $team['size_warning'] = $team['member_count'] < 4 || $team['member_count'] > 7;
                $teams[]              = $team;
            }
            $event['teams']        = $teams;
            $event['member_count'] = array_sum( array_column( $teams, 'member_count' ) );
            $events[]              = $event;
        }

        return new \WP_REST_Response( [ 'events' => $events ] );
    }

    public function get_whatsapp_links( \WP_REST_Request $request ): \WP_REST_Response {
        $id = (int) $request->get_param( 'id' );

        if ( ! $this->expeditions->get_by_id( $id ) ) {
            return $this->error( 'ems_event_not_found', 'Event not found.', 404 );
        }

        return new \WP_REST_Response( [
            'explorer_link' => get_post_meta( $id, 'ems_expedition_whatsapp_explorers', true ) ?: null,
            'parent_link'   => get_post_meta( $id, 'ems_expedition_whatsapp_parents', true ) ?: null,
        ] );
    }

    public function update_whatsapp_links( \WP_REST_Request $request ): \WP_REST_Response {
        $id   = (int) $request->get_param( 'id' );
        $body = $request->get_json_params() ?: [];

        if ( ! $this->expeditions->get_by_id( $id ) ) {
            return $this->error( 'ems_event_not_found', 'Event not found.', 404 );
        }

        $explorer_link = esc_url_raw( $body['explorer_link'] ?? '' );
        $parent_link   = esc_url_raw( $body['parent_link'] ?? '' );

        update_post_meta( $id, 'ems_expedition_whatsapp_explorers', $explorer_link );
        update_post_meta( $id, 'ems_expedition_whatsapp_parents', $parent_link );

        return new \WP_REST_Response( [
            'explorer_link' => $explorer_link ?: null,
            'parent_link'   => $parent_link ?: null,
        ] );
    }

    public function list_osm_events(): \WP_REST_Response {
        $events = [];
        foreach ( $this->osm_events->list_all() as $row ) {
            $events[] = [
                'id'         => (int) ( $row['id'] ?? 0 ),
                'event_id'   => (int) ( $row['event_id'] ?? 0 ),
                'section_id' => (int) ( $row['section_id'] ?? 0 ),
                'name'       => $row['name'] ?? '',
                'start_date' => $row['start_date'] ?? null,
                'end_date'   => $row['end_date'] ?? null,
                'location'   => $row['location'] ?? '',
            ];
        }
        return new \WP_REST_Response( $events );
    }

    private function validate_event( array $data, bool $require_all = true ): bool|\WP_Error {
        $valid_enums = [
            'ems_type'             => [ 'training', 'practice', 'qualifying' ],
            'ems_transport'        => [ 'hillwalking', 'biking', 'paddling' ],
            'ems_level'            => [ 'bronze', 'silver', 'gold' ],
            'ems_first_aid_level'  => [ 'none', 'first_response', 'full_first_aid' ],
            'ems_status'           => [ 'active', 'archived' ],
            'ems_route_status'     => [ 'draft', 'confirmed' ],
        ];

        if ( $require_all ) {
            $required = [ 'ems_event_code', 'ems_type', 'ems_transport', 'ems_level', 'ems_start_date', 'ems_end_date' ];
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
        $hydrated = [];
        foreach ( $members as $member ) {
            $member['scout_id'] = (int) ( $member['scout_id'] ?? 0 );
            $explorer = $this->explorers->find_by_scout_id( $member['scout_id'] );
            if ( $explorer ) {
                $member['first_name']       = $explorer['first_name'] ?? '';
                $member['last_name']        = $explorer['last_name'] ?? '';
                $member['patrol']           = $explorer['patrol'] ?? '';
                $member['first_aid_level']  = $explorer['first_aid_level'] ?? 'none';
                $member['has_asn']          = ! empty( $explorer['additional_support_needs'] ) || $this->has_signup_asn( $member['scout_id'] );
            }
            $hydrated[] = $member;
        }
        return $hydrated;
    }

    private function has_signup_asn( int $scout_id ): bool {
        return $this->signups->has_additional_support_needs( $scout_id );
    }

    private function list_explorers(): array {
        $explorers = [];
        foreach ( $this->explorers->list_all() as $row ) {
            $explorers[] = [
                'scout_id'             => (int) ( $row['scout_id'] ?? 0 ),
                'first_name'           => $row['first_name'] ?? '',
                'last_name'            => $row['last_name'] ?? '',
                'patrol'               => $row['patrol'] ?? '',
                'first_aid_level'      => $row['first_aid_level'] ?? 'none',
                'synced_at'            => $row['synced_at'] ?: null,
                'last_local_update_at' => $row['last_local_update_at'] ?: null,
            ];
        }
        return $explorers;
    }

    private function error( string $code, string $message, int $status ): \WP_REST_Response {
        return new \WP_REST_Response( new \WP_Error( $code, $message, [ 'status' => $status ] ), $status );
    }

    private function register_whatsapp_routes(): void {
        register_rest_route( 'ems/v1', '/events/(?P<id>\d+)/whatsapp', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_whatsapp_links' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
            ],
        ] );

        register_rest_route( 'ems/v1', '/events/(?P<id>\d+)/whatsapp', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'update_whatsapp_links' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
            ],
        ] );
    }

    private function register_planning_routes(): void {
        register_rest_route( 'ems/v1', '/planning-board', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'list_planning_board' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( 'ems/v1', '/planning-board/availability/(?P<event_code>[a-zA-Z0-9\-]+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'list_planning_availability' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'event_code' => [ 'type' => 'string', 'required' => true ],
            ],
        ] );

        register_rest_route( 'ems/v1', '/planning-board/allocate', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'allocate_planning_explorers' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );
    }

    private function register_signup_routes(): void {
        // Participant Places Endpoints
        register_rest_route( 'ems/v1', '/signups/participants', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'list_participant_signups' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( 'ems/v1', '/signups/participants/(?P<id>\d+)/process', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'process_participant_signup' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
            ],
        ] );

        register_rest_route( 'ems/v1', '/signups/participants/(?P<id>\d+)/archive', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'archive_participant_signup' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
            ],
        ] );

        // Expedition Entries Endpoints
        register_rest_route( 'ems/v1', '/signups/expeditions', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'list_expedition_signups' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );



        register_rest_route( 'ems/v1', '/signups/expeditions/(?P<id>\d+)/archive', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'archive_expedition_signup' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
            ],
        ] );
    }

    public function list_participant_signups( \WP_REST_Request $request ): \WP_REST_Response {
        $status = $request->get_param( 'status' ) ?: 'received';
        $rows = $this->signups->get_participant_signups( $status );

        $response_data = [];
        foreach ( $rows as $row ) {
            $bronze = ! empty( $row['bronze_completion'] ) ? json_decode( $row['bronze_completion'], true ) : null;
            $silver = ! empty( $row['silver_completion'] ) ? json_decode( $row['silver_completion'], true ) : null;

            $response_data[] = [
                'id'                  => (int) $row['id'],
                'scout_id'            => (int) $row['scout_id'],
                'parent_user_id'      => (int) $row['parent_user_id'],
                'unit_id'             => ! empty( $row['unit_id'] ) ? (int) $row['unit_id'] : null,
                'unit_name'           => $row['unit_name'] ?: 'Unassigned',
                'explorer_first_name' => $row['explorer_first_name'],
                'explorer_last_name'  => $row['explorer_last_name'],
                'explorer_email'      => $row['explorer_email'],
                'parent_email'        => $row['parent_email'],
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
            ];
        }

        return new \WP_REST_Response( $response_data );
    }

    public function process_participant_signup( \WP_REST_Request $request ): \WP_REST_Response {
        $id = (int) $request->get_param( 'id' );
        $body = $request->get_json_params() ?: [];
        $dofe_number = isset( $body['dofe_number'] ) ? sanitize_text_field( $body['dofe_number'] ) : null;

        $signup = $this->signups->get_participant_signup( $id );
        if ( ! $signup ) {
            return $this->error( 'ems_signup_not_found', 'Signup not found.', 404 );
        }

        if ( $signup['signup_status'] === 'allocated' ) {
            return $this->error( 'ems_signup_already_processed', 'Signup is already processed.', 400 );
        }

        $this->signups->process_participant_signup( $id, get_current_user_id(), $dofe_number );

        return new \WP_REST_Response( [ 'processed' => true ] );
    }

    public function archive_participant_signup( \WP_REST_Request $request ): \WP_REST_Response {
        $id = (int) $request->get_param( 'id' );

        $signup = $this->signups->get_participant_signup( $id );
        if ( ! $signup ) {
            return $this->error( 'ems_signup_not_found', 'Signup not found.', 404 );
        }

        $this->signups->archive_participant_signup( $id );

        return new \WP_REST_Response( [ 'archived' => true ] );
    }

    public function list_expedition_signups( \WP_REST_Request $request ): \WP_REST_Response {
        $status = $request->get_param( 'status' ) ?: 'pending';
        $rows = $this->signups->get_expedition_signups( $status );

        $response_data = [];
        foreach ( $rows as $row ) {
            $prefs = ! empty( $row['expedition_preferences'] ) ? json_decode( $row['expedition_preferences'], true ) : null;

            $response_data[] = [
                'id'                       => (int) $row['id'],
                'scout_id'                 => (int) $row['scout_id'],
                'parent_user_id'           => (int) $row['parent_user_id'],
                'unit_id'                  => ! empty( $row['unit_id'] ) ? (int) $row['unit_id'] : null,
                'unit_name'                => $row['unit_name'] ?: 'Unassigned',
                'explorer_first_name'      => $row['explorer_first_name'],
                'explorer_last_name'       => $row['explorer_last_name'],
                'explorer_email'           => $row['explorer_email'],
                'parent_email'             => $row['parent_email'],
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
            ];
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

        return new \WP_REST_Response( [ 'archived' => true ] );
    }

    public function list_event_teams( \WP_REST_Request $request ): \WP_REST_Response {
        $event_id = (int) $request->get_param( 'event_id' );
        $teams = $this->teams->list_by_expedition( $event_id );
        
        $unallocated = $this->teams->get_unallocated_team( $event_id );
        if ( $unallocated ) {
            $teams[] = $unallocated;
        }

        foreach ( $teams as &$team ) {
            $members = $this->team_members->list_by_team( $team['ID'] );
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
        $parent_asn = $wpdb->get_var( $wpdb->prepare(
            "SELECT additional_support_needs FROM {$wpdb->prefix}ems_expedition_signups WHERE scout_id = %d AND additional_support_needs != '' LIMIT 1",
            $scout_id
        ) ) ?: '';

        $wpdb->insert(
            $wpdb->prefix . 'ems_audit_logs',
            [
                'user_id'         => get_current_user_id() ?: 1,
                'action'          => 'view_asn',
                'target_scout_id' => $scout_id,
                'ip_address'      => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent'      => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'timestamp'       => current_time( 'mysql' ),
            ]
        );

        return new \WP_REST_Response( [
            'scout_id'        => $scout_id,
            'first_name'      => $explorer['first_name'] ?? '',
            'last_name'       => $explorer['last_name'] ?? '',
            'parent_asn'      => $parent_asn,
            'organiser_notes' => $explorer['additional_support_needs'] ?? '',
        ] );
    }

    public function update_explorer_asn( \WP_REST_Request $request ): \WP_REST_Response {
        $scout_id = (int) $request->get_param( 'scout_id' );
        $body     = $request->get_json_params() ?: [];
        $notes    = isset( $body['organiser_notes'] ) ? sanitize_textarea_field( $body['organiser_notes'] ) : '';

        $explorer = $this->explorers->find_by_scout_id( $scout_id );
        if ( ! $explorer ) {
            return $this->error( 'ems_explorer_not_found', 'Explorer not found.', 404 );
        }

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'ems_osm_explorers',
            [ 'additional_support_needs' => $notes ],
            [ 'scout_id' => $scout_id ]
        );

        return new \WP_REST_Response( [
            'success'         => true,
            'organiser_notes' => $notes,
        ] );
    }

    public function list_planning_board( \WP_REST_Request $request ): \WP_REST_Response {
        $raw_events = $this->expeditions->list_all_chronological();
        $filtered = [];

        foreach ( $raw_events as $event ) {
            $level = strtolower( $event['ems_level'] ?? '' );
            $status = strtolower( $event['ems_status'] ?? '' );

            if ( $status === 'archived' ) {
                continue;
            }
            if ( $level !== 'silver' && $level !== 'gold' ) {
                continue;
            }

            $event_code = $event['ems_event_code'] ?? '';
            if ( empty( $event_code ) ) {
                continue;
            }

            // Get available count from signups
            $signups_list = $this->signups->get_expedition_signups( 'pending' );
            $available_count = 0;
            foreach ( $signups_list as $signup ) {
                if ( ( $signup['signup_status'] ?? '' ) === 'archived' ) {
                    continue;
                }
                $prefs = ! empty( $signup['expedition_preferences'] ) ? json_decode( $signup['expedition_preferences'], true ) : null;
                if ( ! is_array( $prefs ) ) {
                    continue;
                }

                $practice = $prefs['exped_practice_dates'] ?? [];
                $qualifier = $prefs['exped_qualifier_dates'] ?? [];

                if ( in_array( $event_code, $practice, true ) || in_array( $event_code, $qualifier, true ) ) {
                    $available_count++;
                }
            }

            // Get allocated count: get all teams of this event, then count team members
            $allocated_count = 0;
            $teams_list = $this->teams->list_by_expedition( $event['ID'] );
            
            $unallocated = $this->teams->get_unallocated_team( $event['ID'] );
            if ( $unallocated ) {
                $teams_list[] = $unallocated;
            }

            foreach ( $teams_list as $team ) {
                $members = $this->team_members->list_by_team( $team['ID'] );
                $allocated_count += count( $members );
            }

            $filtered[] = [
                'id'              => (int) $event['ID'],
                'title'           => $event['post_title'],
                'event_code'      => $event_code,
                'type'            => $event['ems_type'] ?? '',
                'level'           => $level,
                'start_date'      => $event['ems_start_date'] ?? '',
                'end_date'        => $event['ems_end_date'] ?? '',
                'available_count' => $available_count,
                'allocated_count' => $allocated_count,
            ];
        }

        return new \WP_REST_Response( $filtered );
    }

    public function list_planning_availability( \WP_REST_Request $request ): \WP_REST_Response {
        $event_code = $request->get_param( 'event_code' );

        $signups_list = $this->signups->get_expedition_signups( 'pending' );
        $available = [];

        foreach ( $signups_list as $signup ) {
            if ( ( $signup['signup_status'] ?? '' ) === 'archived' ) {
                continue;
            }
            $prefs = ! empty( $signup['expedition_preferences'] ) ? json_decode( $signup['expedition_preferences'], true ) : null;
            if ( ! is_array( $prefs ) ) {
                continue;
            }

            $practice = $prefs['exped_practice_dates'] ?? [];
            $qualifier = $prefs['exped_qualifier_dates'] ?? [];

            if ( in_array( $event_code, $practice, true ) || in_array( $event_code, $qualifier, true ) ) {
                $scout_id = (int) $signup['scout_id'];
                
                global $wpdb;
                $table = $wpdb->prefix . 'ems_team_members';
                $allocations = $wpdb->get_results( $wpdb->prepare(
                    "SELECT team_post_id FROM {$table} WHERE scout_id = %d",
                    $scout_id
                ), ARRAY_A ) ?: [];

                $allocated_event_code = null;
                $allocated_team_code = null;

                foreach ( $allocations as $alloc ) {
                    $team_id = (int) $alloc['team_post_id'];
                    $team = get_post( $team_id );
                    if ( $team && $team->post_type === 'team' ) {
                        $team_code = get_post_meta( $team_id, 'ems_team_code', true );
                        
                        $event_id = $team->post_parent;
                        $event_level = get_post_meta( $event_id, 'ems_level', true );
                        $event_type = get_post_meta( $event_id, 'ems_type', true );
                        $e_code = get_post_meta( $event_id, 'ems_event_code', true );

                        $query_event_id = $wpdb->get_var( $wpdb->prepare(
                            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'ems_event_code' AND meta_value = %s LIMIT 1",
                            $event_code
                        ) );
                        if ( $query_event_id ) {
                            $query_level = get_post_meta( $query_event_id, 'ems_level', true );
                            $query_type = get_post_meta( $query_event_id, 'ems_type', true );

                            if ( strtolower( $event_level ) === strtolower( $query_level ) && strtolower( $event_type ) === strtolower( $query_type ) ) {
                                $allocated_event_code = $e_code;
                                $allocated_team_code = $team_code;
                                break;
                            }
                        }
                    }
                }

                $available[] = [
                    'scout_id'             => $scout_id,
                    'first_name'           => $signup['explorer_first_name'] ?? '',
                    'last_name'            => $signup['explorer_last_name'] ?? '',
                    'unit_name'            => $signup['unit_name'] ?: 'Unassigned',
                    'allocated_event_code' => $allocated_event_code,
                    'allocated_team_code'  => $allocated_team_code,
                ];
            }
        }

        return new \WP_REST_Response( $available );
    }

    public function allocate_planning_explorers( \WP_REST_Request $request ): \WP_REST_Response {
        $body = $request->get_json_params() ?: [];
        $scout_ids = $body['scout_ids'] ?? [];
        $event_id = (int) ( $body['event_id'] ?? 0 );
        $mode = $body['allocation_mode'] ?? 'unallocated';
        $target_team_id = (int) ( $body['target_team_id'] ?? 0 );

        if ( empty( $scout_ids ) || $event_id <= 0 ) {
            return $this->error( 'ems_invalid_parameters', 'scout_ids and event_id are required.', 400 );
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
        } else {
            return $this->error( 'ems_invalid_mode', 'Invalid allocation_mode.', 400 );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ems_team_members';
        $added_by = get_current_user_id() ?: 1;

        foreach ( $scout_ids as $scout_id ) {
            $scout_id = (int) $scout_id;
            
            $allocations = $wpdb->get_results( $wpdb->prepare(
                "SELECT team_post_id FROM {$table} WHERE scout_id = %d",
                $scout_id
            ), ARRAY_A ) ?: [];

            foreach ( $allocations as $alloc ) {
                $old_team_id = (int) $alloc['team_post_id'];
                $old_team = get_post( $old_team_id );
                if ( $old_team && $old_team->post_type === 'team' ) {
                    $old_event_id = $old_team->post_parent;
                    $old_level = get_post_meta( $old_event_id, 'ems_level', true );
                    $old_type = get_post_meta( $old_event_id, 'ems_type', true );

                    if ( strtolower( $old_level ) === strtolower( $event['ems_level'] ?? '' ) && strtolower( $old_type ) === strtolower( $event['ems_type'] ?? '' ) ) {
                        $this->team_members->remove( $old_team_id, $scout_id );
                    }
                }
            }

            try {
                $this->team_members->assign( $dest_team_id, $scout_id, $added_by, 0 );
            } catch ( \InvalidArgumentException $e ) {
                // Already assigned, ignore
            }
        }

        return new \WP_REST_Response( [ 'success' => true ] );
    }
}

