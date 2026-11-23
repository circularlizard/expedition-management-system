<?php
declare(strict_types=1);

namespace EMS\Tests\Unit\Modules\Portal;

use Brain\Monkey;
use Brain\Monkey\Functions;
use EMS\Data\OSM_Explorer_Repository;
use EMS\Data\Signup_Repository;
use EMS\Integrations\TutorLMS_Client;
use EMS\Modules\Portal\Portal_Controller;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class PortalOfferResponseTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_respond_to_offer_atomically_updates_all_rows_and_emails_admin_on_query(): void {
		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';

		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'get_current_user_id' )->justReturn( 10 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-06-01 12:00:00' );
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				if ( $key === 'ems_deadline_policies' ) {
					return array();
				}
				if ( $key === 'admin_email' ) {
					return 'admin@example.org';
				}
				return $default;
			}
		);

		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $query, ...$args ) {
				return vsprintf( str_replace( array( '%d', '%s' ), array( '%s', "'%s'" ), $query ), $args );
			}
		);
		$wpdb->shouldReceive( 'get_results' )->once()->andReturn(
			array(
				array( 'id' => 1, 'notified_at' => '2026-05-30 10:00:00', 'notification_status' => 'notified' ),
				array( 'id' => 2, 'notified_at' => '2026-05-30 10:00:00', 'notification_status' => 'notified' ),
			)
		);
		$wpdb->shouldReceive( 'query' )->with( 'START TRANSACTION' )->once()->andReturn( true );
		$wpdb->shouldReceive( 'query' )->with( Mockery::pattern( '/UPDATE wp_ems_team_members/' ) )->once()->andReturn( 2 );
		$wpdb->shouldReceive( 'query' )->with( 'COMMIT' )->once()->andReturn( true );
		$wpdb->shouldReceive( 'get_var' )->once()->andReturn( 'Bronze Team A' );

		Functions\expect( 'wp_mail' )
			->once()
			->with( 'admin@example.org', Mockery::pattern( '/Bronze Team A/' ), Mockery::pattern( '/Need different dates/' ) )
			->andReturn( true );

		$controller = new Portal_Controller(
			Mockery::mock( OSM_Explorer_Repository::class ),
			Mockery::mock( TutorLMS_Client::class ),
			Mockery::mock( Signup_Repository::class )
		);

		$request = Mockery::mock( \WP_REST_Request::class );
		$request->shouldReceive( 'get_json_params' )->andReturn(
			array(
				'scout_id'             => 30001,
				'cohort_id'            => 5,
				'expedition_signup_id' => 88,
				'action'               => 'query',
				'message'              => 'Need different dates',
			)
		);

		$res = $controller->respond_to_offer( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $res );
		$this->assertSame( 200, $res->get_status() );
		$data = $res->get_data();
		$this->assertSame( 'queried', $data['offer_status'] );
		$this->assertSame( 2, $data['rows_updated'] );
	}

	public function test_respond_to_offer_blocks_when_hard_cutoff_passed(): void {
		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';

		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'get_current_user_id' )->justReturn( 10 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn(
			array(
				'place_offer' => array(
					'communicated_date' => '2020-01-01',
					'hard_cutoff_date'  => '2020-01-05',
					'grace_days'        => 0,
				),
			)
		);

		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SELECT ...' );
		$wpdb->shouldReceive( 'get_results' )->once()->andReturn(
			array(
				array( 'id' => 1, 'notified_at' => '2020-01-01 10:00:00', 'notification_status' => 'notified' ),
			)
		);

		$controller = new Portal_Controller(
			Mockery::mock( OSM_Explorer_Repository::class ),
			Mockery::mock( TutorLMS_Client::class ),
			Mockery::mock( Signup_Repository::class )
		);

		$request = Mockery::mock( \WP_REST_Request::class );
		$request->shouldReceive( 'get_json_params' )->andReturn(
			array(
				'scout_id'             => 30001,
				'cohort_id'            => 5,
				'expedition_signup_id' => 88,
				'action'               => 'accept',
			)
		);

		$res = $controller->respond_to_offer( $request );
		$this->assertInstanceOf( \WP_Error::class, $res );
		$this->assertSame( 'ems_offer_cutoff_passed', $res->get_error_code() );
	}

	public function test_respond_to_offer_blocks_explorer_member_with_403(): void {
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'get_current_user_id' )->justReturn( 22 );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'get_user_meta' )->alias(
			static function ( int $user_id, string $key, bool $single = false ) {
				if ( $key === 'ems_access_type' ) {
					return 'member';
				}
				return array();
			}
		);

		$controller = new Portal_Controller(
			Mockery::mock( OSM_Explorer_Repository::class ),
			Mockery::mock( TutorLMS_Client::class ),
			Mockery::mock( Signup_Repository::class )
		);

		$request = Mockery::mock( \WP_REST_Request::class );
		$request->shouldReceive( 'get_json_params' )->andReturn(
			array(
				'scout_id'             => 30001,
				'expedition_signup_id' => 88,
				'action'               => 'accept',
			)
		);

		$res = $controller->respond_to_offer( $request );
		$this->assertInstanceOf( \WP_Error::class, $res );
		$this->assertSame( 'ems_forbidden', $res->get_error_code() );
	}

	public function test_respond_to_offer_uses_configured_ems_offer_query_email(): void {
		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->posts  = 'wp_posts';

		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'get_current_user_id' )->justReturn( 10 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-06-01 12:00:00' );
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				if ( $key === 'ems_deadline_policies' ) {
					return array();
				}
				if ( $key === 'ems_offer_query_email' ) {
					return 'expeditions@sesscouts.org.uk';
				}
				if ( $key === 'admin_email' ) {
					return 'admin@example.org';
				}
				return $default;
			}
		);

		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_results' )->once()->andReturn(
			array(
				array( 'id' => 1, 'notified_at' => '2026-05-30 10:00:00', 'notification_status' => 'notified' ),
			)
		);
		$wpdb->shouldReceive( 'query' )->with( 'START TRANSACTION' )->once()->andReturn( true );
		$wpdb->shouldReceive( 'query' )->with( 'SQL' )->once()->andReturn( 1 );
		$wpdb->shouldReceive( 'query' )->with( 'COMMIT' )->once()->andReturn( true );
		$wpdb->shouldReceive( 'get_var' )->once()->andReturn( 'S1-W-A' );

		$mailed_to = null;
		Functions\when( 'wp_mail' )->alias(
			static function ( $to ) use ( &$mailed_to ) {
				$mailed_to = $to;
				return true;
			}
		);

		$controller = new Portal_Controller(
			Mockery::mock( OSM_Explorer_Repository::class ),
			Mockery::mock( TutorLMS_Client::class ),
			Mockery::mock( Signup_Repository::class )
		);

		$request = Mockery::mock( \WP_REST_Request::class );
		$request->shouldReceive( 'get_json_params' )->andReturn(
			array(
				'scout_id'             => 30001,
				'expedition_signup_id' => 88,
				'action'               => 'query',
				'message'              => 'Need different dates.',
			)
		);

		$res = $controller->respond_to_offer( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $res );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'expeditions@sesscouts.org.uk', $mailed_to );
	}

	public function test_respond_to_offer_rejects_query_over_1000_chars(): void {
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();

		$controller = new Portal_Controller(
			Mockery::mock( OSM_Explorer_Repository::class ),
			Mockery::mock( TutorLMS_Client::class ),
			Mockery::mock( Signup_Repository::class )
		);

		$request = Mockery::mock( \WP_REST_Request::class );
		$request->shouldReceive( 'get_json_params' )->andReturn(
			array(
				'scout_id'             => 30001,
				'expedition_signup_id' => 88,
				'action'               => 'query',
				'message'              => str_repeat( 'a', 1001 ),
			)
		);

		$res = $controller->respond_to_offer( $request );
		$this->assertInstanceOf( \WP_Error::class, $res );
		$this->assertSame( 'ems_query_message_too_long', $res->get_error_code() );
	}
}
