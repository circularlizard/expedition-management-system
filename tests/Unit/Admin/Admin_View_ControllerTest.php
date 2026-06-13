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

    protected function setUp(): void {
        parent::setUp();
        $this->expeditions  = Mockery::mock( Expedition_Repository::class );
        $this->teams        = Mockery::mock( Team_Repository::class );
        $this->team_members = Mockery::mock( Team_Member_Repository::class );
        $this->tutor_client = Mockery::mock( TutorLMS_Client::class );
    }

    public function test_get_board_data_returns_hydrated_payload(): void {
        $mock_exp = [ 'ID' => 501, 'post_title' => 'Exp 1', 'ems_expedition_code' => 'E1' ];
        $this->expeditions->shouldReceive( 'list_all' )->once()->andReturn( [ $mock_exp ] );

        $mock_team = [ 'ID' => 601, 'ems_team_code' => 'T1', 'ems_expedition_id' => '501' ];
        $this->teams->shouldReceive( 'list_by_expedition' )->with( 501 )->once()->andReturn( [ $mock_team ] );

        $mock_member = [ 'id' => 1, 'user_id' => 123 ];
        $this->team_members->shouldReceive( 'list_by_team' )->with( 601 )->once()->andReturn( [ $mock_member ] );

        Functions\expect( 'get_user_meta' )->andReturnUsing( function( $uid, $key ) {
            return match ( $key ) {
                'ems_first_name' => 'Alice',
                'ems_last_name'  => 'Alpha',
                default => ''
            };
        } );

        // Mock TutorLMS
        $this->tutor_client->shouldReceive( 'get_all_courses' )->andReturn( [] );
        $this->tutor_client->shouldReceive( 'get_enrollment_matrix' )->andReturn( [ 123 => [] ] );

        Functions\expect( 'get_option' )->with( 'ems_osm_last_sync' )->andReturn( '2026-06-13' );

        $controller = new Admin_View_Controller(
            $this->expeditions,
            $this->teams,
            $this->team_members,
            $this->tutor_client
        );

        $response = $controller->get_board_data();
        $data     = $response->get_data();

        $this->assertCount( 1, $data['expeditions'] );
        $this->assertEquals( 'Alice', $data['members'][601][0]['first_name'] );
        $this->assertEquals( '2026-06-13', $data['last_sync'] );
    }
}
