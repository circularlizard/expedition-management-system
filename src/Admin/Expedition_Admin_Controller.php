<?php
namespace EMS\Admin;

use EMS\Data\Season_Repository;
use EMS\Data\Expedition_Repository;
use EMS\Data\Team_Repository;
use EMS\Data\Team_Member_Repository;
use EMS\Data\OSM_Explorer_Repository;
use EMS\Data\OSM_Event_Repository;
use EMS\Core\CPT_Registry;

class Expedition_Admin_Controller {

    private Season_Repository $seasons;
    private Expedition_Repository $expeditions;
    private Team_Repository $teams;
    private Team_Member_Repository $team_members;
    private OSM_Explorer_Repository $explorers;
    private OSM_Event_Repository $osm_events;
    private CPT_Registry $cpt_registry;

    public function __construct(
        Season_Repository $seasons,
        Expedition_Repository $expeditions,
        Team_Repository $teams,
        Team_Member_Repository $team_members,
        ?OSM_Explorer_Repository $explorers = null,
        ?OSM_Event_Repository $osm_events = null,
        ?CPT_Registry $cpt_registry = null
    ) {
        $this->seasons      = $seasons;
        $this->expeditions  = $expeditions;
        $this->teams        = $teams;
        $this->team_members = $team_members;
        $this->explorers    = $explorers ?: new OSM_Explorer_Repository();
        $this->osm_events   = $osm_events ?: new OSM_Event_Repository();
        $this->cpt_registry = $cpt_registry ?: new CPT_Registry();
    }

    public function register_routes(): void {
        $this->register_season_routes();
        $this->register_event_routes();
        $this->register_team_routes();
        $this->register_member_routes();
        $this->register_osm_event_route();
        $this->register_board_route();
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
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'create_event' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( 'ems/v1', '/events/(?P<id>\d+)', [
            'methods'             => \WP_REST_Server::EDITABLE,
            'callback'            => [ $this, 'update_event' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'id' => [ 'type' => 'integer', 'required' => true ],
            ],
        ] );

        register_rest_route( 'ems/v1', '/events/(?P<id>\d+)', [
            'methods'             => \WP_REST_Server::DELETABLE,
            'callback'            => [ $this, 'delete_event' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'id' => [ 'type' => 'integer', 'required' => true ],
            ],
        ] );
    }

    private function register_team_routes(): void {
        register_rest_route( 'ems/v1', '/events/(?P<event_id>\d+)/teams', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'create_team' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'event_id' => [ 'type' => 'integer', 'required' => true ],
            ],
        ] );

        register_rest_route( 'ems/v1', '/teams/(?P<id>\d+)', [
            'methods'             => \WP_REST_Server::DELETABLE,
            'callback'            => [ $this, 'delete_team' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'id' => [ 'type' => 'integer', 'required' => true ],
            ],
        ] );

        register_rest_route( 'ems/v1', '/teams/(?P<id>\d+)/move', [
            'methods'             => \WP_REST_Server::EDITABLE,
            'callback'            => [ $this, 'move_team' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'id' => [ 'type' => 'integer', 'required' => true ],
            ],
        ] );

        register_rest_route( 'ems/v1', '/teams/(?P<id>\d+)/duplicate', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'duplicate_team' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'id' => [ 'type' => 'integer', 'required' => true ],
            ],
        ] );

        register_rest_route( 'ems/v1', '/events/(?P<source_id>\d+)/populate/(?P<target_id>\d+)', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'populate_event' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'source_id' => [ 'type' => 'integer', 'required' => true ],
                'target_id' => [ 'type' => 'integer', 'required' => true ],
            ],
        ] );
    }

    private function register_member_routes(): void {
        register_rest_route( 'ems/v1', '/teams/(?P<team_id>\d+)/members', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'add_member' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'team_id' => [ 'type' => 'integer', 'required' => true ],
            ],
        ] );

        register_rest_route( 'ems/v1', '/teams/(?P<team_id>\d+)/members/(?P<scout_id>\d+)', [
            'methods'             => \WP_REST_Server::DELETABLE,
            'callback'            => [ $this, 'remove_member' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'team_id'  => [ 'type' => 'integer', 'required' => true ],
                'scout_id' => [ 'type' => 'integer', 'required' => true ],
            ],
        ] );

        register_rest_route( 'ems/v1', '/explorers/(?P<scout_id>\d+)/move-team', [
            'methods'             => \WP_REST_Server::EDITABLE,
            'callback'            => [ $this, 'move_explorer' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'scout_id' => [ 'type' => 'integer', 'required' => true ],
            ],
        ] );

        register_rest_route( 'ems/v1', '/explorers/(?P<scout_id>\d+)/first-aid', [
            'methods'             => \WP_REST_Server::EDITABLE,
            'callback'            => [ $this, 'update_first_aid_level' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'scout_id' => [ 'type' => 'integer', 'required' => true ],
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
        $seasons = $this->seasons->list_all();
        $board   = [];

        foreach ( $seasons as $season ) {
            $events = [];
            foreach ( $this->expeditions->list_by_season( $season['ID'] ) as $event ) {
                $teams = [];
                foreach ( $this->teams->list_by_expedition( $event['ID'] ) as $team ) {
                    $members = $this->team_members->list_by_team( $team['ID'] );
                    $team['member_count'] = count( $members );
                    $team['size_warning'] = $team['member_count'] < 4 || $team['member_count'] > 7;
                    $team['members']      = $this->hydrate_members( $members );
                    $teams[]              = $team;
                }
                $event['teams']       = $teams;
                $event['member_count'] = array_sum( array_column( $teams, 'member_count' ) );
                $events[]             = $event;
            }
            $season['events'] = $events;
            $board[]          = $season;
        }

        return new \WP_REST_Response( [
            'seasons'   => $board,
            'explorers' => $this->list_explorers(),
            'last_sync' => get_option( 'ems_osm_last_sync' ) ?: null,
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
        ];

        if ( $require_all ) {
            $required = [ 'season_id', 'ems_event_code', 'ems_type', 'ems_transport', 'ems_level', 'ems_start_date', 'ems_end_date' ];
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
            }
            $hydrated[] = $member;
        }
        return $hydrated;
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
}
