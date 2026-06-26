<?php
namespace EMS\Tests\Unit\Auth;

use EMS\Integrations\OIDC_Login_Handler;
use EMS\Integrations\OSM_API_Client;
use EMS\Integrations\OSM_Parser;
use EMS\Data\OSM_Explorer_Repository;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;
use Mockery;

class OIDC_Login_HandlerTest extends EMSTestCase {
    private OSM_API_Client $api_client;
    private OSM_Parser $parser;
    private \WP_User $user;

    protected function setUp(): void {
        parent::setUp();
        $this->api_client = Mockery::mock( OSM_API_Client::class );
        $this->parser     = Mockery::mock( OSM_Parser::class );

        $this->user     = Mockery::mock( \WP_User::class );
        $this->user->ID = 42;
    }

    protected function tearDown(): void {
        Mockery::close();
        parent::tearDown();
    }

    public function test_handle_osm_login_does_not_store_access_token_in_session(): void {
        Functions\stubs( [ 'update_user_meta' ] );

        $this->api_client->shouldReceive( 'set_access_token' )->once()->with( 'secret-token' );
        $this->api_client->shouldReceive( 'get_data_payload' )
            ->once()
            ->withNoArgs()
            ->andReturn( [] );

        $integration = new OIDC_Login_Handler( $this->api_client, $this->parser );

        if ( ! isset( $_SESSION ) ) {
            $_SESSION = [];
        }
        unset( $_SESSION['ems_osm_access_token'] );

        $integration->handle_osm_login( $this->user, [
            'osm_id'       => 999,
            'access_token' => 'secret-token',
            'patrol'       => 'Cobra',
        ] );

        $this->assertArrayNotHasKey(
            'ems_osm_access_token',
            $_SESSION,
            'Access token must NOT be stored in $_SESSION (ADR 009)'
        );
    }

    public function test_handle_osm_login_stores_osm_id_in_user_meta(): void {
        $stored = [];
        Functions\when( 'update_user_meta' )->alias( static function ( $uid, $key, $val ) use ( &$stored ): bool {
            $stored[ $key ] = $val;
            return true;
        } );

        $this->api_client->shouldReceive( 'set_access_token' )->andReturn();
        $this->api_client->shouldReceive( 'get_data_payload' )->withNoArgs()->andReturn( [] );

        $integration = new OIDC_Login_Handler( $this->api_client, $this->parser );
        $integration->handle_osm_login( $this->user, [
            'osm_id'       => 999,
            'access_token' => 'secret-token',
        ] );

        $this->assertSame( 999, $stored['ems_osm_id'] );
    }

    public function test_handle_osm_login_accepts_stdclass_for_data(): void {
        $stored = [];
        Functions\when( 'update_user_meta' )->alias( static function ( $uid, $key, $val ) use ( &$stored ): bool {
            $stored[ $key ] = $val;
            return true;
        } );

        $this->api_client->shouldReceive( 'set_access_token' )->andReturn();
        $this->api_client->shouldReceive( 'get_data_payload' )->withNoArgs()->andReturn( [] );

        $integration = new OIDC_Login_Handler( $this->api_client, $this->parser );
        $data = new \stdClass();
        $data->osm_id = 999;
        $data->access_token = 'secret-token';
        $data->patrol = 'Cobra';

        $integration->handle_osm_login( $this->user, $data );

        $this->assertSame( 999, $stored['ems_osm_id'] );
        $this->assertSame( 'Cobra', $stored['ems_unit'] );
    }


    public function test_handle_osm_login_stores_access_type_from_payload(): void {
        $raw_payload = json_decode(
            file_get_contents( __DIR__ . '/../../mocks/osm-get-data-payload-parent.json' ),
            true
        );
        $stored = [];
        Functions\when( 'update_user_meta' )->alias( static function ( $uid, $key, $val ) use ( &$stored ): bool {
            $stored[ $key ] = $val;
            return true;
        } );

        $this->api_client->shouldReceive( 'set_access_token' )->once()->with( 'parent-token' );
        $this->api_client->shouldReceive( 'get_data_payload' )
            ->once()
            ->withNoArgs()
            ->andReturn( $raw_payload );

        $real_parser = new OSM_Parser();
        $integration = new OIDC_Login_Handler( $this->api_client, $real_parser );
        $integration->handle_osm_login( $this->user, [
            'osm_id'       => 20002,
            'access_token' => 'parent-token',
        ] );

        $this->assertSame( 'parent', $stored['ems_access_type'] );
    }

