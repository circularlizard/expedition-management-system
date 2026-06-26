<?php
namespace EMS\Admin;

use EMS\Data\Expedition_Repository;
use EMS\Data\Team_Repository;
use EMS\Data\Team_Member_Repository;
use EMS\Integrations\TutorLMS_Client;

/**
 * Controller for administrative views (Expedition Board).
 */
class Admin_View_Controller {

    private Expedition_Repository $expeditions;
    private Team_Repository $teams;
    private Team_Member_Repository $team_members;
    private TutorLMS_Client $tutor_client;

    public function __construct(
        Expedition_Repository $expeditions,
        Team_Repository $teams,
        Team_Member_Repository $team_members,
        TutorLMS_Client $tutor_client
    ) {
        $this->expeditions  = $expeditions;
        $this->teams        = $teams;
        $this->team_members = $team_members;
        $this->tutor_client = $tutor_client;
    }

    /**
     * Registers REST routes for the admin views.
     */
    public function register_routes(): void {
        // NOTE: The `/expedition-board` route is intentionally NOT registered here.
        // It is served by Expedition_Admin_Controller::get_board() (Stage 1.12), which
        // returns the season → event → team hierarchy the current React board expects.
        // Registering it here as well caused a route collision where this legacy
        // handler's payload shape ({expeditions, teams, ...}) shadowed the new one,
        // leaving the board blank because `data.seasons` was undefined.
        register_rest_route( 'ems/v1', '/explorer/(?P<scout_id>\d+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_explorer_detail' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'args'                => [ 'scout_id' => [ 'type' => 'integer', 'required' => true ] ],
        ] );
        register_rest_route( 'ems/v1', '/team/(?P<team_id>\d+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_team_detail' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'args'                => [ 'team_id' => [ 'type' => 'integer', 'required' => true ] ],
        ] );
        register_rest_route( 'ems/v1', '/patrol/(?P<patrol>[^/]+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_patrol_detail' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'args'                => [ 'patrol' => [ 'type' => 'string', 'required' => true ] ],
        ] );
        register_rest_route( 'ems/v1', '/events/(?P<id>\d+)/training-requirements', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_event_training_requirements' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
        ] );
        register_rest_route( 'ems/v1', '/events/(?P<id>\d+)/training-requirements', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'update_event_training_requirements' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
        ] );
    }

    /**
     * Gets the full data set for the expedition board.
     */
    public function get_board_data(): \WP_REST_Response {
        $expeditions = $this->expeditions->list_all();
        $all_teams   = [];
        $all_members = [];

        foreach ( $expeditions as $exp ) {
            $teams = $this->teams->list_by_expedition( $exp['ID'] );
            $all_teams[ $exp['ID'] ] = $teams;

            foreach ( $teams as $team ) {
                $members = $this->team_members->list_by_team( $team['ID'] );
                
                // Hydrate member data
                foreach ( $members as &$member ) {
                    $user_id = isset( $member['user_id'] ) ? (int) $member['user_id'] : 0;
                    if ( $user_id > 0 ) {
                        $this->hydrate_member_data( $member, $user_id );
                    }
                }

                $all_members[ $team['ID'] ] = $members;
            }
        }

        // Fetch ALL explorers from the OSM reference table
        global $wpdb;
        $explorers_table = $wpdb->prefix . 'ems_osm_explorers';
        $explorer_rows   = $wpdb->get_results( "SELECT * FROM {$explorers_table}", ARRAY_A ) ?? [];

        $all_explorers = [];
        foreach ( $explorer_rows as $row ) {
            $explorer = [
                'user_id'    => (int) ( $row['wp_user_id'] ?? 0 ),
                'first_name' => $row['first_name'] ?? '',
                'last_name'  => $row['last_name'] ?? '',
                'scout_id'   => (int) $row['scout_id'],
                'unit'       => $row['patrol'] ?? '',
                'training'   => $row['wp_user_id'] ? $this->get_user_training_summary( (int) $row['wp_user_id'] ) : [],
            ];
            $all_explorers[] = $explorer;
        }

        return new \WP_REST_Response( [
            'expeditions' => $expeditions,
            'teams'       => $all_teams,
            'members'     => $all_members,
            'explorers'   => $all_explorers,
            'last_sync'   => get_option( 'ems_osm_last_sync' ),
        ] );
    }

    /**
     * GET ems/v1/explorer/{scout_id}
     */
    public function get_explorer_detail( \WP_REST_Request $request ): \WP_REST_Response {
        $scout_id = (int) $request->get_param( 'scout_id' );

        global $wpdb;
        $table = $wpdb->prefix . 'ems_osm_explorers';
        $row   = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE scout_id = %d",
            $scout_id
        ), ARRAY_A );

        if ( ! $row ) {
            return new \WP_REST_Response( [ 'error' => 'Explorer not found' ], 404 );
        }

        $wp_user_id = (int) ( $row['wp_user_id'] ?? 0 );

        return new \WP_REST_Response( [
            'scout_id'     => (int) $row['scout_id'],
            'first_name'   => $row['first_name'] ?? '',
            'last_name'    => $row['last_name']  ?? '',
            'email'        => $row['email']       ?? '',
            'patrol'       => $row['patrol']      ?? '',
            'training'     => $wp_user_id > 0 ? $this->get_user_training_summary( $wp_user_id ) : [],
            'last_synced'  => get_option( 'ems_osm_last_sync' ) ?: null,
        ] );
    }

    /**
     * GET ems/v1/team/{team_id}
     */
    public function get_team_detail( \WP_REST_Request $request ): \WP_REST_Response {
        $team_id = (int) $request->get_param( 'team_id' );
        $members = $this->team_members->list_by_team( $team_id );

        global $wpdb;
        $explorers_table = $wpdb->prefix . 'ems_osm_explorers';

        $hydrated       = [];
        $first_aid_count = 0;

        foreach ( $members as $member ) {
            $wp_user_id = (int) ( $member['user_id'] ?? 0 );
            $row = $wp_user_id > 0
                ? $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM {$explorers_table} WHERE wp_user_id = %d",
                    $wp_user_id
                ), ARRAY_A )
                : null;

            $training = [];
            if ( $wp_user_id > 0 ) {
                $training = $this->get_user_training_summary( $wp_user_id );
                if ( ! empty( $row['first_aid'] ) ) {
                    $first_aid_count++;
                }
            }

            $hydrated[] = [
                'user_id'    => $wp_user_id,
                'scout_id'   => $row ? (int) $row['scout_id'] : 0,
                'first_name' => $row['first_name'] ?? '',
                'last_name'  => $row['last_name']  ?? '',
                'patrol'     => $row['patrol']      ?? '',
                'training'   => $training,
            ];
        }

        return new \WP_REST_Response( [
            'team_id'          => $team_id,
            'members'          => $hydrated,
            'first_aid_covered' => $first_aid_count > 0,
            'last_synced'      => get_option( 'ems_osm_last_sync' ) ?: null,
        ] );
    }

    /**
     * GET ems/v1/patrol/{patrol}
     */
    public function get_patrol_detail( \WP_REST_Request $request ): \WP_REST_Response {
        $patrol = sanitize_text_field( urldecode( $request->get_param( 'patrol' ) ) );

        global $wpdb;
        $table = $wpdb->prefix . 'ems_osm_explorers';
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE patrol = %s ORDER BY last_name, first_name",
            $patrol
        ), ARRAY_A );

        $explorers = array_map( static function ( array $row ): array {
            return [
                'scout_id'   => (int) $row['scout_id'],
                'first_name' => $row['first_name'] ?? '',
                'last_name'  => $row['last_name']  ?? '',
                'email'      => $row['email']       ?? '',
                'patrol'     => $row['patrol']      ?? '',
            ];
        }, $rows ?? [] );

        return new \WP_REST_Response( [
            'patrol'      => $patrol,
            'explorers'   => $explorers,
            'last_synced' => get_option( 'ems_osm_last_sync' ) ?: null,
        ] );
    }

    private function hydrate_member_data( array &$member, int $user_id ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ems_osm_explorers';
        $row   = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE wp_user_id = %d",
            $user_id
        ), ARRAY_A );

        $member['first_name'] = $row['first_name'] ?? '';
        $member['last_name']  = $row['last_name']  ?? '';
        $member['scout_id']   = $row ? (int) $row['scout_id'] : 0;
        $member['unit']       = $row['patrol']     ?? '';
        $member['training']   = $this->get_user_training_summary( $user_id );
    }

    /**
     * Returns a simple training status summary for a user.
     */
    private function get_user_training_summary( int $user_id ): array {
        $courses = $this->tutor_client->get_all_courses();
        $course_ids = array_map( fn( $c ) => $c->ID, $courses );
        
        $matrix = $this->tutor_client->get_enrollment_matrix( [ $user_id ], $course_ids );
        $user_matrix = $matrix[ $user_id ] ?? [];

        $complete = 0;
        foreach ( $user_matrix as $status ) {
            if ( $status === 'complete' ) {
                $complete++;
            }
        }

        return [
            'total'    => count( $course_ids ),
            'complete' => $complete,
            'percent'  => count( $course_ids ) > 0 ? round( ( $complete / count( $course_ids ) ) * 100 ) : 0,
        ];
    }

    /**
     * GET ems/v1/events/{id}/training-requirements
     */
    public function get_event_training_requirements( \WP_REST_Request $request ): \WP_REST_Response {
        $event_id = (int) $request->get_param( 'id' );
        $course_ids = get_post_meta( $event_id, 'ems_training_requirements', true );
        if ( ! is_array( $course_ids ) ) {
            $course_ids = [];
        } else {
            $course_ids = array_map( 'intval', $course_ids );
        }

        $all_courses = $this->tutor_client->get_all_courses() ?? [];
        $courses = array_map( function( $course ) {
            return [
                'id'    => (int) $course->ID,
                'title' => $course->post_title,
            ];
        }, $all_courses );

        return new \WP_REST_Response( [
            'course_ids' => $course_ids,
            'courses'    => $courses,
        ], 200 );
    }

    /**
     * POST ems/v1/events/{id}/training-requirements
     */
    public function update_event_training_requirements( \WP_REST_Request $request ): \WP_REST_Response {
        $event_id = (int) $request->get_param( 'id' );
        $params = $request->get_json_params();

        if ( ! is_array( $params ) || ! isset( $params['course_ids'] ) || ! is_array( $params['course_ids'] ) ) {
            return new \WP_REST_Response( [ 'error' => 'Invalid parameters' ], 400 );
        }

        $course_ids = array_map( 'intval', $params['course_ids'] );
        update_post_meta( $event_id, 'ems_training_requirements', $course_ids );

        return new \WP_REST_Response( [
            'success'    => true,
            'course_ids' => $course_ids,
        ], 200 );
    }
}
