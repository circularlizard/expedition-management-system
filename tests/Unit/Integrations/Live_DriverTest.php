<?php
namespace EMS\Tests\Unit\Integrations;

use EMS\Integrations\Drivers\Live_Driver;
use EMS\Integrations\Exceptions\Api_Blocked_Exception;
use EMS\Integrations\Exceptions\Api_Response_Exception;
use EMS\Integrations\Exceptions\Rate_Limit_Exception;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;

class Live_DriverTest extends EMSTestCase {

    private function make_wp_response( int $status, array $headers, string $body ): array {
        return [
            'response' => [ 'code' => $status, 'message' => 'OK' ],
            'headers'  => $headers,
            'body'     => $body,
        ];
    }

    private function stub_request( int $status, array $headers, string $body ): void {
        Functions\when( 'wp_remote_get' )->justReturn(
            $this->make_wp_response( $status, $headers, $body )
        );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $status );
        Functions\when( 'wp_remote_retrieve_headers' )->justReturn( $headers );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
        Functions\when( 'is_wp_error' )->justReturn( false );
    }

    private function stub_wp_error( string $message ): void {
        $error = new \WP_Error( 'http_request_failed', $message );
        Functions\when( 'wp_remote_get' )->justReturn( $error );
        Functions\when( 'is_wp_error' )->justReturn( true );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 0 );
        Functions\when( 'wp_remote_retrieve_headers' )->justReturn( [] );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );
    }

    private function make_driver( string $token = 'test-token' ): Live_Driver {
        Functions\when( 'get_option' )->justReturn( 'https://www.onlinescoutmanager.co.uk/api.php' );
        Functions\when( 'add_query_arg' )->alias( function( array $args, string $base ): string {
            return $base . '?' . http_build_query( $args );
        } );
        $driver = new Live_Driver();
        $driver->set_access_token( $token );
        return $driver;
    }

    // -------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------

    public function test_get_data_payload_returns_decoded_json(): void {
        $driver = $this->make_driver();
        $body   = json_encode( [ 'data' => [ 'globals' => [ 'userid' => '123' ] ] ] );
        $this->stub_request( 200, [], $body );

        $result = $driver->get_data_payload( 'test-token' );
        $this->assertSame( '123', $result['data']['globals']['userid'] );
    }

    public function test_bearer_token_is_sent_in_authorization_header(): void {
        Functions\when( 'get_option' )->justReturn( 'https://www.onlinescoutmanager.co.uk/api.php' );
        Functions\when( 'add_query_arg' )->alias( function( array $args, string $base ): string {
            return $base . '?' . http_build_query( $args );
        } );

        $captured_args = null;
        Functions\when( 'wp_remote_get' )->alias( function( $url, $args ) use ( &$captured_args ) {
            $captured_args = $args;
            return [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => '{}' ];
        } );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_headers' )->justReturn( [] );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );
        Functions\when( 'is_wp_error' )->justReturn( false );

        $driver = new Live_Driver();
        $driver->set_access_token( 'my-secret-token' );
        $driver->get_data_payload( 'my-secret-token' );

        $this->assertSame( 'Bearer my-secret-token', $captured_args['headers']['Authorization'] );
    }

    // -------------------------------------------------------------------
    // WP_Error
    // -------------------------------------------------------------------

    public function test_wp_error_throws_api_response_exception(): void {
        $driver = $this->make_driver();
        $this->stub_wp_error( 'cURL error 6: Could not resolve host' );

        $this->expectException( Api_Response_Exception::class );
        $this->expectExceptionMessageMatches( '/Could not resolve host/' );
        $driver->get_data_payload( 'token' );
    }

    // -------------------------------------------------------------------
    // HTTP 429 — Rate Limiting
    // -------------------------------------------------------------------

    public function test_429_throws_rate_limit_exception(): void {
        $driver = $this->make_driver();
        $this->stub_request( 429, [
            'retry-after'          => '60',
            'x-ratelimit-reset'    => '3600',
            'x-ratelimit-limit'    => '500',
            'x-ratelimit-remaining'=> '0',
        ], '' );

        $this->expectException( Rate_Limit_Exception::class );
        $driver->get_data_payload( 'token' );
    }

    public function test_429_exception_carries_retry_after(): void {
        $driver = $this->make_driver();
        $this->stub_request( 429, [
            'retry-after'       => '120',
            'x-ratelimit-reset' => '7200',
        ], '' );

        try {
            $driver->get_data_payload( 'token' );
            $this->fail( 'Expected Rate_Limit_Exception' );
        } catch ( Rate_Limit_Exception $e ) {
            $this->assertSame( 120, $e->get_retry_after() );
            $this->assertSame( 7200, $e->get_rate_limit_reset() );
        }
    }

    public function test_429_headers_stored_before_exception_thrown(): void {
        $driver = $this->make_driver();
        $this->stub_request( 429, [
            'retry-after'           => '30',
            'x-ratelimit-limit'     => '500',
            'x-ratelimit-remaining' => '0',
            'x-ratelimit-reset'     => '1800',
        ], '' );

        try {
            $driver->get_data_payload( 'token' );
        } catch ( Rate_Limit_Exception $e ) {
            // Expected
        }

        $headers = $driver->get_last_response_headers();
        $this->assertSame( 429, $headers['http_status'] );
        $this->assertSame( 30, $headers['retry-after'] );
        $this->assertSame( 500, $headers['x-ratelimit-limit'] );
        $this->assertSame( 0, $headers['x-ratelimit-remaining'] );
        $this->assertSame( 1800, $headers['x-ratelimit-reset'] );
    }

    // -------------------------------------------------------------------
    // X-Blocked
    // -------------------------------------------------------------------

    public function test_x_blocked_header_throws_api_blocked_exception(): void {
        $driver = $this->make_driver();
        $this->stub_request( 200, [
            'x-blocked' => 'invalid-data',
        ], '{}' );

        $this->expectException( Api_Blocked_Exception::class );
        $driver->get_data_payload( 'token' );
    }

    public function test_x_blocked_exception_carries_header_value(): void {
        $driver = $this->make_driver();
        $this->stub_request( 200, [
            'x-blocked' => 'abuse',
        ], '{}' );

        try {
            $driver->get_data_payload( 'token' );
            $this->fail( 'Expected Api_Blocked_Exception' );
        } catch ( Api_Blocked_Exception $e ) {
            $this->assertSame( 'abuse', $e->get_blocked_header() );
        }
    }

    public function test_x_blocked_checked_before_429(): void {
        $driver = $this->make_driver();
        $this->stub_request( 429, [
            'x-blocked'    => 'blocked-reason',
            'retry-after'  => '60',
        ], '' );

        $this->expectException( Api_Blocked_Exception::class );
        $driver->get_data_payload( 'token' );
    }

    // -------------------------------------------------------------------
    // Bad JSON / non-array response
    // -------------------------------------------------------------------

    public function test_non_json_body_throws_api_response_exception(): void {
        $driver = $this->make_driver();
        $this->stub_request( 200, [], '<html>Error page</html>' );

        $this->expectException( Api_Response_Exception::class );
        $driver->get_data_payload( 'token' );
    }

    public function test_json_scalar_body_throws_api_response_exception(): void {
        $driver = $this->make_driver();
        $this->stub_request( 200, [], '"just a string"' );

        $this->expectException( Api_Response_Exception::class );
        $driver->get_data_payload( 'token' );
    }

    public function test_empty_body_throws_api_response_exception(): void {
        $driver = $this->make_driver();
        $this->stub_request( 200, [], '' );

        $this->expectException( Api_Response_Exception::class );
        $driver->get_data_payload( 'token' );
    }

    // -------------------------------------------------------------------
    // Header parsing
    // -------------------------------------------------------------------

    public function test_all_rate_limit_headers_parsed_on_success(): void {
        $driver = $this->make_driver();
        $this->stub_request( 200, [
            'x-ratelimit-limit'     => '500',
            'x-ratelimit-remaining' => '497',
            'x-ratelimit-reset'     => '1800',
        ], '{"ok":true}' );

        $driver->get_data_payload( 'token' );
        $h = $driver->get_last_response_headers();

        $this->assertSame( 200, $h['http_status'] );
        $this->assertSame( 500, $h['x-ratelimit-limit'] );
        $this->assertSame( 497, $h['x-ratelimit-remaining'] );
        $this->assertSame( 1800, $h['x-ratelimit-reset'] );
        $this->assertNull( $h['retry-after'] );
        $this->assertNull( $h['x-deprecated'] );
        $this->assertNull( $h['x-blocked'] );
    }

    public function test_x_deprecated_header_parsed(): void {
        $driver = $this->make_driver();
        $this->stub_request( 200, [
            'x-deprecated' => 'This endpoint will be removed on 2027-01-01',
        ], '{"ok":true}' );

        $driver->get_data_payload( 'token' );
        $h = $driver->get_last_response_headers();

        $this->assertSame( 'This endpoint will be removed on 2027-01-01', $h['x-deprecated'] );
    }

    public function test_missing_rate_limit_headers_are_null(): void {
        $driver = $this->make_driver();
        $this->stub_request( 200, [], '{"ok":true}' );

        $driver->get_data_payload( 'token' );
        $h = $driver->get_last_response_headers();

        $this->assertNull( $h['x-ratelimit-limit'] );
        $this->assertNull( $h['x-ratelimit-remaining'] );
        $this->assertNull( $h['x-ratelimit-reset'] );
        $this->assertNull( $h['retry-after'] );
    }

    public function test_last_headers_empty_before_any_request(): void {
        Functions\when( 'get_option' )->justReturn( 'https://www.onlinescoutmanager.co.uk/api.php' );
        $driver = new Live_Driver();
        $this->assertSame( [], $driver->get_last_response_headers() );
    }

    // -------------------------------------------------------------------
    // URL construction
    // -------------------------------------------------------------------

    public function test_get_section_members_url_contains_correct_params(): void {
        Functions\when( 'get_option' )->justReturn( 'https://www.onlinescoutmanager.co.uk/api.php' );

        $captured_url = null;
        Functions\when( 'add_query_arg' )->alias( function( array $args, string $base ) use ( &$captured_url ): string {
            $url          = $base . '?' . http_build_query( $args );
            $captured_url = $url;
            return $url;
        } );
        $this->stub_request( 200, [], '{"items":[]}' );

        $driver = new Live_Driver();
        $driver->set_access_token( 'tok' );
        $driver->get_section_members( 43105, 5001 );

        $this->assertStringContainsString( 'sectionid=43105', $captured_url );
        $this->assertStringContainsString( 'termid=5001', $captured_url );
        $this->assertStringContainsString( 'getListOfMembers', $captured_url );
    }

    public function test_get_member_detail_url_contains_scout_id(): void {
        Functions\when( 'get_option' )->justReturn( 'https://www.onlinescoutmanager.co.uk/api.php' );

        $captured_url = null;
        Functions\when( 'add_query_arg' )->alias( function( array $args, string $base ) use ( &$captured_url ): string {
            $url          = $base . '?' . http_build_query( $args );
            $captured_url = $url;
            return $url;
        } );
        $this->stub_request( 200, [], '{"data":[]}' );

        $driver = new Live_Driver();
        $driver->set_access_token( 'tok' );
        $driver->get_member_detail( 43105, 99999, 5001 );

        $this->assertStringContainsString( 'associated_id=99999', $captured_url );
        $this->assertStringContainsString( 'getData', $captured_url );
    }
}
