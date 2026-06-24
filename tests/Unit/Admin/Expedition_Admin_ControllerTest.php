<?php
namespace EMS\Tests\Unit\Admin;

use EMS\Admin\Expedition_Admin_Controller;
use EMS\Data\Season_Repository;
use EMS\Data\Expedition_Repository;
use EMS\Data\Team_Repository;
use EMS\Data\Team_Member_Repository;
use EMS\Data\OSM_Explorer_Repository;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;

class Expedition_Admin_ControllerTest extends EMSTestCase {

    private function create_controller(
        ?Season_Repository $seasons = null,
        ?Expedition_Repository $expeditions = null,
        ?Team_Repository $teams = null,
        ?Team_Member_Repository $team_members = null,
        ?OSM_Explorer_Repository $explorers = null
    ): Expedition_Admin_Controller {
        return new Expedition_Admin_Controller(
            $seasons ?: \Mockery::mock( Season_Repository::class ),
            $expeditions ?: \Mockery::mock( Expedition_Repository::class ),
            $teams ?: \Mockery::mock( Team_Repository::class ),
            $team_members ?: \Mockery::mock( Team_Member_Repository::class ),
            $explorers ?: \Mockery::mock( OSM_Explorer_Repository::class )
        );
    }

    private function json_request( array $body ): \WP_REST_Request {
        $request = new \WP_REST_Request();
        $request->set_body_params( $body );
        $request->set_header( 'content-type', 'application/json' );
        return $request;
    }

    public function test_create_season_returns_201(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $seasons = \Mockery::mock( Season_Repository::class );
        $seasons->shouldReceive( 'create' )->with( [ 'post_title' => '', 'year' => '2026-27', 'status' => 'active' ] )->andReturn( 10 );
        $seasons->shouldReceive( 'get_by_id' )->with( 10 )->andReturn( [ 'ID' => 10, 'ems_season_year' => '2026-27' ] );

        $controller = $this->create_controller( $seasons );
        $response   = $controller->create_season( $this->json_request( [ 'year' => '2026-27' ] ) );

        $this->assertSame( 201, $response->get_status() );
        $this->assertSame( '2026-27', $response->get_data()['ems_season_year'] );
    }

    public function test_create_season_duplicate_year_returns_409(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $seasons = \Mockery::mock( Season_Repository::class );
        $seasons->shouldReceive( 'create' )->andThrow( new \InvalidArgumentException( 'Season year already exists' ) );

        $controller = $this->create_controller( $seasons );
        $response   = $controller->create_season( $this->json_request( [ 'year' => '2026-27' ] ) );

        $this->assertSame( 409, $response->get_status() );
        $this->assertSame( 'ems_season_year_exists', $response->get_data()->get_error_code() );
    }

    public function test_list_seasons_returns_seasons(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $seasons = \Mockery::mock( Season_Repository::class );
        $seasons->shouldReceive( 'list_all' )->andReturn( [ [ 'ID' => 10, 'ems_season_year' => '2026-27' ] ] );

        $controller = $this->create_controller( $seasons );
        $response   = $controller->list_seasons();

        $this->assertSame( 200, $response->get_status() );
        $this->assertCount( 1, $response->get_data() );
    }

