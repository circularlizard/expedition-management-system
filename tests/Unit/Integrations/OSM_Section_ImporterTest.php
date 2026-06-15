<?php
namespace EMS\Tests\Unit\Integrations;

use EMS\Integrations\OSM_Section_Importer;
use EMS\Integrations\OSM_API_Client;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;
use Mockery;

class OSM_Section_ImporterTest extends EMSTestCase {
    private $api_client;
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        $this->api_client = Mockery::mock( OSM_API_Client::class );

        $this->wpdb         = Mockery::mock( 'wpdb' );
        $this->wpdb->prefix = 'wp_';
        $GLOBALS['wpdb']    = $this->wpdb;

        Functions\when( 'current_time' )->justReturn( '2026-06-15 08:00:00' );
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

    public function test_import_section_upserts_to_explorers_table(): void {
        $mock_members = [
            [
                'member_id'    => 1001,
                'first_name'   => 'Alice',
                'last_name'    => 'Alpha',
                'email'        => 'alice@example.com',
                'parent_email' => 'p.alice@example.com',
                'patrol'       => 'Bears',
            ],
        ];

        $this->api_client->shouldReceive( 'get_section_participants' )
            ->with( 43105, 0 )
            ->once()
            ->andReturn( $mock_members );

        $this->wpdb->shouldReceive( 'replace' )
            ->once()
            ->with(
                'wp_ems_osm_explorers',
                Mockery::on( function ( $data ) {
                    return $data['scout_id'] === 1001
                        && $data['first_name'] === 'Alice'
                        && $data['email'] === 'alice@example.com'
                        && $data['section_id'] === 43105;
                } ),
                Mockery::any()
            );

        $importer = new OSM_Section_Importer( $this->api_client );
        $importer->import_section( 43105 );
        $this->assertTrue( true );
    }

    public function test_member_with_zero_scout_id_is_skipped(): void {
        $mock_members = [
            [
                'member_id'    => 0,
                'first_name'   => 'Bad',
                'last_name'    => 'Row',
                'email'        => '',
                'parent_email' => '',
                'patrol'       => '',
            ],
        ];

        $this->api_client->shouldReceive( 'get_section_participants' )->withAnyArgs()->andReturn( $mock_members );

        $this->wpdb->shouldReceive( 'replace' )->never();

        $importer = new OSM_Section_Importer( $this->api_client );
        $importer->import_section( 43105 );
        $this->assertTrue( true );
    }

    public function test_member_with_missing_email_is_handled_gracefully(): void {
        $mock_members = [
            [
                'member_id'    => 1001,
                'first_name'   => 'Alice',
                'last_name'    => 'Alpha',
                'email'        => '',
                'parent_email' => '',
                'patrol'       => 'Bears',
            ],
        ];

        $this->api_client->shouldReceive( 'get_section_participants' )->withAnyArgs()->andReturn( $mock_members );

        $this->wpdb->shouldReceive( 'replace' )
            ->once()
            ->with(
                'wp_ems_osm_explorers',
                Mockery::on( fn( $data ) => $data['scout_id'] === 1001 && $data['email'] === '' ),
                Mockery::any()
            );

        $importer = new OSM_Section_Importer( $this->api_client );
        $importer->import_section( 43105 );
        $this->assertTrue( true );
    }
}
