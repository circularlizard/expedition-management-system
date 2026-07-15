<?php
namespace EMS\Tests\Unit\Admin;

use EMS\Admin\Sync_Preview_Controller;
use EMS\Integrations\Pushback_Sync_Manager;
use EMS\Integrations\OSM_API_Client;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;
use Mockery;

class Sync_Preview_ControllerTest extends EMSTestCase {

	private $api_client;
	private $sync_manager;

	protected function setUp(): void {
		parent::setUp();
		$this->api_client   = Mockery::mock( OSM_API_Client::class );
		$this->sync_manager = Mockery::mock( Pushback_Sync_Manager::class );
	}

	public function test_get_sync_preview_requires_manage_options_permission(): void {
		$controller = new Sync_Preview_Controller( $this->api_client, $this->sync_manager );

		Functions\when( 'current_user_can' )->alias( function( $cap ) {
			return $cap === 'manage_options' ? false : true;
		} );

		$request  = new \WP_REST_Request( 'GET', '/ems/v1/admin/sync-preview' );
		$response = $controller->get_sync_preview( $request );

		$this->assertTrue( is_wp_error( $response ) );
		$this->assertSame( 403, $response->get_error_data()['status'] );
	}

	public function test_get_sync_preview_returns_400_when_missing_section_id(): void {
		$controller = new Sync_Preview_Controller( $this->api_client, $this->sync_manager );
		Functions\when( 'current_user_can' )->justReturn( true );

		$request  = new \WP_REST_Request( 'GET', '/ems/v1/admin/sync-preview' );
		$response = $controller->get_sync_preview( $request );

		$this->assertTrue( is_wp_error( $response ) );
		$this->assertSame( 400, $response->get_error_data()['status'] );
	}

	public function test_get_sync_preview_invokes_sync_manager_and_returns_data(): void {
		$controller = new Sync_Preview_Controller( $this->api_client, $this->sync_manager );
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->api_client->shouldReceive( 'set_access_token' )
			->with( 'test-oauth-token' )
			->once();

		$mock_preview = [ 'flexi_record' => [ 'exists' => true ], 'events' => [], 'errors' => [] ];
		$this->sync_manager->shouldReceive( 'get_preview' )
			->with( 101 )
			->once()
			->andReturn( $mock_preview );

		$request = new \WP_REST_Request( 'GET', '/ems/v1/admin/sync-preview' );
		$request->set_param( 'section_id', 101 );
		$request->set_param( 'access_token', 'test-oauth-token' );

		$response = $controller->get_sync_preview( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $mock_preview, $response->get_data() );
	}
}
