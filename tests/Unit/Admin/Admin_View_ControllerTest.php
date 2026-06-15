<?php
namespace EMS\Tests\Unit\Admin;

use EMS\Admin\Admin_View_Controller;
use EMS\Data\Expedition_Repository;
use EMS\Data\Team_Repository;
use EMS\Data\Team_Member_Repository;
use EMS\Integrations\TutorLMS_Client;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;
use Mockery;

class Admin_View_ControllerTest extends EMSTestCase {

    private $expeditions;
    private $teams;
    private $team_members;
    private $tutor_client;
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        $this->expeditions  = Mockery::mock( Expedition_Repository::class );
        $this->teams        = Mockery::mock( Team_Repository::class );
        $this->team_members = Mockery::mock( Team_Member_Repository::class );
        $this->tutor_client = Mockery::mock( TutorLMS_Client::class );

        $this->wpdb         = Mockery::mock( 'wpdb' );
        $this->wpdb->prefix = 'wp_';
        $GLOBALS['wpdb']    = $this->wpdb;
    }

    private function make_controller(): Admin_View_Controller {
        return new Admin_View_Controller(
            $this->expeditions,
            $this->teams,
            $this->team_members,
            $this->tutor_client
        );
    }

    private function explorer_row( array $overrides = [] ): array {
        return array_merge( [
            'scout_id'   => 1001,
            'wp_user_id' => 123,
            'first_name' => 'Alice',
            'last_name'  => 'Alpha',
            'email'      => 'scout.1001@example-ems.test',
            'patrol'     => 'Bears',
            'first_aid'  => '',
        ], $overrides );
    }

    public function test_get_board_data_returns_hydrated_payload(): void {
        $mock_exp = [ 'ID' => 501, 'post_title' => 'Exp 1', 'ems_expedition_code' => 'E1' ];
        $this->expeditions->shouldReceive( 'list_all' )->once()->andReturn( [ $mock_exp ] );

        $mock_team = [ 'ID' => 601, 'ems_team_code' => 'T1', 'ems_expedition_id' => '501' ];
        $this->teams->shouldReceive( 'list_by_expedition' )->with( 501 )->once()->andReturn( [ $mock_team ] );

        $mock_member = [ 'id' => 1, 'user_id' => 123 ];
        $this->team_members->shouldReceive( 'list_by_team' )->with( 601 )->once()->andReturn( [ $mock_member ] );

        $explorer_row = $this->explorer_row();

        $this->wpdb->shouldReceive( 'prepare' )->andReturnArg( 0 );
        $this->wpdb->shouldReceive( 'get_row' )->andReturn( $explorer_row );
        $this->wpdb->shouldReceive( 'get_results' )->andReturn( [ $explorer_row ] );

        $this->tutor_client->shouldReceive( 'get_all_courses' )->andReturn( [] );
        $this->tutor_client->shouldReceive( 'get_enrollment_matrix' )->andReturn( [ 123 => [] ] );

        Functions\expect( 'get_option' )->with( 'ems_osm_last_sync' )->andReturn( '2026-06-13' );

        $response = $this->make_controller()->get_board_data();
        $data     = $response->get_data();

        $this->assertCount( 1, $data['expeditions'] );
        $this->assertEquals( 'Alice', $data['members'][601][0]['first_name'] );
        $this->assertEquals( 'Alice', $data['explorers'][0]['first_name'] );
        $this->assertEquals( '2026-06-13', $data['last_sync'] );
    }

    public function test_get_explorer_detail_returns_explorer(): void {
        $explorer_row = $this->explorer_row();
        $this->wpdb->shouldReceive( 'prepare' )->andReturnArg( 0 );
        $this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $explorer_row );

        $this->tutor_client->shouldReceive( 'get_all_courses' )->andReturn( [] );
        $this->tutor_client->shouldReceive( 'get_enrollment_matrix' )->andReturn( [ 123 => [] ] );

        Functions\expect( 'get_option' )->with( 'ems_osm_last_sync' )->andReturn( '2026-06-15' );

        $request = Mockery::mock( \WP_REST_Request::class );
        $request->shouldReceive( 'get_param' )->with( 'scout_id' )->andReturn( 1001 );

        $response = $this->make_controller()->get_explorer_detail( $request );
        $data     = $response->get_data();

        $this->assertEquals( 1001,    $data['scout_id'] );
        $this->assertEquals( 'Alice', $data['first_name'] );
        $this->assertEquals( 'Bears', $data['patrol'] );
        $this->assertEquals( '2026-06-15', $data['last_synced'] );
    }

    public function test_get_explorer_detail_returns_404_when_not_found(): void {
        $this->wpdb->shouldReceive( 'prepare' )->andReturnArg( 0 );
        $this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

        $request = Mockery::mock( \WP_REST_Request::class );
        $request->shouldReceive( 'get_param' )->with( 'scout_id' )->andReturn( 9999 );

        $response = $this->make_controller()->get_explorer_detail( $request );

        $this->assertEquals( 404, $response->get_status() );
    }

    public function test_get_team_detail_returns_members_and_first_aid_flag(): void {
        $explorer_row = $this->explorer_row( [ 'first_aid' => 'FIRST RESPONSE' ] );

        $this->team_members->shouldReceive( 'list_by_team' )
            ->with( 601 )->once()
            ->andReturn( [ [ 'id' => 1, 'user_id' => 123 ] ] );

        $this->wpdb->shouldReceive( 'prepare' )->andReturnArg( 0 );
        $this->wpdb->shouldReceive( 'get_row' )->andReturn( $explorer_row );

        $this->tutor_client->shouldReceive( 'get_all_courses' )->andReturn( [] );
        $this->tutor_client->shouldReceive( 'get_enrollment_matrix' )->andReturn( [ 123 => [] ] );

        Functions\expect( 'get_option' )->with( 'ems_osm_last_sync' )->andReturn( '2026-06-15' );

        $request = Mockery::mock( \WP_REST_Request::class );
        $request->shouldReceive( 'get_param' )->with( 'team_id' )->andReturn( 601 );

        $response = $this->make_controller()->get_team_detail( $request );
        $data     = $response->get_data();

        $this->assertEquals( 601,   $data['team_id'] );
        $this->assertCount( 1,      $data['members'] );
        $this->assertEquals( 1001,  $data['members'][0]['scout_id'] );
        $this->assertTrue( $data['first_aid_covered'] );
        $this->assertEquals( '2026-06-15', $data['last_synced'] );
    }

    public function test_get_team_detail_first_aid_false_when_no_first_aid(): void {
        $explorer_row = $this->explorer_row( [ 'first_aid' => '' ] );

        $this->team_members->shouldReceive( 'list_by_team' )
            ->with( 601 )->once()
            ->andReturn( [ [ 'id' => 1, 'user_id' => 123 ] ] );

        $this->wpdb->shouldReceive( 'prepare' )->andReturnArg( 0 );
        $this->wpdb->shouldReceive( 'get_row' )->andReturn( $explorer_row );

        $this->tutor_client->shouldReceive( 'get_all_courses' )->andReturn( [] );
        $this->tutor_client->shouldReceive( 'get_enrollment_matrix' )->andReturn( [ 123 => [] ] );

        Functions\expect( 'get_option' )->with( 'ems_osm_last_sync' )->andReturn( null );

        $request = Mockery::mock( \WP_REST_Request::class );
        $request->shouldReceive( 'get_param' )->with( 'team_id' )->andReturn( 601 );

        $response = $this->make_controller()->get_team_detail( $request );
        $data     = $response->get_data();

        $this->assertFalse( $data['first_aid_covered'] );
        $this->assertNull( $data['last_synced'] );
    }

    public function test_get_patrol_detail_returns_explorers_for_patrol(): void {
        $rows = [
            $this->explorer_row( [ 'scout_id' => 1001, 'first_name' => 'Alice', 'last_name' => 'Alpha' ] ),
            $this->explorer_row( [ 'scout_id' => 1002, 'first_name' => 'Bob',   'last_name' => 'Beta', 'wp_user_id' => 124 ] ),
        ];

        $this->wpdb->shouldReceive( 'prepare' )->andReturnArg( 0 );
        $this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );

        Functions\expect( 'get_option' )->with( 'ems_osm_last_sync' )->andReturn( '2026-06-15' );
        Functions\expect( 'sanitize_text_field' )->andReturnArg( 0 );

        $request = Mockery::mock( \WP_REST_Request::class );
        $request->shouldReceive( 'get_param' )->with( 'patrol' )->andReturn( 'Bears' );

        $response = $this->make_controller()->get_patrol_detail( $request );
        $data     = $response->get_data();

        $this->assertEquals( 'Bears', $data['patrol'] );
        $this->assertCount( 2, $data['explorers'] );
        $this->assertEquals( 1001, $data['explorers'][0]['scout_id'] );
        $this->assertEquals( '2026-06-15', $data['last_synced'] );
    }

    public function test_get_patrol_detail_returns_empty_when_no_explorers(): void {
        $this->wpdb->shouldReceive( 'prepare' )->andReturnArg( 0 );
        $this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( [] );

        Functions\expect( 'get_option' )->with( 'ems_osm_last_sync' )->andReturn( null );
        Functions\expect( 'sanitize_text_field' )->andReturnArg( 0 );

        $request = Mockery::mock( \WP_REST_Request::class );
        $request->shouldReceive( 'get_param' )->with( 'patrol' )->andReturn( 'Unknown' );

        $response = $this->make_controller()->get_patrol_detail( $request );
        $data     = $response->get_data();

        $this->assertCount( 0, $data['explorers'] );
        $this->assertNull( $data['last_synced'] );
    }
}
