<?php
namespace EMS\Tests\Unit\Admin;

use EMS\Admin\OSM_Sync_Auth_Handler;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;
use Mockery;

class OSM_Sync_Auth_HandlerTest extends EMSTestCase {

    protected function setUp(): void {
        parent::setUp();
        
        if ( ! defined( 'AUTH_KEY' ) ) {
            define( 'AUTH_KEY', 'test-auth-key' );
        }
        if ( ! defined( 'SECURE_AUTH_KEY' ) ) {
            define( 'SECURE_AUTH_KEY', 'test-secure-auth-key' );
        }

        // Mock get_option for credentials
        Functions\when( 'get_option' )->alias( function( $key, $default = '' ) {
            if ( $key === 'ems_osm_client_id' ) {
                return 'test-client-id';
            }
            if ( $key === 'ems_osm_client_secret' ) {
                // Use real class to encrypt so real class can decrypt
                return \EMS\Core\Encryption::encrypt('decrypted-secret');
            }
            if ( $key === 'ems_osm_auth_url' ) {
                return 'https://example.com/auth';
            }
            if ( $key === 'ems_osm_token_url' ) {
                return 'https://example.com/token';
            }
            return $default;
        } );
    }

    public function test_initiate_redirects_to_osm(): void {
        Functions\expect( 'current_user_can' )->with( 'manage_options' )->andReturn( true );
        Functions\expect( 'wp_create_nonce' )->with( 'ems_osm_sync' )->andReturn( 'test-nonce' );
        Functions\expect( 'admin_url' )->with( 'admin-post.php?action=ems_osm_callback' )->andReturn( 'https://localhost/callback' );
        
        Functions\expect( 'wp_redirect' )->with( Mockery::on( function( $url ) {
            return str_contains( $url, 'https://example.com/auth' ) &&
                   str_contains( $url, 'client_id=test-client-id' ) &&
                   str_contains( $url, 'state=test-nonce' ) &&
                   str_contains( $url, 'redirect_uri=' . urlencode( 'https://localhost/callback' ) );
        } ) )->once();

        $handler = new OSM_Sync_Auth_Handler();
        $handler->initiate();
        
        $this->assertTrue( true ); // Avoid risky test
    }

    public function test_handle_callback_validates_nonce_and_exchanges_token(): void {
        Functions\expect( 'current_user_can' )->with( 'manage_options' )->andReturn( true );
        Functions\expect( 'wp_verify_nonce' )->with( 'test-nonce', 'ems_osm_sync' )->andReturn( true );
        Functions\expect( 'admin_url' )->with( Mockery::any() )->andReturn( 'https://localhost/dashboard' );

        $_GET['state'] = 'test-nonce';
        $_GET['code']  = 'test-code';

        Functions\expect( 'wp_remote_post' )->once()->andReturn( [
            'body' => json_encode( [ 'access_token' => 'valid-token' ] ),
            'response' => [ 'code' => 200 ]
        ] );
        Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 200 );
        Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( json_encode( [ 'access_token' => 'valid-token' ] ) );

        $callback_called = false;
        $captured_token  = '';
        $on_success = function( $token ) use ( &$callback_called, &$captured_token ) {
            $callback_called = true;
            $captured_token  = $token;
        };

        Functions\expect( 'wp_safe_redirect' )->once();
        Functions\when( 'is_wp_error' )->justReturn( false );

        $handler = new OSM_Sync_Auth_Handler();
        $handler->handle_callback( $on_success );

