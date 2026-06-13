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
        register_rest_route( 'ems/v1', '/expedition-board', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_board_data' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
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
                    $user_id = (int) $member['user_id'];
                    $member['first_name'] = get_user_meta( $user_id, 'ems_first_name', true );
                    $member['last_name']  = get_user_meta( $user_id, 'ems_last_name', true );
                    $member['scout_id']   = get_user_meta( $user_id, 'ems_scout_id', true );
                    $member['unit']       = get_user_meta( $user_id, 'ems_unit', true );
                    
                    // TODO: Training status fallback in Stage 1.8
                    $member['training'] = $this->get_user_training_summary( $user_id );
                }

                $all_members[ $team['ID'] ] = $members;
            }
        }

        return new \WP_REST_Response( [
            'expeditions' => $expeditions,
            'teams'       => $all_teams,
            'members'     => $all_members,
            'last_sync'   => get_option( 'ems_osm_last_sync' ),
        ] );
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
}
