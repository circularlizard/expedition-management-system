<?php
namespace EMS\Tests\Unit\Admin;

use EMS\Admin\Flexi_Mapper_Controller;
use EMS\Integrations\OSM_API_Client;
use EMS\Integrations\Flexi_Structure_Parser;
use EMS\Integrations\Flexi_Column_Map;
use EMS\Integrations\Flexi_Record_Importer;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;

class Flexi_Mapper_ControllerTest extends EMSTestCase {
    private $api_client;
    private $parser;
    private $column_map;
    private $importer;

    protected function setUp(): void {
        parent::setUp();
        $this->api_client = \Mockery::mock( OSM_API_Client::class );
        $this->parser     = \Mockery::mock( Flexi_Structure_Parser::class );
        $this->column_map = \Mockery::mock( Flexi_Column_Map::class );
        $this->importer   = \Mockery::mock( Flexi_Record_Importer::class );
    }

    private function make_controller(): Flexi_Mapper_Controller {
        return new Flexi_Mapper_Controller(
            $this->api_client,
            $this->parser,
            $this->column_map,
            $this->importer
        );
    }

    public function test_save_map_failure_returns_wp_error(): void {
        $controller = $this->make_controller();

        $error = new \WP_Error( 'missing_field', 'Missing required field mapping: dofe_id' );
        $this->column_map->shouldReceive( 'save' )
            ->once()
            ->with( [ 'some' => 'map' ] )
            ->andReturn( $error );

        $request = \Mockery::mock( \WP_REST_Request::class );
        $request->shouldReceive( 'get_json_params' )->once()->andReturn( [ 'some' => 'map' ] );

        $response = $controller->save_map( $request );

        $this->assertInstanceOf( \WP_Error::class, $response );
        $this->assertSame( 400, $response->get_error_data()['status'] );
        $this->assertSame( 'missing_field', $response->get_error_code() );
        $this->assertSame( 'Missing required field mapping: dofe_id', $response->get_error_message() );
    }

    public function test_save_map_success_returns_response(): void {
        $controller = $this->make_controller();

        $this->column_map->shouldReceive( 'save' )
            ->once()
            ->with( [ 'dofe_id' => '123' ] )
            ->andReturn( true );

        $request = \Mockery::mock( \WP_REST_Request::class );
        $request->shouldReceive( 'get_json_params' )->once()->andReturn( [ 'dofe_id' => '123' ] );

        $response = $controller->save_map( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );
        $this->assertTrue( $response->get_data()['success'] );
    }

    public function test_get_map_returns_stored_mapping(): void {
        $controller = $this->make_controller();

        $this->column_map->shouldReceive( 'get' )->once()->andReturn( [ 'dofe_id' => 'col_1' ] );

        $response = $controller->get_map();

        $this->assertInstanceOf( \WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( [ 'dofe_id' => 'col_1' ], $response->get_data()['map'] );
    }

    public function test_check_permission_verifies_capability(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $controller = $this->make_controller();
        $this->assertTrue( $controller->check_permission() );
    }
}
