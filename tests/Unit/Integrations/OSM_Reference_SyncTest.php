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
        $this->parser     = new OSM_Parser();

        $this->wpdb = Mockery::mock( 'wpdb' );
        $this->wpdb->prefix = 'wp_';
        $GLOBALS['wpdb']    = $this->wpdb;
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
            ->with( 43105, 5001 )
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

        $sync = new OSM_Reference_Sync( $this->api_client, $this->parser );
        $sync->sync( [ 43105 ], $this->make_payload() );

        $this->assertTrue( $replaced, 'replace() was not called with correct explorer data' );
    }

    public function test_sync_upserts_events_into_events_table(): void {
        $this->api_client->shouldReceive( 'get_section_participants' )
            ->with( 43105, 5001 )
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
            ->with( 43105, 40001 )
            ->once()
            ->andReturn( [ 'items' => [] ] );

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

        $sync = new OSM_Reference_Sync( $this->api_client, $this->parser );
        $sync->sync( [ 43105 ], $this->make_payload() );

        $this->assertTrue( $replaced, 'replace() was not called with correct event data' );
    }

    public function test_sync_upserts_attendance_into_attendance_table(): void {
        $this->api_client->shouldReceive( 'get_section_participants' )
            ->with( 43105, 5001 )
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
            'items' => [
                [ 'scoutid' => '1001', 'attending' => 'Yes' ],
                [ 'scoutid' => '1002', 'attending' => 'No' ],
            ],
        ];

        $this->api_client->shouldReceive( 'get_event_attendance' )
            ->with( 43105, 40001 )
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

        $sync = new OSM_Reference_Sync( $this->api_client, $this->parser );
        $sync->sync( [ 43105 ], $this->make_payload() );

        $this->assertTrue( true );
    }

    public function test_sync_updates_last_sync_option(): void {
        $this->api_client->shouldReceive( 'get_section_participants' )->andReturn( [] );
        $this->api_client->shouldReceive( 'get_section_events' )->andReturn( [] );

        Functions\when( 'current_time' )->justReturn( '2026-06-15 08:00:00' );

        $option_updated = false;
        Functions\when( 'update_option' )->alias( function ( $key ) use ( &$option_updated ) {
            if ( $key === 'ems_osm_last_sync' ) {
                $option_updated = true;
            }
        } );

        $sync = new OSM_Reference_Sync( $this->api_client, $this->parser );
        $sync->sync( [ 43105 ], $this->make_payload() );

        $this->assertTrue( $option_updated, 'ems_osm_last_sync option was not updated' );
    }

    public function test_sync_skips_section_with_no_term(): void {
        $this->api_client->shouldReceive( 'get_section_participants' )->never();
        $this->api_client->shouldReceive( 'get_section_events' )->never();
        $this->wpdb->shouldReceive( 'replace' )->never();

        Functions\when( 'current_time' )->justReturn( '2026-06-15 08:00:00' );
        Functions\when( 'update_option' )->justReturn( true );

        $empty_payload = [ 'data' => [ 'globals' => [ 'terms' => [] ] ] ];

        $sync = new OSM_Reference_Sync( $this->api_client, $this->parser );
        $sync->sync( [ 43105 ], $empty_payload );

        $this->assertTrue( true );
    }
}
