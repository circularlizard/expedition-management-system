<?php
namespace EMS\Admin;

use EMS\Integrations\OSM_API_Client;
use EMS\Integrations\Flexi_Structure_Parser;
use EMS\Integrations\Flexi_Column_Map;
use EMS\Integrations\Flexi_Record_Importer;

/**
 * Handles REST API requests for the Flexi-Record Column Mapper.
 */
class Flexi_Mapper_Controller {

    private OSM_API_Client $api_client;
    private Flexi_Structure_Parser $parser;
    private Flexi_Column_Map $column_map;
    private Flexi_Record_Importer $importer;

    public function __construct(
        OSM_API_Client $api_client,
        Flexi_Structure_Parser $parser,
        Flexi_Column_Map $column_map,
        Flexi_Record_Importer $importer
    ) {
        $this->api_client = $api_client;
        $this->parser     = $parser;
        $this->column_map = $column_map;
        $this->importer   = $importer;
    }

    /**
     * Registers the REST routes.
     */
    public function register_routes(): void {
        register_rest_route( 'ems/v1', '/flexi-structure', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_structure' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'section_id' => [ 'required' => true, 'type' => 'integer' ],
                'flexi_id'   => [ 'required' => true, 'type' => 'integer' ],
            ],
        ] );

        register_rest_route( 'ems/v1', '/flexi-column-map', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_map' ],
                'permission_callback' => [ $this, 'check_permission' ],
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'save_map' ],
                'permission_callback' => [ $this, 'check_permission' ],
            ],
        ] );

        register_rest_route( 'ems/v1', '/flexi-review', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_review' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'section_id' => [ 'required' => true, 'type' => 'integer' ],
                'flexi_id'   => [ 'required' => true, 'type' => 'integer' ],
            ],
        ] );

        register_rest_route( 'ems/v1', '/flexi-commit', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'commit_rows' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );
    }

    /**
     * Checks if the user has permission to manage options.
     */
    public function check_permission(): bool {
        return current_user_can( 'manage_options' );
    }

    /**
     * Fetches the structure of a flexi-record.
     */
    public function get_structure( \WP_REST_Request $request ): \WP_REST_Response {
        $section_id = (int) $request->get_param( 'section_id' );
        $flexi_id   = (int) $request->get_param( 'flexi_id' );

        $raw      = $this->api_client->get_flexi_record_structure( $section_id, $flexi_id );
        $columns  = $this->parser->parse( $raw );

        return new \WP_REST_Response( [
            'columns' => $columns,
        ] );
    }

    /**
     * Retrieves the current column mapping.
     */
    public function get_map(): \WP_REST_Response {
        return new \WP_REST_Response( [
            'map'             => $this->column_map->get(),
            'required_fields' => Flexi_Column_Map::REQUIRED_FIELDS,
        ] );
    }

    /**
     * Saves the column mapping.
     */
    public function save_map( \WP_REST_Request $request ): \WP_REST_Response {
        $map    = $request->get_json_params() ?: [];
        $result = $this->column_map->save( $map );

        if ( is_wp_error( $result ) ) {
            return new \WP_REST_Response( [
                'error' => $result->get_error_message(),
            ], 400 );
        }

        return new \WP_REST_Response( [ 'success' => true ] );
    }

    /**
     * Fetches bucketed rows for review.
     */
    public function get_review( \WP_REST_Request $request ): \WP_REST_Response {
        $section_id = (int) $request->get_param( 'section_id' );
        $flexi_id   = (int) $request->get_param( 'flexi_id' );

        $raw      = $this->api_client->get_flexi_record_data( $section_id, $flexi_id );
        $buckets  = $this->importer->bucket_rows( $raw['items'] ?? [] );

        return new \WP_REST_Response( [
            'buckets' => $buckets,
        ] );
    }

    /**
     * Commits selected rows.
     */
    public function commit_rows( \WP_REST_Request $request ): \WP_REST_Response {
        $rows = $request->get_json_params() ?: [];
        $count = $this->importer->commit( $rows );

        return new \WP_REST_Response( [
            'success' => true,
            'count'   => $count,
        ] );
    }
}
