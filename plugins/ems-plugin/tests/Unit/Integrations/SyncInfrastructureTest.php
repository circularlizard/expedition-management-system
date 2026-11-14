<?php
namespace EMS\Tests\Unit\Integrations;

use EMS\Integrations\Exceptions\Rate_Limit_Exception;
use EMS\Integrations\Exceptions\Api_Blocked_Exception;
use EMS\Integrations\Exceptions\Api_Response_Exception;
use EMS\Integrations\OSM_Sync_Logger;
use EMS\Integrations\Sync_Result;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;

class SyncInfrastructureTest extends EMSTestCase {

    // -------------------------------------------------------------------
    // Exception classes
    // -------------------------------------------------------------------

    public function test_rate_limit_exception_carries_retry_after(): void {
        $e = new Rate_Limit_Exception( 120, 3600, 'https://osm.example/api' );
        $this->assertSame( 120, $e->get_retry_after() );
        $this->assertSame( 3600, $e->get_rate_limit_reset() );
        $this->assertStringContainsString( '120', $e->getMessage() );
    }

    public function test_rate_limit_exception_defaults_to_zero(): void {
        $e = new Rate_Limit_Exception();
        $this->assertSame( 0, $e->get_retry_after() );
        $this->assertSame( 0, $e->get_rate_limit_reset() );
    }

    public function test_api_blocked_exception_carries_header(): void {
        $e = new Api_Blocked_Exception( 'invalid-data', 'https://osm.example/api' );
        $this->assertSame( 'invalid-data', $e->get_blocked_header() );
        $this->assertStringContainsString( 'blocked', strtolower( $e->getMessage() ) );
    }

    public function test_api_response_exception_includes_url(): void {
        $e = new Api_Response_Exception( 'not valid JSON', 'https://osm.example/api' );
        $this->assertStringContainsString( 'osm.example', $e->getMessage() );
    }

    // -------------------------------------------------------------------
    // Sync_Result
    // -------------------------------------------------------------------

    public function test_sync_result_defaults(): void {
        $r = new Sync_Result( 'mock' );
        $this->assertSame( 'mock', $r->mode );
        $this->assertSame( 0, $r->members_upserted );
        $this->assertSame( 0, $r->members_failed );
        $this->assertSame( 0, $r->events_upserted );
        $this->assertSame( 0, $r->events_failed );
        $this->assertFalse( $r->rate_limited );
        $this->assertFalse( $r->api_blocked );
        $this->assertEmpty( $r->errors );
        $this->assertEmpty( $r->deprecated_endpoints );
        $this->assertNotEmpty( $r->started_at );
    }

    public function test_sync_result_add_error(): void {
        $r = new Sync_Result( 'live' );
        $r->add_error( 'something went wrong' );
        $this->assertCount( 1, $r->errors );
        $this->assertSame( 'something went wrong', $r->errors[0] );
    }

    public function test_sync_result_to_array_has_all_keys(): void {
        $r     = new Sync_Result( 'mock' );
        $array = $r->to_array();
        foreach ( [ 'mode', 'started_at', 'members_upserted', 'members_failed', 'events_upserted',
                    'events_failed', 'errors', 'rate_limited', 'retry_after_seconds',
                    'rate_limit_remaining', 'rate_limit_reset_seconds', 'api_blocked', 'deprecated_endpoints' ] as $key ) {
            $this->assertArrayHasKey( $key, $array, "Missing key: {$key}" );
        }
    }

    // -------------------------------------------------------------------
    // OSM_Sync_Logger
    // -------------------------------------------------------------------

    public function test_logger_accumulates_entries(): void {
        $logger = new OSM_Sync_Logger();
        $logger->log( 'get_data_payload', 'https://osm.example', [], 45.5 );
        $logger->log( 'get_section_members', 'https://osm.example/members', [], 12.1 );

        $entries = $logger->get_entries();
        $this->assertCount( 2, $entries );
        $this->assertSame( 'get_data_payload', $entries[0]['call_type'] );
        $this->assertSame( 'get_section_members', $entries[1]['call_type'] );
    }

    public function test_logger_log_entry_has_all_fields(): void {
        $logger  = new OSM_Sync_Logger();
        $headers = [
            'http_status'            => 200,
            'x-ratelimit-limit'      => 500,
            'x-ratelimit-remaining'  => 498,
            'x-ratelimit-reset'      => 1800,
            'retry-after'            => null,
            'x-deprecated'           => null,
            'x-blocked'              => null,
        ];
        $logger->log( 'get_data_payload', 'https://osm.example', $headers, 33.0 );
        $entry = $logger->get_entries()[0];

        $this->assertSame( 200, $entry['http_status'] );
        $this->assertSame( 500, $entry['rate_limit_limit'] );
        $this->assertSame( 498, $entry['rate_limit_remaining'] );
        $this->assertSame( 1800, $entry['rate_limit_reset_seconds'] );
        $this->assertSame( 33.0, $entry['duration_ms'] );
    }

    public function test_logger_persist_calls_set_transient(): void {
        $logger = new OSM_Sync_Logger();
        $logger->log( 'get_data_payload', 'https://osm.example', [], 10.0 );

        $stored = null;
        Functions\when( 'set_transient' )->alias( function( $key, $value ) use ( &$stored ) {
            if ( $key === 'ems_last_sync_log' ) {
                $stored = $value;
            }
            return true;
        } );

        $logger->persist();
        $this->assertIsArray( $stored );
        $this->assertCount( 1, $stored );
    }

    public function test_logger_log_terminal_appends_entry(): void {
        $logger = new OSM_Sync_Logger();
        $logger->log_terminal( 'rate_limited', [ 'http_status' => 429, 'retry_after' => 60 ] );

        $entries = $logger->get_entries();
        $this->assertCount( 1, $entries );
        $this->assertSame( 'rate_limited', $entries[0]['call_type'] );
        $this->assertSame( 429, $entries[0]['http_status'] );
    }
}
