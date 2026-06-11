<?php
namespace EMS\Tests\Unit\Auth;

use EMS\Auth\LoginWithGoogle_Auth_Provider;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Actions;

class LoginWithGoogle_Auth_ProviderTest extends EMSTestCase {
    public function test_implements_auth_provider_interface(): void {
        $provider = new LoginWithGoogle_Auth_Provider();
        $this->assertInstanceOf( \EMS\Auth\Auth_Provider::class, $provider );
    }

    public function test_get_access_token_returns_empty_before_capture(): void {
        $provider = new LoginWithGoogle_Auth_Provider();
        $this->assertSame( '', $provider->get_access_token() );
    }

    public function test_get_user_data_returns_empty_array_before_capture(): void {
        $provider = new LoginWithGoogle_Auth_Provider();
        $this->assertSame( [], $provider->get_user_data() );
    }

    public function test_capture_stores_access_token(): void {
        $provider = new LoginWithGoogle_Auth_Provider();
        $user     = $this->createMock( \WP_User::class );
        $provider->capture( $user, [ 'access_token' => 'abc123', 'osm_id' => 999 ] );
        $this->assertSame( 'abc123', $provider->get_access_token() );
    }

    public function test_capture_stores_user_data(): void {
        $provider = new LoginWithGoogle_Auth_Provider();
        $user     = $this->createMock( \WP_User::class );
        $data     = [ 'access_token' => 'abc123', 'osm_id' => 999, 'patrol' => 'Cobra' ];
        $provider->capture( $user, $data );
        $this->assertSame( $data, $provider->get_user_data() );
    }

    public function test_capture_handles_missing_access_token_gracefully(): void {
        $provider = new LoginWithGoogle_Auth_Provider();
        $user     = $this->createMock( \WP_User::class );
        $provider->capture( $user, [ 'osm_id' => 999 ] );
        $this->assertSame( '', $provider->get_access_token() );
    }
}
