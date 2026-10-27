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
        $wpdb->shouldReceive( 'get_row' )->andReturn( null );

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
        $wpdb->shouldReceive( 'get_row' )->andReturn( null );
        
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
        $this->assertSame( 4, $results['volunteer_count'] );
    }

    public function test_seed_inserts_expedition_signups_matching_form_8_structure_for_all_levels(): void {
        global $wpdb;

        $wpdb = \Mockery::mock('stdClass');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 1001;

        $wpdb->shouldReceive( 'query' )->andReturn( true );
        $wpdb->shouldReceive( 'prepare' )->andReturn( 'mock_query' );
        $wpdb->shouldReceive( 'get_row' )->andReturn( null );

        $mock_explorers = [
            [
                'scout_id' => '10001',
                'first_name' => 'Alice',
                'last_name' => 'Bronze',
                'email' => 'alice@example.com',
                'parent_email' => 'parent_a@example.com',
                'patrol' => 'S3' // Bronze
            ],
            [
                'scout_id' => '10002',
                'first_name' => 'Bob',
                'last_name' => 'Silver',
                'email' => 'bob@example.com',
                'parent_email' => 'parent_b@example.com',
                'patrol' => 'S4' // Silver
            ],
            [
                'scout_id' => '10003',
                'first_name' => 'Charlie',
                'last_name' => 'Gold',
                'email' => 'charlie@example.com',
                'parent_email' => 'parent_c@example.com',
                'patrol' => 'S5' // Gold
            ],
        ];
        $wpdb->shouldReceive( 'get_results' )->with( \Mockery::pattern('/SELECT scout_id/'), ARRAY_A )->andReturn( $mock_explorers );
        $wpdb->shouldReceive( 'get_col' )->with( \Mockery::pattern('/SELECT short_code/'), \Mockery::any() )->andReturn( [ 'BO-Kelso', 'CR-Cramond', 'PE-Castle' ] );
        $wpdb->shouldReceive( 'insert' )->andReturn( true );

        Functions\when( 'get_posts' )->justReturn( [] );
        Functions\when( 'get_option' )->alias( function( $key, $default = false ) {
            if ( $key === 'ems_fluent_participant_form_id' ) return 6;
            if ( $key === 'ems_fluent_expedition_form_id' ) return 8;
            return $default;
        } );

        $captured_submissions = [];
        $mock_sync = \Mockery::mock('overload:EMS\Integrations\Fluent_Forms_Sync');
        $mock_sync->shouldReceive( 'handle_submission' )->andReturnUsing( function( $entry_id, $form_data, $form_obj ) use ( &$captured_submissions ) {
            $captured_submissions[] = [
                'entry_id' => $entry_id,
                'form_id'  => $form_obj->id,
                'data'     => $form_data,
            ];
            return true;
        } );

        $seeder = new Database_Seeder();
        $results = $seeder->seed();

        $this->assertSame( 3, $results['participant_count'] );
        $this->assertSame( 3, $results['expedition_count'], 'Should seed 3 expedition signups including Bronze' );

        $expedition_submissions = array_filter( $captured_submissions, fn($s) => $s['form_id'] === 8 );
        $this->assertCount( 3, $expedition_submissions );

        $bronze_sub = array_values( array_filter( $expedition_submissions, fn($s) => $s['data']['signup_level'] === 'Bronze' ) )[0]['data'];
        $this->assertSame( 'Bronze', $bronze_sub['signup_level'] );
        $this->assertContains( $bronze_sub['exped_type_bronze'], [ 'Hillwalking', 'Paddling' ] );
        $this->assertNotEmpty( $bronze_sub['signup_dob'] );
        $this->assertTrue( $bronze_sub['terms-n-condition'] );
        $this->assertSame( 'first-aid-yes', $bronze_sub['input_radio'] );
        $this->assertMatchesRegularExpression( '/^\d{7}$/', $bronze_sub['signup_dofe_number'] );

        $silver_sub = array_values( array_filter( $expedition_submissions, fn($s) => $s['data']['signup_level'] === 'Silver' ) )[0]['data'];
        $this->assertSame( 'Silver', $silver_sub['signup_level'] );
        $this->assertNotEmpty( $silver_sub['exped_silver_practice_dates'] );
        $this->assertNotEmpty( $silver_sub['exped_silver_qualifier_dates'] );

        $gold_sub = array_values( array_filter( $expedition_submissions, fn($s) => $s['data']['signup_level'] === 'Gold' ) )[0]['data'];
        $this->assertSame( 'Gold', $gold_sub['signup_level'] );
        $this->assertNotEmpty( $gold_sub['exped_gold_practice_dates'] );
        $this->assertNotEmpty( $gold_sub['exped_gold_qualifier_dates'] );
    }
}
