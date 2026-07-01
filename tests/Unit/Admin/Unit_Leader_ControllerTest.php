<?php
namespace EMS\Tests\Unit\Admin;

use EMS\Admin\Unit_Leader_Controller;
use EMS\Data\Unit_Repository;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;
use Mockery;

class Unit_Leader_ControllerTest extends EMSTestCase {

    private $repository;

    protected function setUp(): void {
        parent::setUp();
        $this->repository = Mockery::mock( Unit_Repository::class );
    }

    private function make_controller(): Unit_Leader_Controller {
        return new Unit_Leader_Controller( $this->repository );
    }

    public function test_check_permission_returns_manage_options(): void {
        Functions\expect( 'current_user_can' )->with( 'manage_options' )->once()->andReturn( true );
        $controller = $this->make_controller();
        $this->assertTrue( $controller->check_permission() );
    }

    public function test_list_leaders(): void {
        $expected = [
            [ 'id' => 1, 'patrol_id' => 101, 'name' => 'Orion', 'leader_first_name' => 'John', 'leader_last_name' => 'Doe', 'leader_email' => 'john.doe@example.com' ]
        ];

        $this->repository->shouldReceive( 'list_active_units' )->once()->andReturn( $expected );

        $controller = $this->make_controller();
        $response = $controller->list_leaders();

        $this->assertInstanceOf( \WP_REST_Response::class, $response );
        $this->assertEquals( 200, $response->get_status() );
        $this->assertEquals( $expected, $response->get_data() );
    }

    public function test_update_leader_success(): void {
        $params = [
            'unit_id'           => 4200,
            'short_code'        => 'ORION-ESU',
            'leader_first_name' => 'Jane',
            'leader_last_name'  => 'Smith',
            'leader_email'      => 'jane.smith@example.com',
        ];

        $request = Mockery::mock( \WP_REST_Request::class );
        $request->shouldReceive( 'get_param' )->with( 'id' )->andReturn( 5 );
        $request->shouldReceive( 'get_json_params' )->andReturn( $params );

        $this->repository->shouldReceive( 'update_custom_mappings' )
            ->with( 5, $params )
            ->once()
            ->andReturn( true );

        $this->repository->shouldReceive( 'find_by_id' )
            ->with( 5 )
            ->once()
            ->andReturn( array_merge( [ 'id' => 5 ], $params ) );

        $controller = $this->make_controller();
        $response = $controller->update_leader( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $response );
        $this->assertEquals( 200, $response->get_status() );
        $this->assertEquals( 'Jane', $response->get_data()['leader_first_name'] );
    }
}
