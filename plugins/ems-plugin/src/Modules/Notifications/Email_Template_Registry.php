<?php
namespace EMS\Modules\Notifications;

use EMS\Data\Unit_Repository;
use EMS\Modules\Signups\Signup_Chaser_Controller;

/**
 * Central declarative registry for all EMS email templates and their shortcodes.
 */
class Email_Template_Registry {

	public const OPTION_OTP_SUBJECT  = 'ems_otp_email_subject';
	public const OPTION_OTP_REPLY_TO = 'ems_otp_email_reply_to';
	public const OPTION_OTP_BODY     = 'ems_otp_email_body';

	public const DEFAULT_OTP_SUBJECT = '[{{site_name}}] Your Verification Code: {{otp}}';
	public const DEFAULT_OTP_BODY    = "<p>Hello,</p>\n<p>Your verification code is: <strong>{{otp}}</strong></p>\n<p>This code is valid for 30 minutes. If you did not request this code, please ignore this email.</p>\n<p>Regards,<br>{{site_name}}</p>";

	public const OPTION_PLACE_OFFER_SUBJECT      = 'ems_expedition_place_offer_subject';
	public const OPTION_PLACE_OFFER_REPLY_TO     = 'ems_expedition_place_offer_reply_to';
	public const OPTION_PLACE_OFFER_BODY         = 'ems_expedition_place_offer_body';
	public const OPTION_ACCEPTANCE_DEADLINE      = 'ems_expedition_offer_acceptance_deadline';
	public const DEFAULT_ACCEPTANCE_DEADLINE     = '31 Dec';
	public const DEFAULT_PLACE_OFFER_SUBJECT     = '[{{site_name}}] Expedition Place Offer — Team {{team_code}}';
	public const DEFAULT_PLACE_OFFER_BODY        = "<p>Hi {{explorer_name}},</p>\n<p>We are pleased to offer you a place on <strong>Team {{team_code}}</strong> for your upcoming DofE Expedition.</p>\n<p><strong>Expedition Dates:</strong></p>\n<ul>\n<li><strong>Practice Expedition:</strong> {{practice_dates}}</li>\n<li><strong>Qualifying Expedition:</strong> {{qualifier_dates}}</li>\n</ul>\n<p><strong>Your Teammates:</strong></p>\n{{teammates_list}}\n{{date_conflict_notice}}\n<p>Please confirm your place via the Explorer Portal by <strong>{{acceptance_deadline}}</strong>:</p>\n<p><a href=\"{{portal_offer_url}}\">{{portal_offer_url}}</a></p>\n<p>Regards,<br>{{site_name}}</p>";

	public const OPTION_OFFER_REMINDER_SUBJECT   = 'ems_place_offer_reminder_subject';
	public const OPTION_OFFER_REMINDER_REPLY_TO  = 'ems_place_offer_reminder_reply_to';
	public const OPTION_OFFER_REMINDER_BODY      = 'ems_place_offer_reminder_body';
	public const DEFAULT_OFFER_REMINDER_SUBJECT  = '[{{site_name}}] Reminder: Confirm Expedition Place Offer — Team {{team_code}}';
	public const DEFAULT_OFFER_REMINDER_BODY     = "<p>Hi {{explorer_name}},</p>\n<p>This is a reminder that your expedition place offer for <strong>Team {{team_code}}</strong> is still awaiting a response from a parent, guardian, or networker.</p>\n<p>Please respond via the Participant Portal by <strong>{{acceptance_deadline}}</strong>:</p>\n<p><a href=\"{{portal_offer_url}}\">{{portal_offer_url}}</a></p>\n<p>Regards,<br>{{site_name}}</p>";

	/**
	 * @var array<string, array<string, mixed>>
	 */
	private array $templates = array();

	public function __construct() {
		$this->register_core_templates();
	}

