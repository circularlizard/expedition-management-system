<?php
namespace EMS\Tests\Unit\Integrations;

use EMS\Integrations\Pushback_Sync_Manager;
use EMS\Integrations\OSM_API_Client;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;
use Mockery;

class Pushback_Sync_ManagerTest extends EMSTestCase {

	private $api_client;
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->api_client = Mockery::mock( OSM_API_Client::class );
		
		$this->wpdb = Mockery::mock( 'wpdb' );
		$this->wpdb->prefix = 'wp_';
		$this->wpdb->last_error = '';
		$this->wpdb->shouldReceive( 'prepare' )->byDefault()->andReturnUsing( fn( $sql, ...$args ) => vsprintf( str_replace( '%d', '%s', $sql ), $args ) );
		$GLOBALS['wpdb'] = $this->wpdb;

		// Mock default OIDC / startup calls
		$this->api_client->shouldReceive( 'get_data_payload' )->byDefault()->andReturn([
			'data' => [
				'globals' => [
					'terms' => [
						'101' => [
							[
								'termid' => '5001',
								'sectionid' => '101',
								'name' => 'Spring 2026',
								'startdate' => '2026-01-01',
								'enddate' => '2026-12-31'
							]
						]
					]
				]
			]
		]);
	}

	public function test_get_preview_proposes_creation_if_flexi_record_missing(): void {
		Functions\when( 'get_option' )->alias( function( $key, $default = false ) {
			if ( $key === 'ems_osm_flexi_record_101' ) {
				return false;
			}
			if ( $key === 'ems_managed_sections' ) {
				return [ 101 => [ 'name' => 'Explorers', 'type' => 'explorers' ] ];
			}
			return $default;
		} );

		$this->api_client->shouldReceive( 'get_flexi_records' )
			->with( 101 )
			->once()
			->andReturn( [] );

		$manager = new Pushback_Sync_Manager( $this->api_client );
		$preview = $manager->get_preview( 101 );

		$this->assertFalse( $preview['flexi_record']['exists'] );
		$this->assertSame( '2026 Expeditions', $preview['flexi_record']['proposed_name'] );
	}

	public function test_get_preview_proposes_missing_columns_if_record_exists(): void {
		Functions\when( 'get_option' )->alias( function( $key, $default = false ) {
			if ( $key === 'ems_osm_flexi_record_101' ) {
				return 73848;
			}
			if ( $key === 'ems_managed_sections' ) {
				return [ 101 => [ 'name' => 'Explorers', 'type' => 'explorers' ] ];
			}
			return $default;
		} );

		$this->api_client->shouldReceive( 'get_flexi_record_structure' )
			->with( 101, 73848 )
			->once()
			->andReturn( [
				'config' => json_encode( [
					[ 'id' => 'f_9', 'name' => 'PRACTICE GROUPS' ]
				] )
			] );

		$this->api_client->shouldReceive( 'get_flexi_record_data' )->andReturn( [ 'items' => [] ] );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( [] );

		$manager = new Pushback_Sync_Manager( $this->api_client );
		$preview = $manager->get_preview( 101 );

		$this->assertTrue( $preview['flexi_record']['exists'] );
		$this->assertContains( 'PRACTICE ACCEPTED', $preview['flexi_record']['missing_columns'] );
		$this->assertNotContains( 'PRACTICE GROUPS', $preview['flexi_record']['missing_columns'] );
	}

	public function test_get_preview_proposes_invitations_for_null_attendance(): void {
		Functions\when( 'get_option' )->alias( function( $key, $default = false ) {
			if ( $key === 'ems_osm_flexi_record_101' ) return 73848;
			if ( $key === 'ems_managed_sections' ) return [ 101 => [ 'name' => 'Explorers', 'type' => 'explorers' ] ];
			return $default;
		} );

		$this->api_client->shouldReceive( 'get_flexi_record_structure' )->andReturn( [
			'config' => json_encode( [
				[ 'id' => 'f_9', 'name' => 'PRACTICE GROUPS' ],
				[ 'id' => 'f_10', 'name' => 'PRACTICE ACCEPTED' ],
				[ 'id' => 'f_11', 'name' => 'QUALIFIER ACCEPTED' ],
				[ 'id' => 'f_12', 'name' => 'QUALIFIER GROUPS' ],
				[ 'id' => 'f_18', 'name' => 'TRAINING DAY' ],
				[ 'id' => 'f_13', 'name' => 'FIRST AID' ]
			] )
		] );

		$this->api_client->shouldReceive( 'get_flexi_record_data' )->andReturn( [ 'items' => [] ] );

		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing( function( $query ) {
			if ( strpos( $query, 'ems_team_members' ) !== false ) {
				return [
					(object) [
						'scout_id' => 30001,
						'first_name' => 'Alice',
						'last_name' => 'Smith',
						'team_code' => 'HGP1-1',
						'event_type' => 'practice',
						'osm_event_id' => 50001,
						'event_date' => '2026-05-29',
						'first_aid_level' => 'first_response'
					]
				];
			}
			return [];
		} );

		$this->api_client->shouldReceive( 'get_event_attendance' )
			->with( 50001, 5001 )
			->once()
			->andReturn( [
				[
					'member_id' => 30001,
					'attending' => null
				]
			] );

		$manager = new Pushback_Sync_Manager( $this->api_client );
		$preview = $manager->get_preview( 101 );

		$this->assertCount( 1, $preview['events'] );
		$this->assertSame( 50001, $preview['events'][0]['event_id'] );
		$this->assertCount( 1, $preview['events'][0]['proposed_invites'] );
		$this->assertSame( 30001, $preview['events'][0]['proposed_invites'][0]['scout_id'] );
	}

	public function test_get_preview_skips_invitations_for_existing_status(): void {
		Functions\when( 'get_option' )->alias( function( $key, $default = false ) {
			if ( $key === 'ems_osm_flexi_record_101' ) return 73848;
			if ( $key === 'ems_managed_sections' ) return [ 101 => [ 'name' => 'Explorers', 'type' => 'explorers' ] ];
			return $default;
		} );

		$this->api_client->shouldReceive( 'get_flexi_record_structure' )->andReturn( [ 'config' => '[]' ] );
		$this->api_client->shouldReceive( 'get_flexi_record_data' )->andReturn( [ 'items' => [] ] );

		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing( function( $query ) {
			if ( strpos( $query, 'ems_team_members' ) !== false ) {
				return [
					(object) [
						'scout_id' => 30001,
						'first_name' => 'Alice',
						'last_name' => 'Smith',
						'team_code' => 'HGP1-1',
						'event_type' => 'practice',
						'osm_event_id' => 50001,
						'event_date' => '2026-05-29',
						'first_aid_level' => ''
					]
				];
			}
			return [];
		} );

		$this->api_client->shouldReceive( 'get_event_attendance' )
			->with( 50001, 5001 )
			->once()
			->andReturn( [
				[
					'member_id' => 30001,
					'attending' => 'yes'
				]
			] );

		$manager = new Pushback_Sync_Manager( $this->api_client );
		$preview = $manager->get_preview( 101 );

		$this->assertCount( 1, $preview['events'] );
		$this->assertSame( 'None', $preview['events'][0]['proposed_invites'][0]['action'] );
	}

	public function test_get_preview_proposes_flexi_record_value_changes(): void {
		Functions\when( 'get_option' )->alias( function( $key, $default = false ) {
			if ( $key === 'ems_osm_flexi_record_101' ) return 73848;
			if ( $key === 'ems_managed_sections' ) return [ 101 => [ 'name' => 'Explorers', 'type' => 'explorers' ] ];
			return $default;
		} );

		$this->api_client->shouldReceive( 'get_flexi_record_structure' )->andReturn( [
			'config' => json_encode( [
				[ 'id' => 'f_9', 'name' => 'PRACTICE GROUPS' ],
				[ 'id' => 'f_10', 'name' => 'PRACTICE ACCEPTED' ]
			] )
		] );

		$this->api_client->shouldReceive( 'get_flexi_record_data' )->andReturn( [
			'items' => [
				[
					'scoutid' => '30001',
					'f_9' => 'HGP1-1',
					'f_10' => 'HGP1-1 29/5 Y'
				]
			]
		] );

		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing( function( $query ) {
			if ( strpos( $query, 'ems_team_members' ) !== false ) {
				return [
					(object) [
						'scout_id' => 30001,
						'first_name' => 'Alice',
						'last_name' => 'Smith',
						'team_code' => 'HGP1-2',
						'event_type' => 'practice',
						'osm_event_id' => null,
						'event_date' => '2026-05-29',
						'first_aid_level' => ''
					]
				];
			}
			return [];
		} );

		$manager = new Pushback_Sync_Manager( $this->api_client );
		$preview = $manager->get_preview( 101 );

		$this->assertCount( 2, $preview['flexi_record']['updates'] );
		$update = $preview['flexi_record']['updates'][0];
		$this->assertSame( 30001, $update['scout_id'] );
		$this->assertSame( 'f_9', $update['column'] );
		$this->assertSame( 'HGP1-1', $update['current_value'] );
		$this->assertSame( 'HGP1-2', $update['proposed_value'] );
	}

	public function test_get_preview_flags_attendance_inconsistencies(): void {
		Functions\when( 'get_option' )->alias( function( $key, $default = false ) {
			if ( $key === 'ems_osm_flexi_record_101' ) return 73848;
			if ( $key === 'ems_managed_sections' ) return [ 101 => [ 'name' => 'Explorers', 'type' => 'explorers' ] ];
			return $default;
		} );

		$this->api_client->shouldReceive( 'get_flexi_record_structure' )->andReturn( [ 'config' => '[]' ] );
		$this->api_client->shouldReceive( 'get_flexi_record_data' )->andReturn( [ 'items' => [] ] );

		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing( function( $query ) {
			if ( strpos( $query, 'ems_team_members' ) !== false ) {
				return [
					// 30001: Assigned in EMS but declined ('no') in OSM
					(object) [
						'scout_id' => 30001,
						'first_name' => 'Alice',
						'last_name' => 'Smith',
						'team_code' => 'HGP1-1',
						'event_type' => 'practice',
						'osm_event_id' => 50001,
						'event_date' => '2026-05-29',
						'first_aid_level' => ''
					]
				];
			}
			return [];
		} );

		$this->api_client->shouldReceive( 'get_event_attendance' )
			->with( 50001, 5001 )
			->once()
			->andReturn( [
				[
					'member_id' => 30001,
					'first_name' => 'Alice',
					'last_name' => 'Smith',
					'attending' => 'no'
				],
				// 30002: Not in EMS but accepted ('yes') in OSM
				[
					'member_id' => 30002,
					'first_name' => 'Bob',
					'last_name' => 'Jones',
					'attending' => 'yes'
				]
			] );

		$manager = new Pushback_Sync_Manager( $this->api_client );
		$preview = $manager->get_preview( 101 );

		$this->assertCount( 1, $preview['events'] );
		$invites = $preview['events'][0]['proposed_invites'];
		$this->assertCount( 2, $invites );

		// Check Alice (assigned in EMS, declined in OSM)
		$alice = $invites[0];
		$this->assertSame( 30001, $alice['scout_id'] );
		$this->assertSame( 'no', $alice['status'] );
		$this->assertSame( 'None', $alice['action'] );
		$this->assertSame( 'Declined in OSM but assigned in EMS', $alice['inconsistency'] );

		// Check Bob (not in EMS, attending in OSM)
		$bob = $invites[1];
		$this->assertSame( 30002, $bob['scout_id'] );
		$this->assertSame( 'yes', $bob['status'] );
		$this->assertSame( 'None', $bob['action'] );
		$this->assertSame( 'Attending in OSM but not assigned in EMS', $bob['inconsistency'] );
	}
}
