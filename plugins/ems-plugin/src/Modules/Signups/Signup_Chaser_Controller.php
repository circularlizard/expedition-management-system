<?php
namespace EMS\Modules\Signups;

use EMS\Core\Audit_Logger;
use EMS\Core\Event_Bus;
use EMS\Core\Feature_Flags;
use EMS\Data\Signup_Repository;
use EMS\Data\Unit_Repository;
use EMS\Modules\Notifications\Email_Template_Registry;
use EMS\Modules\Notifications\Email_Template_Service;

/**
 * REST Controller for sending OSM Share Request and Unpaired Signups Chase emails to unit leaders.
 */
class Signup_Chaser_Controller {

	public const OPTION_HISTORY = 'ems_chaser_request_history';

	public const OPTION_OSM_SHARE_SUBJECT  = 'ems_osm_share_request_email_subject';
	public const OPTION_OSM_SHARE_REPLY_TO = 'ems_osm_share_request_email_reply_to';
	public const OPTION_OSM_SHARE_BODY     = 'ems_osm_share_request_email_body';

	public const OPTION_UNPAIRED_SUBJECT  = 'ems_unpaired_chase_email_subject';
	public const OPTION_UNPAIRED_REPLY_TO = 'ems_unpaired_chase_email_reply_to';
	public const OPTION_UNPAIRED_BODY     = 'ems_unpaired_chase_email_body';

	public const OPTION_MISSING_FORM7_SUBJECT  = 'ems_missing_form7_chase_email_subject';
	public const OPTION_MISSING_FORM7_REPLY_TO = 'ems_missing_form7_chase_email_reply_to';
	public const OPTION_MISSING_FORM7_BODY     = 'ems_missing_form7_chase_email_body';

	public const OPTION_MISSING_EDOFE_SUBJECT  = 'ems_missing_edofe_chase_email_subject';
	public const OPTION_MISSING_EDOFE_REPLY_TO = 'ems_missing_edofe_chase_email_reply_to';
	public const OPTION_MISSING_EDOFE_BODY     = 'ems_missing_edofe_chase_email_body';

	public const OPTION_INDIVIDUAL_CHASE_SUBJECT  = 'ems_individual_chase_email_subject';
	public const OPTION_INDIVIDUAL_CHASE_REPLY_TO = 'ems_individual_chase_email_reply_to';
	public const OPTION_INDIVIDUAL_CHASE_BODY     = 'ems_individual_chase_email_body';

	public const DEFAULT_OSM_SHARE_SUBJECT = '[{{site_name}}] Action Required: Please share Explorer(s) in OSM for {{unit_name}}';
	public const DEFAULT_OSM_SHARE_BODY    = "<p>Hello,</p>\n<p>The following Explorer(s) from <strong>{{unit_name}}</strong> have signed up for DofE but do not yet have a synced Online Scout Manager (OSM) record in our system:</p>\n{{explorer_list_osm}}\n<p>Please could you share these Explorer(s) with the Regional DofE section in OSM so we can link their signups?</p>\n<p>Thank you,<br>{{site_name}}</p>";

	public const DEFAULT_UNPAIRED_SUBJECT = '[{{site_name}}] Incomplete DofE Signups for {{unit_name}}';
	public const DEFAULT_UNPAIRED_BODY    = "<p>Hello,</p>\n<p>The following Explorer(s) from <strong>{{unit_name}}</strong> currently have an incomplete DofE signup (missing either a Participant Place form or an Expedition Preference form):</p>\n<p><strong>Signed up for a Participant Place only (missing Expedition form):</strong></p>\n{{place_only_explorer_list}}\n<p><strong>Signed up for an Expedition only (missing Participant Place form):</strong></p>\n{{exped_only_explorer_list}}\n<p>Please could you follow up with these Explorer(s) / parents so both forms are completed?</p>\n<p>Thank you,<br>{{site_name}}</p>";

	public const DEFAULT_MISSING_FORM7_SUBJECT = '[{{site_name}}] Missing Expedition Sign-up for {{unit_name}}';
	public const DEFAULT_MISSING_FORM7_BODY    = "<p>Hello,</p>\n<p>The following Explorer(s) from <strong>{{unit_name}}</strong> have completed a Participant Place sign-up but have not yet submitted their Expedition Preferences:</p>\n{{place_only_explorer_list}}\n<p>Please could you remind them/their parents to complete their Expedition sign-up?</p>\n<p>Thank you,<br>{{site_name}}</p>";

	public const DEFAULT_MISSING_EDOFE_SUBJECT = '[{{site_name}}] Missing eDofE Number(s) for {{unit_name}}';
	public const DEFAULT_MISSING_EDOFE_BODY    = "<p>Hello,</p>\n<p>The following Explorer(s) from <strong>{{unit_name}}</strong> do not yet have an eDofE ID recorded on their signups:</p>\n{{missing_edofe_explorer_list}}\n<p>Please could you confirm or register their eDofE numbers so we can verify their award status?</p>\n<p>Thank you,<br>{{site_name}}</p>";

