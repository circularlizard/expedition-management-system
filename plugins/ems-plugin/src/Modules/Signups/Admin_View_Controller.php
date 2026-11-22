<?php
namespace EMS\Modules\Signups;

use EMS\Data\Expedition_Repository;
use EMS\Data\Team_Repository;
use EMS\Data\Team_Member_Repository;
use EMS\Integrations\TutorLMS_Client;

/**
 * Controller for administrative views (Expedition Board).
 */
class Admin_View_Controller {

	private Team_Repository $teams;
	private Team_Member_Repository $team_members;
	private TutorLMS_Client $tutor_client;

	public function __construct(
		Team_Repository $teams,
		Team_Member_Repository $team_members,
		TutorLMS_Client $tutor_client
	) {
		$this->teams        = $teams;
		$this->team_members = $team_members;
		$this->tutor_client = $tutor_client;
	}

	/**
	 * Registers REST routes for the admin views.
	 */
	public function register_routes(): void {
		// NOTE: The `/expedition-board` route is intentionally NOT registered here.
		// It is served by Expedition_Admin_Controller::get_board() (Stage 1.12), which
		// returns the season → event → team hierarchy the current React board expects.
		// Registering it here as well caused a route collision where this legacy
		// handler's payload shape ({expeditions, teams, ...}) shadowed the new one,
		// leaving the board blank because `data.seasons` was undefined.
		register_rest_route(
			'ems/v1',
			'/explorer/(?P<scout_id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_explorer_detail' ),
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
				'args'                => array(
					'scout_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			'ems/v1',
			'/explorers/360',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_explorer_360' ),
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
			)
		);
		register_rest_route(
			'ems/v1',
			'/team/(?P<team_id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_team_detail' ),
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
				'args'                => array(
					'team_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			'ems/v1',
			'/patrol/(?P<patrol>[^/]+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_patrol_detail' ),
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
				'args'                => array(
					'patrol' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			'ems/v1',
			'/events/(?P<id>\d+)/training-requirements',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_event_training_requirements' ),
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			'ems/v1',
			'/events/(?P<id>\d+)/training-requirements',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_event_training_requirements' ),
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);
	}


	/**
	 * GET ems/v1/explorer/{scout_id}
	 */
	public function get_explorer_detail( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$scout_id = (int) $request->get_param( 'scout_id' );

		global $wpdb;
		$table = $wpdb->prefix . 'ems_osm_explorers';
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE scout_id = %d",
				$scout_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return new \WP_Error( 'ems_explorer_not_found', 'Explorer not found.', array( 'status' => 404 ) );
		}

		$wp_user_id = (int) ( $row['wp_user_id'] ?? 0 );

		return new \WP_REST_Response(
			array(
				'scout_id'    => (int) $row['scout_id'],
				'first_name'  => $row['first_name'] ?? '',
				'last_name'   => $row['last_name'] ?? '',
				'email'       => $row['email'] ?? '',
				'patrol'      => $row['patrol'] ?? '',
				'training'    => $wp_user_id > 0 ? $this->get_user_training_summary( $wp_user_id ) : array(),
				'last_synced' => get_option( 'ems_osm_last_sync' ) ?: null,
			)
		);
	}

	/**
	 * GET ems/v1/explorers/360
	 * Consolidated Explorer 360° view (works for both scout_id > 0 and unmatched scout_id = 0).
	 */
	public function get_explorer_360( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$scout_id    = (int) $request->get_param( 'scout_id' );
		$signup_type = sanitize_key( (string) $request->get_param( 'signup_type' ) );
		$signup_id   = (int) $request->get_param( 'signup_id' );

		$p_table   = $wpdb->prefix . 'ems_participant_signups';
		$e_table   = $wpdb->prefix . 'ems_expedition_signups';
		$osm_table = $wpdb->prefix . 'ems_osm_explorers';

		$osm_row = null;
		if ( $scout_id > 0 ) {
			$osm_row = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$osm_table} WHERE scout_id = %d LIMIT 1", $scout_id ),
				ARRAY_A
			);
		}

		$participant_rows = array();
		$expedition_rows  = array();

		if ( $scout_id > 0 ) {
			$participant_rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$p_table} WHERE scout_id = %d ORDER BY created_at DESC", $scout_id ),
				ARRAY_A
			) ?: array();
			$expedition_rows  = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$e_table} WHERE scout_id = %d ORDER BY created_at DESC", $scout_id ),
				ARRAY_A
			) ?: array();
		} elseif ( $signup_id > 0 && in_array( $signup_type, array( 'participant', 'expedition' ), true ) ) {
			$anchor_table = $signup_type === 'participant' ? $p_table : $e_table;
			$anchor       = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$anchor_table} WHERE id = %d LIMIT 1", $signup_id ),
				ARRAY_A
			);
			if ( ! $anchor ) {
				return new \WP_Error( 'ems_explorer_not_found', 'Explorer sign-up not found.', array( 'status' => 404 ) );
			}

			$anchor_scout = (int) ( $anchor['scout_id'] ?? 0 );
			if ( $anchor_scout > 0 ) {
				$scout_id         = $anchor_scout;
				$osm_row          = $wpdb->get_row(
					$wpdb->prepare( "SELECT * FROM {$osm_table} WHERE scout_id = %d LIMIT 1", $scout_id ),
					ARRAY_A
				);
				$participant_rows = $wpdb->get_results(
					$wpdb->prepare( "SELECT * FROM {$p_table} WHERE scout_id = %d ORDER BY created_at DESC", $scout_id ),
					ARRAY_A
				) ?: array();
				$expedition_rows  = $wpdb->get_results(
					$wpdb->prepare( "SELECT * FROM {$e_table} WHERE scout_id = %d ORDER BY created_at DESC", $scout_id ),
					ARRAY_A
				) ?: array();
			} else {
				$first  = strtolower( trim( (string) ( $anchor['explorer_first_name'] ?? '' ) ) );
				$last   = strtolower( trim( (string) ( $anchor['explorer_last_name'] ?? '' ) ) );
				$parent = strtolower( trim( (string) ( $anchor['parent_email'] ?? '' ) ) );

				$participant_rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM {$p_table}
						 WHERE scout_id = 0
						   AND LOWER(TRIM(explorer_first_name)) = %s
						   AND LOWER(TRIM(explorer_last_name)) = %s
						   AND LOWER(TRIM(COALESCE(parent_email, ''))) = %s
						 ORDER BY created_at DESC",
						$first,
						$last,
						$parent
					),
					ARRAY_A
				) ?: array();
				$expedition_rows  = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM {$e_table}
						 WHERE scout_id = 0
						   AND LOWER(TRIM(explorer_first_name)) = %s
						   AND LOWER(TRIM(explorer_last_name)) = %s
						   AND LOWER(TRIM(COALESCE(parent_email, ''))) = %s
						 ORDER BY created_at DESC",
						$first,
						$last,
						$parent
					),
					ARRAY_A
				) ?: array();
			}
		} else {
			return new \WP_Error( 'ems_missing_identifier', 'Provide scout_id or signup_type + signup_id.', array( 'status' => 400 ) );
		}

		// Annotate anomalies using SignupAnomalyService.
		$is_synced_osm = ! empty( $osm_row );
		foreach ( $participant_rows as &$pr ) {
			$pr['is_synced_osm'] = $is_synced_osm ? 1 : 0;
		}
		unset( $pr );
		foreach ( $expedition_rows as &$er ) {
			$er['is_synced_osm'] = $is_synced_osm ? 1 : 0;
		}
		unset( $er );

		$anomaly_service = new \EMS\Modules\Signups\Services\SignupAnomalyService();
		$anomaly_result  = $anomaly_service->computeAnomalies( $participant_rows, $expedition_rows );
		$by_key          = $anomaly_result['by_key'];

		$all_signups = array();
		foreach ( $expedition_rows as $er ) {
			$prefs = ! empty( $er['expedition_preferences'] ) ? json_decode( (string) $er['expedition_preferences'], true ) : null;
			$all_signups[] = array(
				'id'                          => (int) $er['id'],
				'type'                        => 'expedition',
				'scout_id'                    => (int) $er['scout_id'],
				'unit_id'                     => ! empty( $er['unit_id'] ) ? (int) $er['unit_id'] : null,
				'unit_name'                   => $er['unit_name'] ?: 'Unassigned',
				'explorer_first_name'         => $er['explorer_first_name'] ?? '',
				'explorer_last_name'          => $er['explorer_last_name'] ?? '',
				'explorer_email'              => $er['explorer_email'] ?? '',
				'parent_email'                => $er['parent_email'] ?? '',
				'leader_email'                => $er['leader_email'] ?? '',
				'dofe_level'                  => $er['dofe_level'] ?? '',
				'dofe_number'                 => $er['dofe_number'] ?? null,
				'expedition_preferences'      => is_array( $prefs ) ? $prefs : null,
				'additional_support_needs'    => $er['additional_support_needs'] ?? '',
				'asn_validated'               => isset( $er['asn_validated'] ) && $er['asn_validated'] !== null ? (int) $er['asn_validated'] : null,
				'first_aid_status'            => $er['first_aid_status'] ?? 'none',
				'first_aid_expiry'            => $er['first_aid_expiry'] ?? null,
				'signup_status'               => $er['signup_status'] ?? 'submitted',
				'kanban_stage'                => ( $er['kanban_stage'] ?? 'new' ) === 'to_process' ? 'new' : ( $er['kanban_stage'] ?? 'new' ),
				'processing_notes'            => $er['processing_notes'] ?? '',
				'participant_place_allocated' => ! empty( $er['participant_place_allocated'] ),
				'participant_signup_id'       => ! empty( $er['participant_signup_id'] ) ? (int) $er['participant_signup_id'] : null,
				'anomalies'                   => $by_key[ 'expedition_' . (int) $er['id'] ] ?? array(),
				'created_at'                  => $er['created_at'] ?? '',
			);
		}
		foreach ( $participant_rows as $pr ) {
			$all_signups[] = array(
				'id'                  => (int) $pr['id'],
				'type'                => 'participant',
				'scout_id'            => (int) $pr['scout_id'],
				'unit_id'             => ! empty( $pr['unit_id'] ) ? (int) $pr['unit_id'] : null,
				'unit_name'           => $pr['unit_name'] ?: 'Unassigned',
				'explorer_first_name' => $pr['explorer_first_name'] ?? '',
				'explorer_last_name'  => $pr['explorer_last_name'] ?? '',
				'explorer_email'      => $pr['explorer_email'] ?? '',
				'parent_email'        => $pr['parent_email'] ?? '',
				'leader_email'        => $pr['leader_email'] ?? '',
				'dofe_level'          => $pr['dofe_level'] ?? '',
				'dofe_number'         => $pr['dofe_number'] ?? null,
				'dofe_registered'     => $pr['dofe_registered'] ?? 'n',
				'payment_status'      => $pr['payment_status'] ?? 'pending',
				'signup_status'       => $pr['signup_status'] ?? 'submitted',
				'kanban_stage'        => ( $pr['kanban_stage'] ?? 'new' ) === 'to_process' ? 'new' : ( $pr['kanban_stage'] ?? 'new' ),
				'processing_notes'    => $pr['processing_notes'] ?? '',
				'anomalies'           => $by_key[ 'participant_' . (int) $pr['id'] ] ?? array(),
				'created_at'          => $pr['created_at'] ?? '',
			);
		}

		// Order: active expeditions first, active participant places second, archived last.
		usort(
			$all_signups,
			static function ( array $a, array $b ): int {
				$a_arch = ( $a['signup_status'] ?? '' ) === 'archived' ? 1 : 0;
				$b_arch = ( $b['signup_status'] ?? '' ) === 'archived' ? 1 : 0;
				if ( $a_arch !== $b_arch ) {
					return $a_arch <=> $b_arch;
				}
				$a_exp = ( $a['type'] ?? '' ) === 'expedition' ? 0 : 1;
				$b_exp = ( $b['type'] ?? '' ) === 'expedition' ? 0 : 1;
				if ( $a_exp !== $b_exp ) {
					return $a_exp <=> $b_exp;
				}
				return strcmp( (string) ( $b['created_at'] ?? '' ), (string) ( $a['created_at'] ?? '' ) );
			}
		);

		// Contact canonical resolution & mismatch detection.
		$osm_exp_email    = trim( (string) ( $osm_row['email1'] ?? $osm_row['email'] ?? '' ) );
		$osm_parent_email = trim( (string) ( $osm_row['p1_email1'] ?? $osm_row['parent_email'] ?? '' ) );

		$discrepancies = array();
		foreach ( $all_signups as $s ) {
			if ( ( $s['signup_status'] ?? 'submitted' ) === 'archived' ) {
				continue;
			}
			$form_exp    = trim( (string) ( $s['explorer_email'] ?? '' ) );
			$form_parent = trim( (string) ( $s['parent_email'] ?? '' ) );

			if ( $osm_row ) {
				if ( $osm_exp_email !== '' && $form_exp !== '' && strcasecmp( $osm_exp_email, $form_exp ) !== 0 ) {
					$discrepancies[] = array(
						'signup_id'   => $s['id'],
						'signup_type' => $s['type'],
						'field'       => 'explorer_email',
						'osm_value'   => $osm_exp_email,
						'form_value'  => $form_exp,
					);
				}
				if ( $osm_parent_email !== '' && $form_parent !== '' && strcasecmp( $osm_parent_email, $form_parent ) !== 0 ) {
					$discrepancies[] = array(
						'signup_id'   => $s['id'],
						'signup_type' => $s['type'],
						'field'       => 'parent_email',
						'osm_value'   => $osm_parent_email,
						'form_value'  => $form_parent,
					);
				}
			}
		}

		$first_signup     = $all_signups[0] ?? array();
		$resolved_exp     = $osm_exp_email !== '' ? $osm_exp_email : trim( (string) ( $first_signup['explorer_email'] ?? '' ) );
		$resolved_parent  = $osm_parent_email !== '' ? $osm_parent_email : trim( (string) ( $first_signup['parent_email'] ?? '' ) );

		// Teams & Training.
		$teams = array();
		if ( $scout_id > 0 ) {
			$tm_table = $wpdb->prefix . 'ems_team_members';
			$c_table  = $wpdb->prefix . 'ems_cohorts';
			$team_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT tm.id, tm.team_post_id, tm.cohort_id, tm.notification_status, tm.availability_override,
					        c.name AS cohort_name, c.dofe_level, c.transport_mode, c.cohort_status,
					        t.post_title AS team_name
					 FROM {$tm_table} tm
					 LEFT JOIN {$c_table} c ON tm.cohort_id = c.id
					 LEFT JOIN {$wpdb->posts} t ON tm.team_post_id = t.ID
					 WHERE tm.scout_id = %d
					 ORDER BY tm.id DESC",
					$scout_id
				),
				ARRAY_A
			) ?: array();
			$teams = $team_rows;
		}

		$training = array(
			'status'            => 'unavailable',
			'completed_modules' => 0,
			'total_modules'     => 0,
		);
		if ( $scout_id > 0 && class_exists( \EMS\Integrations\TutorLms\TutorLmsAdapter::class ) ) {
			$dto      = ( new \EMS\Integrations\TutorLms\TutorLmsAdapter() )->getExplorerCourseProgress( $scout_id );
			$training = array(
				'status'            => $dto->status ?? 'unavailable',
				'completed_modules' => $dto->completedModules ?? 0,
				'total_modules'     => $dto->totalModules ?? 0,
			);
		}

		// Interaction & Audit History.
		$history    = array();
		$logs_table = $wpdb->prefix . 'ems_notification_logs';
		$signup_ids = array_values( array_filter( array_map( static fn( $s ) => (int) $s['id'], $all_signups ) ) );
		if ( $scout_id > 0 ) {
			$n_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, template_slug, recipient_email, subject, metadata, sent_at FROM {$logs_table} WHERE target_scout_id = %d ORDER BY sent_at DESC LIMIT 30",
					$scout_id
				),
				ARRAY_A
			) ?: array();
		} elseif ( ! empty( $signup_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $signup_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$n_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, template_slug, recipient_email, subject, metadata, sent_at FROM {$logs_table} WHERE target_signup_id IN ({$placeholders}) ORDER BY sent_at DESC LIMIT 30",
					...$signup_ids
				),
				ARRAY_A
			) ?: array();
		} else {
			$n_rows = array();
		}

		foreach ( $n_rows as $nr ) {
			$meta      = ! empty( $nr['metadata'] ) ? json_decode( (string) $nr['metadata'], true ) : array();
			$history[] = array(
				'kind'            => 'chaser',
				'title'           => ( ( $meta['audience'] ?? '' ) === 'individual' ? 'Chased Individual/Parent' : 'Chased Unit Leader' ) . ' (' . ( $nr['recipient_email'] ?? '' ) . ')',
				'detail'          => is_array( $meta['anomalies'] ?? null ) ? implode( ', ', $meta['anomalies'] ) : ( $nr['subject'] ?? '' ),
				'timestamp'       => $nr['sent_at'] ?? '',
			);
		}

		return new \WP_REST_Response(
			array(
				'explorer' => array(
					'scout_id'        => $scout_id,
					'is_synced_osm'   => $is_synced_osm,
					'first_name'      => $osm_row['first_name'] ?? ( $first_signup['explorer_first_name'] ?? '' ),
					'last_name'       => $osm_row['last_name'] ?? ( $first_signup['explorer_last_name'] ?? '' ),
					'patrol'          => $osm_row['patrol'] ?? '',
					'unit_name'       => $first_signup['unit_name'] ?? 'Unassigned',
					'dofe_number'     => $osm_row['dofe_number'] ?? ( $first_signup['dofe_number'] ?? null ),
					'first_aid_level' => $osm_row['first_aid_level'] ?? ( $first_signup['first_aid_status'] ?? 'none' ),
				),
				'contacts' => array(
					'osm_canonical' => $osm_row ? array(
						'explorer_email' => $osm_exp_email,
						'parent_email'   => $osm_parent_email,
						'email2'         => $osm_row['email2'] ?? '',
						'p1_email2'      => $osm_row['p1_email2'] ?? '',
						'p2_email1'      => $osm_row['p2_email1'] ?? '',
						'p2_email2'      => $osm_row['p2_email2'] ?? '',
					) : null,
					'resolved'      => array(
						'explorer_email' => $resolved_exp,
						'parent_email'   => $resolved_parent,
					),
					'discrepancies' => $discrepancies,
				),
				'signups'  => $all_signups,
				'teams'    => $teams,
				'training' => $training,
				'history'  => $history,
			),
			200
		);
	}

	/**
	 * GET ems/v1/team/{team_id}
	 */
	public function get_team_detail( \WP_REST_Request $request ): \WP_REST_Response {
		$team_id = (int) $request->get_param( 'team_id' );
		$members = $this->team_members->list_by_team( $team_id );

		global $wpdb;
		$explorers_table = $wpdb->prefix . 'ems_osm_explorers';

		$hydrated        = array();
		$first_aid_count = 0;

		foreach ( $members as $member ) {
			$wp_user_id = (int) ( $member['user_id'] ?? 0 );
			$row        = $wp_user_id > 0
				? $wpdb->get_row(
					$wpdb->prepare(
						"SELECT * FROM {$explorers_table} WHERE wp_user_id = %d",
						$wp_user_id
					),
					ARRAY_A
				)
				: null;

			$training = array();
			if ( $wp_user_id > 0 ) {
				$training = $this->get_user_training_summary( $wp_user_id );
				if ( ! empty( $row['first_aid'] ) ) {
					++$first_aid_count;
				}
			}

			$hydrated[] = array(
				'user_id'    => $wp_user_id,
				'scout_id'   => $row ? (int) $row['scout_id'] : 0,
				'first_name' => $row['first_name'] ?? '',
				'last_name'  => $row['last_name'] ?? '',
				'patrol'     => $row['patrol'] ?? '',
				'training'   => $training,
			);
		}

		return new \WP_REST_Response(
			array(
				'team_id'           => $team_id,
				'members'           => $hydrated,
				'first_aid_covered' => $first_aid_count > 0,
				'last_synced'       => get_option( 'ems_osm_last_sync' ) ?: null,
			)
		);
	}

	/**
	 * GET ems/v1/patrol/{patrol}
	 */
	public function get_patrol_detail( \WP_REST_Request $request ): \WP_REST_Response {
		$patrol = sanitize_text_field( urldecode( $request->get_param( 'patrol' ) ) );

		global $wpdb;
		$table = $wpdb->prefix . 'ems_osm_explorers';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE patrol = %s ORDER BY last_name, first_name",
				$patrol
			),
			ARRAY_A
		);

		$explorers = array_map(
			static function ( array $row ): array {
				return array(
					'scout_id'   => (int) $row['scout_id'],
					'first_name' => $row['first_name'] ?? '',
					'last_name'  => $row['last_name'] ?? '',
					'email'      => $row['email'] ?? '',
					'patrol'     => $row['patrol'] ?? '',
				);
			},
			$rows ?? array()
		);

		return new \WP_REST_Response(
			array(
				'patrol'      => $patrol,
				'explorers'   => $explorers,
				'last_synced' => get_option( 'ems_osm_last_sync' ) ?: null,
			)
		);
	}


	/**
	 * Returns a simple training status summary for a user.
	 */
	private function get_user_training_summary( int $user_id ): array {
		$courses    = $this->tutor_client->get_all_courses();
		$course_ids = array_map( fn( $c ) => $c->ID, $courses );

		$matrix      = $this->tutor_client->get_enrollment_matrix( array( $user_id ), $course_ids );
		$user_matrix = $matrix[ $user_id ] ?? array();

		$complete = 0;
		foreach ( $user_matrix as $status ) {
			if ( $status === 'complete' ) {
				++$complete;
			}
		}

		return array(
			'total'    => count( $course_ids ),
			'complete' => $complete,
			'percent'  => count( $course_ids ) > 0 ? round( ( $complete / count( $course_ids ) ) * 100 ) : 0,
		);
	}

	/**
	 * GET ems/v1/events/{id}/training-requirements
	 */
	public function get_event_training_requirements( \WP_REST_Request $request ): \WP_REST_Response {
		$event_id   = (int) $request->get_param( 'id' );
		$course_ids = get_post_meta( $event_id, 'ems_training_requirements', true );
		if ( ! is_array( $course_ids ) ) {
			$course_ids = array();
		} else {
			$course_ids = array_map( 'intval', $course_ids );
		}

		$all_courses = $this->tutor_client->get_all_courses();
		$courses     = array_map(
			function ( $course ) {
				return array(
					'id'    => (int) $course->ID,
					'title' => $course->post_title,
				);
			},
			$all_courses
		);

		// 1. Get all teams for the event, including UNALLOCATED
		$teams_list  = $this->teams->list_by_expedition( $event_id );
		$unallocated = $this->teams->get_unallocated_team( $event_id );
		if ( $unallocated ) {
			$teams_list[] = $unallocated;
		}

		// 2. Get all members of these teams
		$member_ids = array();
		foreach ( $teams_list as $t ) {
			$m_list = $this->team_members->list_by_team( $t['ID'] );
			foreach ( $m_list as $m ) {
				$scout_id = (int) ( $m['scout_id'] ?? 0 );
				if ( $scout_id > 0 ) {
					$member_ids[] = $scout_id;
				}
			}
		}

		$completion = array();
		if ( ! empty( $member_ids ) ) {
			global $wpdb;
			$explorers_table = $wpdb->prefix . 'ems_osm_explorers';
			$ids_placeholder = implode( ',', array_fill( 0, count( $member_ids ), '%d' ) );
			$rows            = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT e.scout_id, e.wp_user_id, e.first_name, e.last_name, COALESCE(u.name, p.name, e.patrol) as unit_name 
                 FROM {$explorers_table} e
                 LEFT JOIN {$wpdb->prefix}ems_unit_patrols p ON e.section_id = p.section_id AND e.patrol = p.name
                 LEFT JOIN {$wpdb->prefix}ems_units u ON (p.unit_id = u.unit_id OR e.section_id = u.unit_id)
                 WHERE e.scout_id IN ({$ids_placeholder})",
					...$member_ids
				),
				ARRAY_A
			);

			$user_ids = array();
			foreach ( $rows as $r ) {
				$u_id = (int) ( $r['wp_user_id'] ?? 0 );
				if ( $u_id > 0 ) {
					$user_ids[] = $u_id;
				}
			}

			// Get enrollment matrix
			$enrollment_matrix = array();
			if ( ! empty( $user_ids ) && ! empty( $course_ids ) ) {
				$enrollment_matrix = $this->tutor_client->get_enrollment_matrix( $user_ids, $course_ids ) ?: array();
			}

			foreach ( $rows as $r ) {
				$scout_id    = (int) $r['scout_id'];
				$u_id        = (int) ( $r['wp_user_id'] ?? 0 );
				$user_matrix = isset( $enrollment_matrix[ $u_id ] ) ? $enrollment_matrix[ $u_id ] : array();

				// Format user matrix to map course_id => status
				$matrix_formatted = array();
				foreach ( $course_ids as $c_id ) {
					$matrix_formatted[ $c_id ] = $user_matrix[ $c_id ] ?? 'not_enrolled';
				}

				$completion[] = array(
					'scout_id'   => $scout_id,
					'first_name' => $r['first_name'] ?? '',
					'last_name'  => $r['last_name'] ?? '',
					'user_id'    => $u_id,
					'unit_name'  => $r['unit_name'] ?: 'Unassigned',
					'matrix'     => $matrix_formatted,
				);
			}
		}

		return new \WP_REST_Response(
			array(
				'course_ids' => $course_ids,
				'courses'    => $courses,
				'completion' => $completion,
			),
			200
		);
	}

	/**
	 * POST ems/v1/events/{id}/training-requirements
	 */
	public function update_event_training_requirements( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$event_id = (int) $request->get_param( 'id' );
		$params   = $request->get_json_params();

		if ( ! is_array( $params ) || ! isset( $params['course_ids'] ) || ! is_array( $params['course_ids'] ) ) {
			return new \WP_Error( 'ems_invalid_parameters', 'Invalid parameters.', array( 'status' => 400 ) );
		}

		$course_ids = array_map( 'intval', $params['course_ids'] );
		update_post_meta( $event_id, 'ems_training_requirements', $course_ids );

		return new \WP_REST_Response(
			array(
				'success'    => true,
				'course_ids' => $course_ids,
			),
			200
		);
	}
}