    public function test_archive_season_updates_status(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $seasons = \Mockery::mock( Season_Repository::class );
        $seasons->shouldReceive( 'archive' )->with( 10 )->andReturn( true );
        $seasons->shouldReceive( 'get_by_id' )->with( 10 )->andReturn( [ 'ID' => 10, 'ems_season_status' => 'archived' ] );

        $controller = $this->create_controller( $seasons );
        $request    = new \WP_REST_Request();
        $request->set_param( 'id', 10 );
        $response   = $controller->archive_season( $request );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'archived', $response->get_data()['ems_season_status'] );
    }

    public function test_delete_season_empty_returns_200(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $seasons = \Mockery::mock( Season_Repository::class );
        $seasons->shouldReceive( 'get_by_id' )->with( 10 )->andReturn( [ 'ID' => 10 ] );
        $seasons->shouldReceive( 'has_events' )->with( 10 )->andReturn( false );
        $seasons->shouldReceive( 'delete' )->with( 10 )->andReturn( true );

        $controller = $this->create_controller( $seasons );
        $request    = new \WP_REST_Request();
        $request->set_param( 'id', 10 );
        $response   = $controller->delete_season( $request );

        $this->assertSame( 200, $response->get_status() );
        $this->assertTrue( $response->get_data()['deleted'] );
    }

    public function test_delete_season_with_events_returns_409(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $seasons = \Mockery::mock( Season_Repository::class );
        $seasons->shouldReceive( 'get_by_id' )->with( 10 )->andReturn( [ 'ID' => 10 ] );
        $seasons->shouldReceive( 'has_events' )->with( 10 )->andReturn( true );

        $controller = $this->create_controller( $seasons );
        $request    = new \WP_REST_Request();
        $request->set_param( 'id', 10 );
        $response   = $controller->delete_season( $request );

        $this->assertSame( 409, $response->get_status() );
        $this->assertSame( 'ems_season_has_events', $response->get_data()->get_error_code() );
    }

    public function test_delete_season_not_found_returns_404(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $seasons = \Mockery::mock( Season_Repository::class );
        $seasons->shouldReceive( 'get_by_id' )->with( 10 )->andReturn( null );

        $controller = $this->create_controller( $seasons );
        $request    = new \WP_REST_Request();
        $request->set_param( 'id', 10 );
        $response   = $controller->delete_season( $request );

        $this->assertSame( 404, $response->get_status() );
        $this->assertSame( 'ems_season_not_found', $response->get_data()->get_error_code() );
    }

    public function test_create_event_returns_201(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $expeditions = \Mockery::mock( Expedition_Repository::class );
        $expeditions->shouldReceive( 'create' )->andReturn( 20 );
        $expeditions->shouldReceive( 'get_by_id' )->with( 20 )->andReturn( [ 'ID' => 20, 'ems_event_code' => 'H-SP1' ] );

        $controller = $this->create_controller( null, $expeditions );
        $response   = $controller->create_event( $this->json_request( [
            'season_id'      => 10,
            'ems_event_code' => 'H-SP1',
            'ems_type'       => 'practice',
            'ems_transport'  => 'hillwalking',
            'ems_level'      => 'silver',
            'ems_start_date' => '2027-06-01',
            'ems_end_date'   => '2027-06-03',
        ] ) );

        $this->assertSame( 201, $response->get_status() );
        $this->assertSame( 'H-SP1', $response->get_data()['ems_event_code'] );
    }

    public function test_create_event_missing_required_returns_400(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $controller = $this->create_controller();
        $response   = $controller->create_event( $this->json_request( [ 'ems_event_code' => 'H-SP1' ] ) );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'ems_missing_required_field', $response->get_data()->get_error_code() );
    }

    public function test_create_event_invalid_enum_returns_400(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $controller = $this->create_controller();
        $response   = $controller->create_event( $this->json_request( [
            'season_id'      => 10,
            'ems_event_code' => 'H-SP1',
            'ems_type'       => 'invalid',
            'ems_transport'  => 'hillwalking',
            'ems_level'      => 'silver',
            'ems_start_date' => '2027-06-01',
            'ems_end_date'   => '2027-06-03',
        ] ) );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'ems_invalid_field_value', $response->get_data()->get_error_code() );
    }

    public function test_delete_event_with_teams_returns_409(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $expeditions = \Mockery::mock( Expedition_Repository::class );
        $expeditions->shouldReceive( 'get_by_id' )->with( 20 )->andReturn( [ 'ID' => 20 ] );
        $expeditions->shouldReceive( 'has_teams' )->with( 20 )->andReturn( true );

        $controller = $this->create_controller( null, $expeditions );
        $request    = new \WP_REST_Request();
        $request->set_param( 'id', 20 );
        $response   = $controller->delete_event( $request );

        $this->assertSame( 409, $response->get_status() );
        $this->assertSame( 'ems_event_has_teams', $response->get_data()->get_error_code() );
    }

    public function test_create_team_returns_201(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $expeditions = \Mockery::mock( Expedition_Repository::class );
        $expeditions->shouldReceive( 'get_by_id' )->with( 20 )->andReturn( [ 'ID' => 20, 'ems_event_code' => 'H-SP1' ] );

        $teams = \Mockery::mock( Team_Repository::class );
        $teams->shouldReceive( 'create' )->with( 20, 'H-SP1' )->andReturn( 30 );
        $teams->shouldReceive( 'get_by_id' )->with( 30 )->andReturn( [ 'ID' => 30, 'ems_team_code' => 'H-SP1-1' ] );

        $controller = $this->create_controller( null, $expeditions, $teams );
        $request    = new \WP_REST_Request();
        $request->set_param( 'event_id', 20 );
        $response   = $controller->create_team( $request );

        $this->assertSame( 201, $response->get_status() );
        $this->assertSame( 'H-SP1-1', $response->get_data()['ems_team_code'] );
    }

    public function test_delete_team_with_members_returns_409(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $teams = \Mockery::mock( Team_Repository::class );
        $teams->shouldReceive( 'get_by_id' )->with( 30 )->andReturn( [ 'ID' => 30, 'event_id' => 20 ] );

        $team_members = \Mockery::mock( Team_Member_Repository::class );
        $team_members->shouldReceive( 'list_by_team' )->with( 30 )->andReturn( [ [ 'user_id' => 1 ] ] );

        $controller = $this->create_controller( null, null, $teams, $team_members );
        $request    = new \WP_REST_Request();
        $request->set_param( 'id', 30 );
        $response   = $controller->delete_team( $request );

        $this->assertSame( 409, $response->get_status() );
        $this->assertSame( 'ems_team_has_members', $response->get_data()->get_error_code() );
    }

    public function test_move_team_to_different_type_returns_422(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $expeditions = \Mockery::mock( Expedition_Repository::class );
        $expeditions->shouldReceive( 'get_by_id' )->with( 30 )->andReturn( [ 'ID' => 30, 'event_id' => 20, 'ems_team_code' => 'H-SP1-1' ] );
        $expeditions->shouldReceive( 'get_by_id' )->with( 40 )->andReturn( [ 'ID' => 40, 'ems_event_code' => 'H-SQ1', 'ems_type' => 'qualifying' ] );
        $expeditions->shouldReceive( 'get_by_id' )->with( 20 )->andReturn( [ 'ID' => 20, 'ems_type' => 'practice' ] );

        $teams = \Mockery::mock( Team_Repository::class );
        $teams->shouldReceive( 'get_by_id' )->with( 30 )->andReturn( [ 'ID' => 30, 'event_id' => 20, 'ems_team_code' => 'H-SP1-1' ] );

        $controller = $this->create_controller( null, $expeditions, $teams );
        $request    = $this->json_request( [ 'target_event_id' => 40 ] );
        $request->set_param( 'id', 30 );
        $response   = $controller->move_team( $request );

        $this->assertSame( 422, $response->get_status() );
        $this->assertSame( 'ems_incompatible_event_type', $response->get_data()->get_error_code() );
    }

    public function test_add_member_explorer_not_found_returns_404(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $teams = \Mockery::mock( Team_Repository::class );
        $teams->shouldReceive( 'get_by_id' )->with( 30 )->andReturn( [ 'ID' => 30 ] );

        $explorers = \Mockery::mock( OSM_Explorer_Repository::class );
        $explorers->shouldReceive( 'find_by_scout_id' )->with( 9999999 )->andReturn( null );

        $controller = $this->create_controller( null, null, $teams, null, $explorers );
        $request    = $this->json_request( [ 'scout_id' => 9999999 ] );
        $request->set_param( 'team_id', 30 );
        $response   = $controller->add_member( $request );

        $this->assertSame( 404, $response->get_status() );
        $this->assertSame( 'ems_explorer_not_found', $response->get_data()->get_error_code() );
    }

    public function test_get_board_returns_season_event_team_hierarchy(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'get_option' )->justReturn( '2026-06-13 20:00:00' );

        $seasons = \Mockery::mock( Season_Repository::class );
        $seasons->shouldReceive( 'list_all' )->andReturn( [ [ 'ID' => 10, 'ems_season_year' => '2026-27' ] ] );

        $expeditions = \Mockery::mock( Expedition_Repository::class );
        $expeditions->shouldReceive( 'list_by_season' )->with( 10 )->andReturn( [ [ 'ID' => 20, 'ems_event_code' => 'H-SP1' ] ] );

        $teams = \Mockery::mock( Team_Repository::class );
        $teams->shouldReceive( 'list_by_expedition' )->with( 20 )->andReturn( [ [ 'ID' => 30, 'ems_team_code' => 'H-SP1-1' ] ] );

        $team_members = \Mockery::mock( Team_Member_Repository::class );
        $team_members->shouldReceive( 'list_by_team' )->with( 30 )->andReturn( [ [ 'scout_id' => 1 ], [ 'scout_id' => 2 ], [ 'scout_id' => 3 ], [ 'scout_id' => 4 ] ] );

        $explorers = \Mockery::mock( OSM_Explorer_Repository::class );
        $explorers->shouldReceive( 'find_by_scout_id' )->andReturn( [ 'first_name' => 'Alice', 'last_name' => 'MacLeod', 'scout_id' => 3417257, 'patrol' => 'Eagles' ] );
        $explorers->shouldReceive( 'list_all' )->andReturn( [] );

        $controller = $this->create_controller( $seasons, $expeditions, $teams, $team_members, $explorers );
        $response   = $controller->get_board();

        $this->assertSame( 200, $response->get_status() );
        $seasons_data = $response->get_data()['seasons'];
        $this->assertCount( 1, $seasons_data );
        $this->assertCount( 1, $seasons_data[0]['events'] );
        $this->assertSame( 4, $seasons_data[0]['events'][0]['teams'][0]['member_count'] );
        $this->assertFalse( $seasons_data[0]['events'][0]['teams'][0]['size_warning'] );
    }

    public function test_get_board_empty_season(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'get_option' )->justReturn( null );

        $seasons = \Mockery::mock( Season_Repository::class );
        $seasons->shouldReceive( 'list_all' )->andReturn( [ [ 'ID' => 10, 'ems_season_year' => '2026-27' ] ] );

        $expeditions = \Mockery::mock( Expedition_Repository::class );
        $expeditions->shouldReceive( 'list_by_season' )->with( 10 )->andReturn( [] );

        $explorers = \Mockery::mock( OSM_Explorer_Repository::class );
        $explorers->shouldReceive( 'list_all' )->andReturn( [] );

        $controller = $this->create_controller( $seasons, $expeditions, null, null, $explorers );
        $response   = $controller->get_board();

        $this->assertSame( 200, $response->get_status() );
        $this->assertEmpty( $response->get_data()['seasons'][0]['events'] );
    }

    public function test_add_member_assigns_by_scout_id_without_wp_user(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );

        $teams = \Mockery::mock( Team_Repository::class );
        $teams->shouldReceive( 'get_by_id' )->with( 30 )->andReturn( [ 'ID' => 30 ] );

        $explorers = \Mockery::mock( OSM_Explorer_Repository::class );
        $explorers->shouldReceive( 'find_by_scout_id' )->with( 3417257 )->andReturn( [ 'scout_id' => 3417257, 'wp_user_id' => 0, 'first_name' => 'Alice', 'last_name' => 'MacLeod' ] );

        $team_members = \Mockery::mock( Team_Member_Repository::class );
        $team_members->shouldReceive( 'assign' )->with( 30, 3417257, 1, 0 )->andReturn( 5 );
        $team_members->shouldReceive( 'list_by_team' )->with( 30 )->andReturn( [ [ 'scout_id' => 3417257 ] ] );

        $controller = $this->create_controller( null, null, $teams, $team_members, $explorers );
        $request    = $this->json_request( [ 'scout_id' => 3417257 ] );
        $request->set_param( 'team_id', 30 );
        $response   = $controller->add_member( $request );

        $this->assertSame( 201, $response->get_status() );
        $this->assertSame( 3417257, $response->get_data()[0]['scout_id'] );
    }

    public function test_remove_member_by_scout_id(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $teams = \Mockery::mock( Team_Repository::class );
        $teams->shouldReceive( 'get_by_id' )->with( 30 )->andReturn( [ 'ID' => 30 ] );

        $team_members = \Mockery::mock( Team_Member_Repository::class );
        $team_members->shouldReceive( 'remove' )->with( 30, 3417257 )->andReturn( true );
        $team_members->shouldReceive( 'list_by_team' )->with( 30 )->andReturn( [] );

        $explorers = \Mockery::mock( OSM_Explorer_Repository::class );

        $controller = $this->create_controller( null, null, $teams, $team_members, $explorers );
        $request    = new \WP_REST_Request();
        $request->set_param( 'team_id', 30 );
        $request->set_param( 'scout_id', 3417257 );
        $response   = $controller->remove_member( $request );

        $this->assertSame( 200, $response->get_status() );
    }

    public function test_check_permission_rejects_non_admin(): void {
        Functions\when( 'current_user_can' )->justReturn( false );

        $controller = $this->create_controller();
        $this->assertFalse( $controller->check_permission() );
    }
}
