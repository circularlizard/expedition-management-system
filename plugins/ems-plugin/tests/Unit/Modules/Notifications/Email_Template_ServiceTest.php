<?php
namespace EMS\Tests\Modules\Notifications;

use EMS\Tests\EMSTestCase;
use EMS\Modules\Notifications\Email_Template_Service;
use Brain\Monkey\Functions;

class Email_Template_ServiceTest extends EMSTestCase {

	public function test_render_replaces_tokens_in_subject_and_body(): void {
		$service = new Email_Template_Service();

		$result = $service->render(
			'[{{site_name}}] Hello {{unit_name}}',
			'Explorers for {{unit_name}}: {{explorer_list_osm}}',
			array(
				'{{site_name}}'         => 'SES Scouts',
				'{{unit_name}}'         => 'Kelso ESU',
				'{{explorer_list_osm}}' => '<ul><li>Alice Smith</li></ul>',
			)
		);

		$this->assertSame( '[SES Scouts] Hello Kelso ESU', $result['subject'] );
		$this->assertSame( 'Explorers for Kelso ESU: <ul><li>Alice Smith</li></ul>', $result['message'] );
	}

	public function test_build_headers_sanitizes_reply_to_and_adds_html_content_type(): void {
		$service = new Email_Template_Service();

		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = '' ) {
				if ( $key === 'ems_test_reply_to' ) {
					return "Reply-To: support@example.com\r\nBcc: evil@attacker.com";
				}
				return $default;
			}
		);

		$headers = $service->build_headers(
			'ems_test_reply_to',
			array(),
			array( 'Reply-To: old@example.com', 'X-Custom: 1' ),
			true
		);

		$this->assertContains( 'X-Custom: 1', $headers );
		$this->assertContains( 'Reply-To: support@example.comBcc: evil@attacker.com', $headers );
		$this->assertNotContains( 'Reply-To: old@example.com', $headers );
		$this->assertContains( 'Content-Type: text/html; charset=UTF-8', $headers );
	}

	public function test_send_dispatches_wp_mail_with_html_headers_and_overrides(): void {
		$service = new Email_Template_Service();

		Functions\when( 'is_email' )->alias( fn( $email ) => filter_var( $email, FILTER_VALIDATE_EMAIL ) !== false );
		Functions\when( 'get_option' )->justReturn( '' );

		$captured = null;
		Functions\expect( 'wp_mail' )
			->once()
			->andReturnUsing(
				static function ( $to, $subject, $message, $headers ) use ( &$captured ) {
					$captured = compact( 'to', 'subject', 'message', 'headers' );
					return true;
				}
			);

		$sent = $service->send(
			'leader@kelso.scouts.org.uk',
			'ems_unused_subj',
			'Default Subject',
			'ems_unused_body',
			'<p>Default Body</p>',
			'ems_unused_reply',
			array( '{{unit_name}}' => 'Kelso ESU' ),
			true,
			'Edited Subject',
			'<p>Edited Body</p>'
		);

		$this->assertTrue( $sent );
		$this->assertSame( 'leader@kelso.scouts.org.uk', $captured['to'] );
		$this->assertSame( 'Edited Subject', $captured['subject'] );
		$this->assertSame( '<p>Edited Body</p>', $captured['message'] );
		$this->assertContains( 'Content-Type: text/html; charset=UTF-8', $captured['headers'] );
	}

	public function test_send_returns_false_for_invalid_recipient_email(): void {
		$service = new Email_Template_Service();
		Functions\when( 'is_email' )->justReturn( false );

		$this->assertFalse(
			$service->send(
				'not-an-email',
				'subj_opt',
				'Subj',
				'body_opt',
				'Body',
				'reply_opt',
				array(),
				true
			)
		);
	}

	public function test_format_grouped_explorer_list_groups_by_level_and_sorts_by_first_name(): void {
		$service = new Email_Template_Service();

		$html = $service->format_grouped_explorer_list(
			array(
				array( 'first_name' => 'Zoe', 'last_name' => 'Adams', 'dofe_level' => 'bronze' ),
				array( 'first_name' => 'Amy', 'last_name' => 'Brown', 'dofe_level' => 'bronze' ),
				array( 'first_name' => 'Maya', 'last_name' => 'Patel', 'dofe_level' => 'gold' ),
				array( 'first_name' => 'Amy', 'last_name' => 'Adams', 'dofe_level' => 'bronze' ),
			)
		);

		$this->assertStringContainsString( '<p><strong>Bronze</strong></p>', $html );
		$this->assertStringContainsString( '<p><strong>Gold</strong></p>', $html );
		$this->assertStringNotContainsString( 'Silver', $html );

		// Sorted by first name (then last name): Amy Adams, Amy Brown, Zoe Adams
		$posAmyAdams = strpos( $html, '<li>Amy Adams</li>' );
		$posAmyBrown = strpos( $html, '<li>Amy Brown</li>' );
		$posZoeAdams = strpos( $html, '<li>Zoe Adams</li>' );
		$posGold     = strpos( $html, '<p><strong>Gold</strong></p>' );

		$this->assertNotFalse( $posAmyAdams );
		$this->assertNotFalse( $posAmyBrown );
		$this->assertNotFalse( $posZoeAdams );
		$this->assertNotFalse( $posGold );
		$this->assertLessThan( $posAmyBrown, $posAmyAdams );
		$this->assertLessThan( $posZoeAdams, $posAmyBrown );
		$this->assertLessThan( $posGold, $posZoeAdams );
	}

	public function test_expand_list_shortcodes_filters_by_levels_parameter(): void {
		$service = new Email_Template_Service();

		$explorers = array(
			array( 'first_name' => 'Amy', 'last_name' => 'Brown', 'dofe_level' => 'bronze' ),
			array( 'first_name' => 'Maya', 'last_name' => 'Patel', 'dofe_level' => 'silver' ),
			array( 'first_name' => 'Zoe', 'last_name' => 'Adams', 'dofe_level' => 'gold' ),
		);

		$rendered = $service->expand_list_shortcodes(
			'<div>BG:{{explorer_list_osm levels="bronze, gold"}}</div><div>All:{{explorer_list_osm}}</div>',
			array(
				'explorer_list_osm' => $explorers,
			)
		);

		$this->assertStringContainsString( 'BG:<p><strong>Bronze</strong></p><ul><li>Amy Brown</li></ul>', $rendered );
		$this->assertStringContainsString( '<p><strong>Gold</strong></p><ul><li>Zoe Adams</li></ul>', $rendered );
		$this->assertStringNotContainsString( 'BG:<p><strong>Bronze</strong></p><ul><li>Amy Brown</li></ul>' . "\n" . '<p><strong>Silver</strong></p>', $rendered );
		$this->assertStringContainsString( 'All:<p><strong>Bronze</strong></p><ul><li>Amy Brown</li></ul>' . "\n" . '<p><strong>Silver</strong></p><ul><li>Maya Patel</li></ul>' . "\n" . '<p><strong>Gold</strong></p><ul><li>Zoe Adams</li></ul>', $rendered );
	}
}
