<?php
namespace EMS\Tests\Unit\Integrations;

use EMS\Integrations\OSM_API_Client;
use EMS\Integrations\OSM_Parser;
use EMS\Integrations\Rate_Limiter;
use EMS\Integrations\Drivers\Driver_Interface;
use EMS\Tests\EMSTestCase;
use Mockery;

class OSM_API_ClientTest extends EMSTestCase {
    private Driver_Interface $driver;
    private OSM_Parser $parser;

    protected function setUp(): void {
        parent::setUp();
        $this->driver = Mockery::mock( Driver_Interface::class );
        $this->parser = new OSM_Parser();
    }

    protected function tearDown(): void {
        Mockery::close();
        parent::tearDown();
    }

    // -------------------------------------------------------------------
    // Rate_Limiter unit tests (tested in isolation)
    // -------------------------------------------------------------------

    public function test_rate_limiter_initial_tokens_equal_capacity(): void {
        $limiter = new Rate_Limiter( 5, 1.0 );
        $this->assertSame( 5.0, $limiter->get_token_count() );
    }

    public function test_rate_limiter_consume_decrements_token(): void {
        $time    = 0.0;
        $limiter = new Rate_Limiter( 5, 1.0, static function () use ( &$time ): float { return $time; } );
        $limiter->consume();
        $this->assertSame( 4.0, $limiter->get_token_count() );
    }

    public function test_rate_limiter_consume_does_not_sleep_when_tokens_available(): void {
        $time        = 0.0;
        $sleep_calls = 0;
        $sleep_fn    = static function ( int $us ) use ( &$sleep_calls ): void {
            $sleep_calls++;
        };
        $limiter = new Rate_Limiter(
            5, 1.0,
            static function () use ( &$time ): float { return $time; },
            $sleep_fn
        );
        $limiter->consume();
        $this->assertSame( 0, $sleep_calls );
    }

    public function test_rate_limiter_consume_sleeps_when_tokens_exhausted(): void {
        $time     = 0.0;
        $slept_us = 0;
        $sleep_fn = static function ( int $us ) use ( &$slept_us ): void {
            $slept_us = $us;
        };
        $limiter = new Rate_Limiter(
            1, 1.0,
            static function () use ( &$time ): float { return $time; },
            $sleep_fn
        );
        $limiter->consume(); // uses last token
        $limiter->consume(); // should trigger sleep
        $this->assertGreaterThan( 0, $slept_us );
    }

    public function test_rate_limiter_tokens_refill_over_time(): void {
        $time    = 0.0;
        $limiter = new Rate_Limiter(
            5, 2.0,
            static function () use ( &$time ): float { return $time; }
        );
        for ( $i = 0; $i < 5; $i++ ) {
            $limiter->consume();
        }
        $time = 1.0; // advance 1 second → +2 tokens
        $limiter->consume(); // refills 2.0, uses 1 → 1.0 left
        $this->assertSame( 1.0, $limiter->get_token_count() );
    }

    public function test_rate_limiter_bucket_does_not_exceed_capacity(): void {
        $time    = 0.0;
        $limiter = new Rate_Limiter(
            3, 10.0,
            static function () use ( &$time ): float { return $time; }
        );
        $time = 100.0; // would give 1000 tokens but capacity caps at 3
        $limiter->consume(); // refills to 3, uses 1 → 2
        $this->assertSame( 2.0, $limiter->get_token_count() );
    }

    // -------------------------------------------------------------------
    // OSM_API_Client — section participant pull
    // -------------------------------------------------------------------

    public function test_get_section_participants_returns_parsed_members(): void {
        $raw_members = json_decode(
            file_get_contents( __DIR__ . '/../../mocks/osm-list-of-members.json' ),
            true
        );
        $this->driver->shouldReceive( 'get_section_members' )
            ->once()
            ->with( 99001, 5001, 'explorers' )
            ->andReturn( $raw_members );
        $this->driver->shouldReceive( 'get_last_response_headers' )
            ->once()
            ->andReturn( [] );

        $client       = new OSM_API_Client( $this->driver, $this->parser );
        $participants = $client->get_section_participants( 99001, 5001 );

        $this->assertGreaterThan( 80, count( $participants ) );
        $this->assertSame( 3417257, $participants[0]['member_id'] );
        $this->assertIsString( $participants[0]['first_name'] );
    }

    public function test_get_member_detail_returns_parsed_emails(): void {
        $map      = json_decode(
            file_get_contents( __DIR__ . '/../../mocks/osm-member-detail.json' ),
            true
        );
        $scout_id = (int) array_key_first( $map );
        $entry    = $map[ (string) $scout_id ];

        $raw_detail = [
            'data' => [
                [
                    'group_id' => 6,
                    'columns'  => [
                        [ 'column_id' => 12, 'value' => $entry['email'] ],
                        [ 'column_id' => 14, 'value' => $entry['parent_email'] ],
                    ],
                ],
            ],
        ];

        $this->driver->shouldReceive( 'get_member_detail' )
            ->once()
            ->with( 99001, $scout_id, 5001 )
            ->andReturn( $raw_detail );
        $this->driver->shouldReceive( 'get_last_response_headers' )
            ->once()
            ->andReturn( [] );

        $client = new OSM_API_Client( $this->driver, $this->parser );
        $detail = $client->get_member_detail( 99001, $scout_id, 5001 );

        $this->assertSame( "scout.{$scout_id}@example-ems.test", $detail['email'] );
        $this->assertSame( "parent.{$scout_id}@example-ems.test", $detail['parent_email'] );
    }

