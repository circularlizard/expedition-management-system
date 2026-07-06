<?php
namespace EMS\Admin;

use EMS\Data\Volunteer_Repository;

class Volunteer_Controller {
    private Volunteer_Repository $repo;

    public function __construct( ?Volunteer_Repository $repo = null ) {
        $this->repo = $repo ?: new Volunteer_Repository();
    }

    public function register_routes(): void {
        register_rest_route( 'ems/v1', '/volunteers', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_volunteers' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( 'ems/v1', '/volunteers/signup', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'signup' ],
            'permission_callback' => '__return_true', // Public endpoint
        ] );

        register_rest_route( 'ems/v1', '/volunteers/assign', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'assign' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );
    }

    public function check_permission(): bool {
        return current_user_can( 'manage_options' );
    }

    public function get_volunteers( \WP_REST_Request $request ): \WP_REST_Response {
        $data = $this->repo->get_volunteers();
        return new \WP_REST_Response( $data, 200 );
    }

    public function signup( \WP_REST_Request $request ): \WP_REST_Response {
        $params = $request->get_json_params() ?: [];

        try {
            $volunteer = $this->repo->save_volunteer( $params );

            $expedition_post_id = isset( $params['expedition_post_id'] ) ? (int) $params['expedition_post_id'] : 0;
            $shifts = isset( $params['shifts'] ) ? (array) $params['shifts'] : [];

            if ( $expedition_post_id > 0 && ! empty( $shifts ) ) {
                $this->repo->save_availability( $volunteer['id'], $expedition_post_id, $shifts );
            }

            return new \WP_REST_Response( [
                'success' => true,
                'volunteer_id' => $volunteer['id']
            ], 200 );
        } catch ( \Exception $e ) {
            return new \WP_REST_Response( [
                'success' => false,
                'message' => $e->getMessage()
            ], 400 );
        }
    }

    public function assign( \WP_REST_Request $request ): \WP_REST_Response {
        $params = $request->get_json_params() ?: [];
        $availability_id = isset( $params['availability_id'] ) ? (int) $params['availability_id'] : 0;
        $confirmed = isset( $params['confirmed'] ) ? (int) $params['confirmed'] : 0;

        if ( $availability_id <= 0 ) {
            return new \WP_REST_Response( [
                'success' => false,
                'message' => 'Invalid availability ID.'
            ], 400 );
        }

        $success = $this->repo->confirm_availability( $availability_id, $confirmed );

        return new \WP_REST_Response( [
            'success' => $success
        ], $success ? 200 : 400 );
    }
}
