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

	public function test_get_preview_proposes_invitations_for_null_attendance(): void {
		Functions\when( 'get_option' )->alias( function( $key, $default = false ) {
			if ( $key === 'ems_managed_sections' ) return [ 101 => [ 'name' => 'Explorers', 'type' => 'explorers' ] ];
			return $default;
		} );

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
			if ( $key === 'ems_managed_sections' ) return [ 101 => [ 'name' => 'Explorers', 'type' => 'explorers' ] ];
			return $default;
		} );

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

	public function test_get_preview_flags_attendance_inconsistencies(): void {
		Functions\when( 'get_option' )->alias( function( $key, $default = false ) {
			if ( $key === 'ems_managed_sections' ) return [ 101 => [ 'name' => 'Explorers', 'type' => 'explorers' ] ];
			return $default;
		} );

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

	public function test_execute_sync_calls_update_endpoints_for_updates_and_invitations(): void {
		Functions\when( 'get_option' )->alias( function( $key, $default = false ) {
			if ( $key === 'ems_writeback_section_id' ) return 101;
			if ( $key === 'ems_managed_sections' ) return [ 101 => [ 'name' => 'Explorers', 'type' => 'explorers' ] ];
			return $default;
		} );

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

		$this->api_client->shouldReceive( 'update_event_attendance' )
			->with( 101, 50001, [ 30001 ] )
			->once()
			->andReturn( [ 'error' => false ] );

		$manager = new Pushback_Sync_Manager( $this->api_client );
		$result = $manager->execute_sync( 101 );

		$this->assertSame( 1, $result['event_invites_count'] );
	}
}
