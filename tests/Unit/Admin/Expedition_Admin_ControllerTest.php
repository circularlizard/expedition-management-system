<?php
namespace EMS\Tests\Unit\Admin;

use EMS\Admin\Expedition_Admin_Controller;
use EMS\Data\Season_Repository;
use EMS\Data\Expedition_Repository;
use EMS\Data\Team_Repository;
use EMS\Data\Team_Member_Repository;
use EMS\Data\OSM_Explorer_Repository;
use EMS\Data\OSM_Event_Repository;
use EMS\Data\Signup_Repository;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;

class Expedition_Admin_ControllerTest extends EMSTestCase {

    private function create_controller(
        ?Season_Repository $seasons = null,
        ?Expedition_Repository $expeditions = null,
        ?Team_Repository $teams = null,
        ?Team_Member_Repository $team_members = null,
        ?OSM_Explorer_Repository $explorers = null,
        ?OSM_Event_Repository $osm_events = null,
        ?Signup_Repository $signups = null
    ): Expedition_Admin_Controller {
        if ( ! $signups ) {
            $signups = \Mockery::mock( Signup_Repository::class );
            $signups->shouldReceive( 'has_additional_support_needs' )->byDefault()->andReturn( false );
        }
        return new Expedition_Admin_Controller(
            $seasons ?: \Mockery::mock( Season_Repository::class ),
            $expeditions ?: \Mockery::mock( Expedition_Repository::class ),
            $teams ?: \Mockery::mock( Team_Repository::class ),
            $team_members ?: \Mockery::mock( Team_Member_Repository::class ),
            $explorers ?: \Mockery::mock( OSM_Explorer_Repository::class ),
            $osm_events ?: \Mockery::mock( OSM_Event_Repository::class ),
            null,
            $signups
        );
    }

    private function json_request( array $body ): \WP_REST_Request {
        $request = new \WP_REST_Request();
        $request->set_body_params( $body );
        $request->set_header( 'content-type', 'application/json' );
        return $request;
    }

    public function test_create_event_returns_201(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $expeditions = \Mockery::mock( Expedition_Repository::class );
        $expeditions->shouldReceive( 'create' )->andReturn( 20 );
        $expeditions->shouldReceive( 'get_by_id' )->with( 20 )->andReturn( [ 'ID' => 20, 'ems_event_code' => 'H-SP1' ] );

        $teams = \Mockery::mock( Team_Repository::class );
        $teams->shouldReceive( 'create' )->once()->with( 20, 'H-SP1', 'UNALLOCATED' )->andReturn( 100 );

        $controller = $this->create_controller( null, $expeditions, $teams );
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

    public function test_update_event_success(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $expeditions = \Mockery::mock( Expedition_Repository::class );
        $expeditions->shouldReceive( 'get_by_id' )->with( 20 )->andReturn( [ 'ID' => 20, 'ems_event_code' => 'H-SP1' ] );
        $expeditions->shouldReceive( 'update' )->once()->with( 20, [ 'ems_lic_name' => 'Jane Smith' ] )->andReturn( true );

        $controller = $this->create_controller( null, $expeditions );
        $request    = $this->json_request( [ 'ems_lic_name' => 'Jane Smith' ] );
        $request->set_param( 'id', 20 );
        $response   = $controller->update_event( $request );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'H-SP1', $response->get_data()['ems_event_code'] );
    }

    public function test_update_event_invalid_enum_returns_400(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $expeditions = \Mockery::mock( Expedition_Repository::class );
        $expeditions->shouldReceive( 'get_by_id' )->with( 20 )->andReturn( [ 'ID' => 20 ] );

        $controller = $this->create_controller( null, $expeditions );
        $request    = $this->json_request( [ 'ems_type' => 'invalid_type' ] );
        $request->set_param( 'id', 20 );
        $response   = $controller->update_event( $request );

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

        $expeditions = \Mockery::mock( Expedition_Repository::class );
        $expeditions->shouldReceive( 'list_all_chronological' )->andReturn( [ [ 'ID' => 20, 'ems_event_code' => 'H-SP1' ] ] );

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
        $this->assertSame( 'All Events', $seasons_data[0]['post_title'] );
        $this->assertCount( 1, $seasons_data[0]['events'] );
        $this->assertSame( 4, $seasons_data[0]['events'][0]['teams'][0]['member_count'] );
        $this->assertFalse( $seasons_data[0]['events'][0]['teams'][0]['size_warning'] );
    }

    public function test_get_board_empty_season(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'get_option' )->justReturn( null );

        $seasons = \Mockery::mock( Season_Repository::class );

