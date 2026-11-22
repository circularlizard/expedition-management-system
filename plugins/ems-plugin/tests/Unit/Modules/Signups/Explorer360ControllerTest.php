<?php
declare(strict_types=1);

namespace EMS\Tests\Unit\Modules\Signups;

use Brain\Monkey\Functions;
use EMS\Data\Team_Member_Repository;
use EMS\Data\Team_Repository;
use EMS\Integrations\TutorLMS_Client;
use EMS\Modules\Signups\Admin_View_Controller;
use EMS\Tests\EMSTestCase;
use Mockery;

class Explorer360ControllerTest extends EMSTestCase {

	public function test_get_explorer_360_detects_contact_discrepancies_and_orders_signups(): void {
		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->posts  = 'wp_posts';

		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $sql, ...$args ) => $sql );
		$wpdb->shouldReceive( 'get_row' )->andReturn(
			array(
				'scout_id'        => 30001,
				'first_name'      => 'Alex',
				'last_name'       => 'Brown',
				'email1'          => 'osm.alex@example.com',
				'p1_email1'       => 'osm.parent@example.com',
				'patrol'          => 'Kelso ESU',
				'dofe_number'     => '12345',
				'first_aid_level' => 'first_response',
			)
		);

		$call = 0;
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			static function () use ( &$call ) {
				$call++;
				if ( $call === 1 ) {
					// participant signups
					return array(
						array(
							'id'                  => 1,
							'scout_id'            => 30001,
							'explorer_first_name' => 'Alex',
							'explorer_last_name'  => 'Brown',
							'explorer_email'      => 'form.alex@example.com',
							'parent_email'        => 'osm.parent@example.com',
							'dofe_level'          => 'silver',
							'dofe_number'         => '12345',
							'signup_status'       => 'archived',
							'kanban_stage'        => 'place_allocated',
							'created_at'          => '2026-01-01 10:00:00',
						),
					);
				}
				if ( $call === 2 ) {
					// expedition signups
					return array(
						array(
							'id'                          => 2,
							'scout_id'                    => 30001,
							'explorer_first_name'         => 'Alex',
							'explorer_last_name'          => 'Brown',
							'explorer_email'              => 'form.alex@example.com',
							'parent_email'                => 'osm.parent@example.com',
							'dofe_level'                  => 'silver',
							'dofe_number'                 => '12345',
							'signup_status'               => 'submitted',
							'kanban_stage'                => 'new',
							'participant_place_allocated' => 1,
							'created_at'                  => '2026-02-01 10:00:00',
						),
					);
				}
				return array();
			}
		);

		Functions\when( 'get_users' )->justReturn( array() );

		$controller = new Admin_View_Controller(
			Mockery::mock( Team_Repository::class ),
			Mockery::mock( Team_Member_Repository::class ),
			Mockery::mock( TutorLMS_Client::class )
		);

		$request = Mockery::mock( \WP_REST_Request::class );
		$request->shouldReceive( 'get_param' )->with( 'scout_id' )->andReturn( 30001 );
		$request->shouldReceive( 'get_param' )->with( 'signup_type' )->andReturn( '' );
		$request->shouldReceive( 'get_param' )->with( 'signup_id' )->andReturn( 0 );

		$response = $controller->get_explorer_360( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		// Active expedition comes first, archived participant place comes second.
		$this->assertSame( 'expedition', $data['signups'][0]['type'] );
		$this->assertSame( 'participant', $data['signups'][1]['type'] );

		// Contact discrepancy detected for active expedition form email vs OSM canonical.
		$this->assertCount( 1, $data['contacts']['discrepancies'] );
		$this->assertSame( 'explorer_email', $data['contacts']['discrepancies'][0]['field'] );
		$this->assertSame( 'osm.alex@example.com', $data['contacts']['discrepancies'][0]['osm_value'] );
		$this->assertSame( 'form.alex@example.com', $data['contacts']['discrepancies'][0]['form_value'] );
	}

	public function test_get_explorer_360_ignores_emails_matching_any_osm_slot(): void {
		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->posts  = 'wp_posts';

		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $sql, ...$args ) => $sql );
		$wpdb->shouldReceive( 'get_row' )->andReturn(
			array(
				'scout_id'        => 30002,
				'first_name'      => 'Alex',
				'last_name'       => 'Brown',
				'email1'          => 'osm.alex@example.com',
				'email2'          => 'alt.alex@example.com',
				'p1_email1'       => 'parent1@example.com',
				'p2_email1'       => 'parent2@example.com',
				'patrol'          => 'Kelso ESU',
				'dofe_number'     => '12345',
				'first_aid_level' => 'first_response',
			)
		);

		$call = 0;
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			static function () use ( &$call ) {
				$call++;
				if ( $call === 2 ) {
					return array(
						array(
							'id'                  => 10,
							'scout_id'            => 30002,
							'explorer_first_name' => 'Alex',
							'explorer_last_name'  => 'Brown',
							'explorer_email'      => 'alt.alex@example.com',
							'parent_email'        => 'parent2@example.com',
							'dofe_level'          => 'silver',
							'dofe_number'         => '12345',
							'signup_status'       => 'submitted',
							'kanban_stage'        => 'new',
							'created_at'          => '2026-02-01 10:00:00',
						),
					);
				}
				return array();
			}
		);

		Functions\when( 'get_users' )->justReturn( array() );

		$controller = new Admin_View_Controller(
			Mockery::mock( Team_Repository::class ),
			Mockery::mock( Team_Member_Repository::class ),
			Mockery::mock( TutorLMS_Client::class )
		);

		$request = Mockery::mock( \WP_REST_Request::class );
		$request->shouldReceive( 'get_param' )->with( 'scout_id' )->andReturn( 30002 );
		$request->shouldReceive( 'get_param' )->with( 'signup_type' )->andReturn( '' );
		$request->shouldReceive( 'get_param' )->with( 'signup_id' )->andReturn( 0 );

		$response = $controller->get_explorer_360( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 0, $data['contacts']['discrepancies'] );
	}
}
