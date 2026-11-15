<?php
namespace EMS\Tests\Modules\Notifications;

use Brain\Monkey\Functions;
use EMS\Modules\Notifications\Email_Template_Registry;
use EMS\Modules\Notifications\Email_Template_Service;
use EMS\Modules\Notifications\Email_Templates_Controller;
use EMS\Tests\EMSTestCase;

class Email_Templates_ControllerTest extends EMSTestCase {

	public function test_list_templates_returns_saved_values_and_tokens(): void {
		$options = array(
			'ems_osm_share_request_email_subject' => 'Custom Subject',
		);
		Functions\when( 'get_option' )->alias( fn( string $k, $default = false ) => $options[ $k ] ?? $default );

		$controller = new Email_Templates_Controller( new Email_Template_Registry(), new Email_Template_Service() );
		$response   = $controller->list_templates();

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );

		$osm = null;
		foreach ( $data as $row ) {
			if ( ( $row['slug'] ?? '' ) === 'osm_share_request' ) {
				$osm = $row;
				break;
			}
		}

		$this->assertNotNull( $osm );
		$this->assertSame( 'Custom Subject', $osm['subject'] );
		$this->assertNotEmpty( $osm['tokens'] );
	}

	public function test_update_template_persists_sanitized_options(): void {
		$stored = array();
		Functions\when( 'update_option' )->alias(
			function ( string $key, $value ) use ( &$stored ) {
				$stored[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_kses_post' )->alias( fn( string $v ) => $v );
		Functions\when( 'sanitize_email' )->alias( fn( string $v ) => trim( $v ) );

		Functions\when( 'get_option' )->alias( fn( string $k, $default = false ) => $default );

		$controller = new Email_Templates_Controller( new Email_Template_Registry(), new Email_Template_Service() );
		$request    = new \WP_REST_Request( 'PUT', '/ems/v1/email-templates/osm_share_request' );
		$request->set_param( 'slug', 'osm_share_request' );
		$request->set_param( 'subject', 'Updated {{unit_name}} Subject' );
		$request->set_param( 'reply_to', 'dofe@example.com' );
		$request->set_param( 'body', '<p>Updated body {{explorer_list_osm}}</p>' );

		$response = $controller->update_template( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Updated {{unit_name}} Subject', $stored['ems_osm_share_request_email_subject'] );
		$this->assertSame( 'dofe@example.com', $stored['ems_osm_share_request_email_reply_to'] );
		$this->assertSame( '<p>Updated body {{explorer_list_osm}}</p>', $stored['ems_osm_share_request_email_body'] );
	}

	public function test_reset_template_deletes_options_and_returns_defaults(): void {
		$deleted = array();
		Functions\when( 'get_option' )->alias( fn( string $k, $default = false ) => $default );
		Functions\when( 'delete_option' )->alias(
			function ( string $key ) use ( &$deleted ) {
				$deleted[] = $key;
				return true;
			}
		);

		$controller = new Email_Templates_Controller( new Email_Template_Registry(), new Email_Template_Service() );
		$request    = new \WP_REST_Request( 'POST', '/ems/v1/email-templates/osm_share_request/reset' );
		$request->set_param( 'slug', 'osm_share_request' );

		$response = $controller->reset_template( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( 'ems_osm_share_request_email_subject', $deleted );
		$this->assertContains( 'ems_osm_share_request_email_reply_to', $deleted );
		$this->assertContains( 'ems_osm_share_request_email_body', $deleted );
	}

	public function test_send_test_email_substitutes_sample_tokens_and_calls_wp_mail(): void {
		Functions\when( 'get_option' )->alias( fn( string $k, $default = false ) => $default );
		Functions\when( 'get_bloginfo' )->justReturn( 'SES Scouts EMS' );
		Functions\when( 'sanitize_email' )->alias( fn( string $v ) => trim( $v ) );
		Functions\when( 'wp_kses_post' )->alias( fn( string $v ) => $v );
		Functions\when( 'is_email' )->alias( fn( string $v ) => str_contains( $v, '@' ) );

		$captured = null;
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $message, $headers ) use ( &$captured ) {
				$captured = compact( 'to', 'subject', 'message', 'headers' );
				return true;
			}
		);

		$controller = new Email_Templates_Controller( new Email_Template_Registry(), new Email_Template_Service() );
		$request    = new \WP_REST_Request( 'POST', '/ems/v1/email-templates/osm_share_request/test' );
		$request->set_param( 'slug', 'osm_share_request' );
		$request->set_param( 'to', 'admin@example.com' );
		$request->set_param( 'subject', 'Test {{unit_name}}' );
		$request->set_param( 'body', '<p>List: {{explorer_list_osm}}</p>' );

		$response = $controller->send_test_email( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertNotNull( $captured );
		$this->assertSame( 'admin@example.com', $captured['to'] );
		$this->assertStringContainsString( 'Kelso ESU', $captured['subject'] );
		$this->assertStringNotContainsString( '{{unit_name}}', $captured['subject'] );
		$this->assertStringNotContainsString( '{{explorer_list_osm}}', $captured['message'] );
	}

	public function test_unknown_template_slug_returns_404_wp_error(): void {
		$controller = new Email_Templates_Controller( new Email_Template_Registry(), new Email_Template_Service() );
		$request    = new \WP_REST_Request( 'POST', '/ems/v1/email-templates/nonexistent/reset' );
		$request->set_param( 'slug', 'nonexistent' );

		$result = $controller->reset_template( $request );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ems_template_not_found', $result->get_error_code() );
	}
}