        $expeditions = \Mockery::mock( Expedition_Repository::class );
        $expeditions->shouldReceive( 'list_all_chronological' )->andReturn( [] );

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
        $explorers->shouldReceive( 'touch_last_local_update' )->once()->with( 3417257 )->andReturn( true );

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
        $explorers->shouldReceive( 'touch_last_local_update' )->once()->with( 3417257 )->andReturn( true );

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

    public function test_list_osm_events_returns_events(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $osm_events = \Mockery::mock( OSM_Event_Repository::class );
        $osm_events->shouldReceive( 'list_all' )->andReturn( [
            [ 'id' => 1, 'event_id' => 40001, 'section_id' => 99001, 'name' => 'Summer Camp', 'start_date' => '2026-07-01', 'end_date' => '2026-07-03', 'location' => 'Loch Lomond' ],
        ] );

        $controller = $this->create_controller( null, null, null, null, null, $osm_events );
        $response   = $controller->list_osm_events();

        $this->assertSame( 200, $response->get_status() );
        $this->assertCount( 1, $response->get_data() );
        $this->assertSame( 'Summer Camp', $response->get_data()[0]['name'] );
    }

    public function test_update_first_aid_level_returns_updated_level(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $explorers = \Mockery::mock( OSM_Explorer_Repository::class );
        $explorers->shouldReceive( 'find_by_scout_id' )->with( 30001 )->andReturn( [ 'scout_id' => 30001 ] );
        $explorers->shouldReceive( 'update_first_aid_level' )->with( 30001, 'full_first_aid' )->andReturn( true );

        $controller = $this->create_controller( null, null, null, null, $explorers );
        $request    = $this->json_request( [ 'first_aid_level' => 'full_first_aid' ] );
        $request->set_param( 'scout_id', 30001 );
        $response   = $controller->update_first_aid_level( $request );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'full_first_aid', $response->get_data()['first_aid_level'] );
    }

    public function test_update_first_aid_level_returns_500_when_repository_fails(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $explorers = \Mockery::mock( OSM_Explorer_Repository::class );
        $explorers->shouldReceive( 'find_by_scout_id' )->with( 30001 )->andReturn( [ 'scout_id' => 30001 ] );
        $explorers->shouldReceive( 'update_first_aid_level' )->with( 30001, 'full_first_aid' )->andReturn( false );

        $controller = $this->create_controller( null, null, null, null, $explorers );
        $request    = $this->json_request( [ 'first_aid_level' => 'full_first_aid' ] );
        $request->set_param( 'scout_id', 30001 );
        $response   = $controller->update_first_aid_level( $request );

        $this->assertSame( 500, $response->get_status() );
    }

    public function test_update_first_aid_level_rejects_invalid_value(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $explorers = \Mockery::mock( OSM_Explorer_Repository::class );
        $explorers->shouldReceive( 'find_by_scout_id' )->with( 30001 )->andReturn( [ 'scout_id' => 30001 ] );

        $controller = $this->create_controller( null, null, null, null, $explorers );
        $request    = $this->json_request( [ 'first_aid_level' => 'surgeon' ] );
        $request->set_param( 'scout_id', 30001 );
        $response   = $controller->update_first_aid_level( $request );

        $this->assertSame( 400, $response->get_status() );
    }

    public function test_get_explorer_profile_returns_joined_data(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $explorers = \Mockery::mock( OSM_Explorer_Repository::class );
        $explorers->shouldReceive( 'find_by_scout_id' )->with( 10001 )->andReturn( [
            'scout_id'        => 10001,
            'wp_user_id'      => 42,
            'first_name'      => 'John',
            'last_name'       => 'Doe',
            'email'           => 'john@example.com',
            'patrol'          => 'Summit',
            'section_id'      => 99001,
            'additional_support_needs' => 'Organizer note'
        ] );

        global $wpdb;
        $wpdb = \Mockery::mock( \wpdb::class );
        $wpdb->prefix   = 'wp_';
        $wpdb->posts    = 'wp_posts';
        $wpdb->usermeta = 'wp_usermeta';
        $wpdb->comments = 'wp_comments';
        $wpdb->shouldReceive( 'prepare' )->byDefault()->andReturnUsing( function( $sql, ...$args ) {
            $pattern = str_replace( [ '%d', '%f' ], '%s', $sql );
            return vsprintf( $pattern, $args );
        } );

        // 1. Leader email from ems_units
        $wpdb->shouldReceive( 'get_var' )->with( \Mockery::on( function( $sql ) {
            return strpos( $sql, 'leader_email FROM wp_ems_units' ) !== false;
        } ) )->andReturn( 'leader@example.com' );

        // 2. Teams/events query:
        // Return 1 training assignment and 1 practice assignment
        $wpdb->shouldReceive( 'get_results' )->with( \Mockery::on( function( $sql ) {
            return strpos( $sql, 'FROM wp_ems_team_members' ) !== false;
        } ), ARRAY_A )->andReturn( [
            [
                'team_id'      => 201,
                'team_name'    => 'Team A',
                'event_id'     => 101,
                'event_title'  => 'Bronze Training',
                'team_code'    => 'T-BR1-1',
                'event_type'   => 'training',
                'start_date'   => '2026-06-15',
                'end_date'     => '2026-06-16',
                'osm_event_id' => '40001',
            ],
            [
                'team_id'      => 202,
                'team_name'    => 'Team B',
                'event_id'     => 102,
                'event_title'  => 'Silver Practice',
                'team_code'    => 'P-SI1-1',
                'event_type'   => 'practice',
                'start_date'   => '2026-07-15',
                'end_date'     => '2026-07-16',
                'osm_event_id' => '40002',
            ]
        ] );

        // 3. Attendance status query for each OSM event
        $wpdb->shouldReceive( 'get_var' )->with( \Mockery::on( function( $sql ) {
            return strpos( $sql, 'FROM wp_ems_osm_event_attendance' ) !== false;
        } ) )->andReturnUsing( function( $sql ) {
            if ( strpos( $sql, 'event_id = 40001' ) !== false || strpos( $sql, '40001' ) !== false ) {
                return 'Yes';
            }
            return 'Invited';
        } );

        // 4. Expedition signup query (dates and team preferences)
        $wpdb->shouldReceive( 'get_row' )->with( \Mockery::on( function( $sql ) {
            return strpos( $sql, 'FROM wp_ems_expedition_signups' ) !== false;
        } ), ARRAY_A )->andReturn( [
            'expedition_preferences' => json_encode( [
                'exped_type'            => 'hillwalking',
                'exped_practice_dates'  => '2026-06-15',
                'exped_qualifier_dates' => '2026-07-15',
                'exped_team_names'      => 'Buddy list',
            ] ),
            'additional_support_needs' => 'Parent notes',
        ] );

        // 5. Participant places signups query
        $wpdb->shouldReceive( 'get_results' )->with( \Mockery::on( function( $sql ) {
            return strpos( $sql, 'FROM wp_ems_participant_signups' ) !== false;
        } ), ARRAY_A )->andReturn( [
            [
                'id'                 => 12,
                'dofe_level'         => 'gold',
                'created_at'         => '2026-06-01 10:00:00',
                'signup_status'      => 'received',
                'form_submission_id' => 8888,
            ]
        ] );

        // 5b. Tutor LMS matrix queries:
        // Query 1: Active enrollments
        $wpdb->shouldReceive( 'get_results' )->with( \Mockery::on( function( $sql ) {
            return strpos( $sql, "post_type   = 'tutor_enrolled'" ) !== false;
        } ) )->andReturn( [
            (object) [
                'post_author' => 42,
                'post_parent' => 301,
            ]
        ] );

        // Query 2: Explicit completion meta
        $wpdb->shouldReceive( 'get_results' )->with( \Mockery::on( function( $sql ) {
            return strpos( $sql, 'FROM wp_usermeta' ) !== false && strpos( $sql, 'meta_key IN' ) !== false;
        } ) )->andReturn( [
            (object) [
                'user_id'  => 42,
                'meta_key' => '_tutor_completed_course_301'
            ]
        ] );

        // Query 3: All lesson + quiz IDs per course
        $wpdb->shouldReceive( 'get_results' )->with( \Mockery::on( function( $sql ) {
            return strpos( $sql, "t.post_type   = 'topics'" ) !== false;
        } ) )->andReturn( [] );

        // Query 4: SHOW TABLES LIKE
        $wpdb->shouldReceive( 'get_var' )->with( \Mockery::on( function( $sql ) {
            return strpos( $sql, 'SHOW TABLES LIKE' ) !== false;
        } ) )->andReturn( null );

        // We stub TutorLMS client via get_posts and metadata if it is called, or we can mock get_posts / get_user_meta
        Functions\when( 'get_posts' )->alias( function( $args ) {
            if ( isset( $args['post_type'] ) && $args['post_type'] === 'courses' ) {
                return [
                    (object) [ 'ID' => 301, 'post_title' => 'Camp Prep' ],
                ];
            }
            if ( isset( $args['post_type'] ) && $args['post_type'] === 'tutor_enrolled' ) {
                return [
                    (object) [ 'post_author' => 42 ],
                ];
            }
            return [];
        } );
        Functions\when( 'get_user_meta' )->alias( function( $user_id, $key, $single ) {
            if ( $user_id === 42 && strpos( $key, '_tutor_completed_course_301' ) !== false ) {
                return '1';
            }
            return '';
        } );

        $controller = $this->create_controller( null, null, null, null, $explorers );
        $request    = new \WP_REST_Request();
        $request->set_param( 'scout_id', 10001 );

        $response   = $controller->get_explorer_profile( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();

        $this->assertSame( 'John', $data['first_name'] );
        $this->assertSame( 'Doe', $data['last_name'] );
        $this->assertSame( 10001, $data['scout_id'] );
        $this->assertSame( 'john@example.com', $data['email'] );
        $this->assertSame( 'Summit', $data['unit'] );
        $this->assertSame( 'leader@example.com', $data['leader_email'] );
        $this->assertSame( 'Organizer note', $data['organiser_notes'] );
        $this->assertSame( 'Parent notes', $data['parent_asn'] );

        // Check events
        $this->assertCount( 1, $data['training_events'] );
        $this->assertSame( 'Bronze Training', $data['training_events'][0]['event_title'] );
        $this->assertSame( 'Yes', $data['training_events'][0]['osm_status'] );

        $this->assertCount( 1, $data['practice_events'] );
        $this->assertSame( 'Silver Practice', $data['practice_events'][0]['event_title'] );
        $this->assertSame( 'Invited', $data['practice_events'][0]['osm_status'] );

        $this->assertCount( 0, $data['qualifiers_events'] );

        // Check preferences
        $this->assertSame( 'hillwalking', $data['preferences']['exped_type'] );

        // Check participant place signups
        $this->assertCount( 1, $data['participant_signups'] );
        $this->assertSame( 'gold', $data['participant_signups'][0]['dofe_level'] );

        // Check training records
        $this->assertCount( 1, $data['training_records'] );
        $this->assertSame( 'Camp Prep', $data['training_records'][0]['title'] );
        $this->assertSame( 'complete', $data['training_records'][0]['status'] );
    }

    public function test_get_explorer_profile_returns_404(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $explorers = \Mockery::mock( OSM_Explorer_Repository::class );
        $explorers->shouldReceive( 'find_by_scout_id' )->with( 99999 )->andReturn( null );

        $controller = $this->create_controller( null, null, null, null, $explorers );
        $request    = new \WP_REST_Request();
        $request->set_param( 'scout_id', 99999 );

        $response   = $controller->get_explorer_profile( $request );

        $this->assertSame( 404, $response->get_status() );
    }

    public function test_list_participant_signups_success(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $signups = \Mockery::mock( Signup_Repository::class );
        $signups->shouldReceive( 'get_participant_signups' )->with( 'received' )->andReturn( [
            [
                'id' => 10,
                'scout_id' => 101,
                'parent_user_id' => 4,
                'unit_id' => 99001,
                'unit_name' => 'Kelso',
                'explorer_first_name' => 'Mary',
                'explorer_last_name' => 'Smith',
                'explorer_email' => 'mary@example.com',
                'parent_email' => 'parent@example.com',
                'dofe_level' => 'bronze',
                'dob' => '2010-05-15',
                'dofe_registered' => 'n',
                'dofe_number' => null,
                'dofe_org' => null,
                'bronze_completion' => null,
                'silver_completion' => null,
                'signup_status' => 'received',
                'payment_status' => 'paid',
                'form_submission_id' => 1234,
                'is_synced_osm' => 1,
                'created_at' => '2026-06-13 20:00:00',
            ]
        ] );

        $controller = $this->create_controller( null, null, null, null, null, null, $signups );
        $request = new \WP_REST_Request();
        $request->set_param( 'status', 'received' );
        $response = $controller->list_participant_signups( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertCount( 1, $data );
        $this->assertSame( 'Mary', $data[0]['explorer_first_name'] );
    }

    public function test_export_participant_signups_success(): void {
        if ( ! defined( 'EMS_UNIT_TESTS' ) ) {
            define( 'EMS_UNIT_TESTS', true );
        }
        Functions\when( 'current_user_can' )->justReturn( true );

        $signups = \Mockery::mock( Signup_Repository::class );
        $signups->shouldReceive( 'get_participant_signups_for_export' )->with( 'received', 'bronze' )->andReturn( [
            [
                'id' => 10,
                'scout_id' => 101,
                'parent_user_id' => 4,
                'unit_id' => 99001,
                'unit_name' => 'Kelso',
                'explorer_first_name' => 'Mary',
                'explorer_last_name' => 'Smith',
                'explorer_email' => 'mary@example.com',
                'parent_email' => 'parent@example.com',
                'dofe_level' => 'bronze',
                'dob' => '2010-05-15',
                'dofe_registered' => 'n',
                'dofe_number' => 'D12345',
                'dofe_org' => null,
                'bronze_completion' => null,
                'silver_completion' => null,
                'signup_status' => 'received',
                'payment_status' => 'paid',
                'form_submission_id' => 1234,
                'is_synced_osm' => 1,
                'has_osm_record' => 1,
                'osm_wp_user_id' => 42,
                'processed_by_name' => 'Admin User',
                'processed_at' => '2026-06-13 21:00:00',
                'created_at' => '2026-06-13 20:00:00',
            ]
        ] );

        $controller = $this->create_controller( null, null, null, null, null, null, $signups );
        $request = new \WP_REST_Request();
        $request->set_param( 'status', 'received' );
        $request->set_param( 'level', 'bronze' );

        ob_start();
        $controller->export_participant_signups( $request );
        $csv_data = ob_get_clean();

        $this->assertStringContainsString( 'ID,"Scout ID","Explorer First Name"', $csv_data );
        $this->assertStringContainsString( '10,101,Mary,Smith', $csv_data );
        $this->assertStringContainsString( 'linked', $csv_data );
    }

    public function test_process_participant_signup_success(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'get_current_user_id' )->justReturn( 42 );

        $signups = \Mockery::mock( Signup_Repository::class );
        $signups->shouldReceive( 'get_participant_signup' )->with( 10 )->andReturn( [
            'id' => 10,
            'payment_status' => 'paid',
            'signup_status' => 'received',
        ] );
        $signups->shouldReceive( 'process_participant_signup' )->with( 10, 42, 'D-778899' )->andReturn( true );

        $controller = $this->create_controller( null, null, null, null, null, null, $signups );
        $request = $this->json_request( [ 'dofe_number' => 'D-778899' ] );
        $request->set_param( 'id', 10 );
        $response = $controller->process_participant_signup( $request );

        $this->assertSame( 200, $response->get_status() );
        $this->assertTrue( $response->get_data()['processed'] );
    }



    public function test_archive_participant_signup_success(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $signups = \Mockery::mock( Signup_Repository::class );
        $signups->shouldReceive( 'get_participant_signup' )->with( 12 )->andReturn( [ 'id' => 12 ] );
        $signups->shouldReceive( 'archive_participant_signup' )->with( 12 )->andReturn( true );

        $controller = $this->create_controller( null, null, null, null, null, null, $signups );
        $request = new \WP_REST_Request();
        $request->set_param( 'id', 12 );
        $response = $controller->archive_participant_signup( $request );

        $this->assertSame( 200, $response->get_status() );
        $this->assertTrue( $response->get_data()['archived'] );
    }

    public function test_list_expedition_signups_success(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $signups = \Mockery::mock( Signup_Repository::class );
        $signups->shouldReceive( 'get_expedition_signups' )->with( 'pending' )->andReturn( [
            [
                'id' => 20,
                'scout_id' => 102,
                'parent_user_id' => 5,
                'unit_id' => 99002,
                'unit_name' => 'SMESU',
                'explorer_first_name' => 'John',
                'explorer_last_name' => 'Doe',
                'explorer_email' => 'john@example.com',
                'parent_email' => 'parent2@example.com',
                'dofe_level' => 'silver',
                'dofe_number' => 'D-991234',
                'expedition_preferences' => '{"exped_type":"Hillwalking"}',
                'additional_support_needs' => '',
                'first_aid_status' => 'first_response',
                'first_aid_expiry' => '2028-06-13',
                'signup_status' => 'pending',
                'form_submission_id' => 5678,
                'is_synced_osm' => 0,
                'created_at' => '2026-06-13 20:00:00',
            ]
        ] );

        $controller = $this->create_controller( null, null, null, null, null, null, $signups );
        $request = new \WP_REST_Request();
        $request->set_param( 'status', 'pending' );
        $response = $controller->list_expedition_signups( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertCount( 1, $data );
        $this->assertSame( 'John', $data[0]['explorer_first_name'] );
    }

    public function test_list_planning_board_success(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $expeditions = \Mockery::mock( Expedition_Repository::class );
        $expeditions->shouldReceive( 'list_all_chronological' )->andReturn( [
            [ 'ID' => 101, 'ems_event_code' => 'H-SP1', 'ems_status' => 'active', 'ems_level' => 'silver', 'ems_type' => 'practice', 'post_title' => 'Hill Practice 1' ],
            [ 'ID' => 102, 'ems_event_code' => 'H-BP1', 'ems_status' => 'active', 'ems_level' => 'bronze', 'ems_type' => 'practice', 'post_title' => 'Bronze Practice' ],
        ] );

        $signups = \Mockery::mock( Signup_Repository::class );
        $signups->shouldReceive( 'get_expedition_signups' )->with( 'pending' )->andReturn( [
            [ 'scout_id' => 4001, 'dofe_level' => 'silver', 'expedition_preferences' => '{"exped_type":"Hillwalking","exped_practice_dates":["H-SP1"],"exped_qualifier_dates":[]}' ],
            [ 'scout_id' => 4002, 'dofe_level' => 'silver', 'expedition_preferences' => '{"exped_type":"Hillwalking","exped_practice_dates":["H-SP1"],"exped_qualifier_dates":[]}' ],
        ] );

        $teams = \Mockery::mock( Team_Repository::class );
        $teams->shouldReceive( 'list_by_expedition' )->with( 101 )->andReturn( [
            [ 'ID' => 201, 'ems_team_code' => 'H-SP1-1' ]
        ] );
        $teams->shouldReceive( 'get_unallocated_team' )->with( 101 )->andReturn(
            [ 'ID' => 202, 'ems_team_code' => 'UNALLOCATED' ]
        );

        $team_members = \Mockery::mock( Team_Member_Repository::class );
        $team_members->shouldReceive( 'list_by_team' )->with( 201 )->andReturn( [ [ 'scout_id' => 4001 ] ] );
        $team_members->shouldReceive( 'list_by_team' )->with( 202 )->andReturn( [] );

        Functions\when( 'get_post_meta' )->justReturn( 'none' );

        $controller = $this->create_controller( null, $expeditions, $teams, $team_members, null, null, $signups );
        $response = $controller->list_planning_board( new \WP_REST_Request() );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertCount( 1, $data ); // Bronze event excluded
        $this->assertSame( 'H-SP1', $data[0]['event_code'] );
        $this->assertSame( 2, $data[0]['available_count'] );
        $this->assertSame( 1, $data[0]['allocated_count'] );
    }

    public function test_list_planning_board_availability_success(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $signups = \Mockery::mock( Signup_Repository::class );
        $signups->shouldReceive( 'get_expedition_signups' )->with( 'pending' )->andReturn( [
            [ 'scout_id' => 4001, 'explorer_first_name' => 'Alice', 'explorer_last_name' => 'MacLeod', 'unit_name' => 'SMESU', 'dofe_level' => 'silver', 'expedition_preferences' => '{"exped_type":"Hillwalking","exped_practice_dates":["H-SP1"],"exped_qualifier_dates":[]}' ],
            [ 'scout_id' => 4002, 'explorer_first_name' => 'Bob', 'explorer_last_name' => 'Smith', 'unit_name' => 'Kelso', 'dofe_level' => 'silver', 'expedition_preferences' => '{"exped_type":"Hillwalking","exped_practice_dates":["H-SP1"],"exped_qualifier_dates":[]}' ],
        ] );

        $teams = \Mockery::mock( Team_Repository::class );
        $teams->shouldReceive( 'list_by_expedition' )->with( 103 )->andReturn( [
            [ 'ID' => 201, 'ems_team_code' => 'H-SP2-1' ]
        ] );
        $team_members = \Mockery::mock( Team_Member_Repository::class );

        // Mock Alice as unallocated, Bob as allocated to H-SP2-1 on event 102
        global $wpdb;
        $wpdb = \Mockery::mock( \wpdb::class );
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive( 'prepare' )->byDefault()->andReturnUsing( function( $sql, ...$args ) {
            if ( isset( $args[0] ) ) {
                return $sql . ' /* scout_id: ' . $args[0] . ' */';
            }
            return $sql;
        } );
        $wpdb->shouldReceive( 'get_var' )->andReturn( 103 ); // query event ID is 103
        $wpdb->shouldReceive( 'get_row' )->andReturn( [ 'team_post_id' => 201 ] ); // Bob is in team 201
        $wpdb->shouldReceive( 'get_results' )->andReturnUsing( function($sql) {
            if ( strpos( $sql, 'wp_ems_team_members' ) !== false && strpos( $sql, 'scout_id: 4002' ) !== false ) {
                return [ [ 'team_post_id' => 201 ] ];
            }
            return [];
        } );
        $wpdb->shouldReceive( 'get_col' )->andReturn( [] )->byDefault();

        // Stub get_post and get_post_meta for Bob's allocation
        $post_obj = (object) [ 'ID' => 201, 'post_type' => 'team', 'post_parent' => 102, 'post_title' => 'Team H-SP2-1' ];
        Functions\when( 'get_post' )->alias( function( $id ) use ( $post_obj ) {
            return $id === 201 ? $post_obj : null;
        } );
        Functions\when( 'get_post_meta' )->alias( function( $post_id, $key ) {
            if ( $post_id === 201 && $key === 'ems_team_code' ) {
                return 'H-SP2-1';
            }
            if ( ( $post_id === 102 || $post_id === 103 ) && $key === 'ems_level' ) {
                return 'silver';
            }
            if ( ( $post_id === 102 || $post_id === 103 ) && $key === 'ems_type' ) {
                return 'practice';
            }
            if ( $post_id === 102 && $key === 'ems_event_code' ) {
                return 'H-SP2';
            }
            return '';
        } );

        $controller = $this->create_controller( null, null, $teams, $team_members, null, null, $signups );
        $request = new \WP_REST_Request();
        $request->set_param( 'event_code', 'H-SP1' );
        $response = $controller->list_planning_availability( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertArrayHasKey( 'explorers', $data );
        $this->assertArrayHasKey( 'teams', $data );
        $this->assertCount( 2, $data['explorers'] );
        $this->assertSame( 'Alice', $data['explorers'][0]['first_name'] );
        $this->assertArrayHasKey( 'team_preferences', $data['explorers'][0] );
        $this->assertNull( $data['explorers'][0]['allocated_event_code'] );
        $this->assertSame( 'Bob', $data['explorers'][1]['first_name'] );
        $this->assertSame( 'H-SP2-1', $data['explorers'][1]['allocated_team_code'] );
        $this->assertCount( 1, $data['teams'] );
        $this->assertSame( 'H-SP2-1', $data['teams'][0]['ems_team_code'] );

    }

    public function test_allocate_planning_board_unallocated_success(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $expeditions = \Mockery::mock( Expedition_Repository::class );
        $expeditions->shouldReceive( 'get_by_id' )->with( 101 )->andReturn( [ 'ID' => 101, 'ems_event_code' => 'H-SP1', 'ems_level' => 'silver', 'ems_type' => 'practice' ] );

        // Mock teams and team_members
        $teams = \Mockery::mock( Team_Repository::class );
        // Virtual team with ID 202
        $teams->shouldReceive( 'get_unallocated_team' )->with( 101 )->andReturn( [ 'ID' => 202, 'ems_team_code' => 'UNALLOCATED' ] );

        $team_members = \Mockery::mock( Team_Member_Repository::class );
        
        // Mock DB table name and wpdb
        global $wpdb;
        $wpdb = \Mockery::mock( \wpdb::class );
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive( 'prepare' )->byDefault()->andReturnUsing( function( $sql, ...$args ) {
            return $sql;
        } );
        $wpdb->shouldReceive( 'get_results' )->andReturn( [] );
        $wpdb->shouldReceive( 'get_var' )->with( \Mockery::on(function($sql) {
            return strpos($sql, "meta_key = 'ems_event_code'") !== false;
        }) )->andReturn( 101 );
        
        // Explorer 4001 has no current team
        $wpdb->shouldReceive( 'get_row' )->with( \Mockery::on(function($sql) {
            return strpos($sql, 'FROM wp_ems_team_members') !== false;
        }), ARRAY_A )->andReturn( null );

        // Expect assignment to team 202
        $team_members->shouldReceive( 'assign' )->once()->with( 202, 4001, 1, 0 )->andReturn( 15 );

        $controller = $this->create_controller( null, $expeditions, $teams, $team_members );
        
        $request = $this->json_request( [
            'scout_ids'  => [ 4001 ],
            'event_code' => 'H-SP1',
            'mode'       => 'unallocated',
        ] );
        
        $response = $controller->allocate_planning_explorers( $request );
        $this->assertSame( 200, $response->get_status() );
        $this->assertTrue( $response->get_data()['success'] );
    }

    public function test_allocate_planning_board_new_team_success(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $expeditions = \Mockery::mock( Expedition_Repository::class );
        $expeditions->shouldReceive( 'get_by_id' )->with( 101 )->andReturn( [ 'ID' => 101, 'ems_event_code' => 'H-SP1', 'ems_level' => 'silver', 'ems_type' => 'practice' ] );

        $teams = \Mockery::mock( Team_Repository::class );
        // Creates a new team with ID 203
        $teams->shouldReceive( 'create' )->once()->with( 101, 'H-SP1' )->andReturn( 203 );

        $team_members = \Mockery::mock( Team_Member_Repository::class );
        
        // Explorer 4001 is currently in team 201 (which is empty after removal)
        global $wpdb;
        $wpdb = \Mockery::mock( \wpdb::class );
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive( 'prepare' )->byDefault()->andReturnUsing( function( $sql, ...$args ) {
            return $sql;
        } );
        $wpdb->shouldReceive( 'get_row' )->andReturn( [ 'team_post_id' => 201 ] );
        $wpdb->shouldReceive( 'get_results' )->andReturn( [ [ 'team_post_id' => 201 ] ] );
        $wpdb->shouldReceive( 'get_var' )->with( \Mockery::on(function($sql) {
            return strpos($sql, "meta_key = 'ems_event_code'") !== false;
        }) )->andReturn( 101 );

        // Stub get_post for old team check
        $post_obj = (object) [ 'ID' => 201, 'post_type' => 'team', 'post_parent' => 102, 'post_title' => 'Team H-SP2-1' ];
        Functions\when( 'get_post' )->alias( function( $id ) use ( $post_obj ) {
            return $id === 201 ? $post_obj : null;
        } );
        Functions\when( 'get_post_meta' )->alias( function( $post_id, $key ) {
            if ( $post_id === 201 && $key === 'ems_team_code' ) {
                return 'H-SP2-1';
            }
            if ( $post_id === 102 && $key === 'ems_level' ) {
                return 'silver';
            }
            if ( $post_id === 102 && $key === 'ems_type' ) {
                return 'practice';
            }
            return '';
        } );

        // Remove from 201
        $team_members->shouldReceive( 'remove' )->once()->with( 201, 4001 )->andReturn( true );
        // Assign to 203
        $team_members->shouldReceive( 'assign' )->once()->with( 203, 4001, 1, 0 )->andReturn( 16 );

        $controller = $this->create_controller( null, $expeditions, $teams, $team_members );
        
        $request = $this->json_request( [
            'scout_ids'  => [ 4001 ],
            'event_code' => 'H-SP1',
            'mode'       => 'new_team',
        ] );

        $response = $controller->allocate_planning_explorers( $request );
        $this->assertSame( 200, $response->get_status() );
        $this->assertTrue( $response->get_data()['success'] );
    }

    public function test_add_planning_explorer_success(): void {
        global $wpdb;
        $wpdb = \Mockery::mock( \wpdb::class );
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive( 'prepare' )->byDefault()->andReturnUsing( function( $sql, ...$args ) {
            return $sql;
        } );
        $wpdb->shouldReceive( 'get_var' )->andReturn( 101 ); // event ID
        $wpdb->shouldReceive( 'get_col' )->andReturn( [] ); // no other teams/existing assignments

        $teams = \Mockery::mock( Team_Repository::class );
        $teams->shouldReceive( 'get_unallocated_team' )->with( 101 )->andReturn( [ 'ID' => 201 ] );

        $explorers_repo = \Mockery::mock( OSM_Explorer_Repository::class );
        $explorers_repo->shouldReceive( 'find_by_scout_id' )->with( 5001 )->andReturn( [
            'scout_id'   => 5001,
            'wp_user_id' => 99,
        ] );

        $team_members = \Mockery::mock( Team_Member_Repository::class );
        $team_members->shouldReceive( 'assign' )->once()->with( 201, 5001, 1, 99 )->andReturn( 15 );

        $controller = $this->create_controller( null, null, $teams, $team_members, $explorers_repo );
        $request = $this->json_request( [
            'scout_id'   => 5001,
            'event_code' => 'H-SP1',
        ] );

        $response = $controller->add_planning_explorer( $request );
        $this->assertSame( 200, $response->get_status() );
        $this->assertTrue( $response->get_data()['success'] );
    }

    public function test_get_planning_synced_explorers_success(): void {
        $explorers_repo = \Mockery::mock( OSM_Explorer_Repository::class );
        $explorers_repo->shouldReceive( 'list_all' )->once()->andReturn( [
            [
                'scout_id'        => 5001,
                'first_name'      => 'Alice',
                'last_name'       => 'MacLeod',
                'patrol'          => 'SMESU',
                'first_aid_level' => 'full_first_aid',
                'synced_at'       => '2026-06-13 20:00:00',
                'last_local_update_at' => null,
            ]
        ] );

        $controller = $this->create_controller( null, null, null, null, $explorers_repo );
        $request = new \WP_REST_Request();

        $response = $controller->get_planning_synced_explorers( $request );
        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertCount( 1, $data );
        $this->assertSame( 'Alice', $data[0]['first_name'] );
    }
}




