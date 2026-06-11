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
            file_get_contents( __DIR__ . '/../../mocks/members.json' ),
            true
        );
        $this->driver->shouldReceive( 'get_section_members' )
            ->once()
            ->with( 99001 )
            ->andReturn( $raw_members );

        $client       = new OSM_API_Client( $this->driver, $this->parser );
        $participants = $client->get_section_participants( 99001 );

        $this->assertCount( 2, $participants );
        $this->assertSame( 1001, $participants[0]['member_id'] );
        $this->assertSame( 'John', $participants[0]['first_name'] );
    }

    public function test_get_section_events_returns_parsed_events(): void {
        $raw_events = json_decode(
            file_get_contents( __DIR__ . '/../../mocks/osm-events.json' ),
            true
        );
        $this->driver->shouldReceive( 'get_section_events' )
            ->once()
            ->with( 99001 )
            ->andReturn( $raw_events );

        $client = new OSM_API_Client( $this->driver, $this->parser );
        $events = $client->get_section_events( 99001 );

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
            ->with( 'test-token' )
            ->andReturn( $raw_payload );

        $client  = new OSM_API_Client( $this->driver, $this->parser );
        $payload = $client->get_data_payload( 'test-token' );

        $this->assertSame( $raw_payload, $payload );
    }
}
