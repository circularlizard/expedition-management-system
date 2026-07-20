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
		$this->wpdb->shouldReceive( 'get_row' )->byDefault()->andReturn( (object) [ 'name' => 'Mock OSM Event' ] );
		$this->wpdb->shouldReceive( 'get_col' )->byDefault()->andReturn( [ 42 ] );
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
						'first_aid_level' => 'first_response',
						'section_id' => 101,
						'event_code' => 'HGP1'
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
						'first_aid_level' => '',
						'section_id' => 101,
						'event_code' => 'HGP1'
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
						'first_aid_level' => '',
						'section_id' => 101,
						'event_code' => 'HGP1'
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
		$this->assertTrue( $update['overwrite'] );

		$update_accepted = $preview['flexi_record']['updates'][1];
		$this->assertSame( 'f_10', $update_accepted['column'] );
		$this->assertSame( 'HGP1-1 29/5 Y', $update_accepted['current_value'] );
		$this->assertSame( 'HGP1 29/5', $update_accepted['proposed_value'] );
		$this->assertTrue( $update_accepted['overwrite'] );
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
						'first_aid_level' => '',
						'section_id' => 101,
						'event_code' => 'HGP1'
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

	public function test_ensure_flexi_record_creates_if_not_exists(): void {
		Functions\when( 'get_option' )->alias( function( $key, $default = false ) {
			if ( $key === 'ems_writeback_section_id' ) return 101;
			if ( $key === 'ems_osm_writeback_flexi_record_id' ) return false;
			return $default;
		} );

		$stored = [];
		Functions\when( 'update_option' )->alias( static function ( $k, $v ) use ( &$stored ) { $stored[$k] = $v; return true; } );

		// get_flexi_records returns no records with 2026 Expeditions name initially
		$this->api_client->shouldReceive( 'get_flexi_records' )
			->with( 101 )
			->once()
			->andReturn( [] );

		// create_flexi_record is called
		$this->api_client->shouldReceive( 'create_flexi_record' )
			->with( 101, '2026 Expeditions' )
			->once()
			->andReturn( [ 'id' => 75534 ] );

		// get_flexi_record_structure returns config with f_9 and f_10 columns, missing others
		$this->api_client->shouldReceive( 'get_flexi_record_structure' )
			->with( 101, 75534 )
			->once()
			->andReturn( [
				'config' => json_encode( [
					[ 'id' => 'f_9', 'name' => 'PRACTICE GROUPS' ],
					[ 'id' => 'f_10', 'name' => 'PRACTICE ACCEPTED' ],
					[ 'id' => 'f_11', 'name' => 'QUALIFIER GROUPS' ],
					[ 'id' => 'f_12', 'name' => 'QUALIFIER ACCEPTED' ],
					[ 'id' => 'f_18', 'name' => 'TRAINING DAY' ],
					[ 'id' => 'f_13', 'name' => 'FIRST AID' ]
				] )
			] );

		$manager = new Pushback_Sync_Manager( $this->api_client );
		$flexi_id = $manager->ensure_flexi_record( 101 );

		$this->assertSame( 75534, $flexi_id );
		$this->assertSame( 75534, $stored['ems_osm_writeback_flexi_record_id'] );
		$this->assertSame( 75534, $stored['ems_osm_flexi_record_101'] );
	}

	public function test_ensure_flexi_record_adds_missing_columns(): void {
		Functions\when( 'get_option' )->alias( function( $key, $default = false ) {
			if ( $key === 'ems_writeback_section_id' ) return 101;
			if ( $key === 'ems_osm_writeback_flexi_record_id' ) return 73848;
			return $default;
		} );

		$this->api_client->shouldReceive( 'get_flexi_records' )
			->with( 101 )
			->once()
			->andReturn( [ [ 'id' => 73848, 'name' => '2026 Expeditions' ] ] );

		// missing QUALIFIER GROUPS and FIRST AID
		$this->api_client->shouldReceive( 'get_flexi_record_structure' )
			->with( 101, 73848 )
			->once()
			->andReturn( [
				'config' => json_encode( [
					[ 'id' => 'f_9', 'name' => 'PRACTICE GROUPS' ],
					[ 'id' => 'f_10', 'name' => 'PRACTICE ACCEPTED' ],
					[ 'id' => 'f_12', 'name' => 'QUALIFIER ACCEPTED' ],
					[ 'id' => 'f_18', 'name' => 'TRAINING DAY' ]
				] )
			] );

		// Expectations to create the missing columns
		$this->api_client->shouldReceive( 'add_flexi_record_column' )
			->with( 101, 73848, 'QUALIFIER GROUPS' )
			->once();

		$this->api_client->shouldReceive( 'add_flexi_record_column' )
			->with( 101, 73848, 'FIRST AID' )
			->once();

		$manager = new Pushback_Sync_Manager( $this->api_client );
		$flexi_id = $manager->ensure_flexi_record( 101 );

		$this->assertSame( 73848, $flexi_id );
	}

	public function test_execute_sync_calls_update_endpoints_for_updates_and_invitations(): void {
		Functions\when( 'get_option' )->alias( function( $key, $default = false ) {
			if ( $key === 'ems_writeback_section_id' ) return 101;
			if ( $key === 'ems_osm_writeback_flexi_record_id' ) return 73848;
			if ( $key === 'ems_managed_sections' ) return [ 101 => [ 'name' => 'Explorers', 'type' => 'explorers' ] ];
			return $default;
		} );

		$this->api_client->shouldReceive( 'get_flexi_records' )
			->with( 101 )
			->andReturn( [ [ 'id' => 73848, 'name' => '2026 Expeditions' ] ] );

		$this->api_client->shouldReceive( 'get_flexi_record_structure' )
			->with( 101, 73848 )
			->andReturn( [
				'config' => json_encode( [
					[ 'id' => 'f_9', 'name' => 'PRACTICE GROUPS' ],
					[ 'id' => 'f_10', 'name' => 'PRACTICE ACCEPTED' ],
					[ 'id' => 'f_11', 'name' => 'QUALIFIER GROUPS' ],
					[ 'id' => 'f_12', 'name' => 'QUALIFIER ACCEPTED' ],
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
						'first_aid_level' => '',
						'section_id' => 101,
						'event_code' => 'HGP1'
					]
				];
			}
			return [];
		} );

		$this->api_client->shouldReceive( 'get_event_attendance' )
			->with( 50001, 5001 )
			->andReturn( [ [ 'member_id' => 30001, 'attending' => null ] ] );

		$this->api_client->shouldReceive( 'update_flexi_record_data' )
			->with( 101, 73848, [ 'scouts' => [ 30001 ], 'col' => 'f_9', 'value' => 'HGP1-1' ] )
			->once()
			->andReturn( [ 'error' => false ] );

		$this->api_client->shouldReceive( 'update_flexi_record_data' )
			->with( 101, 73848, [ 'scouts' => [ 30001 ], 'col' => 'f_10', 'value' => 'HGP1 29/5' ] )
			->once()
			->andReturn( [ 'error' => false ] );

		$this->api_client->shouldReceive( 'update_event_attendance' )
			->with( 101, 50001, [ 30001 ] )
			->once()
			->andReturn( [ 'error' => false ] );

		$manager = new Pushback_Sync_Manager( $this->api_client );
		$result = $manager->execute_sync( 101 );

		$this->assertSame( 2, $result['flexi_updates_count'] );
		$this->assertSame( 1, $result['event_invites_count'] );
	}
}
