<?php
namespace EMS\Tests\Unit\Integrations;

use EMS\Integrations\OSM_Reference_Sync;
use EMS\Integrations\OSM_API_Client;
use EMS\Integrations\OSM_Parser;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;
use Mockery;

class OSM_Reference_SyncTest extends EMSTestCase {

    private $api_client;
    private $parser;
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        $this->api_client = Mockery::mock( OSM_API_Client::class );
        $this->api_client->shouldReceive( 'set_sync_result' )->zeroOrMoreTimes();
        $this->api_client->shouldReceive( 'get_patrols' )->zeroOrMoreTimes()->andReturn( [ 'patrols' => [] ] );
        $this->parser     = new OSM_Parser();

        Functions\when( 'get_option' )->alias( function( $key, $default = false ) {
            if ( $key === 'ems_managed_sections' ) {
                return [];
            }
            return $default;
        } );

        $this->wpdb = Mockery::mock( 'wpdb' );
        $this->wpdb->prefix     = 'wp_';
        $this->wpdb->last_error = '';
        $GLOBALS['wpdb']        = $this->wpdb;
    }

    protected function tearDown(): void {
        \Mockery::close();
        parent::tearDown();
    }

    private function make_payload( int $section_id = 43105, int $term_id = 5001, string $start = '2026-01-01', string $end = '2026-12-31' ): array {
        return [
            'data' => [
                'globals' => [
                    'terms' => [
                        (string) $section_id => [
                            [
                                'termid'    => (string) $term_id,
                                'sectionid' => (string) $section_id,
                                'name'      => 'Spring 2026',
                                'startdate' => $start,
                                'enddate'   => $end,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function test_sync_upserts_members_into_explorers_table(): void {
        $members = [
            [
                'member_id'  => 1001,
                'first_name' => 'Alice',
                'last_name'  => 'Alpha',
                'patrol'     => 'Bears',
            ],
        ];

        $this->api_client->shouldReceive( 'get_section_participants' )
            ->with( 43105, 5001, 'explorers' )
            ->once()
            ->andReturn( $members );

        $this->api_client->shouldReceive( 'get_member_detail' )
            ->with( 43105, 1001, 5001 )
            ->once()
            ->andReturn( [ 'email' => 'alice@example.com', 'parent_email' => 'p.alice@example.com' ] );

        $this->api_client->shouldReceive( 'get_section_events' )
            ->with( 43105, 5001 )
            ->once()
            ->andReturn( [] );

        $replaced = false;
        $this->wpdb->shouldReceive( 'replace' )
            ->once()
            ->with(
                'wp_ems_osm_explorers',
                Mockery::on( function ( $data ) use ( &$replaced ) {
                    $replaced = $data['scout_id'] === 1001
                        && $data['first_name'] === 'Alice'
                        && $data['email'] === 'alice@example.com';
                    return true;
                } ),
                Mockery::any()
            );

        Functions\when( 'current_time' )->justReturn( '2026-06-15 08:00:00' );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'set_transient' )->justReturn( true );

        $sync = new OSM_Reference_Sync( $this->api_client, $this->parser );
        $sync->sync( [ 43105 ], $this->make_payload() );

        $this->assertTrue( $replaced, 'replace() was not called with correct explorer data' );
    }

    public function test_sync_upserts_events_into_events_table(): void {
        $this->api_client->shouldReceive( 'get_section_participants' )
            ->with( 43105, 5001, 'explorers' )
            ->once()
            ->andReturn( [] );

        $events = [
            [
                'event_id'   => 40001,
                'name'       => 'Silver Practice',
                'start_date' => '2026-08-01',
                'end_date'   => '2026-08-03',
                'location'   => 'Test Hills',
            ],
        ];

        $this->api_client->shouldReceive( 'get_section_events' )
            ->with( 43105, 5001 )
            ->once()
            ->andReturn( $events );

        $this->api_client->shouldReceive( 'get_event_attendance' )
            ->with( 40001, 5001 )
            ->once()
            ->andReturn( [ 'data' => [] ] );

        $replaced = false;
        $this->wpdb->shouldReceive( 'replace' )
            ->once()
            ->with(
                'wp_ems_osm_events',
                Mockery::on( function ( $data ) use ( &$replaced ) {
                    $replaced = $data['event_id'] === 40001 && $data['name'] === 'Silver Practice';
                    return true;
                } ),
                Mockery::any()
            );

        Functions\when( 'current_time' )->justReturn( '2026-06-15 08:00:00' );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'set_transient' )->justReturn( true );

        $sync = new OSM_Reference_Sync( $this->api_client, $this->parser );
        $sync->sync( [ 43105 ], $this->make_payload() );

        $this->assertTrue( $replaced, 'replace() was not called with correct event data' );
    }

    public function test_sync_upserts_attendance_into_attendance_table(): void {
        $this->api_client->shouldReceive( 'get_section_participants' )
            ->with( 43105, 5001, 'explorers' )
            ->once()
            ->andReturn( [] );

        $events = [
            [ 'event_id' => 40001, 'name' => 'Silver Practice', 'start_date' => '2026-08-01', 'end_date' => '2026-08-03', 'location' => '' ],
        ];

        $this->api_client->shouldReceive( 'get_section_events' )
            ->with( 43105, 5001 )
            ->once()
            ->andReturn( $events );

        $attendance = [
            'data' => [
                [ 'member_id' => '1001', 'attending' => 'Yes' ],
                [ 'member_id' => '1002', 'attending' => 'No' ],
            ],
        ];

        $this->api_client->shouldReceive( 'get_event_attendance' )
            ->with( 40001, 5001 )
            ->once()
            ->andReturn( $attendance );

        $attendance_calls = [];
        $this->wpdb->shouldReceive( 'replace' )
            ->with( 'wp_ems_osm_events', Mockery::any(), Mockery::any() )
            ->once();

        $this->wpdb->shouldReceive( 'replace' )
            ->with(
                'wp_ems_osm_event_attendance',
                Mockery::on( function ( $data ) use ( &$attendance_calls ) {
                    $attendance_calls[] = $data;
                    return true;
                } ),
                Mockery::any()
            )
            ->twice();

        Functions\when( 'current_time' )->justReturn( '2026-06-15 08:00:00' );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'set_transient' )->justReturn( true );

        $sync = new OSM_Reference_Sync( $this->api_client, $this->parser );
        $sync->sync( [ 43105 ], $this->make_payload() );

        $this->assertCount( 2, $attendance_calls );
        $this->assertEquals( 1001, $attendance_calls[0]['scout_id'] );
        $this->assertEquals( 'Yes', $attendance_calls[0]['status'] );
        $this->assertEquals( 1002, $attendance_calls[1]['scout_id'] );
        $this->assertEquals( 'No', $attendance_calls[1]['status'] );
    }

    public function test_sync_skips_member_with_zero_scout_id(): void {
        $members = [
            [ 'member_id' => 0, 'first_name' => 'Bad', 'last_name' => 'Row', 'patrol' => '' ],
        ];

        $this->api_client->shouldReceive( 'get_section_participants' )->andReturn( $members );
        $this->api_client->shouldReceive( 'get_section_events' )->andReturn( [] );

        $this->wpdb->shouldReceive( 'replace' )->never();

        Functions\when( 'current_time' )->justReturn( '2026-06-15 08:00:00' );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'set_transient' )->justReturn( true );

        $sync = new OSM_Reference_Sync( $this->api_client, $this->parser );
        $sync->sync( [ 43105 ], $this->make_payload() );

        $this->assertTrue( true );
    }

    public function test_sync_writes_last_sync_result_transient(): void {
        $this->api_client->shouldReceive( 'get_section_participants' )->andReturn( [] );
        $this->api_client->shouldReceive( 'get_section_events' )->andReturn( [] );

        Functions\when( 'current_time' )->justReturn( '2026-06-15 08:00:00' );
        Functions\when( 'update_option' )->justReturn( true );

        $transient_written = false;
        Functions\when( 'set_transient' )->alias( function ( $key ) use ( &$transient_written ) {
            if ( $key === 'ems_last_sync_result' ) {
                $transient_written = true;
            }
        } );

        $sync = new OSM_Reference_Sync( $this->api_client, $this->parser );
        $sync->sync( [ 43105 ], $this->make_payload() );

        $this->assertTrue( $transient_written, 'ems_last_sync_result transient was not written' );
    }

    public function test_sync_skips_section_with_no_term(): void {
        $this->api_client->shouldReceive( 'get_section_participants' )->never();
        $this->api_client->shouldReceive( 'get_section_events' )->never();
        $this->wpdb->shouldReceive( 'replace' )->never();

        Functions\when( 'current_time' )->justReturn( '2026-06-15 08:00:00' );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'set_transient' )->justReturn( true );

        $empty_payload = [ 'data' => [ 'globals' => [ 'terms' => [] ] ] ];

        $sync = new OSM_Reference_Sync( $this->api_client, $this->parser );
        $sync->sync( [ 43105 ], $empty_payload );

        $this->assertTrue( true );
    }

    // -------------------------------------------------------------------
    // Stage 1.10: Sync_Result, mode, member_limit, exception handling
    // -------------------------------------------------------------------

    public function test_sync_returns_sync_result_with_counts(): void {
        $this->api_client->shouldReceive( 'get_section_participants' )
            ->andReturn( [ [ 'member_id' => 1001, 'first_name' => 'A', 'last_name' => 'B', 'patrol' => '' ] ] );
        $this->api_client->shouldReceive( 'get_member_detail' )
            ->andReturn( [ 'email' => 'a@b.com', 'parent_email' => '' ] );
        $this->api_client->shouldReceive( 'get_section_events' )
            ->andReturn( [ [ 'event_id' => 40001, 'name' => 'Ev', 'start_date' => '2026-08-01', 'end_date' => '2026-08-03', 'location' => '' ] ] );
        $this->api_client->shouldReceive( 'get_event_attendance' )
            ->andReturn( [ 'data' => [] ] );
        $this->wpdb->shouldReceive( 'replace' )->andReturn( 1 );

        Functions\when( 'current_time' )->justReturn( '2026-06-15 08:00:00' );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'set_transient' )->justReturn( true );

        $sync   = new OSM_Reference_Sync( $this->api_client, $this->parser );
        $result = $sync->sync( [ 43105 ], $this->make_payload(), 'mock' );

        $this->assertSame( 1, $result->members_upserted );
        $this->assertSame( 0, $result->members_failed );
        $this->assertSame( 1, $result->events_upserted );
        $this->assertSame( 0, $result->events_failed );
        $this->assertSame( 'mock', $result->mode );
        $this->assertFalse( $result->rate_limited );
        $this->assertFalse( $result->api_blocked );
    }

    public function test_sync_respects_member_limit(): void {
        $members = array_map( fn( $i ) => [ 'member_id' => $i, 'first_name' => 'X', 'last_name' => 'Y', 'patrol' => '' ], range( 1001, 1010 ) );

        $this->api_client->shouldReceive( 'get_section_participants' )->andReturn( $members );
        $this->api_client->shouldReceive( 'get_member_detail' )->andReturn( [ 'email' => '', 'parent_email' => '' ] );
        $this->api_client->shouldReceive( 'get_section_events' )->andReturn( [] );

        $call_count = 0;
        $this->wpdb->shouldReceive( 'replace' )
            ->with( 'wp_ems_osm_explorers', Mockery::any(), Mockery::any() )
            ->andReturnUsing( function() use ( &$call_count ) { $call_count++; return 1; } );

        Functions\when( 'current_time' )->justReturn( '2026-06-15 08:00:00' );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'set_transient' )->justReturn( true );

        $sync = new OSM_Reference_Sync( $this->api_client, $this->parser );
        $sync->sync( [ 43105 ], $this->make_payload(), 'live-limited', 3 );

        $this->assertSame( 3, $call_count, 'Only first 3 members should be upserted' );
    }

    public function test_sync_stops_and_records_rate_limited_on_exception(): void {
        $this->api_client->shouldReceive( 'get_section_participants' )
            ->andThrow( new \EMS\Integrations\Exceptions\Rate_Limit_Exception( 60, 3600, 'http://osm.example' ) );
        $this->api_client->shouldReceive( 'get_section_events' )->never();
        $this->wpdb->shouldReceive( 'replace' )->never();

        Functions\when( 'current_time' )->justReturn( '2026-06-15 08:00:00' );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'set_transient' )->justReturn( true );

        $sync   = new OSM_Reference_Sync( $this->api_client, $this->parser );
        $result = $sync->sync( [ 43105 ], $this->make_payload() );

        $this->assertTrue( $result->rate_limited );
        $this->assertSame( 60, $result->retry_after_seconds );
        $this->assertSame( 3600, $result->rate_limit_reset_seconds );
        $this->assertNotEmpty( $result->errors );
    }

    public function test_sync_stops_and_records_api_blocked_on_exception(): void {
        $this->api_client->shouldReceive( 'get_section_participants' )
            ->andThrow( new \EMS\Integrations\Exceptions\Api_Blocked_Exception( 'blocked-reason', 'http://osm.example' ) );
        $this->api_client->shouldReceive( 'get_section_events' )->never();
        $this->wpdb->shouldReceive( 'replace' )->never();

        Functions\when( 'current_time' )->justReturn( '2026-06-15 08:00:00' );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'set_transient' )->justReturn( true );

        $sync   = new OSM_Reference_Sync( $this->api_client, $this->parser );
        $result = $sync->sync( [ 43105 ], $this->make_payload() );

        $this->assertTrue( $result->api_blocked );
        $this->assertNotEmpty( $result->errors );
    }

    public function test_sync_result_is_persisted_as_transient(): void {
        $this->api_client->shouldReceive( 'get_section_participants' )->andReturn( [] );
        $this->api_client->shouldReceive( 'get_section_events' )->andReturn( [] );

        Functions\when( 'current_time' )->justReturn( '2026-06-15 08:00:00' );
        Functions\when( 'update_option' )->justReturn( true );

        $stored_key   = null;
        $stored_value = null;
        Functions\when( 'set_transient' )->alias( function( $key, $value ) use ( &$stored_key, &$stored_value ) {
            if ( $key === 'ems_last_sync_result' ) {
                $stored_key   = $key;
                $stored_value = $value;
            }
            return true;
        } );

        $sync = new OSM_Reference_Sync( $this->api_client, $this->parser );
        $sync->sync( [ 43105 ], $this->make_payload(), 'mock' );

        $this->assertSame( 'ems_last_sync_result', $stored_key );
        $this->assertSame( 'mock', $stored_value['mode'] );
    }

    public function test_logger_is_persisted_after_sync(): void {
        $this->api_client->shouldReceive( 'get_section_participants' )->andReturn( [] );
        $this->api_client->shouldReceive( 'get_section_events' )->andReturn( [] );

        Functions\when( 'current_time' )->justReturn( '2026-06-15 08:00:00' );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'set_transient' )->justReturn( true );

        $logger = Mockery::mock( \EMS\Integrations\OSM_Sync_Logger::class )->makePartial();
        $logger->shouldReceive( 'persist' )->once();

        $sync = new OSM_Reference_Sync( $this->api_client, $this->parser );
        $sync->sync( [ 43105 ], $this->make_payload(), 'mock', 0, $logger );

        $this->addToAssertionCount( 1 );
    }
}
