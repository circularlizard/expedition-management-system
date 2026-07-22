<?php
namespace EMS\Tests\Unit\Admin;

use EMS\Admin\Volunteer_Controller;
use EMS\Data\Volunteer_Repository;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;

class Volunteer_ControllerTest extends EMSTestCase {
    private $repo;

    protected function setUp(): void {
        parent::setUp();
        $this->repo = \Mockery::mock( Volunteer_Repository::class );
    }

    public function test_get_volunteers_returns_all_schedules(): void {
        $this->repo->shouldReceive('get_volunteers')->once()->andReturn([
            [
                'id' => 1,
                'first_name' => 'Jane',
                'email' => 'jane@example.com',
                'availability' => []
            ]
        ]);

        $controller = new Volunteer_Controller( $this->repo );
        Functions\when( 'current_user_can' )->justReturn( true );

        $request = new \WP_REST_Request( 'GET', '/ems/v1/volunteers' );
        $response = $controller->get_volunteers( $request );

        $this->assertEquals( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertCount( 1, $data );
        $this->assertEquals( 'Jane', $data[0]['first_name'] );
    }

    public function test_public_signup_wizard_creates_volunteer_and_availability(): void {
        $this->repo->shouldReceive('save_volunteer')->once()->andReturn([
            'id' => 10,
            'email' => 'test@example.com'
        ]);
        $this->repo->shouldReceive('save_availability')->once()->with(10, 5, [
            ['date' => '2026-08-14', 'overnight' => 1]
        ], 'part');

        $controller = new Volunteer_Controller( $this->repo );

        $request = \Mockery::mock( \WP_REST_Request::class );
        $request->shouldReceive('get_json_params')->once()->andReturn([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'test@example.com',
            'expedition_post_id' => 5,
            'shifts' => [
                ['date' => '2026-08-14', 'overnight' => 1]
            ]
        ]);

        $response = $controller->signup( $request );

        $this->assertEquals( 200, $response->get_status() );
        $this->assertTrue( $response->get_data()['success'] );
    }

    public function test_assign_availability_confirms_shift(): void {
        $this->repo->shouldReceive('get_availability_table')->once()->andReturn('wp_ems_volunteer_availability');
        global $wpdb;
        $wpdb = \Mockery::mock('stdClass');
        $wpdb->shouldReceive('prepare')->andReturn('SELECT id FROM wp_ems_volunteer_availability WHERE volunteer_id = 42 AND expedition_post_id = 10');
        $wpdb->shouldReceive('get_results')->once()->andReturn([
            (object)['id' => 100]
        ]);

        $this->repo->shouldReceive('confirm_availability')->once()->with(100, 1)->andReturn(true);

        $controller = new Volunteer_Controller( $this->repo );
        Functions\when( 'current_user_can' )->justReturn( true );

        $request = \Mockery::mock( \WP_REST_Request::class );
        $request->shouldReceive('get_json_params')->once()->andReturn([
            'volunteer_id' => 42,
            'expedition_post_id' => 10,
            'confirmed' => 1
        ]);

        $response = $controller->assign( $request );

        $this->assertEquals( 200, $response->get_status() );
        $this->assertTrue( $response->get_data()['success'] );
    }

    public function test_save_volunteer_admin(): void {
        $this->repo->shouldReceive('save_volunteer')->once()->andReturn([
            'id' => 10,
            'email' => 'test@example.com',
            'constraints' => ['max_practices' => 2]
        ]);

        $controller = new Volunteer_Controller( $this->repo );
        Functions\when( 'current_user_can' )->justReturn( true );

        $request = \Mockery::mock( \WP_REST_Request::class );
        $request->shouldReceive('get_json_params')->once()->andReturn([
            'first_name' => 'Jane',
            'email' => 'test@example.com',
            'constraints' => ['max_practices' => 2]
        ]);

        $response = $controller->save_volunteer_admin( $request );

        $this->assertEquals( 200, $response->get_status() );
        $this->assertTrue( $response->get_data()['success'] );
        $this->assertEquals( ['max_practices' => 2], $response->get_data()['volunteer']['constraints'] );
    }

    public function test_save_availability_admin(): void {
        $this->repo->shouldReceive('save_availability')->once()->with(10, 5, [
            ['date' => '2026-08-14', 'overnight' => 0]
        ], 'part');

        $controller = new Volunteer_Controller( $this->repo );
        Functions\when( 'current_user_can' )->justReturn( true );

        $request = \Mockery::mock( \WP_REST_Request::class );
        $request->shouldReceive('get_json_params')->once()->andReturn([
            'volunteer_id' => 10,
            'expedition_post_id' => 5,
            'shifts' => [
                ['date' => '2026-08-14', 'overnight' => 0]
            ],
            'signup_type' => 'part'
        ]);

        $response = $controller->save_availability_admin( $request );

        $this->assertEquals( 200, $response->get_status() );
        $this->assertTrue( $response->get_data()['success'] );
    }
}
