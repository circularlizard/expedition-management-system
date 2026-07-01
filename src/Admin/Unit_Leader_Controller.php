<?php
namespace EMS\Admin;

use EMS\Data\Unit_Repository;

class Unit_Leader_Controller {

    private Unit_Repository $repository;

    public function __construct( ?Unit_Repository $repository = null ) {
        $this->repository = $repository ?: new Unit_Repository();
    }

    public function register_routes(): void {
        register_rest_route( 'ems/v1', '/unit-leaders', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'list_leaders' ],
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
    }

    public function check_permission(): bool {
        return current_user_can( 'manage_options' );
    }

    public function list_leaders(): \WP_REST_Response {
        $units = $this->repository->list_active_units();
        return new \WP_REST_Response( $units, 200 );
    }

    public function update_leader( \WP_REST_Request $request ) {
        $id     = (int) $request->get_param( 'id' );
        $params = $request->get_json_params() ?: [];

        try {
            $this->repository->update_custom_mappings( $id, $params );
            $unit = $this->repository->find_by_id( $id );
            return new \WP_REST_Response( $unit, 200 );
        } catch ( \InvalidArgumentException $e ) {
            return new \WP_Error( 'ems_validation_error', $e->getMessage(), [ 'status' => 400 ] );
        } catch ( \Exception $e ) {
            return new \WP_Error( 'ems_server_error', $e->getMessage(), [ 'status' => 500 ] );
        }
    }
}
