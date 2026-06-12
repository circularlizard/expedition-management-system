<?php
namespace EMS\Tests\Unit\Auth;

use EMS\Integrations\OSM_Auth_Integration;
use EMS\Integrations\OSM_API_Client;
use EMS\Integrations\OSM_Parser;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;
use Mockery;

class OSM_Auth_IntegrationTest extends EMSTestCase {
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

        $this->api_client->shouldReceive( 'get_data_payload' )
            ->once()
            ->with( 'secret-token' )
            ->andReturn( [] );

        $integration = new OSM_Auth_Integration( $this->api_client, $this->parser );

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

        $this->api_client->shouldReceive( 'get_data_payload' )->andReturn( [] );

        $integration = new OSM_Auth_Integration( $this->api_client, $this->parser );
        $integration->handle_osm_login( $this->user, [
            'osm_id'       => 999,
            'access_token' => 'secret-token',
        ] );

        $this->assertSame( 999, $stored['ems_osm_id'] );
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

        $this->api_client->shouldReceive( 'get_data_payload' )
            ->once()
            ->with( 'parent-token' )
            ->andReturn( $raw_payload );

        $real_parser = new OSM_Parser();
        $integration = new OSM_Auth_Integration( $this->api_client, $real_parser );
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

        $this->api_client->shouldReceive( 'get_data_payload' )
            ->once()
            ->with( 'explorer-token' )
            ->andReturn( $raw_payload );

        $integration = new OSM_Auth_Integration( $this->api_client, new OSM_Parser() );
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

        $integration = new OSM_Auth_Integration( $this->api_client, $this->parser );
        $integration->handle_osm_login( $this->user, [ 'patrol' => 'Some Patrol' ] );

        $this->assertSame( 'local', $stored['ems_access_type'] );
    }

    public function test_local_user_without_access_token_does_not_call_api(): void {
        Functions\stubs( [ 'update_user_meta' ] );

        $this->api_client->shouldReceive( 'get_data_payload' )->never();

        $integration = new OSM_Auth_Integration( $this->api_client, $this->parser );
        $integration->handle_osm_login( $this->user, [] );
        $this->addToAssertionCount( 1 );
    }

    public function test_local_user_without_access_token_throws_no_exception(): void {
        Functions\stubs( [ 'update_user_meta' ] );

        $integration = new OSM_Auth_Integration( $this->api_client, $this->parser );

        $this->expectNotToPerformAssertions();
        $integration->handle_osm_login( $this->user, [] );
    }
}