    public function test_set_access_token_delegates_to_driver(): void {
        $this->driver->shouldReceive( 'set_access_token' )
            ->once()
            ->with( 'test-token' );

        $client = new OSM_API_Client( $this->driver, $this->parser );
        $client->set_access_token( 'test-token' );
        $this->assertTrue( true );
    }

    public function test_get_section_events_returns_parsed_events(): void {
        $raw_events = json_decode(
            file_get_contents( __DIR__ . '/../../mocks/osm-events.json' ),
            true
        );
        $this->driver->shouldReceive( 'get_section_events' )
            ->once()
            ->with( 99001, 5001 )
            ->andReturn( $raw_events );
        $this->driver->shouldReceive( 'get_last_response_headers' )
            ->once()
            ->andReturn( [] );

        $client = new OSM_API_Client( $this->driver, $this->parser );
        $events = $client->get_section_events( 99001, 5001 );

        $this->assertCount( 2, $events );
        $this->assertSame( 40001, $events[0]['event_id'] );
    }

    public function test_get_data_payload_delegates_to_driver(): void {
        $raw_payload = json_decode(
            file_get_contents( __DIR__ . '/../../mocks/osm-get-data-payload-explorer.json' ),
            true
        );
        $this->driver->shouldReceive( 'get_data_payload' )
            ->once()
            ->withNoArgs()
            ->andReturn( $raw_payload );
        $this->driver->shouldReceive( 'get_last_response_headers' )
            ->once()
            ->andReturn( [] );

        $client  = new OSM_API_Client( $this->driver, $this->parser );
        $payload = $client->get_data_payload();

        $this->assertSame( $raw_payload, $payload );
    }

    public function test_after_call_fires_even_when_driver_throws(): void {
        $this->driver->shouldReceive( 'get_section_members' )
            ->andThrow( new \EMS\Integrations\Exceptions\Rate_Limit_Exception( 60, 3600, 'https://osm.example' ) );
        $this->driver->shouldReceive( 'get_last_response_headers' )
            ->once()
            ->andReturn( [
                'http_status'           => 429,
                'x-ratelimit-limit'     => 500,
                'x-ratelimit-remaining' => 0,
                'x-ratelimit-reset'     => null,
                'retry-after'           => 60,
            ] );

        $logger_entries = [];
        $logger = Mockery::mock( \EMS\Integrations\OSM_Sync_Logger::class );
        $logger->shouldReceive( 'log' )->once()->andReturnUsing( function() use ( &$logger_entries ) {
            $logger_entries[] = func_get_args();
        } );

        $limiter = new Rate_Limiter( 10, 1.0 );
        $client  = new OSM_API_Client( $this->driver, $this->parser, $limiter, $logger );

        try {
            $client->get_section_participants( 99001, 5001 );
        } catch ( \EMS\Integrations\Exceptions\Rate_Limit_Exception $e ) {
            // expected
        }

        $this->assertSame( 0.0, $limiter->get_token_count(), 'Rate limiter should be updated from headers even on exception' );
        $this->assertCount( 1, $logger_entries, 'Logger should record the failing call' );
    }

    public function test_header_aware_rate_limiting(): void {
        $this->driver->shouldReceive( 'get_data_payload' )
            ->andReturn( [] );
        $this->driver->shouldReceive( 'get_last_response_headers' )
            ->andReturn( [
                'x-ratelimit-limit'     => 100,
                'x-ratelimit-remaining' => 0,
                'x-ratelimit-reset'     => 3600,
            ] );

        $limiter = new Rate_Limiter( 10, 1.0 );
        $client  = new OSM_API_Client( $this->driver, $this->parser, $limiter );

        $client->get_data_payload();

        $this->assertSame( 0.0, $limiter->get_token_count() );
    }

    public function test_rate_limiter_update_from_headers_uses_reset_as_duration(): void {
        $time    = 1000.0;
        $limiter = new Rate_Limiter(
            10, 1.0,
            static function () use ( &$time ): float { return $time; }
        );

        $limiter->update_from_headers( [
            'x-ratelimit-limit'     => 500,
            'x-ratelimit-remaining' => 250,
            'x-ratelimit-reset'     => 1800,
        ] );

        $this->assertSame( 250.0, $limiter->get_token_count() );
        // refill_rate = 500 / 1800 ≈ 0.2778 tokens/sec
        // advance 1800 seconds → should refill back to capacity
        $time = 1000.0 + 1800.0;
        $limiter->consume();
        $this->assertSame( 499.0, $limiter->get_token_count() );
    }
}