	public const DEFAULT_INDIVIDUAL_CHASE_SUBJECT = '[{{site_name}}] Action Required: DofE Sign-up for {{explorer_name}}';
	public const DEFAULT_INDIVIDUAL_CHASE_BODY    = "<p>Hello,</p>\n<p>We are reviewing the DofE sign-up for <strong>{{explorer_name}}</strong> ({{dofe_level}}) and noticed the following item(s) to resolve:</p>\n<p><strong>{{anomalies_list}}</strong></p>\n<p>Please reply to let us know or complete the missing details.</p>\n<p>Thank you,<br>{{site_name}}</p>";

	private Signup_Repository $signup_repo;
	private Unit_Repository $unit_repo;
	private Email_Template_Service $email_service;

	public function __construct(
		Signup_Repository $signup_repo,
		Unit_Repository $unit_repo,
		Email_Template_Service $email_service
	) {
		$this->signup_repo   = $signup_repo;
		$this->unit_repo     = $unit_repo;
		$this->email_service = $email_service;
	}

	/**
	 * Registers REST API routes for signup chasers.
	 */
	public function register_routes(): void {
		register_rest_route(
			'ems/v1',
			'/signups/chaser-history',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_chaser_history' ),
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
			)
		);

		register_rest_route(
			'ems/v1',
			'/signups/send-chaser',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'send_chaser' ),
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
			)
		);

		register_rest_route(
			'ems/v1',
			'/signups/(?P<type>participant|expedition)/(?P<id>\d+)/history',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_signup_chase_history' ),
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
			)
		);
	}

	/**
	 * GET /ems/v1/signups/{type}/{id}/history
	 */
	public function get_signup_chase_history( \WP_REST_Request $request ): \WP_REST_Response {
		$type     = sanitize_key( (string) $request->get_param( 'type' ) );
		$id       = (int) $request->get_param( 'id' );
		$scout_id = (int) $request->get_param( 'scout_id' );

		$history = array();
		global $wpdb;
		if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'get_results' ) && method_exists( $wpdb, 'prepare' ) ) {
			$logs_table = $wpdb->prefix . 'ems_notification_logs';
			if ( $scout_id > 0 ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id, template_slug, trigger_type, recipient_email, cc_emails, subject, metadata, status, sent_at
						 FROM {$logs_table}
						 WHERE (target_signup_id = %d AND target_signup_type = %s) OR target_scout_id = %d
						 ORDER BY sent_at DESC LIMIT 50",
						$id,
						$type,
						$scout_id
					),
					ARRAY_A
				);
			} else {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id, template_slug, trigger_type, recipient_email, cc_emails, subject, metadata, status, sent_at
						 FROM {$logs_table}
						 WHERE target_signup_id = %d AND target_signup_type = %s
						 ORDER BY sent_at DESC LIMIT 50",
						$id,
						$type
					),
					ARRAY_A
				);
			}
			foreach ( (array) $rows as $row ) {
				$meta      = ! empty( $row['metadata'] ) ? json_decode( (string) $row['metadata'], true ) : null;
				$history[] = array(
					'id'              => (int) ( $row['id'] ?? 0 ),
					'template_slug'   => (string) ( $row['template_slug'] ?? '' ),
					'recipient_email' => (string) ( $row['recipient_email'] ?? '' ),
					'cc_emails'       => (string) ( $row['cc_emails'] ?? '' ),
					'subject'         => (string) ( $row['subject'] ?? '' ),
					'metadata'        => is_array( $meta ) ? $meta : array(),
					'sent_at'         => (string) ( $row['sent_at'] ?? '' ),
				);
			}
		}

		return new \WP_REST_Response( array( 'history' => $history ), 200 );
	}

	/**
	 * GET /ems/v1/signups/chaser-history
	 */
	public function get_chaser_history( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$type = sanitize_key( (string) $request->get_param( 'type' ) );
		if ( ! in_array( $type, array( 'osm_share', 'unpaired_signups', 'missing_form7', 'missing_edofe' ), true ) ) {
			$type = 'osm_share';
		}

		$history = array();

		// Prefer ems_notification_logs when available.
		global $wpdb;
		if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'get_results' ) && method_exists( $wpdb, 'prepare' ) ) {
			$logs_table = $wpdb->prefix . 'ems_notification_logs';
			$rows       = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT recipient_email, subject, sent_at FROM {$logs_table} WHERE template_slug = %s ORDER BY sent_at DESC LIMIT 200",
					$type
				),
				ARRAY_A
			);
			if ( is_array( $rows ) && ! empty( $rows ) ) {
				foreach ( $rows as $row ) {
					$history[] = $row;
				}
			}
		}

		if ( empty( $history ) ) {
			$all_history = get_option( self::OPTION_HISTORY, array() );
			$history     = ( is_array( $all_history ) && isset( $all_history[ $type ] ) && is_array( $all_history[ $type ] ) )
				? $all_history[ $type ]
				: array();
		}

		return new \WP_REST_Response(
			array(
				'history'         => $history,
				'fluent_smtp_url' => admin_url( 'admin.php?page=fluent-mail#/logs' ),
			),
			200
		);
	}

	/**
	 * POST /ems/v1/signups/send-chaser
	 */
	public function send_chaser( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! Feature_Flags::is_enabled( 'notifications' ) ) {
			return new \WP_Error(
				'ems_notifications_disabled',
				'Notifications module is currently disabled.',
				array( 'status' => 503 )
			);
		}

		$chaser_type = sanitize_key( (string) $request->get_param( 'chaser_type' ) );
		if ( ! in_array( $chaser_type, array( 'osm_share', 'unpaired_signups', 'missing_form7', 'missing_edofe' ), true ) ) {
			return new \WP_Error(
				'ems_invalid_chaser_type',
				'Invalid chaser_type. Expected osm_share, unpaired_signups, missing_form7, or missing_edofe.',
				array( 'status' => 400 )
			);
		}

		$target_audience = sanitize_key( (string) ( $request->get_param( 'target_audience' ) ?: 'unit_leader' ) );
		$raw_anomalies   = $request->get_param( 'anomalies' );
		$anomalies       = is_array( $raw_anomalies ) ? array_values( array_map( 'sanitize_key', $raw_anomalies ) ) : array();

		if ( $target_audience === 'individual' ) {
			return $this->send_individual_chaser( $request, $chaser_type, $anomalies );
		}

		$unit_name = trim( (string) $request->get_param( 'unit_name' ) );
		if ( $unit_name === '' ) {
			return new \WP_Error(
				'ems_missing_unit',
				'unit_name is required.',
				array( 'status' => 400 )
			);
		}

		$preview          = (bool) $request->get_param( 'preview' );
		$to_override      = trim( (string) $request->get_param( 'to_override' ) );
		$subject_override = (string) $request->get_param( 'subject_override' );
		$body_override    = (string) $request->get_param( 'body_override' );
		$raw_levels       = $request->get_param( 'levels' );
		$level_filter     = array();
		if ( is_array( $raw_levels ) ) {
			foreach ( $raw_levels as $lvl ) {
				$clean_lvl = strtolower( trim( (string) $lvl ) );
				if ( in_array( $clean_lvl, array( 'bronze', 'silver', 'gold' ), true ) ) {
					$level_filter[] = $clean_lvl;
				}
			}
		}

		$units       = $this->unit_repo->list_active_units();
		$unit_record = $this->find_unit_record( $units, $unit_name );
		$leader_email = trim( (string) ( $unit_record['leader_email'] ?? '' ) );
		$recipient    = $to_override !== '' ? $to_override : $leader_email;

		$unit_signups = $this->get_unit_active_signups( $unit_name, $unit_record );
		$buckets      = $this->group_by_explorer_identity( $unit_signups );

		$included_param = $request->get_param( 'included_signups' );
		if ( is_array( $included_param ) && ! empty( $included_param ) ) {
			$allowed_keys   = array();
			$allowed_scouts = array();
			foreach ( $included_param as $inc ) {
				if ( ! is_array( $inc ) ) {
					continue;
				}
				$inc_id    = (int) ( $inc['id'] ?? 0 );
				$inc_type  = sanitize_key( (string) ( $inc['type'] ?? '' ) );
				$inc_scout = (int) ( $inc['scout_id'] ?? 0 );
				if ( $inc_id > 0 && $inc_type !== '' ) {
					$allowed_keys[ $inc_type . '_' . $inc_id ] = true;
				}
				if ( $inc_scout > 0 ) {
					$allowed_scouts[ $inc_scout ] = true;
				}
			}
			if ( ! empty( $allowed_keys ) || ! empty( $allowed_scouts ) ) {
				$buckets = array_values(
					array_filter(
						$buckets,
						static function ( array $b ) use ( $allowed_keys, $allowed_scouts ): bool {
							$b_scout = (int) ( $b['scout_id'] ?? 0 );
							if ( $b_scout > 0 && isset( $allowed_scouts[ $b_scout ] ) ) {
								return true;
							}
							foreach ( $b['items'] as $item ) {
								$k = sanitize_key( (string) ( $item['type'] ?? '' ) ) . '_' . (int) ( $item['id'] ?? 0 );
								if ( isset( $allowed_keys[ $k ] ) ) {
									return true;
								}
							}
							return false;
						}
					)
				);
			}
		}

		$all_explorers           = array();
		$unsynced_explorers      = array();
		$place_only_explorers    = array();
		$exped_only_explorers    = array();
		$missing_edofe_explorers = array();

		foreach ( $buckets as $bucket ) {
			$primary = $bucket['items'][0];
			$level   = strtolower( trim( (string) ( $primary['dofe_level'] ?? '' ) ) );

			$all_explorers[] = array(
				'first_name' => $primary['explorer_first_name'] ?? '',
				'last_name'  => $primary['explorer_last_name'] ?? '',
				'dofe_level' => $level,
			);

			$has_unsynced      = false;
			$has_missing_edofe = true;
			foreach ( $bucket['items'] as $item ) {
				if ( (int) ( $item['scout_id'] ?? 0 ) === 0 || empty( $item['is_synced_osm'] ) ) {
					$has_unsynced = true;
				}
				if ( trim( (string) ( $item['dofe_number'] ?? '' ) ) !== '' ) {
					$has_missing_edofe = false;
				}
			}
			if ( $has_unsynced ) {
				$unsynced_explorers[] = array(
					'first_name' => $primary['explorer_first_name'] ?? '',
					'last_name'  => $primary['explorer_last_name'] ?? '',
					'dofe_level' => $level,
				);
			}
			if ( $has_missing_edofe ) {
				$missing_edofe_explorers[] = array(
					'first_name' => $primary['explorer_first_name'] ?? '',
					'last_name'  => $primary['explorer_last_name'] ?? '',
					'dofe_level' => $level,
				);
			}

			foreach ( array( 'bronze', 'silver', 'gold' ) as $lvl ) {
				$has_p = false;
				$has_e = false;
				foreach ( $bucket['items'] as $item ) {
					if ( strtolower( trim( (string) ( $item['dofe_level'] ?? '' ) ) ) !== $lvl ) {
						continue;
					}
					if ( ( $item['type'] ?? '' ) === 'participant' ) {
						$has_p = true;
					} elseif ( ( $item['type'] ?? '' ) === 'expedition' ) {
						$has_e = true;
					}
				}
				if ( $has_p && ! $has_e ) {
					$place_only_explorers[] = array(
						'first_name' => $primary['explorer_first_name'] ?? '',
						'last_name'  => $primary['explorer_last_name'] ?? '',
						'dofe_level' => $lvl,
					);
				} elseif ( $has_e && ! $has_p ) {
					$exped_only_explorers[] = array(
						'first_name' => $primary['explorer_first_name'] ?? '',
						'last_name'  => $primary['explorer_last_name'] ?? '',
						'dofe_level' => $lvl,
					);
				}
			}
		}

		if ( ! empty( $level_filter ) ) {
			$filter_fn = static fn( array $exp ): bool => in_array( strtolower( (string) ( $exp['dofe_level'] ?? '' ) ), $level_filter, true );
			$all_explorers           = array_values( array_filter( $all_explorers, $filter_fn ) );
			$unsynced_explorers      = array_values( array_filter( $unsynced_explorers, $filter_fn ) );
			$place_only_explorers    = array_values( array_filter( $place_only_explorers, $filter_fn ) );
			$exped_only_explorers    = array_values( array_filter( $exped_only_explorers, $filter_fn ) );
			$missing_edofe_explorers = array_values( array_filter( $missing_edofe_explorers, $filter_fn ) );
		}

		$site_name = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : 'EMS';
		if ( $site_name !== '' && function_exists( 'wp_specialchars_decode' ) ) {
			$site_name = wp_specialchars_decode( $site_name, ENT_QUOTES );
		}

		$token_datasets = array(
			'explorer_list'               => $all_explorers,
			'explorer_list_osm'           => $unsynced_explorers,
			'place_only_explorer_list'    => $place_only_explorers,
			'exped_only_explorer_list'    => $exped_only_explorers,
			'missing_edofe_explorer_list' => $missing_edofe_explorers,
		);

		$resolved_unit_name = trim( (string) ( $unit_record['name'] ?? '' ) );
		if ( $resolved_unit_name === '' ) {
			$resolved_unit_name = $unit_name;
		}

		$replacements = array(
			'{{unit_name}}'           => $resolved_unit_name,
			'{{site_name}}'           => $site_name,
			'{{leader_email}}'        => $recipient,
			'{{explorer_count}}'      => (string) count( $unsynced_explorers ),
			'{{place_only_count}}'    => (string) count( $place_only_explorers ),
			'{{exped_only_count}}'    => (string) count( $exped_only_explorers ),
			'{{missing_edofe_count}}' => (string) count( $missing_edofe_explorers ),
		);

		if ( $chaser_type === 'osm_share' ) {
			$subj_opt     = self::OPTION_OSM_SHARE_SUBJECT;
			$default_subj = self::DEFAULT_OSM_SHARE_SUBJECT;
			$body_opt     = self::OPTION_OSM_SHARE_BODY;
			$default_body = self::DEFAULT_OSM_SHARE_BODY;
			$reply_opt    = self::OPTION_OSM_SHARE_REPLY_TO;
			$count        = count( $unsynced_explorers );
		} elseif ( $chaser_type === 'missing_form7' ) {
			$subj_opt     = self::OPTION_MISSING_FORM7_SUBJECT;
			$default_subj = self::DEFAULT_MISSING_FORM7_SUBJECT;
			$body_opt     = self::OPTION_MISSING_FORM7_BODY;
			$default_body = self::DEFAULT_MISSING_FORM7_BODY;
			$reply_opt    = self::OPTION_MISSING_FORM7_REPLY_TO;
			$count        = count( $place_only_explorers );
		} elseif ( $chaser_type === 'missing_edofe' ) {
			$subj_opt     = self::OPTION_MISSING_EDOFE_SUBJECT;
			$default_subj = self::DEFAULT_MISSING_EDOFE_SUBJECT;
			$body_opt     = self::OPTION_MISSING_EDOFE_BODY;
			$default_body = self::DEFAULT_MISSING_EDOFE_BODY;
			$reply_opt    = self::OPTION_MISSING_EDOFE_REPLY_TO;
			$count        = count( $missing_edofe_explorers );
		} else {
			$subj_opt     = self::OPTION_UNPAIRED_SUBJECT;
			$default_subj = self::DEFAULT_UNPAIRED_SUBJECT;
			$body_opt     = self::OPTION_UNPAIRED_BODY;
			$default_body = self::DEFAULT_UNPAIRED_BODY;
			$reply_opt    = self::OPTION_UNPAIRED_REPLY_TO;
			$count        = count( $place_only_explorers ) + count( $exped_only_explorers );
		}

		$rendered = $this->email_service->resolve_template(
			$subj_opt,
			$default_subj,
			$body_opt,
			$default_body,
			$replacements,
			$token_datasets
		);

		if ( $preview ) {
			$raw_subject = trim( (string) get_option( $subj_opt, '' ) );
			if ( $raw_subject === '' ) {
				$raw_subject = $default_subj;
			}
			$raw_body = trim( (string) get_option( $body_opt, '' ) );
			if ( $raw_body === '' ) {
				$raw_body = $default_body;
			}

			$template_slug = $chaser_type === 'osm_share' ? 'osm_share_request' : 'unpaired_chase';
			$registry      = new Email_Template_Registry();
			$template_def  = $registry->get( $template_slug );
			$shortcodes    = is_array( $template_def['tokens'] ?? null ) ? $template_def['tokens'] : array();

			return new \WP_REST_Response(
				array(
					'unit_name'      => $unit_name,
					'to'             => $recipient,
					'subject'        => $rendered['subject'],
					'message'        => $rendered['message'],
					'raw_subject'    => $raw_subject,
					'raw_message'    => $raw_body,
					'replacements'   => $replacements,
					'token_datasets' => $token_datasets,
					'shortcodes'     => $shortcodes,
					'reply_to'       => trim( (string) get_option( $reply_opt, '' ) ),
					'explorer_count' => $count,
				),
				200
			);
		}

		if ( $recipient === '' || ! is_email( $recipient ) ) {
			return new \WP_Error(
				'ems_invalid_leader_email',
				'A valid recipient email address is required.',
				array( 'status' => 400 )
			);
		}

		$sent = $this->email_service->send(
			$recipient,
			$subj_opt,
			$default_subj,
			$body_opt,
			$default_body,
			$reply_opt,
			$replacements,
			true,
			$subject_override !== '' ? $subject_override : null,
			$body_override !== '' ? $body_override : null,
			$token_datasets
		);

		if ( ! $sent ) {
			return new \WP_Error(
				'ems_email_send_failed',
				'Failed to send email via wp_mail.',
				array( 'status' => 500 )
			);
		}

		$now         = current_time( 'mysql' );
		$all_history = get_option( self::OPTION_HISTORY, array() );
		if ( ! is_array( $all_history ) ) {
			$all_history = array();
		}
		if ( ! isset( $all_history[ $chaser_type ] ) || ! is_array( $all_history[ $chaser_type ] ) ) {
			$all_history[ $chaser_type ] = array();
		}
		$all_history[ $chaser_type ][ $unit_name ] = $now;
		update_option( self::OPTION_HISTORY, $all_history );

		// Log dispatch to canonical ems_notification_logs table (unit summary + per-Explorer entries).
		global $wpdb;
		if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'insert' ) ) {
			$subject_used = $subject_override !== '' ? $subject_override : $rendered['subject'];
			$user_id      = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
			$meta_json    = wp_json_encode(
				array(
					'audience'  => 'unit_leader',
					'unit_name' => $unit_name,
					'anomalies' => $anomalies,
				)
			);

			$wpdb->insert(
				$wpdb->prefix . 'ems_notification_logs',
				array(
					'template_slug'   => $chaser_type,
					'trigger_type'    => 'manual',
					'recipient_email' => $recipient,
					'subject'         => $subject_used,
					'metadata'        => $meta_json,
					'status'          => 'sent',
					'triggered_by'    => $user_id,
					'sent_at'         => $now,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
			);

			$included = $request->get_param( 'included_signups' );
			if ( is_array( $included ) ) {
				foreach ( $included as $inc ) {
					$inc_id    = (int) ( $inc['id'] ?? 0 );
					$inc_type  = sanitize_key( (string) ( $inc['type'] ?? '' ) );
					$inc_scout = (int) ( $inc['scout_id'] ?? 0 );
					if ( $inc_id <= 0 && $inc_scout <= 0 ) {
						continue;
					}
					$wpdb->insert(
						$wpdb->prefix . 'ems_notification_logs',
						array(
							'template_slug'      => $chaser_type,
							'trigger_type'       => 'manual',
							'recipient_email'    => $recipient,
							'subject'            => $subject_used,
							'target_scout_id'    => $inc_scout,
							'target_signup_id'   => $inc_id > 0 ? $inc_id : null,
							'target_signup_type' => in_array( $inc_type, array( 'participant', 'expedition' ), true ) ? $inc_type : null,
							'metadata'           => $meta_json,
							'status'             => 'sent',
							'triggered_by'       => $user_id,
							'sent_at'            => $now,
						),
						array( '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s' )
					);
				}
			}
		}

		if ( class_exists( Audit_Logger::class ) && method_exists( Audit_Logger::class, 'log' ) ) {
			Audit_Logger::log(
				'chaser_email_' . $chaser_type . ':' . $unit_name,
				null
			);
		}

		Event_Bus::dispatch(
			'ems.signups.chaser_sent',
			(object) array(
				'chaser_type' => $chaser_type,
				'unit_name'   => $unit_name,
				'recipient'   => $recipient,
				'count'       => $count,
				'sent_at'     => $now,
			)
		);

		return new \WP_REST_Response(
			array(
				'sent'      => true,
				'unit_name' => $unit_name,
				'to'        => $recipient,
				'sent_at'   => $now,
				'history'   => $all_history[ $chaser_type ],
			),
			200
		);
	}

	/**
	 * Sends (or previews) an individual/parent anomaly chaser email and logs per-Explorer history.
	 *
	 * @param string[] $anomalies
	 */
	private function send_individual_chaser( \WP_REST_Request $request, string $chaser_type, array $anomalies ): \WP_REST_Response|\WP_Error {
		$preview          = (bool) $request->get_param( 'preview' );
		$to_raw           = trim( (string) $request->get_param( 'to_override' ) );
		$cc               = trim( (string) $request->get_param( 'cc_email' ) );
		$subject_override = trim( (string) $request->get_param( 'subject_override' ) );
		$body_override    = (string) $request->get_param( 'body_override' );
		$signup_id        = (int) $request->get_param( 'target_signup_id' );
		$signup_type      = sanitize_key( (string) $request->get_param( 'target_signup_type' ) );
		$scout_id         = (int) $request->get_param( 'target_scout_id' );
		$explorer         = trim( (string) $request->get_param( 'explorer_name' ) ) ?: 'Explorer';
		$first_name       = trim( (string) $request->get_param( 'explorer_first_name' ) );
		if ( $first_name === '' ) {
			$parts      = preg_split( '/\s+/', $explorer );
			$first_name = ! empty( $parts[0] ) ? $parts[0] : $explorer;
		}
		$dofe_level = trim( (string) $request->get_param( 'dofe_level' ) );
		$unit_name  = trim( (string) $request->get_param( 'unit_name' ) );

		$site_name = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : 'EMS';
		if ( $site_name !== '' && function_exists( 'wp_specialchars_decode' ) ) {
			$site_name = wp_specialchars_decode( $site_name, ENT_QUOTES );
		}

		$anomalies_list = $this->format_anomaly_labels( $anomalies );

		$replacements = array(
			'{{explorer_name}}'       => $explorer,
			'{{explorer_first_name}}' => $first_name,
			'{{dofe_level}}'          => $dofe_level,
			'{{unit_name}}'           => $unit_name,
			'{{anomalies_list}}'      => $anomalies_list,
			'{{site_name}}'           => $site_name,
		);

		$rendered = $this->email_service->resolve_template(
			self::OPTION_INDIVIDUAL_CHASE_SUBJECT,
			self::DEFAULT_INDIVIDUAL_CHASE_SUBJECT,
			self::OPTION_INDIVIDUAL_CHASE_BODY,
			self::DEFAULT_INDIVIDUAL_CHASE_BODY,
			$replacements
		);

		if ( $preview ) {
			$raw_subject = trim( (string) get_option( self::OPTION_INDIVIDUAL_CHASE_SUBJECT, '' ) );
			if ( $raw_subject === '' ) {
				$raw_subject = self::DEFAULT_INDIVIDUAL_CHASE_SUBJECT;
			}
			$raw_body = trim( (string) get_option( self::OPTION_INDIVIDUAL_CHASE_BODY, '' ) );
			if ( $raw_body === '' ) {
				$raw_body = self::DEFAULT_INDIVIDUAL_CHASE_BODY;
			}

			$registry     = new Email_Template_Registry();
			$template_def = $registry->get( 'individual_chase' );
			$shortcodes   = is_array( $template_def['tokens'] ?? null ) ? $template_def['tokens'] : array();

			return new \WP_REST_Response(
				array(
					'unit_name'    => $unit_name,
					'subject'      => $rendered['subject'],
					'message'      => $rendered['message'],
					'raw_subject'  => $raw_subject,
					'raw_message'  => $raw_body,
					'replacements' => $replacements,
					'shortcodes'   => $shortcodes,
					'reply_to'     => trim( (string) get_option( self::OPTION_INDIVIDUAL_CHASE_REPLY_TO, '' ) ),
				),
				200
			);
		}

		$to_list = array();
		foreach ( preg_split( '/[,;]+/', $to_raw ) ?: array() as $candidate ) {
			$candidate = trim( (string) $candidate );
			$lower     = strtolower( $candidate );
			if ( $candidate !== '' && is_email( $candidate ) && ! isset( $to_list[ $lower ] ) ) {
				$to_list[ $lower ] = (string) preg_replace( '/[\r\n\t]+/', '', $candidate );
			}
		}

		if ( empty( $to_list ) ) {
			return new \WP_Error( 'ems_invalid_recipient_email', 'A valid recipient email address is required.', array( 'status' => 400 ) );
		}

		$to_param = count( $to_list ) === 1 ? reset( $to_list ) : array_values( $to_list );
		$to_log   = implode( ', ', array_values( $to_list ) );

		$has_subject_override = $subject_override !== '';
		$has_body_override    = trim( $body_override ) !== '';
		if ( $has_subject_override || $has_body_override ) {
			$rendered_override = $this->email_service->render(
				$has_subject_override ? $subject_override : $rendered['subject'],
				$has_body_override ? $body_override : $rendered['message'],
				$replacements
			);
			$subject = $rendered_override['subject'];
			$body    = $rendered_override['message'];
		} else {
			$subject = $rendered['subject'];
			$body    = $rendered['message'];
		}

		$base_headers = array();
		if ( $cc !== '' ) {
			$valid_ccs = array();
			foreach ( preg_split( '/[,;]+/', $cc ) ?: array() as $candidate ) {
				$candidate = trim( (string) $candidate );
				$lower     = strtolower( $candidate );
				if ( $candidate !== '' && is_email( $candidate ) && ! isset( $to_list[ $lower ] ) && ! isset( $valid_ccs[ $lower ] ) ) {
					$valid_ccs[ $lower ] = (string) preg_replace( '/[\r\n\t]+/', '', $candidate );
				}
			}
			if ( ! empty( $valid_ccs ) ) {
				$base_headers[] = 'Cc: ' . implode( ', ', array_values( $valid_ccs ) );
			}
		}

		$headers = $this->email_service->build_headers(
			self::OPTION_INDIVIDUAL_CHASE_REPLY_TO,
			$replacements,
			$base_headers,
			true
		);

		$sent = (bool) wp_mail( $to_param, $subject, $body, $headers );
		if ( ! $sent ) {
			return new \WP_Error( 'ems_email_send_failed', 'Failed to send individual chaser email.', array( 'status' => 500 ) );
		}

		$now = current_time( 'mysql' ) ?: gmdate( 'Y-m-d H:i:s' );
		global $wpdb;
		if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'insert' ) ) {
			$wpdb->insert(
				$wpdb->prefix . 'ems_notification_logs',
				array(
					'template_slug'      => $chaser_type,
					'trigger_type'       => 'manual',
					'recipient_email'    => $to_log,
					'cc_emails'          => $cc !== '' ? $cc : null,
					'subject'            => $subject,
					'target_scout_id'    => $scout_id,
					'target_signup_id'   => $signup_id > 0 ? $signup_id : null,
					'target_signup_type' => in_array( $signup_type, array( 'participant', 'expedition' ), true ) ? $signup_type : null,
					'metadata'           => wp_json_encode(
						array(
							'audience'  => 'individual',
							'anomalies' => $anomalies,
						)
					),
					'status'             => 'sent',
					'triggered_by'       => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
					'sent_at'            => $now,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s' )
			);
		}

		return new \WP_REST_Response(
			array(
				'sent'    => true,
				'to'      => $to_log,
				'cc'      => $cc,
				'sent_at' => $now,
			),
			200
		);
	}

	/**
	 * Formats anomaly keys into a comma-separated list of human-readable labels.
	 *
	 * @param string[] $anomalies
	 */
	private function format_anomaly_labels( array $anomalies ): string {
		$labels_map = array(
			'place_without_expedition' => 'Place Without Expedition',
			'expedition_without_place' => 'Expedition Without Place',
			'level_mismatch'           => 'Level Mismatch',
			'duplicate_signup'         => 'Duplicate',
			'unmatched_osm'            => 'Unmatched OSM',
			'pending_osm_sync'         => 'Pending OSM Sync',
			'missing_edofe'            => 'Missing eDofE',
			'unvalidated_asn'          => 'Unvalidated ASN',
		);

		$labels = array();
		foreach ( $anomalies as $key ) {
			$clean = sanitize_key( (string) $key );
			if ( $clean !== '' ) {
				$labels[] = esc_html( $labels_map[ $clean ] ?? $clean );
			}
		}

		return implode( ', ', $labels );
	}

	/**
	 * Finds a unit record matching the given unit_name or short_code.
	 *
	 * @param array<int,array<string,mixed>> $units
	 * @param string                         $unit_name
	 * @return array<string,mixed>
	 */
	private function find_unit_record( array $units, string $unit_name ): array {
		$needle = strtolower( trim( $unit_name ) );
		foreach ( $units as $u ) {
			$name  = strtolower( trim( (string) ( $u['name'] ?? '' ) ) );
			$short = strtolower( trim( (string) ( $u['short_code'] ?? '' ) ) );
			if ( $needle !== '' && ( $name === $needle || $short === $needle ) ) {
				return $u;
			}
		}
		return array();
	}

	/**
	 * Returns all active (non-archived) signups belonging to the specified unit.
	 *
	 * @param string              $unit_name
	 * @param array<string,mixed> $unit_record
	 * @return array<int,array<string,mixed>>
	 */
	private function get_unit_active_signups( string $unit_name, array $unit_record ): array {
		$participants = $this->signup_repo->get_participant_signups( 'all' );
		$expeditions  = $this->signup_repo->get_expedition_signups( 'all' );

		$all = array();
		foreach ( $participants as $p ) {
			$p['type'] = 'participant';
			$all[]     = $p;
		}
		foreach ( $expeditions as $e ) {
			$e['type'] = 'expedition';
			$all[]     = $e;
		}

		$match_names = array_filter(
			array(
				strtolower( trim( $unit_name ) ),
				strtolower( trim( (string) ( $unit_record['name'] ?? '' ) ) ),
				strtolower( trim( (string) ( $unit_record['short_code'] ?? '' ) ) ),
			)
		);

		$filtered = array();
		foreach ( $all as $s ) {
			if ( ( $s['signup_status'] ?? '' ) === 'archived' ) {
				continue;
			}
			$s_unit = strtolower( trim( (string) ( $s['unit_name'] ?? 'Unassigned' ) ) );
			if ( in_array( $s_unit, $match_names, true ) ) {
				$filtered[] = $s;
			}
		}

		return $filtered;
	}

	/**
	 * Groups signup rows by explorer identity (scout_id or email/name fallback).
	 *
	 * @param array<int,array<string,mixed>> $signups
	 * @return array<int,array{scout_id: int, keys: array<string,bool>, items: array<int,array<string,mixed>>}>
	 */
	private function group_by_explorer_identity( array $signups ): array {
		$groups = array();

		foreach ( $signups as $s ) {
			$scout_id   = (int) ( $s['scout_id'] ?? 0 );
			$email      = strtolower( trim( (string) ( $s['explorer_email'] ?? '' ) ) );
			$first_name = strtolower( trim( (string) ( $s['explorer_first_name'] ?? '' ) ) );
			$last_name  = strtolower( trim( (string) ( $s['explorer_last_name'] ?? '' ) ) );
			$full_name  = ( $first_name !== '' && $last_name !== '' ) ? "{$first_name}_{$last_name}" : '';
			$parent     = strtolower( trim( (string) ( $s['parent_email'] ?? '' ) ) );

			$matched_idx = null;
			if ( $scout_id !== 0 ) {
				foreach ( $groups as $idx => $g ) {
					if ( $g['scout_id'] === $scout_id ) {
						$matched_idx = $idx;
						break;
					}
				}
			}

			if ( $matched_idx === null ) {
				foreach ( $groups as $idx => $g ) {
					if ( $scout_id !== 0 && $g['scout_id'] !== 0 && $g['scout_id'] !== $scout_id ) {
						continue;
					}
					if (
						( $email !== '' && isset( $g['keys'][ "email_{$email}" ] ) ) ||
						( $full_name !== '' && $parent !== '' && isset( $g['keys'][ "name_{$parent}_{$full_name}" ] ) ) ||
						( $full_name !== '' && isset( $g['keys'][ "fullname_{$full_name}" ] ) )
					) {
						$matched_idx = $idx;
						break;
					}
				}
			}

			$item_keys = array();
			if ( $scout_id !== 0 ) {
				$item_keys[ "scout_{$scout_id}" ] = true;
			}
			if ( $email !== '' ) {
				$item_keys[ "email_{$email}" ] = true;
			}
			if ( $full_name !== '' && $parent !== '' ) {
				$item_keys[ "name_{$parent}_{$full_name}" ] = true;
			}
			if ( $full_name !== '' ) {
				$item_keys[ "fullname_{$full_name}" ] = true;
			}

			if ( $matched_idx !== null ) {
				if ( $groups[ $matched_idx ]['scout_id'] === 0 && $scout_id !== 0 ) {
					$groups[ $matched_idx ]['scout_id'] = $scout_id;
				}
				$groups[ $matched_idx ]['keys']    = array_merge( $groups[ $matched_idx ]['keys'], $item_keys );
				$groups[ $matched_idx ]['items'][] = $s;
			} else {
				$groups[] = array(
					'scout_id' => $scout_id,
					'keys'     => $item_keys,
					'items'    => array( $s ),
				);
			}
		}

		return $groups;
	}
}
