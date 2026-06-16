<?php
namespace EMS\Tests\Unit\Integrations;

use EMS\Integrations\OSM_Reference_Sync;
use EMS\Integrations\OSM_API_Client;
use EMS\Integrations\OSM_Parser;
use EMS\Integrations\Exceptions\Api_Blocked_Exception;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Tests that ems_api_blocked option is set on Api_Blocked_Exception
 * and that OSM_Sync_Auth_Handler refuses to initiate when flag is set.
 */
class ApiBlockedFlagTest extends EMSTestCase {

    private $api_client;
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        $this->api_client = Mockery::mock( OSM_API_Client::class );
        $this->api_client->shouldReceive( 'set_sync_result' )->zeroOrMoreTimes();
        $this->api_client->shouldReceive( 'get_patrols' )->zeroOrMoreTimes()->andReturn( [ 'patrols' => [] ] );

        Functions\when( 'get_option' )->alias( function( $key, $default = false ) {
            if ( $key === 'ems_managed_sections' ) {
                return [];
            }
            return $default;
        } );

        $this->wpdb             = Mockery::mock( 'wpdb' );
        $this->wpdb->prefix     = 'wp_';
        $this->wpdb->last_error = '';
        $GLOBALS['wpdb']        = $this->wpdb;
    }

    protected function tearDown(): void {
        Mockery::close();
        parent::tearDown();
    }

    private function make_payload(): array {
        return [
            'data' => [
                'globals' => [
                    'terms' => [
                        '43105' => [ [
                            'termid'    => '5001',
                            'sectionid' => '43105',
                            'name'      => 'Spring 2026',
                            'startdate' => '2026-01-01',
                            'enddate'   => '2026-12-31',
                        ] ],
                    ],
                ],
            ],
        ];
    }

    // -------------------------------------------------------------------
    // OSM_Reference_Sync persists the flag
    // -------------------------------------------------------------------

    public function test_api_blocked_exception_sets_ems_api_blocked_option(): void {
        $this->api_client->shouldReceive( 'get_section_participants' )
            ->andThrow( new Api_Blocked_Exception( 'abuse', 'https://osm.example' ) );

        Functions\when( 'current_time' )->justReturn( '2026-06-16 09:00:00' );
        Functions\when( 'set_transient' )->justReturn( true );

        $blocked_set = false;
        Functions\when( 'update_option' )->alias( function( $key ) use ( &$blocked_set ) {
            if ( $key === 'ems_api_blocked' ) {
                $blocked_set = true;
            }
        } );

        $sync = new OSM_Reference_Sync( $this->api_client, new OSM_Parser() );
        $result = $sync->sync( [ 43105 ], $this->make_payload() );

        $this->assertTrue( $result->api_blocked );
        $this->assertTrue( $blocked_set, 'ems_api_blocked option was not set' );
    }

    public function test_rate_limit_exception_does_not_set_api_blocked_flag(): void {
        $this->api_client->shouldReceive( 'get_section_participants' )
            ->andThrow( new \EMS\Integrations\Exceptions\Rate_Limit_Exception( 60, 3600, 'https://osm.example' ) );

        Functions\when( 'current_time' )->justReturn( '2026-06-16 09:00:00' );
        Functions\when( 'set_transient' )->justReturn( true );

        $blocked_set = false;
        Functions\when( 'update_option' )->alias( function( $key ) use ( &$blocked_set ) {
            if ( $key === 'ems_api_blocked' ) {
                $blocked_set = true;
            }
        } );

        $sync = new OSM_Reference_Sync( $this->api_client, new OSM_Parser() );
        $sync->sync( [ 43105 ], $this->make_payload() );

        $this->assertFalse( $blocked_set, 'ems_api_blocked should NOT be set on rate limit' );
    }

    // -------------------------------------------------------------------
    // OSM_Sync_Auth_Handler refuses when flag is set
    // -------------------------------------------------------------------

    public function test_initiate_redirects_with_api_blocked_error_when_flag_set(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'get_option' )->alias( function( $key, $default = false ) {
            if ( $key === 'ems_api_blocked' )     return true;
            if ( $key === 'ems_osm_client_id' )   return 'client-id';
            if ( $key === 'ems_osm_client_secret' ) return \EMS\Core\Encryption::encrypt( 'secret' );
            if ( $key === 'ems_osm_auth_url' )    return 'https://osm.example/auth';
            if ( $key === 'ems_osm_token_url' )   return 'https://osm.example/token';
            return $default;
        } );

        $redirect_url = null;
        Functions\when( 'admin_url' )->alias( function( $path ) {
            return 'https://localhost/wp-admin/' . ltrim( $path, '/' );
        } );
        Functions\when( 'wp_safe_redirect' )->alias( function( $url ) use ( &$redirect_url ) {
            $redirect_url = $url;
        } );

        $handler = new \EMS\Admin\OSM_Sync_Auth_Handler();
        $handler->initiate();

        $this->assertStringContainsString( 'error=api_blocked', $redirect_url );
    }

    public function test_handle_callback_redirects_with_api_blocked_error_when_flag_set(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'get_option' )->alias( function( $key, $default = false ) {
            if ( $key === 'ems_api_blocked' )     return true;
            if ( $key === 'ems_osm_client_id' )   return 'client-id';
            if ( $key === 'ems_osm_client_secret' ) return \EMS\Core\Encryption::encrypt( 'secret' );
            if ( $key === 'ems_osm_auth_url' )    return 'https://osm.example/auth';
            if ( $key === 'ems_osm_token_url' )   return 'https://osm.example/token';
            return $default;
        } );
        Functions\when( 'admin_url' )->alias( function( $path ) {
            return 'https://localhost/wp-admin/' . ltrim( $path, '/' );
        } );

        $redirect_url = null;
        Functions\when( 'wp_safe_redirect' )->alias( function( $url ) use ( &$redirect_url ) {
            $redirect_url = $url;
        } );

        $on_success_called = false;
        $handler = new \EMS\Admin\OSM_Sync_Auth_Handler();
        $handler->handle_callback( function() use ( &$on_success_called ) {
            $on_success_called = true;
        } );

        $this->assertStringContainsString( 'error=api_blocked', $redirect_url );
        $this->assertFalse( $on_success_called, 'on_success must not be called when API is blocked' );
    }

    public function test_initiate_proceeds_normally_when_flag_not_set(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'get_user_meta' )->justReturn( '' );
        Functions\when( 'get_option' )->alias( function( $key, $default = false ) {
            if ( $key === 'ems_api_blocked' )     return false;
            if ( $key === 'ems_osm_client_id' )   return 'client-id';
            if ( $key === 'ems_osm_client_secret' ) return \EMS\Core\Encryption::encrypt( 'secret' );
            if ( $key === 'ems_osm_auth_url' )    return 'https://osm.example/auth';
            if ( $key === 'ems_osm_token_url' )   return 'https://osm.example/token';
            if ( $key === 'ems_osm_scope' )       return 'section:member:read';
            return $default;
        } );
        Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
        Functions\when( 'admin_url' )->justReturn( 'https://localhost/callback' );

        $redirect_url = null;
        Functions\when( 'wp_redirect' )->alias( function( $url ) use ( &$redirect_url ) {
            $redirect_url = $url;
        } );

        $handler = new \EMS\Admin\OSM_Sync_Auth_Handler();
        $handler->initiate();

        $this->assertStringContainsString( 'osm.example/auth', $redirect_url );
        $this->assertStringNotContainsString( 'api_blocked', $redirect_url );
    }
}
