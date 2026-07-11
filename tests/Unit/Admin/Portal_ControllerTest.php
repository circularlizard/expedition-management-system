<?php
namespace EMS\Tests\Unit\Admin;

use EMS\Admin\Portal_Controller;
use EMS\Data\OSM_Explorer_Repository;
use EMS\Integrations\TutorLMS_Client;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;

class Portal_ControllerTest extends EMSTestCase {
    private $explorer_repo;
    private $tutor_client;

    protected function setUp(): void {
        parent::setUp();
        $this->explorer_repo = \Mockery::mock( OSM_Explorer_Repository::class );
        $this->tutor_client  = \Mockery::mock( TutorLMS_Client::class );
    }

    public function test_get_me_unauthenticated_returns_logged_in_false(): void {
        Functions\when( 'is_user_logged_in' )->justReturn( false );
        Functions\when( 'wp_get_current_user' )->alias( function() {
            $user = \Mockery::mock('WP_User');
            $user->shouldReceive('exists')->andReturn(false);
            return $user;
        } );

        $controller = new Portal_Controller( $this->explorer_repo, $this->tutor_client );
        $request = new \WP_REST_Request( 'GET', '/ems/v1/portal/me' );
        $response = $controller->get_me( $request );

        $this->assertEquals( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertFalse( $data['logged_in'] );
    }

    public function test_get_me_parent_returns_children(): void {
        Functions\when( 'is_user_logged_in' )->justReturn( true );
        Functions\when( 'wp_get_current_user' )->alias( function() {
            $user = \Mockery::mock('WP_User');
            $user->ID = 123;
            $user->exists = true;
            $user->display_name = 'Parent Name';
            $user->shouldReceive('exists')->andReturn(true);
            return $user;
        } );

        Functions\when( 'get_user_meta' )->alias( function( $user_id, $key, $single ) {
            if ( $key === 'ems_access_type' ) {
                return 'parent';
            }
            if ( $key === 'ems_scout_ids' ) {
                return [ 30001, 30002 ];
            }
            if ( $key === 'ems_children' ) {
                return [
                    [ 'scout_id' => 30001, 'first_name' => 'David', 'last_name' => 'Strachan', 'patrol' => 'Falcons' ],
                    [ 'scout_id' => 30002, 'first_name' => 'James', 'last_name' => 'Strachan', 'patrol' => 'Kestrels' ]
                ];
            }
            return '';
        } );

        $controller = new Portal_Controller( $this->explorer_repo, $this->tutor_client );
        $request = new \WP_REST_Request( 'GET', '/ems/v1/portal/me' );
        $response = $controller->get_me( $request );

        $this->assertEquals( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertTrue( $data['logged_in'] );
        $this->assertEquals( 'parent', $data['access_type'] );
        $this->assertCount( 2, $data['profiles'] );
        $this->assertEquals( 30001, $data['profiles'][0]['scout_id'] );
    }

    public function test_get_explorer_detail_unauthorized_returns_403(): void {
        Functions\when( 'is_user_logged_in' )->justReturn( true );
        Functions\when( 'get_current_user_id' )->justReturn( 123 );
        Functions\when( 'wp_get_current_user' )->alias( function() {
            $user = \Mockery::mock('WP_User');
            $user->ID = 123;
            $user->shouldReceive('exists')->andReturn(true);
            return $user;
        } );

        Functions\when( 'get_user_meta' )->alias( function( $user_id, $key, $single ) {
            if ( $key === 'ems_access_type' ) {
                return 'member';
            }
            return '';
        } );
        Functions\when( 'current_user_can' )->justReturn( false );

        $this->explorer_repo->shouldReceive( 'find_by_scout_id' )->with( 99999 )->once()->andReturn([
            'scout_id' => 99999,
            'wp_user_id' => 999, // different user ID
        ]);

        $controller = new Portal_Controller( $this->explorer_repo, $this->tutor_client );
        $request = new \WP_REST_Request( 'GET', '/ems/v1/portal/explorer/99999' );
        $request->set_param( 'scout_id', 99999 );

        $response = $controller->get_explorer_detail( $request );
        $this->assertEquals( 403, $response->get_status() );
    }

    public function test_get_explorer_detail_authorized_returns_payload(): void {
        Functions\when( 'is_user_logged_in' )->justReturn( true );
        Functions\when( 'get_current_user_id' )->justReturn( 123 );
        Functions\when( 'get_user_meta' )->alias( function( $user_id, $key, $single ) {
            if ( $key === 'ems_access_type' ) {
                return 'parent';
            }
            if ( $key === 'ems_scout_ids' ) {
                return [ 30001 ];
            }
            if ( $key === 'ems_training_requirements' ) {
                return [ 101, 102 ];
            }
            if ( $key === '_tutor_completed_course_101' ) {
                return true;
            }
            if ( $key === '_tutor_completed_course_102' ) {
                return false;
            }
            return '';
        } );
        Functions\when( 'current_user_can' )->justReturn( false );

        $this->explorer_repo->shouldReceive( 'find_by_scout_id' )->with( 30001 )->andReturn([
            'scout_id' => 30001,
            'wp_user_id' => 456,
            'first_name' => 'David',
            'last_name' => 'Strachan',
        ]);

        // Mock wpdb queries for signups, team memberships, and teammates
        global $wpdb;
        $wpdb = \Mockery::mock('stdClass');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturnUsing(function($query, ...$args) {
            return [$query, $args];
        });

        $wpdb->shouldReceive('get_results')->andReturnUsing(function($prepared) {
            $query = $prepared[0];
            $args = $prepared[1];
            if (str_contains($query, 'ems_participant_signups')) {
                return [
                    [
                        'id' => 1,
                        'dofe_level' => 'silver',
                        'signup_status' => 'allocated',
                        'payment_status' => 'reconciled',
                        'created_at' => '2026-06-13 20:00:00'
                    ]
                ];
            }
            if (str_contains($query, 'ems_expedition_signups')) {
                return [];
            }
            if (str_contains($query, 'ems_team_members') && str_contains($query, 'team_post_id =')) {
                // teammates list
                return [
                    [ 'scout_id' => 30002 ]
                ];
            }
            if (str_contains($query, 'ems_team_members')) {
                // user memberships
                return [
                    [ 'team_post_id' => 777 ]
                ];
            }
            return [];
        });

        // Mock WordPress post and metadata function calls
        Functions\when( 'get_post' )->alias( function( $id ) {
            $post = new \stdClass();
            if ( $id === 777 ) {
                $post->ID = 777;
                $post->post_type = 'team';
                $post->post_parent = 888;
            } elseif ( $id === 888 ) {
                $post->ID = 888;
                $post->post_type = 'expedition';
                $post->post_title = 'Silver Qualifying Expedition';
            }
            return $post;
        } );

        Functions\when( 'get_post_meta' )->alias( function( $id, $key, $single ) {
            if ( $id === 777 ) {
                if ( $key === 'ems_team_code' ) return 'S-PR1-1';
                if ( $key === 'ems_route_status' ) return 'feedback_required';
            }
            if ( $id === 888 ) {
                if ( $key === 'ems_type' ) return 'qualifying';
                if ( $key === 'ems_level' ) return 'silver';
                if ( $key === 'ems_start_date' ) return '2026-07-20';
                if ( $key === 'ems_end_date' ) return '2026-07-23';
                if ( $key === 'ems_start_location' ) return 'Mournes';
                if ( $key === 'ems_lic_name' ) return 'John Doe';
                if ( $key === 'ems_expedition_whatsapp_parents' ) return 'https://chat.whatsapp.com/parents';
                if ( $key === 'ems_training_requirements' ) return [ 101, 102 ];
            }
            return '';
        } );

        $this->explorer_repo->shouldReceive( 'find_by_scout_id' )->with( 30002 )->andReturn([
            'scout_id' => 30002,
            'first_name' => 'Alice',
            'last_name' => 'Smith',
            'patrol' => 'Falcons',
        ]);

        $this->tutor_client->shouldReceive( 'get_all_courses' )->once()->andReturn([
            (object)[ 'ID' => 101, 'post_title' => 'First Aid Training' ],
            (object)[ 'ID' => 102, 'post_title' => 'Campcraft & Cooking' ],
        ]);

        Functions\when( 'get_permalink' )->alias( function($id) {
            return "https://example.com/course/" . $id;
        } );

        $controller = new Portal_Controller( $this->explorer_repo, $this->tutor_client );
        $request = new \WP_REST_Request( 'GET', '/ems/v1/portal/explorer/30001' );
        $request->set_param( 'scout_id', 30001 );

        $response = $controller->get_explorer_detail( $request );
        $this->assertEquals( 200, $response->get_status() );
        $data = $response->get_data();

        $this->assertEquals( 'David', $data['explorer']['first_name'] );
        $this->assertCount( 1, $data['signups'] );
        $this->assertEquals( 'silver', $data['signups'][0]['dofe_level'] );
        $this->assertEquals( 'participant', $data['signups'][0]['type'] ?? 'participant' );
        
        $this->assertCount( 1, $data['events']['qualifying'] );
        $this->assertEquals( 'Silver Qualifying Expedition', $data['events']['qualifying'][0]['name'] );
        
        $this->assertCount( 2, $data['training_checklist'] );
        $this->assertEquals( 'First Aid Training', $data['training_checklist'][0]['course_name'] );
        $this->assertTrue( $data['training_checklist'][0]['completed'] );
        $this->assertFalse( $data['training_checklist'][1]['completed'] );

        $this->assertEquals( 'S-PR1-1', $data['team']['team_code'] );
        $this->assertCount( 1, $data['team']['teammates'] );
        $this->assertEquals( 'Alice', $data['team']['teammates'][0]['first_name'] );
        $this->assertEquals( 'S.', $data['team']['teammates'][0]['last_initial'] );
    }
}