	/**
	 * Registers a template definition (or overrides an existing slug).
	 *
	 * @param array<string, mixed> $definition Template definition.
	 */
	public function register_template( array $definition ): void {
		$slug = sanitize_key( (string) ( $definition['slug'] ?? '' ) );
		if ( $slug === '' ) {
			return;
		}
		$definition['slug']        = $slug;
		$this->templates[ $slug ] = $definition;
	}

	/**
	 * Returns all registered template definitions.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		return array_values( $this->templates );
	}

	/**
	 * Retrieves a single template definition by slug.
	 *
	 * @param string $slug Template slug.
	 * @return array<string, mixed>|null
	 */
	public function get( string $slug ): ?array {
		$key = sanitize_key( $slug );
		return $this->templates[ $key ] ?? null;
	}

	private function register_core_templates(): void {
		$live_site_name = 'SE Explorer Expeditions';

		$this->register_template(
			array(
				'slug'            => 'leader_invite',
				'label'           => 'Unit Leader Invitation',
				'category'        => 'Unit Leaders',
				'description'     => 'Sent when inviting a unit leader to set their password and access the Unit Leader Dashboard.',
				'subject_option'  => 'ems_leader_invite_email_subject',
				'reply_to_option' => 'ems_leader_invite_email_reply_to',
				'body_option'     => 'ems_leader_invite_email_body',
				'default_subject' => Unit_Repository::DEFAULT_INVITE_SUBJECT,
				'default_body'    => Unit_Repository::DEFAULT_INVITE_BODY,
				'tokens'          => array(
					array(
						'token'       => '{{unit_name}}',
						'label'       => 'Assigned Units',
						'description' => 'Formatted list of assigned unit names (e.g. Kelso ESU and Peebles ESU)',
						'example'     => 'Kelso ESU',
					),
					array(
						'token'       => '{{set_password_url}}',
						'label'       => 'Set Password URL',
						'description' => 'Single-use password reset / activation link',
						'example'     => 'https://example.com/wp-login.php?action=rp&key=sample',
					),
					array(
						'token'       => '{{dashboard_url}}',
						'label'       => 'Dashboard URL',
						'description' => 'URL to the front-end Unit Leader Dashboard',
						'example'     => 'https://example.com/leader-dashboard/',
					),
					array(
						'token'       => '{{user_email}}',
						'label'       => 'Leader Email',
						'description' => 'Recipient leader login email address',
						'example'     => 'leader@kelso.scouts.org.uk',
					),
					array(
						'token'       => '{{site_name}}',
						'label'       => 'Site Name',
						'description' => 'WordPress site title',
						'example'     => $live_site_name,
					),
				),
			)
		);

		$this->register_template(
			array(
				'slug'            => 'osm_share_request',
				'label'           => 'OSM Share Request',
				'category'        => 'Signups & Chasers',
				'description'     => 'Sent to unit leaders from the Missing OSM Records tab requesting OSM record sharing.',
				'subject_option'  => Signup_Chaser_Controller::OPTION_OSM_SHARE_SUBJECT,
				'reply_to_option' => Signup_Chaser_Controller::OPTION_OSM_SHARE_REPLY_TO,
				'body_option'     => Signup_Chaser_Controller::OPTION_OSM_SHARE_BODY,
				'default_subject' => Signup_Chaser_Controller::DEFAULT_OSM_SHARE_SUBJECT,
				'default_body'    => Signup_Chaser_Controller::DEFAULT_OSM_SHARE_BODY,
				'tokens'          => array(
					array(
						'token'       => '{{unit_name}}',
						'label'       => 'Unit Name',
						'description' => 'Explorer Unit name',
						'example'     => 'Kelso ESU',
					),
					array(
						'token'           => '{{explorer_list_osm}}',
						'label'           => 'Unsynced Explorers List',
						'description'     => 'HTML list of unit explorers not yet synced with OSM, grouped by DofE level and sorted by first name. Supports levels="..." filter.',
						'supports_levels' => true,
						'example'         => '<h4>Bronze</h4><ul><li>Amy Brown</li></ul>',
					),
					array(
						'token'           => '{{explorer_list}}',
						'label'           => 'All Unit Explorers List',
						'description'     => 'HTML list of all signed-up explorers in the unit, grouped by DofE level. Supports levels="..." filter.',
						'supports_levels' => true,
						'example'         => '<h4>Bronze</h4><ul><li>Amy Brown</li><li>Chris Davis</li></ul>',
					),
					array(
						'token'       => '{{explorer_count}}',
						'label'       => 'Unsynced Count',
						'description' => 'Number of unsynced explorers included in the email',
						'example'     => '2',
					),
					array(
						'token'       => '{{leader_email}}',
						'label'       => 'Leader Email',
						'description' => 'Unit leader email address',
						'example'     => 'leader@kelso.scouts.org.uk',
					),
					array(
						'token'       => '{{site_name}}',
						'label'       => 'Site Name',
						'description' => 'WordPress site title',
						'example'     => $live_site_name,
					),
				),
			)
		);

		$this->register_template(
			array(
				'slug'            => 'unpaired_chase',
				'label'           => 'Unpaired Signups Chase',
				'category'        => 'Signups & Chasers',
				'description'     => 'Sent to unit leaders from the Unpaired Signups tab to follow up explorers missing either a Place or Expedition form.',
				'subject_option'  => Signup_Chaser_Controller::OPTION_UNPAIRED_SUBJECT,
				'reply_to_option' => Signup_Chaser_Controller::OPTION_UNPAIRED_REPLY_TO,
				'body_option'     => Signup_Chaser_Controller::OPTION_UNPAIRED_BODY,
				'default_subject' => Signup_Chaser_Controller::DEFAULT_UNPAIRED_SUBJECT,
				'default_body'    => Signup_Chaser_Controller::DEFAULT_UNPAIRED_BODY,
				'tokens'          => array(
					array(
						'token'       => '{{unit_name}}',
						'label'       => 'Unit Name',
						'description' => 'Explorer Unit name',
						'example'     => 'Kelso ESU',
					),
					array(
						'token'           => '{{place_only_explorer_list}}',
						'label'           => 'Place Only Explorers List',
						'description'     => 'HTML list of explorers with a Participant Place signup but no Expedition signup. Supports levels="..." filter.',
						'supports_levels' => true,
						'example'         => '<h4>Bronze</h4><ul><li>Liam Scott</li></ul>',
					),
					array(
						'token'           => '{{exped_only_explorer_list}}',
						'label'           => 'Expedition Only Explorers List',
						'description'     => 'HTML list of explorers with an Expedition signup but no Participant Place signup. Supports levels="..." filter.',
						'supports_levels' => true,
						'example'         => '<h4>Silver</h4><ul><li>Maya Patel</li></ul>',
					),
					array(
						'token'           => '{{explorer_list}}',
						'label'           => 'All Unit Explorers List',
						'description'     => 'HTML list of all signed-up explorers in the unit, grouped by DofE level. Supports levels="..." filter.',
						'supports_levels' => true,
						'example'         => '<h4>Bronze</h4><ul><li>Liam Scott</li></ul>',
					),
					array(
						'token'       => '{{place_only_count}}',
						'label'       => 'Place Only Count',
						'description' => 'Number of Place Only explorers',
						'example'     => '1',
					),
					array(
						'token'       => '{{exped_only_count}}',
						'label'       => 'Expedition Only Count',
						'description' => 'Number of Expedition Only explorers',
						'example'     => '1',
					),
					array(
						'token'       => '{{leader_email}}',
						'label'       => 'Leader Email',
						'description' => 'Unit leader email address',
						'example'     => 'leader@kelso.scouts.org.uk',
					),
					array(
						'token'       => '{{site_name}}',
						'label'       => 'Site Name',
						'description' => 'WordPress site title',
						'example'     => $live_site_name,
					),
				),
			)
		);

		$this->register_template(
			array(
				'slug'            => 'individual_chase',
				'label'           => 'Individual / Parent Anomaly Chase',
				'category'        => 'Signups & Chasers',
				'description'     => 'Sent directly to an Explorer and/or Parent/Guardian from the Anomalies & Chasers screen.',
				'subject_option'  => Signup_Chaser_Controller::OPTION_INDIVIDUAL_CHASE_SUBJECT,
				'reply_to_option' => Signup_Chaser_Controller::OPTION_INDIVIDUAL_CHASE_REPLY_TO,
				'body_option'     => Signup_Chaser_Controller::OPTION_INDIVIDUAL_CHASE_BODY,
				'default_subject' => Signup_Chaser_Controller::DEFAULT_INDIVIDUAL_CHASE_SUBJECT,
				'default_body'    => Signup_Chaser_Controller::DEFAULT_INDIVIDUAL_CHASE_BODY,
				'tokens'          => array(
					array(
						'token'       => '{{explorer_name}}',
						'label'       => 'Explorer Name',
						'description' => 'Full name of the explorer',
						'example'     => 'Amy Brown',
					),
					array(
						'token'       => '{{explorer_first_name}}',
						'label'       => 'Explorer First Name',
						'description' => 'First name of the explorer',
						'example'     => 'Amy',
					),
					array(
						'token'       => '{{dofe_level}}',
						'label'       => 'DofE Level',
						'description' => 'Explorer DofE award level',
						'example'     => 'Bronze',
					),
					array(
						'token'       => '{{unit_name}}',
						'label'       => 'Unit Name',
						'description' => 'Explorer Unit name',
						'example'     => 'Kelso ESU',
					),
					array(
						'token'       => '{{anomalies_list}}',
						'label'       => 'Anomalies List',
						'description' => 'Comma-separated list of detected anomaly labels for this explorer',
						'example'     => 'Place Without Expedition, Missing eDofE',
					),
					array(
						'token'       => '{{site_name}}',
						'label'       => 'Site Name',
						'description' => 'WordPress site title',
						'example'     => $live_site_name,
					),
				),
			)
		);

		$this->register_template(
			array(
				'slug'            => 'fluent_otp',
				'label'           => 'Email OTP Verification',
				'category'        => 'System & Verification',
				'description'     => 'Sent when an applicant verifies their email address via OTP during Fluent Forms signup.',
				'subject_option'  => self::OPTION_OTP_SUBJECT,
				'reply_to_option' => self::OPTION_OTP_REPLY_TO,
				'body_option'     => self::OPTION_OTP_BODY,
				'default_subject' => self::DEFAULT_OTP_SUBJECT,
				'default_body'    => self::DEFAULT_OTP_BODY,
				'tokens'          => array(
					array(
						'token'       => '{{otp}}',
						'label'       => 'Verification Code',
						'description' => '6-digit one-time verification code',
						'example'     => '482910',
					),
					array(
						'token'       => '{{site_name}}',
						'label'       => 'Site Name',
						'description' => 'WordPress site title',
						'example'     => $live_site_name,
					),
				),
			)
		);

		$this->register_template(
			array(
				'slug'            => 'expedition_place_offer',
				'label'           => 'Expedition Place Offer',
				'category'        => 'Expeditions & Teams',
				'description'     => 'Sent manually to explorers (and CC parent/guardian) offering their Practice and/or Qualifying expedition team place.',
				'subject_option'  => self::OPTION_PLACE_OFFER_SUBJECT,
				'reply_to_option' => self::OPTION_PLACE_OFFER_REPLY_TO,
				'body_option'     => self::OPTION_PLACE_OFFER_BODY,
				'default_subject' => self::DEFAULT_PLACE_OFFER_SUBJECT,
				'default_body'    => self::DEFAULT_PLACE_OFFER_BODY,
				'tokens'          => array(
					array(
						'token'       => '{{explorer_name}}',
						'label'       => 'Explorer Name',
						'description' => 'Full name of the explorer',
						'example'     => 'Amy Brown',
					),
					array(
						'token'       => '{{team_code}}',
						'label'       => 'Team Code',
						'description' => 'Assigned team code or cohort name',
						'example'     => 'B-FOOT-01',
					),
					array(
						'token'       => '{{practice_dates}}',
						'label'       => 'Practice Dates',
						'description' => 'Formatted Practice expedition dates (or TBC / Not assigned)',
						'example'     => '16–17 May 2026',
					),
					array(
						'token'       => '{{qualifier_dates}}',
						'label'       => 'Qualifier Dates',
						'description' => 'Formatted Qualifying expedition dates (or TBC / Not assigned)',
						'example'     => '20–21 Jun 2026',
					),
					array(
						'token'       => '{{teammates_list}}',
						'label'       => 'Teammates List',
						'description' => 'HTML list of other explorers in the same cohort/team',
						'example'     => '<ul><li>Chris Davis</li><li>Liam Scott</li></ul>',
					),
					array(
						'token'       => '{{date_conflict_notice}}',
						'label'       => 'Date Conflict Notice',
						'description' => 'Optional notice shown when the explorer has a pending date conflict',
						'example'     => '<p><strong>Note:</strong> A date conflict is currently flagged for review.</p>',
					),
					array(
						'token'       => '{{portal_offer_url}}',
						'label'       => 'Portal Offer URL',
						'description' => 'Link to the Explorer Portal to accept or query the offer',
						'example'     => 'https://example.com/explorer-portal/',
					),
					array(
						'token'       => '{{acceptance_deadline}}',
						'label'       => 'Acceptance Deadline',
						'description' => 'Deadline date for confirming the expedition place (defaults to 31 Dec)',
						'example'     => self::DEFAULT_ACCEPTANCE_DEADLINE,
					),
					array(
						'token'       => '{{site_name}}',
						'label'       => 'Site Name',
						'description' => 'WordPress site title',
						'example'     => $live_site_name,
					),
				),
			)
		);

		$this->register_template(
			array(
				'slug'            => 'place_offer_reminder',
				'label'           => 'Place Offer Deadline Reminder',
				'category'        => 'Expeditions & Teams',
				'description'     => 'Automated daily cron reminder sent 3 days before and on the configured Place Offer communicated deadline for pending offers.',
				'subject_option'  => self::OPTION_OFFER_REMINDER_SUBJECT,
				'reply_to_option' => self::OPTION_OFFER_REMINDER_REPLY_TO,
				'body_option'     => self::OPTION_OFFER_REMINDER_BODY,
				'default_subject' => self::DEFAULT_OFFER_REMINDER_SUBJECT,
				'default_body'    => self::DEFAULT_OFFER_REMINDER_BODY,
				'tokens'          => array(
					array(
						'token'       => '{{explorer_name}}',
						'label'       => 'Explorer Name',
						'description' => 'Full name of the explorer',
						'example'     => 'Amy Brown',
					),
					array(
						'token'       => '{{team_code}}',
						'label'       => 'Team Code',
						'description' => 'Assigned team code',
						'example'     => 'B-FOOT-01',
					),
					array(
						'token'       => '{{acceptance_deadline}}',
						'label'       => 'Communicated Deadline',
						'description' => 'Configured communicated deadline date',
						'example'     => '2026-12-15',
					),
					array(
						'token'       => '{{portal_offer_url}}',
						'label'       => 'Portal Offer URL',
						'description' => 'Link to the Participant Portal',
						'example'     => 'https://example.com/explorer-portal/',
					),
					array(
						'token'       => '{{site_name}}',
						'label'       => 'Site Name',
						'description' => 'WordPress site title',
						'example'     => $live_site_name,
					),
				),
			)
		);
	}
}