    public function test_handle_osm_login_stores_scout_ids_from_payload(): void {
        $raw_payload = json_decode(
            file_get_contents( __DIR__ . '/../../mocks/osm-get-data-payload-explorer.json' ),
            true
        );
        $stored = [];
        Functions\when( 'update_user_meta' )->alias( static function ( $uid, $key, $val ) use ( &$stored ): bool {
            $stored[ $key ] = $val;
            return true;
        } );

        $this->api_client->shouldReceive( 'set_access_token' )->once()->with( 'explorer-token' );
        $this->api_client->shouldReceive( 'get_data_payload' )
            ->once()
            ->withNoArgs()
            ->andReturn( $raw_payload );

        $integration = new OIDC_Login_Handler( $this->api_client, new OSM_Parser() );
        $integration->handle_osm_login( $this->user, [
            'osm_id'       => 20001,
            'access_token' => 'explorer-token',
        ] );

        $this->assertContains( 30001, $stored['ems_scout_ids'] );
    }

    public function test_local_user_without_access_token_gets_local_access_type(): void {
        $stored = [];
        Functions\when( 'update_user_meta' )->alias( static function ( $uid, $key, $val ) use ( &$stored ): bool {
            $stored[ $key ] = $val;
            return true;
        } );

        $integration = new OIDC_Login_Handler( $this->api_client, $this->parser );
        $integration->handle_osm_login( $this->user, [ 'patrol' => 'Some Patrol' ] );

        $this->assertSame( 'local', $stored['ems_access_type'] );
    }

    public function test_local_user_without_access_token_does_not_call_api(): void {
        Functions\stubs( [ 'update_user_meta' ] );

        $this->api_client->shouldReceive( 'get_data_payload' )->never();

        $integration = new OIDC_Login_Handler( $this->api_client, $this->parser );
        $integration->handle_osm_login( $this->user, [] );
        $this->addToAssertionCount( 1 );
    }

    public function test_local_user_without_access_token_throws_no_exception(): void {
        Functions\stubs( [ 'update_user_meta' ] );

        $integration = new OIDC_Login_Handler( $this->api_client, $this->parser );

        $this->expectNotToPerformAssertions();
        $integration->handle_osm_login( $this->user, [] );
    }

    public function test_handle_osm_login_calls_link_wp_user_by_email_with_user_email(): void {
        Functions\stubs( [ 'update_user_meta' ] );

        $this->user->user_email = 'alice@example.com';

        $explorer_repo = Mockery::mock( OSM_Explorer_Repository::class );
        $explorer_repo->shouldReceive( 'link_wp_user_by_email' )
            ->once()
            ->with( 'alice@example.com', 42 )
            ->andReturn( 1 );

        $integration = new OIDC_Login_Handler( $this->api_client, $this->parser, $explorer_repo );
        $integration->handle_osm_login( $this->user, [] );
        $this->addToAssertionCount( 1 );
    }

    public function test_handle_osm_login_skips_link_when_user_email_is_empty(): void {
        Functions\stubs( [ 'update_user_meta' ] );

        $this->user->user_email = '';

        $explorer_repo = Mockery::mock( OSM_Explorer_Repository::class );
        $explorer_repo->shouldReceive( 'link_wp_user_by_email' )->never();

        $integration = new OIDC_Login_Handler( $this->api_client, $this->parser, $explorer_repo );
        $integration->handle_osm_login( $this->user, [] );
        $this->addToAssertionCount( 1 );
    }

    public function test_handle_user_created_calls_link_wp_user_by_email(): void {
        Functions\stubs( [ 'update_user_meta' ] );
        Functions\when( 'get_user_by' )->justReturn( $this->user );

        $this->user->user_email = 'bob@example.com';

        $explorer_repo = Mockery::mock( OSM_Explorer_Repository::class );
        $explorer_repo->shouldReceive( 'link_wp_user_by_email' )
            ->once()
            ->with( 'bob@example.com', 42 )
            ->andReturn( 1 );

        $integration = new OIDC_Login_Handler( $this->api_client, $this->parser, $explorer_repo );
        $integration->handle_user_created( 42, new \stdClass() );
        $this->addToAssertionCount( 1 );
    }

    public function test_handle_user_created_skips_link_when_user_not_found(): void {
        Functions\when( 'get_user_by' )->justReturn( false );

        $explorer_repo = Mockery::mock( OSM_Explorer_Repository::class );
        $explorer_repo->shouldReceive( 'link_wp_user_by_email' )->never();

        $integration = new OIDC_Login_Handler( $this->api_client, $this->parser, $explorer_repo );
        $integration->handle_user_created( 99, new \stdClass() );
        $this->addToAssertionCount( 1 );
    }
}
