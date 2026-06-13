<?php
namespace EMS\Tests\Unit\Integrations;

use EMS\Integrations\OSM_Section_Importer;
use EMS\Integrations\OSM_API_Client;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;
use Mockery;

class OSM_Section_ImporterTest extends EMSTestCase {
    private $api_client;

    protected function setUp(): void {
        parent::setUp();
        $this->api_client = Mockery::mock( OSM_API_Client::class );
        
        // Mock get_users to return empty by default
        Functions\when( 'get_users' )->justReturn( [] );
        // Mock user creation helpers
        Functions\when( 'username_exists' )->justReturn( false );
        Functions\when( 'email_exists' )->justReturn( false );
        Functions\when( 'wp_generate_password' )->justReturn( 'password' );
    }

    public function test_import_all_calls_import_section_for_managed_sections(): void {
        Functions\expect( 'get_option' )
            ->with( 'ems_managed_sections', [] )
            ->andReturn( [ 43105 => [], 43106 => [] ] );

        $importer = Mockery::mock( OSM_Section_Importer::class . '[import_section]', [ $this->api_client ] );
        $importer->shouldReceive( 'import_section' )->with( 43105 )->once();
        $importer->shouldReceive( 'import_section' )->with( 43106 )->once();

        $importer->import_all();
        $this->assertTrue( true );
    }

    public function test_import_section_upserts_members(): void {
        $mock_members = [
            [
                'member_id'    => 1001,
                'first_name'   => 'Alice',
                'last_name'    => 'Alpha',
                'email'        => 'alice@example.com',
                'parent_email' => 'p.alice@example.com',
                'patrol'       => 'Bears'
            ]
        ];

        $this->api_client->shouldReceive( 'get_section_participants' )
            ->with( 43105 )
            ->once()
            ->andReturn( $mock_members );

        Functions\expect( 'wp_insert_user' )
            ->once()
            ->andReturn( 123 );

        // We call update_user_meta multiple times (6 times in total)
        Functions\expect( 'update_user_meta' )->times( 6 );

        $importer = new OSM_Section_Importer( $this->api_client );
        $importer->import_section( 43105 );
        $this->assertTrue( true );
    }

    public function test_existing_member_is_updated_not_created(): void {
        $mock_members = [
            [
                'member_id'    => 1001,
                'first_name'   => 'Alice',
                'last_name'    => 'Alpha',
                'email'        => 'alice@example.com',
                'parent_email' => 'p.alice@example.com',
                'patrol'       => 'Bears'
            ]
        ];

        $this->api_client->shouldReceive( 'get_section_participants' )->andReturn( $mock_members );

        // Simulate finding existing user
        $existing_user = (object) [ 'ID' => 123 ];
        Functions\when( 'get_users' )->justReturn( [ $existing_user ] );

        Functions\expect( 'wp_insert_user' )->never();
        Functions\expect( 'update_user_meta' )->times( 6 );

        $importer = new OSM_Section_Importer( $this->api_client );
        $importer->import_section( 43105 );
        $this->assertTrue( true );
    }

    public function test_member_with_missing_email_is_handled(): void {
        $mock_members = [
            [
                'member_id'    => 1001,
                'first_name'   => 'Alice',
                'last_name'    => 'Alpha',
                'email'        => '',
                'parent_email' => '',
                'patrol'       => 'Bears'
            ]
        ];

        $this->api_client->shouldReceive( 'get_section_participants' )->andReturn( $mock_members );

        Functions\expect( 'wp_insert_user' )
            ->once()
            ->with( Mockery::on( function( $args ) {
                return str_contains( $args['user_login'], 'scout_1001' );
            } ) )
            ->andReturn( 123 );
        
        Functions\when( 'update_user_meta' )->justReturn( true );

        $importer = new OSM_Section_Importer( $this->api_client );
        $importer->import_section( 43105 );
        $this->assertTrue( true );
    }
}
