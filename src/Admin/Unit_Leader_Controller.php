<?php
namespace EMS\Admin;

use EMS\Data\Unit_Leader_Repository;

class Unit_Leader_Controller {

    private Unit_Leader_Repository $repository;

    public function __construct( ?Unit_Leader_Repository $repository = null ) {
        $this->repository = $repository ?: new Unit_Leader_Repository();
    }

    public function register_routes(): void {
        register_rest_route( 'ems/v1', '/unit-leaders', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'list_leaders' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( 'ems/v1', '/unit-leaders', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'create_leader' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( 'ems/v1', '/unit-leaders/(?P<id>\d+)', [
            'methods'             => \WP_REST_Server::EDITABLE,
            'callback'            => [ $this, 'update_leader' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'id' => [ 'type' => 'integer', 'required' => true ],
            ],
        ] );

        register_rest_route( 'ems/v1', '/unit-leaders/(?P<id>\d+)', [
            'methods'             => \WP_REST_Server::DELETABLE,
            'callback'            => [ $this, 'delete_leader' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'id' => [ 'type' => 'integer', 'required' => true ],
            ],
        ] );
    }

    public function check_permission(): bool {
        return current_user_can( 'manage_options' );
    }

    public function list_leaders(): \WP_REST_Response {
        $leaders = $this->repository->list_all();
        return new \WP_REST_Response( $leaders, 200 );
    }

    public function create_leader( \WP_REST_Request $request ) {
        $params = $request->get_json_params() ?: [];

        try {
            $id = $this->repository->create( $params );
            $leader = $this->repository->find_by_id( $id );
            return new \WP_REST_Response( $leader, 201 );
        } catch ( \InvalidArgumentException $e ) {
            return new \WP_Error( 'ems_validation_error', $e->getMessage(), [ 'status' => 400 ] );
        } catch ( \Exception $e ) {
            return new \WP_Error( 'ems_server_error', $e->getMessage(), [ 'status' => 500 ] );
        }
    }

    public function update_leader( \WP_REST_Request $request ) {
        $id     = (int) $request->get_param( 'id' );
        $params = $request->get_json_params() ?: [];

        try {
            $this->repository->update( $id, $params );
            $leader = $this->repository->find_by_id( $id );
            return new \WP_REST_Response( $leader, 200 );
        } catch ( \InvalidArgumentException $e ) {
            return new \WP_Error( 'ems_validation_error', $e->getMessage(), [ 'status' => 400 ] );
        } catch ( \Exception $e ) {
            return new \WP_Error( 'ems_server_error', $e->getMessage(), [ 'status' => 500 ] );
        }
    }

    public function delete_leader( \WP_REST_Request $request ) {
        $id = (int) $request->get_param( 'id' );
        $this->repository->delete( $id );
        return new \WP_REST_Response( [ 'deleted' => true ], 200 );
    }
}
