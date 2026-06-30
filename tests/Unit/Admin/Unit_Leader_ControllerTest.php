<?php
namespace EMS\Tests\Unit\Admin;

use EMS\Admin\Unit_Leader_Controller;
use EMS\Data\Unit_Leader_Repository;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;
use Mockery;

class Unit_Leader_ControllerTest extends EMSTestCase {

    private $repository;

    protected function setUp(): void {
        parent::setUp();
        $this->repository = Mockery::mock( Unit_Leader_Repository::class );
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
            [ 'id' => 1, 'unit_name' => 'Orion', 'leader_first_name' => 'John', 'leader_last_name' => 'Doe', 'leader_email' => 'john.doe@example.com' ]
        ];

        $this->repository->shouldReceive( 'list_all' )->once()->andReturn( $expected );

        $controller = $this->make_controller();
        $response = $controller->list_leaders();

        $this->assertInstanceOf( \WP_REST_Response::class, $response );
        $this->assertEquals( 200, $response->get_status() );
        $this->assertEquals( $expected, $response->get_data() );
    }

    public function test_create_leader_success(): void {
        $params = [
            'unit_name'         => 'Orion',
            'leader_first_name' => 'John',
            'leader_last_name'  => 'Doe',
            'leader_email'      => 'john.doe@example.com',
        ];

        $request = Mockery::mock( \WP_REST_Request::class );
        $request->shouldReceive( 'get_json_params' )->andReturn( $params );

        $this->repository->shouldReceive( 'create' )
            ->with( $params )
            ->once()
            ->andReturn( 42 );

        $this->repository->shouldReceive( 'find_by_id' )
            ->with( 42 )
            ->once()
            ->andReturn( array_merge( [ 'id' => 42 ], $params ) );

        $controller = $this->make_controller();
        $response = $controller->create_leader( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $response );
        $this->assertEquals( 201, $response->get_status() );
        $this->assertEquals( 42, $response->get_data()['id'] );
    }

    public function test_create_leader_validation_failure(): void {
        $params = [
            'unit_name'         => 'Orion',
            'leader_first_name' => 'John',
            'leader_last_name'  => 'Doe',
            'leader_email'      => 'invalid',
        ];

        $request = Mockery::mock( \WP_REST_Request::class );
        $request->shouldReceive( 'get_json_params' )->andReturn( $params );

        $this->repository->shouldReceive( 'create' )
            ->with( $params )
            ->once()
            ->andThrow( new \InvalidArgumentException( 'Invalid leader email format' ) );

        $controller = $this->make_controller();
        $response = $controller->create_leader( $request );

        $this->assertInstanceOf( \WP_Error::class, $response );
        $this->assertEquals( 'ems_validation_error', $response->get_error_code() );
        $this->assertEquals( 'Invalid leader email format', $response->get_error_message() );
    }

    public function test_update_leader_success(): void {
        $params = [
            'unit_name'         => 'Orion',
            'leader_first_name' => 'Jane',
            'leader_last_name'  => 'Smith',
            'leader_email'      => 'jane.smith@example.com',
        ];

        $request = Mockery::mock( \WP_REST_Request::class );
        $request->shouldReceive( 'get_param' )->with( 'id' )->andReturn( 5 );
        $request->shouldReceive( 'get_json_params' )->andReturn( $params );

        $this->repository->shouldReceive( 'update' )
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

    public function test_delete_leader_success(): void {
        $request = Mockery::mock( \WP_REST_Request::class );
        $request->shouldReceive( 'get_param' )->with( 'id' )->andReturn( 5 );

        $this->repository->shouldReceive( 'delete' )
            ->with( 5 )
            ->once()
            ->andReturn( true );

        $controller = $this->make_controller();
        $response = $controller->delete_leader( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $response );
        $this->assertEquals( 200, $response->get_status() );
        $this->assertTrue( $response->get_data()['deleted'] );
    }
}
