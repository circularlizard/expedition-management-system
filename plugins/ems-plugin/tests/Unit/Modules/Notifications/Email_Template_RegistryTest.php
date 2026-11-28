<?php
namespace EMS\Tests\Modules\Notifications;

use EMS\Modules\Notifications\Email_Template_Registry;
use EMS\Tests\EMSTestCase;

class Email_Template_RegistryTest extends EMSTestCase {

	public function test_default_registry_contains_all_current_templates_with_double_brace_tokens(): void {
		$registry  = new Email_Template_Registry();
		$templates = $registry->all();

		$slugs = array_column( $templates, 'slug' );
		$this->assertContains( 'leader_invite', $slugs );
		$this->assertContains( 'osm_share_request', $slugs );
		$this->assertContains( 'unpaired_chase', $slugs );
		$this->assertContains( 'individual_chase', $slugs );
		$this->assertContains( 'fluent_otp', $slugs );

		$individual = $registry->get( 'individual_chase' );
		$this->assertNotNull( $individual );
		$this->assertSame( 'Signups & Chasers', $individual['category'] );
		$this->assertSame( 'ems_individual_chase_email_subject', $individual['subject_option'] );

		$osm = $registry->get( 'osm_share_request' );
		$this->assertNotNull( $osm );
		$this->assertSame( 'Signups & Chasers', $osm['category'] );
		$this->assertSame( 'ems_osm_share_request_email_subject', $osm['subject_option'] );

		foreach ( $templates as $template ) {
			$this->assertNotEmpty( $template['tokens'] );
			foreach ( $template['tokens'] as $token ) {
				$this->assertStringStartsWith( '{{', $token['token'] );
				$this->assertStringEndsWith( '}}', $token['token'] );
			}
		}
	}

	public function test_custom_template_can_be_registered_dynamically(): void {
		$registry = new Email_Template_Registry();
		$registry->register_template(
			array(
				'slug'            => 'volunteer_confirmation',
				'label'           => 'Volunteer Confirmation',
				'category'        => 'Volunteers',
				'description'     => 'Sent when a volunteer submits availability.',
				'subject_option'  => 'ems_volunteer_confirm_subject',
				'reply_to_option' => 'ems_volunteer_confirm_reply_to',
				'body_option'     => 'ems_volunteer_confirm_body',
				'default_subject' => 'Thank you {{volunteer_name}}',
				'default_body'    => '<p>Hi {{volunteer_name}}</p>',
				'tokens'          => array(
					array(
						'token'       => '{{volunteer_name}}',
						'label'       => 'Volunteer Name',
						'description' => 'Volunteer full name',
						'example'     => 'Alex Taylor',
					),
				),
			)
		);

		$tpl = $registry->get( 'volunteer_confirmation' );
		$this->assertNotNull( $tpl );
		$this->assertSame( 'Volunteers', $tpl['category'] );
	}
}
