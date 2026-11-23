<?php
namespace EMS\Tests\Modules\Signups;

use EMS\Tests\EMSTestCase;
use EMS\Modules\Signups\Signup_Chaser_Controller;
use EMS\Modules\Notifications\Email_Template_Service;
use EMS\Data\Signup_Repository;
use EMS\Data\Unit_Repository;
use Brain\Monkey\Functions;
use Mockery;

class Signup_Chaser_ControllerTest extends EMSTestCase {

	private function make_signups_fixture(): array {
		return array(
			'participant' => array(
				array(
					'id'                  => 1,
					'type'                => 'participant',
					'scout_id'            => 0,
					'unit_name'           => 'Kelso ESU',
					'explorer_first_name' => 'Zoe',
					'explorer_last_name'  => 'Adams',
					'explorer_email'      => 'zoe@example.com',
					'parent_email'        => 'pzoe@example.com',
					'dofe_level'          => 'bronze',
					'is_synced_osm'       => 0,
					'signup_status'       => 'submitted',
				),
				array(
					'id'                  => 2,
					'type'                => 'participant',
					'scout_id'            => 99999,
					'unit_name'           => 'Kelso ESU',
					'explorer_first_name' => 'Amy',
					'explorer_last_name'  => 'Brown',
					'explorer_email'      => 'amy@example.com',
					'parent_email'        => 'pamy@example.com',
					'dofe_level'          => 'bronze',
					'is_synced_osm'       => 0,
					'signup_status'       => 'submitted',
				),
				array(
					'id'                  => 3,
					'type'                => 'participant',
					'scout_id'            => 30001,
					'unit_name'           => 'Kelso ESU',
					'explorer_first_name' => 'Chris',
					'explorer_last_name'  => 'Davis',
					'explorer_email'      => 'chris@example.com',
					'parent_email'        => 'pchris@example.com',
					'dofe_level'          => 'bronze',
					'is_synced_osm'       => 1,
					'signup_status'       => 'submitted',
				),
				array(
					'id'                  => 4,
					'type'                => 'participant',
					'scout_id'            => 30002,
					'unit_name'           => 'Peebles ESU',
					'explorer_first_name' => 'Liam',
					'explorer_last_name'  => 'Scott',
					'explorer_email'      => 'liam@example.com',
					'parent_email'        => 'pliam@example.com',
					'dofe_level'          => 'bronze',
					'is_synced_osm'       => 1,
					'signup_status'       => 'submitted',
				),
			),
			'expedition' => array(
				array(
					'id'                  => 10,
					'type'                => 'expedition',
					'scout_id'            => 30001,
					'unit_name'           => 'Kelso ESU',
					'explorer_first_name' => 'Chris',
					'explorer_last_name'  => 'Davis',
					'explorer_email'      => 'chris@example.com',
					'parent_email'        => 'pchris@example.com',
					'dofe_level'          => 'bronze',
					'is_synced_osm'       => 1,
					'signup_status'       => 'submitted',
				),
				array(
					'id'                  => 11,
					'type'                => 'expedition',
					'scout_id'            => 30003,
					'unit_name'           => 'Peebles ESU',
					'explorer_first_name' => 'Maya',
					'explorer_last_name'  => 'Patel',
					'explorer_email'      => 'maya@example.com',
					'parent_email'        => 'pmaya@example.com',
					'dofe_level'          => 'silver',
					'is_synced_osm'       => 1,
					'signup_status'       => 'submitted',
				),
			),
		);
	}

	private function make_units_fixture(): array {
		return array(
			array(
				'id'           => 1,
				'unit_id'      => 10,
				'name'         => 'Kelso ESU',
				'short_code'   => 'BO-Kelso',
				'district'     => 'Borders',
				'leader_email' => 'leader@kelso.scouts.org.uk',
			),
			array(
				'id'           => 2,
				'unit_id'      => 20,
				'name'         => 'Peebles ESU',
				'short_code'   => 'TW-Peebles',
				'district'     => 'Tweeddale',
				'leader_email' => 'leader@peebles.scouts.org.uk',
			),
		);
	}

