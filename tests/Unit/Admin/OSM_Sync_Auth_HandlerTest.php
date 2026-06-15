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
        
        Functions\expect( 'wp_safe_redirect' )->with( Mockery::on( function( $url ) {
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
        Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( json_encode( [ 'access_token' => 'valid-token' ] ) );

        $callback_called = false;
        $captured_token  = '';
        $on_success = function( $token ) use ( &$callback_called, &$captured_token ) {
            $callback_called = true;
            $captured_token  = $token;
        };

        Functions\expect( 'wp_redirect' )->once();

        $handler = new OSM_Sync_Auth_Handler();
        $handler->handle_callback( $on_success );

        $this->assertTrue( $callback_called );
        $this->assertEquals( 'valid-token', $captured_token );
    }

    public function test_handle_callback_fails_on_invalid_nonce(): void {
        Functions\expect( 'current_user_can' )->with( 'manage_options' )->andReturn( true );
        Functions\expect( 'wp_verify_nonce' )->andReturn( false );

        $_GET['state'] = 'bad-nonce';

        $handler = new OSM_Sync_Auth_Handler();
        
        $this->expectException( \Exception::class );
        $this->expectExceptionMessage( 'Invalid state parameter.' );
        
        $handler->handle_callback( function() {} );
    }
}
