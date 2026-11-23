<?php
declare(strict_types=1);

namespace EMS\Modules\Notifications\Services;

use EMS\Modules\Notifications\Email_Template_Registry;
use EMS\Services\DeadlinePolicyService;

/**
 * Daily WP-Cron job (`ems_daily_deadline_check`) that dispatches deduplicated
 * reminder emails against configured communicated deadlines (`ems_deadline_policies`).
 */
class DeadlineReminderCron {

	public const CRON_HOOK = 'ems_daily_deadline_check';

	private DeadlinePolicyService $deadline_policy;
	private ?object $wpdb;

	public function __construct( ?DeadlinePolicyService $deadline_policy = null, ?object $wpdb = null ) {
		$this->deadline_policy = $deadline_policy ?: new DeadlinePolicyService();
		$this->wpdb            = $wpdb;
	}

	private function get_wpdb(): object {
		if ( $this->wpdb === null ) {
			global $wpdb;
			$this->wpdb = $wpdb;
		}
		return $this->wpdb;
	}

	/**
	 * Schedules the daily deadline check cron event if not already scheduled.
	 */
	public static function register_cron(): void {
		if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_event' ) ) {
			if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_event( time(), 'daily', self::CRON_HOOK );
			}
		}
	}

	/**
	 * WP-Cron callback entrypoint.
	 */
	public static function run_scheduled(): void {
		$instance = new self();
		$instance->run();
	}

	/**
	 * Evaluates configured communicated deadlines and sends deduplicated reminders
	 * at 3 days before and on the due date itself.
	 *
	 * @param int|null $now_ts Optional Unix timestamp override for deterministic tests.
	 * @return array<string, int> Summary counts.
	 */
	public function run( ?int $now_ts = null ): array {
		$now      = $now_ts ?? time();
		$policies = $this->deadline_policy->get_policies();

		$summary = array(
			'place_offer_sent'    => 0,
			'place_offer_skipped' => 0,
			'route_card_sent'     => 0,
			'route_card_skipped'  => 0,
		);

		$place_comm = (string) ( $policies['place_offer']['communicated_date'] ?? '' );
		$window     = $this->resolve_reminder_window( $place_comm, $now );
		if ( $window !== null ) {
			$res                              = $this->process_place_offer_reminders( $place_comm, $window );
			$summary['place_offer_sent']      = $res['sent'];
			$summary['place_offer_skipped']   = $res['skipped'];
		}

		$route_comm   = (string) ( $policies['route_card']['communicated_date'] ?? '' );
		$route_window = $this->resolve_reminder_window( $route_comm, $now );
		if ( $route_window !== null ) {
			$res                             = $this->process_route_card_reminders( $route_comm, $route_window );
			$summary['route_card_sent']      = $res['sent'];
			$summary['route_card_skipped']   = $res['skipped'];
		}

		return $summary;
	}

	/**
	 * Returns '3d' when 1..3 days remain before communicated_date, '0d' on due date,
	 * or null outside reminder windows.
	 */
	public function resolve_reminder_window( string $communicated_date, int $now_ts ): ?string {
		if ( $communicated_date === '' ) {
			return null;
		}
		$today_str = gmdate( 'Y-m-d', $now_ts );
		$today_ts  = strtotime( $today_str . ' 00:00:00' );
		$due_ts    = strtotime( $communicated_date . ' 00:00:00' );
		if ( $today_ts === false || $due_ts === false ) {
			return null;
		}

		$days_until = (int) round( ( $due_ts - $today_ts ) / 86400 );
		if ( $days_until === 0 ) {
			return '0d';
		}
		if ( $days_until >= 1 && $days_until <= 3 ) {
			return '3d';
		}
		return null;
	}

	/**
	 * @return array{sent: int, skipped: int}
	 */
	private function process_place_offer_reminders( string $comm_date, string $window ): array {
		$wpdb       = $this->get_wpdb();
		$tm_table   = $wpdb->prefix . 'ems_team_members';
		$es_table   = $wpdb->prefix . 'ems_expedition_signups';
		$logs_table = $wpdb->prefix . 'ems_notification_logs';
		$posts      = $wpdb->posts ?? ( $wpdb->prefix . 'posts' );
		$postmeta   = $wpdb->postmeta ?? ( $wpdb->prefix . 'postmeta' );

		$rows = $wpdb->get_results(
			"SELECT tm.id, tm.team_post_id, tm.scout_id, tm.expedition_signup_id,
			        COALESCE(NULLIF(pm_code.meta_value, ''), t.post_title) AS team_code,
			        es.explorer_first_name, es.explorer_last_name,
			        es.explorer_email, es.parent_email
			 FROM {$tm_table} tm
			 INNER JOIN {$posts} t ON t.ID = tm.team_post_id
			 LEFT JOIN {$postmeta} pm_code ON pm_code.post_id = t.ID AND pm_code.meta_key = 'ems_team_code'
			 LEFT JOIN {$es_table} es ON es.id = tm.expedition_signup_id
			 WHERE tm.notification_status = 'notified'
			   AND tm.offer_status = 'pending'
			   AND t.post_type = 'team'
			   AND t.post_status != 'trash'
			   AND UPPER(COALESCE(pm_code.meta_value, '')) != 'UNALLOCATED'
			 ORDER BY tm.id ASC",
			ARRAY_A
		) ?: array();

		$sent    = 0;
		$skipped = 0;
		$seen    = array();

		$subject_tpl = (string) get_option(
			Email_Template_Registry::OPTION_OFFER_REMINDER_SUBJECT,
			Email_Template_Registry::DEFAULT_OFFER_REMINDER_SUBJECT
		);
		$body_tpl    = (string) get_option(
			Email_Template_Registry::OPTION_OFFER_REMINDER_BODY,
			Email_Template_Registry::DEFAULT_OFFER_REMINDER_BODY
		);
		$reply_to    = trim( (string) get_option( Email_Template_Registry::OPTION_OFFER_REMINDER_REPLY_TO, '' ) );
		$site_name   = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : 'EMS';
		$portal_url  = function_exists( 'home_url' ) ? (string) home_url( '/explorer-portal/' ) : '/explorer-portal/';

		foreach ( $rows as $row ) {
			$esid     = (int) ( $row['expedition_signup_id'] ?? 0 );
			$scout_id = (int) ( $row['scout_id'] ?? 0 );
			$id_part  = $esid > 0 ? "es:{$esid}" : "sc:{$scout_id}";

			if ( isset( $seen[ $id_part ] ) ) {
				continue;
			}
			$seen[ $id_part ] = true;

			$dedupe_key = "place_offer_reminder:{$id_part}:{$comm_date}:{$window}";
			$already    = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$logs_table} WHERE dedupe_key = %s AND status = 'sent' LIMIT 1",
					$dedupe_key
				)
			);
			if ( $already ) {
				++$skipped;
				continue;
			}

			$parent_email   = trim( (string) ( $row['parent_email'] ?? '' ) );
			$explorer_email = trim( (string) ( $row['explorer_email'] ?? '' ) );
			$to_email       = is_email( $parent_email ) ? $parent_email : ( is_email( $explorer_email ) ? $explorer_email : '' );
			if ( $to_email === '' ) {
				++$skipped;
				continue;
			}

			$cc_emails = array();
			if ( is_email( $explorer_email ) && strtolower( $explorer_email ) !== strtolower( $to_email ) ) {
				$cc_emails[] = $explorer_email;
			}

			$explorer_name = trim( (string) ( ( $row['explorer_first_name'] ?? '' ) . ' ' . ( $row['explorer_last_name'] ?? '' ) ) );
			if ( $explorer_name === '' ) {
				$explorer_name = 'Explorer';
			}
			$team_code = trim( (string) ( $row['team_code'] ?? '' ) ) ?: 'Your Team';

			$tokens = array(
				'{{explorer_name}}'       => $explorer_name,
				'{{team_code}}'           => $team_code,
				'{{acceptance_deadline}}' => $comm_date,
				'{{portal_offer_url}}'    => $portal_url,
				'{{site_name}}'           => $site_name,
			);

			$subject = strtr( $subject_tpl, $tokens );
			$body    = strtr( $body_tpl, $tokens );
			$headers = array( 'Content-Type: text/html; charset=UTF-8' );
			if ( $reply_to !== '' && is_email( $reply_to ) ) {
				$headers[] = 'Reply-To: ' . $reply_to;
			}
			foreach ( $cc_emails as $cc ) {
				$headers[] = 'Cc: ' . $cc;
			}

			$mail_ok = function_exists( 'wp_mail' ) ? (bool) wp_mail( $to_email, $subject, $body, $headers ) : false;

			$wpdb->insert(
				$logs_table,
				array(
					'template_slug'      => 'place_offer_reminder',
					'trigger_type'       => 'cron',
					'recipient_email'    => $to_email,
					'cc_emails'          => ! empty( $cc_emails ) ? implode( ', ', $cc_emails ) : null,
					'subject'            => substr( $subject, 0, 255 ),
					'target_scout_id'    => $scout_id,
					'target_signup_id'   => $esid > 0 ? $esid : null,
					'target_signup_type' => $esid > 0 ? 'expedition' : null,
					'target_team_id'     => (int) ( $row['team_post_id'] ?? 0 ) ?: null,
					'dedupe_key'         => $dedupe_key,
					'status'             => $mail_ok ? 'sent' : 'failed',
					'sent_at'            => current_time( 'mysql' ),
				)
			);

			if ( $mail_ok ) {
				++$sent;
			} else {
				++$skipped;
			}
		}

		return array(
			'sent'    => $sent,
			'skipped' => $skipped,
		);
	}

	/**
	 * Sends deduplicated reminders for accepted team members whose team has not yet
	 * submitted a route card (when `dofe_route_cards` exists).
	 *
	 * @return array{sent: int, skipped: int}
	 */
	private function process_route_card_reminders( string $comm_date, string $window ): array {
		$wpdb       = $this->get_wpdb();
		$rc_table   = $wpdb->prefix . 'dofe_route_cards';
		$table_name = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $rc_table ) );
		if ( $table_name !== $rc_table ) {
			return array( 'sent' => 0, 'skipped' => 0 );
		}

		$tm_table   = $wpdb->prefix . 'ems_team_members';
		$es_table   = $wpdb->prefix . 'ems_expedition_signups';
		$logs_table = $wpdb->prefix . 'ems_notification_logs';
		$posts      = $wpdb->posts ?? ( $wpdb->prefix . 'posts' );
		$postmeta   = $wpdb->postmeta ?? ( $wpdb->prefix . 'postmeta' );

		$rows = $wpdb->get_results(
			"SELECT tm.team_post_id, tm.scout_id, tm.expedition_signup_id,
			        COALESCE(NULLIF(pm_code.meta_value, ''), t.post_title) AS team_code,
			        es.explorer_first_name, es.explorer_last_name,
			        es.explorer_email, es.parent_email
			 FROM {$tm_table} tm
			 INNER JOIN {$posts} t ON t.ID = tm.team_post_id
			 LEFT JOIN {$postmeta} pm_code ON pm_code.post_id = t.ID AND pm_code.meta_key = 'ems_team_code'
			 LEFT JOIN {$es_table} es ON es.id = tm.expedition_signup_id
			 LEFT JOIN {$rc_table} rc ON rc.team_id = tm.team_post_id AND rc.status IN ('submitted', 'approved')
			 WHERE tm.offer_status = 'accepted'
			   AND t.post_type = 'team'
			   AND t.post_status != 'trash'
			   AND UPPER(COALESCE(pm_code.meta_value, '')) != 'UNALLOCATED'
			   AND rc.id IS NULL
			 ORDER BY tm.id ASC",
			ARRAY_A
		) ?: array();

		$sent       = 0;
		$skipped    = 0;
		$seen       = array();
		$site_name  = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : 'EMS';
		$portal_url = function_exists( 'home_url' ) ? (string) home_url( '/explorer-portal/' ) : '/explorer-portal/';

		foreach ( $rows as $row ) {
			$esid     = (int) ( $row['expedition_signup_id'] ?? 0 );
			$scout_id = (int) ( $row['scout_id'] ?? 0 );
			$team_id  = (int) ( $row['team_post_id'] ?? 0 );
			$id_part  = $esid > 0 ? "es:{$esid}" : "sc:{$scout_id}";

			if ( isset( $seen[ "{$team_id}:{$id_part}" ] ) ) {
				continue;
			}
			$seen[ "{$team_id}:{$id_part}" ] = true;

			$dedupe_key = "route_card_reminder:{$team_id}:{$id_part}:{$comm_date}:{$window}";
			$already    = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$logs_table} WHERE dedupe_key = %s AND status = 'sent' LIMIT 1",
					$dedupe_key
				)
			);
			if ( $already ) {
				++$skipped;
				continue;
			}

			$explorer_email = trim( (string) ( $row['explorer_email'] ?? '' ) );
			$parent_email   = trim( (string) ( $row['parent_email'] ?? '' ) );
			$to_email       = is_email( $explorer_email ) ? $explorer_email : ( is_email( $parent_email ) ? $parent_email : '' );
			if ( $to_email === '' ) {
				++$skipped;
				continue;
			}

			$team_code = trim( (string) ( $row['team_code'] ?? '' ) ) ?: 'Your Team';
			$subject   = "[{$site_name}] Route Card Reminder — Team {$team_code}";
			$body      = sprintf(
				'<p>Hi %s,</p><p>This is a friendly reminder that your Route Card for <strong>Team %s</strong> is due by <strong>%s</strong>.</p><p><a href="%s">%s</a></p><p>Regards,<br>%s</p>',
				esc_html( trim( (string) ( ( $row['explorer_first_name'] ?? '' ) . ' ' . ( $row['explorer_last_name'] ?? '' ) ) ) ?: 'Explorer' ),
				esc_html( $team_code ),
				esc_html( $comm_date ),
				esc_url( $portal_url ),
				esc_html( $portal_url ),
				esc_html( $site_name )
			);

			$mail_ok = function_exists( 'wp_mail' ) ? (bool) wp_mail( $to_email, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) ) : false;

			$wpdb->insert(
				$logs_table,
				array(
					'template_slug'      => 'route_card_reminder',
					'trigger_type'       => 'cron',
					'recipient_email'    => $to_email,
					'subject'            => substr( $subject, 0, 255 ),
					'target_scout_id'    => $scout_id,
					'target_signup_id'   => $esid > 0 ? $esid : null,
					'target_signup_type' => $esid > 0 ? 'expedition' : null,
					'target_team_id'     => $team_id ?: null,
					'dedupe_key'         => $dedupe_key,
					'status'             => $mail_ok ? 'sent' : 'failed',
					'sent_at'            => current_time( 'mysql' ),
				)
			);

			if ( $mail_ok ) {
				++$sent;
			} else {
				++$skipped;
			}
		}

		return array(
			'sent'    => $sent,
			'skipped' => $skipped,
		);
	}
}
