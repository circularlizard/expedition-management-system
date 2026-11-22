<?php
declare(strict_types=1);

namespace EMS\Tests\Unit\Modules\Expeditions;

use Brain\Monkey\Functions;
use EMS\Data\OSM_Explorer_Repository;
use EMS\Data\Signup_Repository;
use EMS\Data\Team_Member_Repository;
use EMS\Data\Team_Repository;
use EMS\Modules\Expeditions\Expedition_Admin_Controller;
use EMS\Tests\EMSTestCase;
use Mockery;

class Expedition_Admin_ControllerKanbanTest extends EMSTestCase {

	public function test_update_signup_kanban_dispatches_object_payload_and_returns_200(): void {
		$signups = Mockery::mock( Signup_Repository::class );
		$signups->shouldReceive( 'get_expedition_signup' )
			->once()
			->with( 54 )
			->andReturn(
				array(
					'id'           => 54,
					'scout_id'     => 12345,
					'kanban_stage' => 'new',
				)
			);
		$signups->shouldReceive( 'update_kanban_stage' )
			->once()
			->with( 54, 'expedition', 'team_allocated', null )
			->andReturn( true );

		Functions\when( 'get_current_user_id' )->justReturn( 7 );

		$dispatched = array();
		Functions\when( 'do_action' )->alias(
			static function ( string $event, mixed $payload = null ) use ( &$dispatched ): void {
				$dispatched[] = array(
					'event'   => $event,
					'payload' => $payload,
				);
			}
		);

		$controller = new Expedition_Admin_Controller(
			Mockery::mock( \EMS\Data\Expedition_Repository::class ),
			Mockery::mock( Team_Repository::class ),
			Mockery::mock( Team_Member_Repository::class ),
			Mockery::mock( OSM_Explorer_Repository::class ),
			Mockery::mock( \EMS\Data\OSM_Event_Repository::class ),
			$signups
		);

		$request = Mockery::mock( \WP_REST_Request::class );
		$request->shouldReceive( 'get_json_params' )->andReturn(
			array(
				'type'         => 'expedition',
				'kanban_stage' => 'team_allocated',
			)
		);
		$request->shouldReceive( 'get_param' )->with( 'type' )->andReturn( null );
		$request->shouldReceive( 'get_param' )->with( 'id' )->andReturn( 54 );

		$response = $controller->update_signup_kanban( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['updated'] );
		$this->assertSame( 'team_allocated', $data['kanban_stage'] );

		$this->assertCount( 1, $dispatched );
		$this->assertSame( 'ems.signup.processed', $dispatched[0]['event'] );
		$this->assertIsObject( $dispatched[0]['payload'] );
		$this->assertSame( 54, $dispatched[0]['payload']->signup_id );
	}

	public function test_update_expedition_place_allocated_returns_200(): void {
		$signups = Mockery::mock( Signup_Repository::class );
		$signups->shouldReceive( 'get_expedition_signup' )
			->once()
			->with( 54 )
			->andReturn( array( 'id' => 54 ) );
		$signups->shouldReceive( 'set_participant_place_allocated' )
			->once()
			->with( 54, true, null )
			->andReturn( true );

		$controller = new Expedition_Admin_Controller(
			Mockery::mock( \EMS\Data\Expedition_Repository::class ),
			Mockery::mock( Team_Repository::class ),
			Mockery::mock( Team_Member_Repository::class ),
			Mockery::mock( OSM_Explorer_Repository::class ),
			Mockery::mock( \EMS\Data\OSM_Event_Repository::class ),
			$signups
		);

		$request = Mockery::mock( \WP_REST_Request::class );
		$request->shouldReceive( 'get_param' )->with( 'id' )->andReturn( 54 );
		$request->shouldReceive( 'get_json_params' )->andReturn(
			array( 'participant_place_allocated' => true )
		);

		$response = $controller->update_expedition_place_allocated( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['participant_place_allocated'] );
	}

	public function test_participant_place_allocated_syncs_matching_expedition_signups(): void {
		$signups = Mockery::mock( Signup_Repository::class );
		$signups->shouldReceive( 'get_participant_signup' )
			->once()
			->with( 12 )
			->andReturn(
				array(
					'id'           => 12,
					'scout_id'     => 12345,
					'dofe_level'   => 'silver',
					'kanban_stage' => 'new',
				)
			);
		$signups->shouldReceive( 'update_kanban_stage' )
			->once()
			->with( 12, 'participant', 'place_allocated', null )
			->andReturn( true );
		$signups->shouldReceive( 'sync_participant_place_allocation_to_expeditions' )
			->once()
			->with( 12, true )
			->andReturn( array( 54 ) );

		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'do_action' )->justReturn( null );

		$controller = new Expedition_Admin_Controller(
			Mockery::mock( \EMS\Data\Expedition_Repository::class ),
			Mockery::mock( Team_Repository::class ),
			Mockery::mock( Team_Member_Repository::class ),
			Mockery::mock( OSM_Explorer_Repository::class ),
			Mockery::mock( \EMS\Data\OSM_Event_Repository::class ),
			$signups
		);

		$request = Mockery::mock( \WP_REST_Request::class );
		$request->shouldReceive( 'get_json_params' )->andReturn(
			array(
				'type'         => 'participant',
				'kanban_stage' => 'place_allocated',
			)
		);
		$request->shouldReceive( 'get_param' )->with( 'type' )->andReturn( null );
		$request->shouldReceive( 'get_param' )->with( 'id' )->andReturn( 12 );

		$response = $controller->update_signup_kanban( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 54 ), $response->get_data()['synced_expedition_ids'] );
	}
}
