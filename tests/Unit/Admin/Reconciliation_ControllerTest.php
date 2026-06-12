<?php
namespace EMS\Tests\Unit\Admin;

use EMS\Admin\Reconciliation_Controller;
use EMS\Integrations\OSM_API_Client;
use EMS\Integrations\Gravity_Forms_Client;
use EMS\Tests\EMSTestCase;
use Mockery;

class Reconciliation_ControllerTest extends EMSTestCase {
    private OSM_API_Client $osm_client;
    private Gravity_Forms_Client $gf_client;

    // OSM has john + jane; GF has john + sam
    // → john is matched, jane is only_in_osm, sam is only_in_gf
    private array $osm_members = [
        [ 'member_id' => 1001, 'first_name' => 'John', 'last_name' => 'Explorer', 'email' => 'john.explorer@example.com' ],
        [ 'member_id' => 1002, 'first_name' => 'Jane', 'last_name' => 'Scout',    'email' => 'jane.scout@example.com' ],
    ];

    private array $gf_entries = [
        [ 'id' => '1', 'first_name' => 'John', 'last_name' => 'Explorer', 'email' => 'john.explorer@example.com' ],
        [ 'id' => '2', 'first_name' => 'Sam',  'last_name' => 'Wanderer', 'email' => 'sam.wanderer@example.com' ],
    ];

    protected function setUp(): void {
        parent::setUp();
        $this->osm_client = Mockery::mock( OSM_API_Client::class );
        $this->gf_client  = Mockery::mock( Gravity_Forms_Client::class );
    }

    protected function tearDown(): void {
        Mockery::close();
        parent::tearDown();
    }

    private function make_controller(): Reconciliation_Controller {
        $this->osm_client->shouldReceive( 'get_section_participants' )
            ->andReturn( $this->osm_members );
        $this->gf_client->shouldReceive( 'get_entries' )
            ->andReturn( $this->gf_entries );
        return new Reconciliation_Controller( $this->osm_client, $this->gf_client );
    }

    public function test_matched_contains_explorer_present_in_both(): void {
        $result = $this->make_controller()->reconcile( 99001, 1 );
        $emails = array_column( $result['matched'], 'email' );
        $this->assertContains( 'john.explorer@example.com', $emails );
    }

    public function test_only_in_osm_contains_member_missing_from_gf(): void {
        $result = $this->make_controller()->reconcile( 99001, 1 );
        $emails = array_column( $result['only_in_osm'], 'email' );
        $this->assertContains( 'jane.scout@example.com', $emails );
    }

    public function test_only_in_gf_contains_entry_missing_from_osm(): void {
        $result = $this->make_controller()->reconcile( 99001, 1 );
        $emails = array_column( $result['only_in_gf'], 'email' );
        $this->assertContains( 'sam.wanderer@example.com', $emails );
    }

    public function test_matched_does_not_appear_in_only_in_osm(): void {
        $result        = $this->make_controller()->reconcile( 99001, 1 );
        $osm_only_mails = array_column( $result['only_in_osm'], 'email' );
        $this->assertNotContains( 'john.explorer@example.com', $osm_only_mails );
    }

    public function test_matched_does_not_appear_in_only_in_gf(): void {
        $result       = $this->make_controller()->reconcile( 99001, 1 );
        $gf_only_mails = array_column( $result['only_in_gf'], 'email' );
        $this->assertNotContains( 'john.explorer@example.com', $gf_only_mails );
    }

    public function test_result_has_all_three_keys(): void {
        $result = $this->make_controller()->reconcile( 99001, 1 );
        $this->assertArrayHasKey( 'matched',     $result );
        $this->assertArrayHasKey( 'only_in_osm', $result );
        $this->assertArrayHasKey( 'only_in_gf',  $result );
    }

    public function test_matching_is_case_insensitive(): void {
        $this->osm_client->shouldReceive( 'get_section_participants' )
            ->andReturn( [ [ 'member_id' => 1003, 'first_name' => 'Alice', 'last_name' => 'Smith', 'email' => 'Alice.Smith@example.com' ] ] );
        $this->gf_client->shouldReceive( 'get_entries' )
            ->andReturn( [ [ 'id' => '3', 'first_name' => 'Alice', 'last_name' => 'Smith', 'email' => 'alice.smith@example.com' ] ] );

        $ctrl   = new Reconciliation_Controller( $this->osm_client, $this->gf_client );
        $result = $ctrl->reconcile( 99001, 1 );

        $this->assertCount( 1, $result['matched'] );
        $this->assertEmpty( $result['only_in_osm'] );
        $this->assertEmpty( $result['only_in_gf'] );
    }

    public function test_empty_inputs_return_empty_result(): void {
        $this->osm_client->shouldReceive( 'get_section_participants' )->andReturn( [] );
        $this->gf_client->shouldReceive( 'get_entries' )->andReturn( [] );

        $ctrl   = new Reconciliation_Controller( $this->osm_client, $this->gf_client );
        $result = $ctrl->reconcile( 99001, 1 );

        $this->assertEmpty( $result['matched'] );
        $this->assertEmpty( $result['only_in_osm'] );
        $this->assertEmpty( $result['only_in_gf'] );
    }
}