        $this->assertTrue( $callback_called );
        $this->assertEquals( 'valid-token', $captured_token );
    }

    public function test_handle_callback_treats_non_2xx_token_response_as_error(): void {
        Functions\expect( 'current_user_can' )->with( 'manage_options' )->andReturn( true );
        Functions\when( 'get_option' )->alias( function( $key, $default = '' ) {
            if ( $key === 'ems_api_blocked' ) return false;
            if ( $key === 'ems_osm_client_id' ) return 'test-client-id';
            if ( $key === 'ems_osm_client_secret' ) return \EMS\Core\Encryption::encrypt( 'secret' );
            if ( $key === 'ems_osm_auth_url' ) return 'https://example.com/auth';
            if ( $key === 'ems_osm_token_url' ) return 'https://example.com/token';
            return $default;
        } );
        Functions\expect( 'wp_verify_nonce' )->andReturn( true );
        Functions\expect( 'admin_url' )->andReturn( 'https://localhost/reference?error=token_exchange' );

        $_GET['state'] = 'test-nonce';
        $_GET['code']  = 'test-code';

        Functions\expect( 'wp_remote_post' )->once()->andReturn( [] );
        Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof \WP_Error );
        Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 401 );
        Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( '{"error":"invalid_client"}' );

        $redirect_url      = null;
        $on_success_called = false;
        Functions\expect( 'wp_safe_redirect' )->once()->andReturnUsing( function( $url ) use ( &$redirect_url ) {
            $redirect_url = $url;
        } );

        $handler = new OSM_Sync_Auth_Handler();
        $handler->handle_callback( function() use ( &$on_success_called ) {
            $on_success_called = true;
        } );

        $this->assertStringContainsString( 'token_exchange', $redirect_url );
        $this->assertFalse( $on_success_called, 'on_success must not fire on non-2xx token response' );
    }

    public function test_handle_callback_rejects_http_token_url(): void {
        Functions\expect( 'current_user_can' )->with( 'manage_options' )->andReturn( true );
        Functions\when( 'get_option' )->alias( function( $key, $default = '' ) {
            if ( $key === 'ems_api_blocked' )      return false;
            if ( $key === 'ems_osm_client_id' )    return 'test-client-id';
            if ( $key === 'ems_osm_client_secret' ) return \EMS\Core\Encryption::encrypt( 'secret' );
            if ( $key === 'ems_osm_auth_url' )     return 'https://example.com/auth';
            if ( $key === 'ems_osm_token_url' )    return 'http://example.com/token';
            return $default;
        } );
        Functions\expect( 'wp_verify_nonce' )->andReturn( true );
        Functions\expect( 'admin_url' )->andReturn( 'https://localhost/reference?error=token_exchange' );
        Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof \WP_Error );

        $_GET['state'] = 'nonce';
        $_GET['code']  = 'code';

        $on_success_called = false;
        $redirect_url      = null;
        Functions\expect( 'wp_safe_redirect' )->once()->andReturnUsing( function( $url ) use ( &$redirect_url ) {
            $redirect_url = $url;
        } );
        Functions\expect( 'wp_remote_post' )->never();

        $handler = new OSM_Sync_Auth_Handler();
        $handler->handle_callback( function() use ( &$on_success_called ) {
            $on_success_called = true;
        } );

        $this->assertStringContainsString( 'token_exchange', $redirect_url );
        $this->assertFalse( $on_success_called );
    }

    public function test_handle_callback_redirects_on_invalid_nonce(): void {
        Functions\expect( 'current_user_can' )->with( 'manage_options' )->andReturn( true );
        Functions\expect( 'wp_verify_nonce' )->andReturn( false );
        Functions\expect( 'admin_url' )->andReturn( 'https://localhost/reference?error=invalid_state' );

        $_GET['state'] = 'bad-nonce';

        $redirect_url = null;
        Functions\expect( 'wp_safe_redirect' )->once()->andReturnUsing( function( $url ) use ( &$redirect_url ) {
            $redirect_url = $url;
        } );

        $handler = new OSM_Sync_Auth_Handler();
        $handler->handle_callback( function() {} );

        $this->assertStringContainsString( 'invalid_state', $redirect_url );
    }

    public function test_initiate_includes_scope_in_auth_url(): void {
        Functions\expect( 'current_user_can' )->with( 'manage_options' )->andReturn( true );
        Functions\expect( 'wp_create_nonce' )->andReturn( 'nonce' );
        Functions\expect( 'admin_url' )->with( 'admin-post.php?action=ems_osm_callback' )->andReturn( 'https://localhost/callback' );
        Functions\when( 'get_option' )->alias( function( $key, $default = '' ) {
            if ( $key === 'ems_osm_client_id' ) return 'test-client-id';
            if ( $key === 'ems_osm_client_secret' ) return \EMS\Core\Encryption::encrypt( 'secret' );
            if ( $key === 'ems_osm_auth_url' ) return 'https://example.com/auth';
            if ( $key === 'ems_osm_token_url' ) return 'https://example.com/token';
            if ( $key === 'ems_osm_scope' ) return 'section:member:read section:events:read';
            return $default;
        } );

        $redirect_url = null;
        Functions\expect( 'wp_redirect' )->once()->andReturnUsing( function( $url ) use ( &$redirect_url ) {
            $redirect_url = $url;
        } );

        $handler = new OSM_Sync_Auth_Handler();
        $handler->initiate();

        $this->assertStringContainsString( urlencode( 'section:member:read section:events:read' ), $redirect_url );
    }
}