	public function test_send_chaser_returns_503_when_notifications_disabled(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = false ) {
				if ( $key === 'ems_feature_flags' ) {
					return array( 'notifications' => false );
				}
				return $default;
			}
		);

		$signup_repo = Mockery::mock( Signup_Repository::class );
		$unit_repo   = Mockery::mock( Unit_Repository::class );
		$controller  = new Signup_Chaser_Controller( $signup_repo, $unit_repo, new Email_Template_Service() );

		$request = new \WP_REST_Request( 'POST', '/ems/v1/signups/send-chaser' );
		$request->set_param( 'chaser_type', 'osm_share' );
		$request->set_param( 'unit_name', 'Kelso ESU' );

		$response = $controller->send_chaser( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'ems_notifications_disabled', $response->get_error_code() );
		$this->assertSame( array( 'status' => 503 ), $response->get_error_data() );
	}

	public function test_preview_osm_share_populates_explorer_list_osm_and_explorer_list(): void {
		$fixtures = $this->make_signups_fixture();
		$units    = $this->make_units_fixture();

		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = '' ) {
				if ( $key === 'ems_feature_flags' ) {
					return array( 'notifications' => true );
				}
				if ( $key === 'ems_osm_share_request_email_body' ) {
					return '<div>Unsynced:{{explorer_list_osm}}</div><div>All:{{explorer_list}}</div>';
				}
				return $default;
			}
		);
		Functions\when( 'get_bloginfo' )->justReturn( 'SES Scouts' );

		$signup_repo = Mockery::mock( Signup_Repository::class );
		$signup_repo->shouldReceive( 'get_participant_signups' )->andReturn( $fixtures['participant'] );
		$signup_repo->shouldReceive( 'get_expedition_signups' )->andReturn( $fixtures['expedition'] );

		$unit_repo = Mockery::mock( Unit_Repository::class );
		$unit_repo->shouldReceive( 'list_active_units' )->andReturn( $units );

		$controller = new Signup_Chaser_Controller( $signup_repo, $unit_repo, new Email_Template_Service() );

		$request = new \WP_REST_Request( 'POST', '/ems/v1/signups/send-chaser' );
		$request->set_param( 'chaser_type', 'osm_share' );
		$request->set_param( 'unit_name', 'Kelso ESU' );
		$request->set_param( 'preview', true );

		$response = $controller->send_chaser( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();

		$this->assertSame( 'leader@kelso.scouts.org.uk', $data['to'] );
		$this->assertSame( 2, $data['explorer_count'] );

		// Unsynced list has Amy Brown before Zoe Adams, and omits Chris Davis
		$this->assertStringContainsString( '<li>Amy Brown</li><li>Zoe Adams</li>', $data['message'] );
		$this->assertStringContainsString( 'All:<p><strong>Bronze</strong></p><ul><li>Amy Brown</li><li>Chris Davis</li><li>Zoe Adams</li></ul>', $data['message'] );
	}

	public function test_send_osm_share_sends_html_email_with_overrides_and_records_history(): void {
		$fixtures = $this->make_signups_fixture();
		$units    = $this->make_units_fixture();

		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = '' ) {
				if ( $key === 'ems_feature_flags' ) {
					return array( 'notifications' => true );
				}
				if ( $key === 'ems_chaser_request_history' ) {
					return array();
				}
				return $default;
			}
		);
		Functions\when( 'get_bloginfo' )->justReturn( 'SES Scouts' );
		Functions\when( 'is_email' )->alias( fn( $e ) => filter_var( $e, FILTER_VALIDATE_EMAIL ) !== false );
		Functions\when( 'do_action' )->justReturn( null );

		$captured = null;
		Functions\expect( 'wp_mail' )->once()->andReturnUsing(
			static function ( $to, $subject, $message, $headers ) use ( &$captured ) {
				$captured = compact( 'to', 'subject', 'message', 'headers' );
				return true;
			}
		);

		$saved_history = null;
		Functions\when( 'update_option' )->alias(
			static function ( string $key, $val ) use ( &$saved_history ) {
				if ( $key === 'ems_chaser_request_history' ) {
					$saved_history = $val;
				}
				return true;
			}
		);

		$signup_repo = Mockery::mock( Signup_Repository::class );
		$signup_repo->shouldReceive( 'get_participant_signups' )->andReturn( $fixtures['participant'] );
		$signup_repo->shouldReceive( 'get_expedition_signups' )->andReturn( $fixtures['expedition'] );

		$unit_repo = Mockery::mock( Unit_Repository::class );
		$unit_repo->shouldReceive( 'list_active_units' )->andReturn( $units );

		$controller = new Signup_Chaser_Controller( $signup_repo, $unit_repo, new Email_Template_Service() );

		$request = new \WP_REST_Request( 'POST', '/ems/v1/signups/send-chaser' );
		$request->set_param( 'chaser_type', 'osm_share' );
		$request->set_param( 'unit_name', 'Kelso ESU' );
		$request->set_param( 'to_override', 'custom@kelso.scouts.org.uk' );
		$request->set_param( 'subject_override', 'Custom OSM Share Subject' );
		$request->set_param( 'body_override', '<p>Custom HTML body</p>' );

		$response = $controller->send_chaser( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();

		$this->assertTrue( $data['sent'] );
		$this->assertSame( 'custom@kelso.scouts.org.uk', $captured['to'] );
		$this->assertSame( 'Custom OSM Share Subject', $captured['subject'] );
		$this->assertSame( '<p>Custom HTML body</p>', $captured['message'] );
		$this->assertContains( 'Content-Type: text/html; charset=UTF-8', $captured['headers'] );
		$this->assertSame( '2026-06-13 20:00:00', $saved_history['osm_share']['Kelso ESU'] ?? null );
	}

	public function test_preview_unpaired_signups_populates_place_only_and_exped_only_lists(): void {
		$fixtures = $this->make_signups_fixture();
		$units    = $this->make_units_fixture();

		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = '' ) {
				if ( $key === 'ems_feature_flags' ) {
					return array( 'notifications' => true );
				}
				if ( $key === 'ems_unpaired_chase_email_body' ) {
					return '<div>PlaceOnly:{{place_only_explorer_list}}</div><div>ExpedOnly:{{exped_only_explorer_list}}</div><div>All:{{explorer_list}}</div>';
				}
				return $default;
			}
		);
		Functions\when( 'get_bloginfo' )->justReturn( 'SES Scouts' );

		$signup_repo = Mockery::mock( Signup_Repository::class );
		$signup_repo->shouldReceive( 'get_participant_signups' )->andReturn( $fixtures['participant'] );
		$signup_repo->shouldReceive( 'get_expedition_signups' )->andReturn( $fixtures['expedition'] );

		$unit_repo = Mockery::mock( Unit_Repository::class );
		$unit_repo->shouldReceive( 'list_active_units' )->andReturn( $units );

		$controller = new Signup_Chaser_Controller( $signup_repo, $unit_repo, new Email_Template_Service() );

		$request = new \WP_REST_Request( 'POST', '/ems/v1/signups/send-chaser' );
		$request->set_param( 'chaser_type', 'unpaired_signups' );
		$request->set_param( 'unit_name', 'Peebles ESU' );
		$request->set_param( 'preview', true );

		$response = $controller->send_chaser( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();

		$this->assertSame( 'leader@peebles.scouts.org.uk', $data['to'] );
		$this->assertStringContainsString( 'PlaceOnly:<p><strong>Bronze</strong></p><ul><li>Liam Scott</li></ul>', $data['message'] );
		$this->assertStringContainsString( 'ExpedOnly:<p><strong>Silver</strong></p><ul><li>Maya Patel</li></ul>', $data['message'] );
		$this->assertStringContainsString( 'All:<p><strong>Bronze</strong></p><ul><li>Liam Scott</li></ul>', $data['message'] );
	}

	public function test_get_chaser_history_returns_history_and_fluent_smtp_url(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = array() ) {
				if ( $key === 'ems_chaser_request_history' ) {
					return array(
						'osm_share' => array( 'Kelso ESU' => '2026-06-13 18:00:00' ),
					);
				}
				return $default;
			}
		);
		Functions\when( 'admin_url' )->alias( fn( $path ) => 'https://example.com/wp-admin/' . $path );

		$signup_repo = Mockery::mock( Signup_Repository::class );
		$unit_repo   = Mockery::mock( Unit_Repository::class );
		$controller  = new Signup_Chaser_Controller( $signup_repo, $unit_repo, new Email_Template_Service() );

		$request = new \WP_REST_Request( 'GET', '/ems/v1/signups/chaser-history' );
		$request->set_param( 'type', 'osm_share' );

		$response = $controller->get_chaser_history( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();

		$this->assertSame( array( 'Kelso ESU' => '2026-06-13 18:00:00' ), $data['history'] );
		$this->assertSame( 'https://example.com/wp-admin/admin.php?page=fluent-mail#/logs', $data['fluent_smtp_url'] );
	}

	public function test_preview_chaser_respects_levels_filter_and_shortcode_levels_param(): void {
		$fixtures = $this->make_signups_fixture();
		$fixtures['participant'][] = array(
			'id'                  => 99,
			'type'                => 'participant',
			'scout_id'            => 0,
			'unit_name'           => 'Kelso ESU',
			'explorer_first_name' => 'Goldie',
			'explorer_last_name'  => 'Locks',
			'explorer_email'      => 'goldie@example.com',
			'parent_email'        => 'pgoldie@example.com',
			'dofe_level'          => 'gold',
			'is_synced_osm'       => 0,
			'signup_status'       => 'submitted',
		);
		$units = $this->make_units_fixture();

		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = '' ) {
				if ( $key === 'ems_feature_flags' ) {
					return array( 'notifications' => true );
				}
				if ( $key === 'ems_osm_share_request_email_body' ) {
					return '<div>{{explorer_list_osm}}</div>';
				}
				return $default;
			}
		);
		Functions\when( 'get_bloginfo' )->justReturn( 'SES Scouts' );

		$signup_repo = Mockery::mock( Signup_Repository::class );
		$signup_repo->shouldReceive( 'get_participant_signups' )->andReturn( $fixtures['participant'] );
		$signup_repo->shouldReceive( 'get_expedition_signups' )->andReturn( $fixtures['expedition'] );

		$unit_repo = Mockery::mock( Unit_Repository::class );
		$unit_repo->shouldReceive( 'list_active_units' )->andReturn( $units );

		$controller = new Signup_Chaser_Controller( $signup_repo, $unit_repo, new Email_Template_Service() );

		$request = new \WP_REST_Request( 'POST', '/ems/v1/signups/send-chaser' );
		$request->set_param( 'chaser_type', 'osm_share' );
		$request->set_param( 'unit_name', 'Kelso ESU' );
		$request->set_param( 'levels', array( 'bronze' ) );
		$request->set_param( 'preview', true );

		$response = $controller->send_chaser( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();

		$this->assertSame( 2, $data['explorer_count'] );
		$this->assertStringContainsString( 'Amy Brown', $data['message'] );
		$this->assertStringNotContainsString( 'Goldie Locks', $data['message'] );
		$this->assertStringContainsString( '{{explorer_list_osm}}', $data['raw_message'] );
		$this->assertArrayHasKey( 'token_datasets', $data );
		$this->assertNotEmpty( $data['shortcodes'] );
	}

	public function test_send_chaser_expands_shortcodes_and_tokens_in_overrides(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key, $default = false ) => $key === 'ems_feature_flags' ? array() : $default
		);
		Functions\when( 'is_email' )->returnArg();
		Functions\when( 'get_bloginfo' )->justReturn( 'SES' );
		Functions\when( 'esc_html' )->returnArg();

		$signup_repo = \Mockery::mock( Signup_Repository::class );
		$unit_repo   = \Mockery::mock( Unit_Repository::class );

		$unit_repo->shouldReceive( 'list_active_units' )->andReturn(
			array(
				array(
					'unit_id'      => 10,
					'name'         => 'Kelso ESU',
					'leader_email' => 'leader@kelso.scouts.org.uk',
				),
			)
		);

		$signup_repo->shouldReceive( 'get_participant_signups' )->andReturn(
			array(
				array(
					'id'                  => 1,
					'scout_id'            => 0,
					'is_synced_osm'       => false,
					'unit_id'             => 10,
					'unit_name'           => 'Kelso ESU',
					'explorer_first_name' => 'Amy',
					'explorer_last_name'  => 'Brown',
					'dofe_level'          => 'bronze',
					'signup_status'       => 'active',
				),
			)
		);
		$signup_repo->shouldReceive( 'get_expedition_signups' )->andReturn( array() );

		$captured_mail = null;
		Functions\when( 'wp_mail' )->alias(
			static function ( $to, $subject, $message ) use ( &$captured_mail ): bool {
				$captured_mail = array(
					'to'      => $to,
					'subject' => $subject,
					'message' => $message,
				);
				return true;
			}
		);

		$controller = new Signup_Chaser_Controller( $signup_repo, $unit_repo, new Email_Template_Service() );

		$request = new \WP_REST_Request( 'POST', '/ems/v1/signups/send-chaser' );
		$request->set_param( 'chaser_type', 'osm_share' );
		$request->set_param( 'unit_name', 'Kelso ESU' );
		$request->set_param( 'preview', false );
		$request->set_param( 'subject_override', 'Action for {{unit_name}}' );
		$request->set_param( 'body_override', '<p>List:</p>{{explorer_list_osm levels=&quot;bronze&quot;}}' );

		$response = $controller->send_chaser( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertNotNull( $captured_mail );
		$this->assertSame( 'Action for Kelso ESU', $captured_mail['subject'] );
		$this->assertStringContainsString( 'Amy Brown', $captured_mail['message'] );
		$this->assertStringNotContainsString( '{{', $captured_mail['message'] );
	}

	public function test_send_chaser_renders_unit_name_instead_of_short_code_for_unit_name_token(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key, $default = false ) => $key === 'ems_feature_flags' ? array() : $default
		);
		Functions\when( 'is_email' )->returnArg();
		Functions\when( 'get_bloginfo' )->justReturn( 'SES' );
		Functions\when( 'esc_html' )->returnArg();

		$signup_repo = \Mockery::mock( Signup_Repository::class );
		$unit_repo   = \Mockery::mock( Unit_Repository::class );

		$unit_repo->shouldReceive( 'list_active_units' )->andReturn(
			array(
				array(
					'unit_id'      => 10,
					'name'         => 'Kelso ESU',
					'short_code'   => 'BO-Kelso',
					'leader_email' => 'leader@kelso.scouts.org.uk',
				),
			)
		);

		$signup_repo->shouldReceive( 'get_participant_signups' )->andReturn(
			array(
				array(
					'id'                  => 1,
					'scout_id'            => 0,
					'is_synced_osm'       => false,
					'unit_id'             => 10,
					'unit_name'           => 'BO-Kelso',
					'explorer_first_name' => 'Amy',
					'explorer_last_name'  => 'Brown',
					'dofe_level'          => 'bronze',
					'signup_status'       => 'active',
				),
			)
		);
		$signup_repo->shouldReceive( 'get_expedition_signups' )->andReturn( array() );

		$captured_mail = null;
		Functions\when( 'wp_mail' )->alias(
			static function ( $to, $subject, $message ) use ( &$captured_mail ): bool {
				$captured_mail = array(
					'to'      => $to,
					'subject' => $subject,
					'message' => $message,
				);
				return true;
			}
		);

		$controller = new Signup_Chaser_Controller( $signup_repo, $unit_repo, new Email_Template_Service() );

		$request = new \WP_REST_Request( 'POST', '/ems/v1/signups/send-chaser' );
		$request->set_param( 'chaser_type', 'osm_share' );
		$request->set_param( 'unit_name', 'BO-Kelso' );
		$request->set_param( 'preview', false );
		$request->set_param( 'subject_override', 'Action for {{unit_name}}' );
		$request->set_param( 'body_override', '<p>Unit: {{unit_name}}</p>' );

		$response = $controller->send_chaser( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertNotNull( $captured_mail );
		$this->assertSame( 'Action for Kelso ESU', $captured_mail['subject'] );
		$this->assertSame( '<p>Unit: Kelso ESU</p>', $captured_mail['message'] );
	}

	public function test_send_individual_chaser_supports_multiple_cc_emails(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key, $default = false ) => $key === 'ems_feature_flags' ? array() : $default
		);
		Functions\when( 'is_email' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		$signup_repo = \Mockery::mock( Signup_Repository::class );
		$unit_repo   = \Mockery::mock( Unit_Repository::class );

		$captured_mail = null;
		Functions\when( 'wp_mail' )->alias(
			static function ( $to, $subject, $message, $headers = array() ) use ( &$captured_mail ): bool {
				$captured_mail = array(
					'to'      => $to,
					'subject' => $subject,
					'message' => $message,
					'headers' => $headers,
				);
				return true;
			}
		);

		$controller = new Signup_Chaser_Controller( $signup_repo, $unit_repo, new Email_Template_Service() );

		$request = new \WP_REST_Request( 'POST', '/ems/v1/signups/send-chaser' );
		$request->set_param( 'target_audience', 'individual' );
		$request->set_param( 'chaser_type', 'unpaired_signups' );
		$request->set_param( 'to_override', 'explorer@example.com, parent1@example.com, parent2@example.com' );
		$request->set_param( 'cc_email', 'leader@example.com, parent1@example.com, LEADER@example.com' );
		$request->set_param( 'subject_override', 'Update needed' );
		$request->set_param( 'body_override', '<p>Hello</p>' );

		$response = $controller->send_chaser( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertNotNull( $captured_mail );
		$this->assertSame(
			array( 'explorer@example.com', 'parent1@example.com', 'parent2@example.com' ),
			$captured_mail['to']
		);
		$this->assertContains( 'Cc: leader@example.com', $captured_mail['headers'] );
	}

	public function test_send_chaser_filters_by_included_signups_when_provided(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key, $default = false ) => $key === 'ems_feature_flags' ? array() : $default
		);
		Functions\when( 'is_email' )->returnArg();
		Functions\when( 'get_bloginfo' )->justReturn( 'SES' );
		Functions\when( 'esc_html' )->returnArg();

		$signup_repo = \Mockery::mock( Signup_Repository::class );
		$unit_repo   = \Mockery::mock( Unit_Repository::class );

		$unit_repo->shouldReceive( 'list_active_units' )->andReturn(
			array(
				array(
					'unit_id'      => 10,
					'name'         => 'Kelso ESU',
					'short_code'   => 'BO-Kelso',
					'leader_email' => 'leader@kelso.scouts.org.uk',
				),
			)
		);

		$signup_repo->shouldReceive( 'get_participant_signups' )->andReturn(
			array(
				array(
					'id'                  => 1,
					'scout_id'            => 0,
					'is_synced_osm'       => false,
					'unit_id'             => 10,
					'unit_name'           => 'Kelso ESU',
					'explorer_first_name' => 'Amy',
					'explorer_last_name'  => 'Brown',
					'dofe_level'          => 'bronze',
					'signup_status'       => 'submitted',
				),
				array(
					'id'                  => 2,
					'scout_id'            => 0,
					'is_synced_osm'       => false,
					'unit_id'             => 10,
					'unit_name'           => 'Kelso ESU',
					'explorer_first_name' => 'Ben',
					'explorer_last_name'  => 'Clark',
					'dofe_level'          => 'bronze',
					'signup_status'       => 'submitted',
				),
			)
		);
		$signup_repo->shouldReceive( 'get_expedition_signups' )->andReturn( array() );

		$controller = new Signup_Chaser_Controller( $signup_repo, $unit_repo, new Email_Template_Service() );

		$request = new \WP_REST_Request( 'POST', '/ems/v1/signups/send-chaser' );
		$request->set_param( 'chaser_type', 'osm_share' );
		$request->set_param( 'unit_name', 'Kelso ESU' );
		$request->set_param( 'preview', true );
		$request->set_param(
			'included_signups',
			array(
				array(
					'id'       => 1,
					'type'     => 'participant',
					'scout_id' => 0,
				),
			)
		);

		$response = $controller->send_chaser( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();

		$this->assertSame( 1, $data['explorer_count'] );
		$this->assertStringContainsString( 'Amy Brown', $data['message'] );
		$this->assertStringNotContainsString( 'Ben Clark', $data['message'] );
	}
}
