<?php
namespace EMS\Tests\Unit\Data;

use EMS\Data\Database_Seeder;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class Database_SeederTest extends EMSTestCase {

    protected function setUp(): void {
        parent::setUp();
        Functions\stubs( [ '__', 'esc_html__', 'esc_attr__' ] );
        Functions\when( 'wp_insert_post' )->justReturn( 101 );
        Functions\when( 'update_post_meta' )->justReturn( true );
        Functions\when( 'is_wp_error' )->justReturn( false );
        Functions\when( 'current_time' )->justReturn( '2026-06-13 20:00:00' );
        Functions\when( 'wp_delete_post' )->justReturn( true );
    }

    public function test_seed_requires_explorers(): void {
        global $wpdb;
        
        $wpdb = \Mockery::mock('stdClass');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive( 'query' )->andReturn( true );
        $wpdb->shouldReceive( 'prepare' )->andReturn( 'mock_query' );
        $wpdb->shouldReceive( 'get_results' )->with( \Mockery::pattern('/SELECT scout_id/'), ARRAY_A )->andReturn( [] );

        Functions\when( 'get_posts' )->justReturn( [] );
        Functions\when( 'get_option' )->justReturn( 6 );

        $seeder = new Database_Seeder();

        $this->expectException( \Exception::class );
        $this->expectExceptionMessage( 'No explorers found in database' );

        $seeder->seed();
    }

    public function test_seed_inserts_expeditions_and_submissions(): void {
        global $wpdb;

        $wpdb = \Mockery::mock('stdClass');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 999;
        
        $wpdb->shouldReceive( 'query' )->andReturn( true );
        $wpdb->shouldReceive( 'prepare' )->andReturn( 'mock_query' );
        
        $mock_explorers = [
            [
                'scout_id' => '10001',
                'first_name' => 'Alice',
                'last_name' => 'Smith',
                'email' => 'alice@example.com',
                'parent_email' => 'parent@example.com',
                'patrol' => 'S4'
            ]
        ];
        $wpdb->shouldReceive( 'get_results' )->with( \Mockery::pattern('/SELECT scout_id/'), ARRAY_A )->andReturn( $mock_explorers );
        $wpdb->shouldReceive( 'insert' )->andReturn( true );

        Functions\when( 'get_posts' )->justReturn( [] );
        Functions\when( 'get_option' )->alias( function( $key, $default = false ) {
            if ( $key === 'ems_fluent_participant_form_id' ) {
                return 6;
            }
            if ( $key === 'ems_fluent_expedition_form_id' ) {
                return 7;
            }
            return $default;
        } );

        // Mock Fluent_Forms_Sync->handle_submission
        $mock_sync = \Mockery::mock('overload:EMS\Integrations\Fluent_Forms_Sync');
        $mock_sync->shouldReceive( 'handle_submission' )->andReturn( true );

        $seeder = new Database_Seeder();
        $results = $seeder->seed();

        $this->assertSame( 1, $results['participant_count'] );
        $this->assertSame( 1, $results['expedition_count'] );
    }
}
