<?php
namespace EMS\Modules\Expeditions\Rest;

use EMS\Modules\Expeditions\Repositories\CohortMemberRepository;
use EMS\Modules\Expeditions\Repositories\CohortRepository;

/**
 * REST Controller for persistent expedition cohorts, scheduling,
 * manual place-offer notification dispatch, and team readiness.
 */
class CohortController {

	private const NAMESPACE = 'ems/v1';

	private CohortRepository $cohorts;
	private CohortMemberRepository $members;
	private ?object $scheduler;
	private ?object $readiness;
	private ?object $dispatcher;

	public function __construct(
		?CohortRepository $cohorts = null,
		?CohortMemberRepository $members = null,
		?object $scheduler = null,
		?object $readiness = null,
		?object $dispatcher = null
	) {
		$this->cohorts    = $cohorts ?? new CohortRepository();
		$this->members    = $members ?? new CohortMemberRepository();
		$this->scheduler  = $scheduler;
		$this->readiness  = $readiness;
		$this->dispatcher = $dispatcher;
	}

	/**
	 * Register all cohort, scheduling, notification, and readiness routes under ems/v1.
	 */
	public function register_routes(): void {
		$this->register_cohort_routes();
		$this->register_member_routes();
		$this->register_schedule_routes();
		$this->register_notification_routes();
		$this->register_readiness_routes();
	}

	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	private function register_cohort_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/cohorts',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_cohorts' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_cohort' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/cohorts/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_cohort' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => $this->id_arg(),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_cohort' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => $this->id_arg(),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/cohorts/(?P<id>\d+)/merge',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'merge_cohort' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id' => $this->id_arg(),
				),
			)
		);
	}

	private function register_member_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/cohorts/(?P<id>\d+)/members',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'add_cohort_members' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id' => $this->id_arg(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/cohorts/(?P<id>\d+)/members/(?P<expedition_signup_id>\d+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'remove_cohort_member' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id'                   => $this->id_arg(),
					'expedition_signup_id' => $this->id_arg(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/cohorts/resolve-query',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'resolve_member_query' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/cohorts/(?P<id>\d+)/members/(?P<expedition_signup_id>\d+)/stage-override',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'set_stage_override' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id'                   => $this->id_arg(),
					'expedition_signup_id' => $this->id_arg(),
				),
			)
		);
	}

	private function register_schedule_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/cohorts/(?P<id>\d+)/schedule',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_cohort_schedule_candidates' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => $this->id_arg(),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'schedule_cohort' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => $this->id_arg(),
					),
				),
			)
		);
	}

	private function register_notification_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/expeditions/notifications/preview',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'preview_notifications' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/expeditions/notifications/dispatch',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'dispatch_notifications' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	private function register_readiness_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/expeditions/teams-readiness',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_teams_readiness' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * GET /wp-json/ems/v1/cohorts
	 */
	public function list_cohorts( \WP_REST_Request $request ): \WP_REST_Response {
		$filters = array();

		$string_filters = array( 'dofe_level', 'transport_mode', 'cohort_status', 'search' );
		foreach ( $string_filters as $key ) {
			$val = $request->get_param( $key );
			if ( is_string( $val ) && $val !== '' ) {
				$filters[ $key ] = sanitize_text_field( $val );
			}
		}

		$int_filters = array( 'practice_expedition_id', 'qualifier_expedition_id', 'expedition_id' );
		foreach ( $int_filters as $key ) {
			$val = absint( $request->get_param( $key ) );
			if ( $val > 0 ) {
				$filters[ $key ] = $val;
			}
		}

		$cohorts = $this->cohorts->listByFilters( $filters );
		if ( empty( $cohorts ) ) {
			return new \WP_REST_Response( array(), 200 );
		}

		$cohort_ids        = array_map( static fn( array $c ): int => (int) $c['id'], $cohorts );
		$all_members       = $this->members->listByCohorts( $cohort_ids );
		$offer_map         = $this->fetch_member_offer_statuses( $cohort_ids );
		$members_by_cohort = array();
		$cohort_by_prac    = array();
		$cohort_by_qual    = array();
		$cohort_names      = array();

		foreach ( $cohorts as $c ) {
			$cid                  = (int) $c['id'];
			$cohort_names[ $cid ] = (string) ( $c['name'] ?? "Team #{$cid}" );
			if ( ! empty( $c['practice_team_post_id'] ) ) {
				$cohort_by_prac[ (int) $c['practice_team_post_id'] ] = $cid;
			}
			if ( ! empty( $c['qualifier_team_post_id'] ) ) {
				$cohort_by_qual[ (int) $c['qualifier_team_post_id'] ] = $cid;
			}
		}

		foreach ( $all_members as $member ) {
			$cid  = (int) $member['cohort_id'];
			$esid = (int) $member['expedition_signup_id'];
			$key  = "{$cid}:{$esid}";
			if ( isset( $offer_map[ $key ] ) ) {
				$member['notification_status'] = $offer_map[ $key ]['notification_status'];
				$member['offer_status']        = $offer_map[ $key ]['offer_status'];
				$member['offer_query_message'] = $offer_map[ $key ]['offer_query_message'];
				$member['offer_responded_at']  = $offer_map[ $key ]['offer_responded_at'];
			}

			$prac_ov = (int) ( $member['practice_team_post_id_override'] ?? 0 );
			$qual_ov = (int) ( $member['qualifier_team_post_id_override'] ?? 0 );
			if ( $prac_ov > 0 && isset( $cohort_by_prac[ $prac_ov ] ) ) {
				$target_cid                               = $cohort_by_prac[ $prac_ov ];
				$member['practice_override_cohort_id']    = $target_cid;
				$member['practice_override_cohort_name']  = $cohort_names[ $target_cid ] ?? '';
				if ( $target_cid !== $cid ) {
					$guest                    = $member;
					$guest['is_stage_guest']  = true;
					$guest['guest_stage']     = 'practice';
					$guest['home_cohort_id']  = $cid;
					$guest['home_cohort_name'] = $cohort_names[ $cid ] ?? '';
					$members_by_cohort[ $target_cid ][] = $guest;
				}
			}
			if ( $qual_ov > 0 && isset( $cohort_by_qual[ $qual_ov ] ) ) {
				$target_cid                                = $cohort_by_qual[ $qual_ov ];
				$member['qualifier_override_cohort_id']    = $target_cid;
				$member['qualifier_override_cohort_name']  = $cohort_names[ $target_cid ] ?? '';
				if ( $target_cid !== $cid ) {
					$guest                     = $member;
					$guest['is_stage_guest']   = true;
					$guest['guest_stage']      = 'qualifier';
					$guest['home_cohort_id']   = $cid;
					$guest['home_cohort_name'] = $cohort_names[ $cid ] ?? '';
					$members_by_cohort[ $target_cid ][] = $guest;
				}
			}

			$members_by_cohort[ $cid ][] = $member;
		}

		foreach ( $cohorts as &$cohort ) {
			$cid                    = (int) $cohort['id'];
			$cohort_members         = $members_by_cohort[ $cid ] ?? array();
			$cohort['members']      = $cohort_members;
			$cohort['member_count'] = count( $cohort_members );
		}
		unset( $cohort );

		$standalone = $this->fetch_standalone_event_teams( $cohorts, $filters );
		$all_teams  = array_merge( $cohorts, $standalone );

		return new \WP_REST_Response( $all_teams, 200 );
	}

	/**
	 * POST /wp-json/ems/v1/cohorts
	 */
	public function create_cohort( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body = $this->get_request_body( $request );

		$data = array(
			'name'                    => sanitize_text_field( (string) ( $body['name'] ?? '' ) ),
			'dofe_level'              => strtolower( sanitize_text_field( (string) ( $body['dofe_level'] ?? '' ) ) ),
			'transport_mode'          => strtolower( sanitize_text_field( (string) ( $body['transport_mode'] ?? 'foot' ) ) ) ?: 'foot',
			'practice_expedition_id'  => $this->nullable_positive_int( $body['practice_expedition_id'] ?? null ),
			'qualifier_expedition_id' => $this->nullable_positive_int( $body['qualifier_expedition_id'] ?? null ),
			'cohort_status'           => sanitize_text_field( (string) ( $body['cohort_status'] ?? 'forming' ) ) ?: 'forming',
			'notes'                   => isset( $body['notes'] ) && $body['notes'] !== null ? sanitize_textarea_field( (string) $body['notes'] ) : null,
			'created_by'              => (int) get_current_user_id(),
		);

		try {
			$cohort_id = $this->cohorts->create( $data );
		} catch ( \InvalidArgumentException $e ) {
			return $this->error( 'ems_invalid_cohort', $e->getMessage(), 400 );
		} catch ( \RuntimeException $e ) {
			return $this->error( 'ems_cohort_create_failed', $e->getMessage(), 500 );
		}

		$signup_ids = $this->extract_positive_int_list( $body['expedition_signup_ids'] ?? array() );
		foreach ( $signup_ids as $signup_id ) {
			try {
				$this->members->create(
					array(
						'cohort_id'            => $cohort_id,
						'expedition_signup_id' => $signup_id,
						'added_by'             => (int) get_current_user_id(),
					)
				);
			} catch ( \Throwable $e ) {
				// Skip duplicate or invalid signup IDs during bulk cohort creation.
			}
		}

		return new \WP_REST_Response( $this->hydrate_cohort( $cohort_id ), 201 );
	}

	/**
	 * PATCH /wp-json/ems/v1/cohorts/{id}
	 */
	public function update_cohort( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id = absint( $request->get_param( 'id' ) );
		if ( $this->cohorts->find( $id ) === null ) {
			return $this->error( 'ems_cohort_not_found', 'Cohort not found.', 404 );
		}

		$body   = $this->get_request_body( $request );
		$fields = array(
			'name',
			'dofe_level',
			'transport_mode',
			'practice_expedition_id',
			'qualifier_expedition_id',
			'practice_team_post_id',
			'qualifier_team_post_id',
			'cohort_status',
			'notes',
		);

		$update_data = array();
		foreach ( $fields as $field ) {
			if ( array_key_exists( $field, $body ) ) {
				$update_data[ $field ] = $body[ $field ];
			}
		}

		try {
			$this->cohorts->update( $id, $update_data );
		} catch ( \InvalidArgumentException $e ) {
			return $this->error( 'ems_invalid_cohort', $e->getMessage(), 400 );
		}

		return new \WP_REST_Response( $this->hydrate_cohort( $id ), 200 );
	}

	/**
	 * DELETE /wp-json/ems/v1/cohorts/{id}
	 */
	public function delete_cohort( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id = absint( $request->get_param( 'id' ) );
		if ( $this->cohorts->find( $id ) === null ) {
			return $this->error( 'ems_cohort_not_found', 'Cohort not found.', 404 );
		}

		$this->members->deleteByCohort( $id );
		$deleted = $this->cohorts->delete( $id );

		if ( ! $deleted ) {
			return $this->error( 'ems_cohort_delete_failed', 'Failed to delete cohort.', 500 );
		}

		return new \WP_REST_Response(
			array(
				'deleted' => true,
				'id'      => $id,
			),
			200
		);
	}

	/**
	 * POST /wp-json/ems/v1/cohorts/{id}/merge
	 */
	public function merge_cohort( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$source_id = absint( $request->get_param( 'id' ) );
		$body      = $this->get_request_body( $request );
		$target_id = absint( $body['target_cohort_id'] ?? 0 );

		$source = $this->cohorts->find( $source_id );
		if ( $source === null ) {
			return $this->error( 'ems_cohort_not_found', 'Source team not found.', 404 );
		}

		if ( $target_id <= 0 || $target_id === $source_id ) {
			return $this->error( 'ems_invalid_merge_target', 'Select a different target team to merge into.', 400 );
		}

		$target = $this->cohorts->find( $target_id );
		if ( $target === null ) {
			return $this->error( 'ems_target_cohort_not_found', 'Target team not found.', 404 );
		}

		if (
			(string) ( $source['dofe_level'] ?? '' ) !== (string) ( $target['dofe_level'] ?? '' )
			|| (string) ( $source['transport_mode'] ?? '' ) !== (string) ( $target['transport_mode'] ?? '' )
		) {
			return $this->error( 'ems_merge_scope_mismatch', 'Teams must share the same DofE level and transport mode to merge.', 400 );
		}

		$source_members = $this->members->listByCohort( $source_id );
		$target_members = $this->members->listByCohort( $target_id );

		$existing_signups = array();
		foreach ( $target_members as $tm ) {
			$existing_signups[ (int) ( $tm['expedition_signup_id'] ?? 0 ) ] = true;
		}

		$moved_count      = 0;
		$moved_signup_ids = array();
		$current_user_id  = (int) get_current_user_id();

		foreach ( $source_members as $sm ) {
			$signup_id = (int) ( $sm['expedition_signup_id'] ?? 0 );
			if ( $signup_id <= 0 || isset( $existing_signups[ $signup_id ] ) ) {
				continue;
			}
			$this->members->create(
				array(
					'cohort_id'             => $target_id,
					'expedition_signup_id'  => $signup_id,
					'scout_id'              => max( 0, (int) ( $sm['scout_id'] ?? 0 ) ),
					'member_status'         => (string) ( $sm['member_status'] ?? 'active' ) ?: 'active',
					'availability_override' => $sm['availability_override'] ?? null,
					'notes'                 => $sm['notes'] ?? null,
					'added_by'              => $current_user_id,
				)
			);
			$existing_signups[ $signup_id ] = true;
			$moved_signup_ids[]             = $signup_id;
			++$moved_count;
		}

		global $wpdb;
		$previously_notified = array();
		if ( isset( $wpdb ) && is_object( $wpdb ) ) {
			$tm_table = $wpdb->prefix . 'ems_team_members';
			$rows     = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT expedition_signup_id FROM {$tm_table} WHERE cohort_id = %d AND notification_status IN ('notified', 're_notify_needed')",
					$source_id
				)
			);
			if ( is_array( $rows ) ) {
				$previously_notified = array_map( 'intval', $rows );
			}
			$wpdb->delete( $tm_table, array( 'cohort_id' => $source_id ), array( '%d' ) );
		}

		foreach ( array( 'practice_team_post_id', 'qualifier_team_post_id' ) as $col ) {
			$team_post_id = (int) ( $source[ $col ] ?? 0 );
			if ( $team_post_id > 0 && function_exists( 'wp_delete_post' ) ) {
				wp_delete_post( $team_post_id, true );
			}
		}

		$this->members->deleteByCohort( $source_id );
		$this->cohorts->delete( $source_id );

		$practice_id  = $this->nullable_positive_int( $target['practice_expedition_id'] ?? null );
		$qualifier_id = $this->nullable_positive_int( $target['qualifier_expedition_id'] ?? null );
		$scheduler    = $this->get_scheduler();

		if ( $scheduler !== null && method_exists( $scheduler, 'scheduleCohort' ) && ( $practice_id !== null || $qualifier_id !== null ) ) {
			$this->invoke_schedule_cohort(
				$scheduler,
				$target_id,
				array(
					'practice_expedition_id'  => $practice_id,
					'qualifier_expedition_id' => $qualifier_id,
					'member_resolutions'      => array(),
				)
			);

			if ( ! empty( $previously_notified ) && isset( $wpdb ) && is_object( $wpdb ) ) {
				$tm_table = $wpdb->prefix . 'ems_team_members';
				foreach ( $previously_notified as $sid ) {
					if ( in_array( $sid, $moved_signup_ids, true ) ) {
						$wpdb->update(
							$tm_table,
							array( 'notification_status' => 're_notify_needed' ),
							array(
								'cohort_id'            => $target_id,
								'expedition_signup_id' => $sid,
							),
							array( '%s' ),
							array( '%d', '%d' )
						);
					}
				}
			}
		}

		$updated_target = $this->cohorts->find( $target_id );

		return new \WP_REST_Response(
			array(
				'merged'           => true,
				'source_cohort_id' => $source_id,
				'target_cohort_id' => $target_id,
				'moved_count'      => $moved_count,
				'target_cohort'    => $updated_target ? $this->hydrate_cohort( $target_id ) : null,
			),
			200
		);
	}

	/**
	 * POST /wp-json/ems/v1/cohorts/{id}/members
	 */
	public function add_cohort_members( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$cohort_id = absint( $request->get_param( 'id' ) );
		if ( $this->cohorts->find( $cohort_id ) === null ) {
			return $this->error( 'ems_cohort_not_found', 'Cohort not found.', 404 );
		}

		$body = $this->get_request_body( $request );

		$single_signup_id = absint( $body['expedition_signup_id'] ?? 0 );
		$bulk_signup_ids  = $this->extract_positive_int_list( $body['expedition_signup_ids'] ?? array() );

		if ( $single_signup_id > 0 && empty( $bulk_signup_ids ) ) {
			try {
				$membership_id = $this->members->create(
					array(
						'cohort_id'             => $cohort_id,
						'expedition_signup_id'  => $single_signup_id,
						'scout_id'              => max( 0, absint( $body['scout_id'] ?? 0 ) ),
						'member_status'         => sanitize_text_field( (string) ( $body['member_status'] ?? 'active' ) ) ?: 'active',
						'availability_override' => $body['availability_override'] ?? null,
						'notes'                 => isset( $body['notes'] ) && $body['notes'] !== null ? sanitize_textarea_field( (string) $body['notes'] ) : null,
						'added_by'              => (int) get_current_user_id(),
					)
				);
			} catch ( \InvalidArgumentException $e ) {
				$status = str_contains( $e->getMessage(), 'already a member' ) ? 409 : 400;
				return $this->error( 'ems_cohort_member_error', $e->getMessage(), $status );
			} catch ( \RuntimeException $e ) {
				return $this->error( 'ems_cohort_member_failed', $e->getMessage(), 500 );
			}

			return new \WP_REST_Response(
				array(
					'id'      => $membership_id,
					'member'  => $this->members->find( $membership_id ),
					'members' => $this->members->listByCohort( $cohort_id ),
				),
				201
			);
		}

		if ( empty( $bulk_signup_ids ) ) {
			return $this->error( 'ems_missing_signup_id', 'Provide expedition_signup_id or expedition_signup_ids.', 400 );
		}

		$added   = array();
		$skipped = array();
		foreach ( $bulk_signup_ids as $signup_id ) {
			try {
				$membership_id = $this->members->create(
					array(
						'cohort_id'            => $cohort_id,
						'expedition_signup_id' => $signup_id,
						'added_by'             => (int) get_current_user_id(),
					)
				);
				$added[]       = $membership_id;
			} catch ( \Throwable $e ) {
				$skipped[] = array(
					'expedition_signup_id' => $signup_id,
					'reason'               => $e->getMessage(),
				);
			}
		}

		return new \WP_REST_Response(
			array(
				'cohort_id' => $cohort_id,
				'added'     => $added,
				'skipped'   => $skipped,
				'members'   => $this->members->listByCohort( $cohort_id ),
			),
			201
		);
	}

	/**
	 * DELETE /wp-json/ems/v1/cohorts/{id}/members/{expedition_signup_id}
	 */
	public function remove_cohort_member( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$cohort_id            = absint( $request->get_param( 'id' ) );
		$expedition_signup_id = absint( $request->get_param( 'expedition_signup_id' ) );

		if ( $this->cohorts->find( $cohort_id ) === null ) {
			return $this->error( 'ems_cohort_not_found', 'Cohort not found.', 404 );
		}

		$deleted = $this->members->deleteByCohortAndSignup( $cohort_id, $expedition_signup_id );
		if ( ! $deleted ) {
			return $this->error( 'ems_cohort_member_not_found', 'Cohort member not found.', 404 );
		}

		return new \WP_REST_Response(
			array(
				'deleted'              => true,
				'cohort_id'            => $cohort_id,
				'expedition_signup_id' => $expedition_signup_id,
			),
			200
		);
	}

	/**
	 * GET /wp-json/ems/v1/cohorts/{id}/schedule
	 */
	public function get_cohort_schedule_candidates( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$cohort_id = absint( $request->get_param( 'id' ) );
		if ( $this->cohorts->find( $cohort_id ) === null ) {
			return $this->error( 'ems_cohort_not_found', 'Cohort not found.', 404 );
		}

		$scheduler = $this->get_scheduler();
		if ( $scheduler === null ) {
			return $this->error( 'ems_scheduler_unavailable', 'CohortSchedulerService is not available.', 501 );
		}

		$stage = $request->get_param( 'stage' );
		$stage = is_string( $stage ) && $stage !== '' ? sanitize_key( $stage ) : null;

		try {
			foreach ( array( 'scoreExpeditions', 'scoreCandidateExpeditions', 'getCandidateExpeditions', 'scoreCandidates' ) as $method ) {
				if ( method_exists( $scheduler, $method ) ) {
					$result = $stage !== null
						? $scheduler->{$method}( $cohort_id, $stage )
						: $scheduler->{$method}( $cohort_id );
					return new \WP_REST_Response( $result, 200 );
				}
			}
		} catch ( \InvalidArgumentException $e ) {
			return $this->error( 'ems_schedule_candidates_error', $e->getMessage(), 400 );
		} catch ( \Throwable $e ) {
			return $this->error( 'ems_schedule_candidates_failed', $e->getMessage(), 500 );
		}

		return $this->error( 'ems_scheduler_method_missing', 'Candidate scoring method is not implemented on CohortSchedulerService.', 501 );
	}

	/**
	 * POST /wp-json/ems/v1/cohorts/{id}/schedule
	 */
	public function schedule_cohort( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$cohort_id = absint( $request->get_param( 'id' ) );
		if ( $this->cohorts->find( $cohort_id ) === null ) {
			return $this->error( 'ems_cohort_not_found', 'Cohort not found.', 404 );
		}

		$scheduler = $this->get_scheduler();
		if ( $scheduler === null || ! method_exists( $scheduler, 'scheduleCohort' ) ) {
			return $this->error( 'ems_scheduler_unavailable', 'CohortSchedulerService::scheduleCohort() is not available.', 501 );
		}

		$body = $this->get_request_body( $request );

		$practice_id  = $this->nullable_positive_int( $body['practice_expedition_id'] ?? null );
		$qualifier_id = $this->nullable_positive_int( $body['qualifier_expedition_id'] ?? null );
		$resolutions  = $this->sanitize_resolutions( $body['member_resolutions'] ?? array() );

		$payload = array(
			'practice_expedition_id'  => $practice_id,
			'qualifier_expedition_id' => $qualifier_id,
			'member_resolutions'      => $resolutions,
		);

		try {
			$result = $this->invoke_schedule_cohort( $scheduler, $cohort_id, $payload );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return new \WP_REST_Response( $result, 200 );
		} catch ( \InvalidArgumentException $e ) {
			return $this->error( 'ems_schedule_invalid', $e->getMessage(), 400 );
		} catch ( \Throwable $e ) {
			return $this->error( 'ems_schedule_failed', $e->getMessage(), 500 );
		}
	}

	/**
	 * POST /wp-json/ems/v1/expeditions/notifications/preview
	 */
	public function preview_notifications( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$dispatcher = $this->get_dispatcher();
		if ( $dispatcher === null || ! method_exists( $dispatcher, 'previewBatch' ) ) {
			return $this->error( 'ems_dispatcher_unavailable', 'ExpeditionOfferDispatcher::previewBatch() is not available.', 501 );
		}

		$body   = $this->get_request_body( $request );
		$params = array(
			'scope'                 => sanitize_key( (string) ( $body['scope'] ?? 'cohort' ) ),
			'cohort_id'             => $this->nullable_positive_int( $body['cohort_id'] ?? null ),
			'expedition_signup_ids' => $this->extract_positive_int_list( $body['expedition_signup_ids'] ?? array() ),
			'dofe_level'            => isset( $body['dofe_level'] ) ? strtolower( sanitize_text_field( (string) $body['dofe_level'] ) ) : null,
			'transport_mode'        => isset( $body['transport_mode'] ) ? strtolower( sanitize_text_field( (string) $body['transport_mode'] ) ) : null,
		);

		try {
			$result = $dispatcher->previewBatch( $params );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return new \WP_REST_Response( $result, 200 );
		} catch ( \InvalidArgumentException $e ) {
			return $this->error( 'ems_preview_invalid', $e->getMessage(), 400 );
		} catch ( \Throwable $e ) {
			return $this->error( 'ems_preview_failed', $e->getMessage(), 500 );
		}
	}

	/**
	 * POST /wp-json/ems/v1/expeditions/notifications/dispatch
	 */
	public function dispatch_notifications( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body = $this->get_request_body( $request );

		if ( ( $body['confirm_dispatch'] ?? false ) !== true ) {
			return $this->error(
				'ems_confirmation_required',
				'Explicit confirmation (confirm_dispatch === true) is required before dispatching place offers.',
				400
			);
		}

		$signup_ids = $this->extract_positive_int_list( $body['expedition_signup_ids'] ?? array() );
		if ( empty( $signup_ids ) ) {
			return $this->error(
				'ems_missing_recipients',
				'At least one valid expedition_signup_id is required.',
				400
			);
		}

		$dispatcher = $this->get_dispatcher();
		if ( $dispatcher === null || ! method_exists( $dispatcher, 'dispatchBatch' ) ) {
			return $this->error( 'ems_dispatcher_unavailable', 'ExpeditionOfferDispatcher::dispatchBatch() is not available.', 501 );
		}

		$params = array(
			'confirm_dispatch'      => true,
			'expedition_signup_ids' => $signup_ids,
			'cohort_id'             => $this->nullable_positive_int( $body['cohort_id'] ?? null ),
		);

		try {
			$result = $this->invoke_dispatch_batch( $dispatcher, $params );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return new \WP_REST_Response( $result, 200 );
		} catch ( \InvalidArgumentException $e ) {
			return $this->error( 'ems_dispatch_invalid', $e->getMessage(), 400 );
		} catch ( \Throwable $e ) {
			return $this->error( 'ems_dispatch_failed', $e->getMessage(), 500 );
		}
	}

	/**
	 * GET /wp-json/ems/v1/expeditions/teams-readiness
	 */
	public function get_teams_readiness( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$readiness = $this->get_readiness();
		if ( $readiness === null ) {
			return $this->error( 'ems_readiness_unavailable', 'TeamReadinessService is not available.', 501 );
		}

		$filters = array();
		foreach ( array( 'dofe_level', 'transport_mode', 'stage' ) as $key ) {
			$val = $request->get_param( $key );
			if ( is_string( $val ) && $val !== '' ) {
				$filters[ $key ] = strtolower( sanitize_text_field( $val ) );
			}
		}

		$expedition_id = absint( $request->get_param( 'expedition_id' ) );
		if ( $expedition_id > 0 ) {
			$filters['expedition_id'] = $expedition_id;
		}

		try {
			foreach ( array( 'getTeamsReadiness', 'getReadiness', 'listTeamsReadiness' ) as $method ) {
				if ( method_exists( $readiness, $method ) ) {
					$result = $readiness->{$method}( $filters );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					return new \WP_REST_Response( $result, 200 );
				}
			}
		} catch ( \InvalidArgumentException $e ) {
			return $this->error( 'ems_readiness_invalid', $e->getMessage(), 400 );
		} catch ( \Throwable $e ) {
			return $this->error( 'ems_readiness_failed', $e->getMessage(), 500 );
		}

		return $this->error( 'ems_readiness_method_missing', 'Readiness aggregation method is not implemented on TeamReadinessService.', 501 );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function hydrate_cohort( int $cohort_id ): ?array {
		$cohort = $this->cohorts->find( $cohort_id );
		if ( $cohort === null ) {
			return null;
		}

		$members                = $this->members->listByCohort( $cohort_id );
		$cohort['members']      = $members;
		$cohort['member_count'] = count( $members );

		return $cohort;
	}

	private function get_scheduler(): ?object {
		if ( $this->scheduler === null ) {
			$class = '\\EMS\\Modules\\Expeditions\\Services\\CohortSchedulerService';
			if ( class_exists( $class ) ) {
				$this->scheduler = new $class();
			}
		}
		return $this->scheduler;
	}

	private function get_readiness(): ?object {
		if ( $this->readiness === null ) {
			$class = '\\EMS\\Modules\\Expeditions\\Services\\TeamReadinessService';
			if ( class_exists( $class ) ) {
				$this->readiness = new $class();
			}
		}
		return $this->readiness;
	}

	private function get_dispatcher(): ?object {
		if ( $this->dispatcher === null ) {
			$class = '\\EMS\\Modules\\Notifications\\Services\\ExpeditionOfferDispatcher';
			if ( class_exists( $class ) ) {
				$this->dispatcher = new $class();
			}
		}
		return $this->dispatcher;
	}

	/**
	 * Invoke CohortSchedulerService::scheduleCohort() supporting both array-options
	 * and positional-argument signatures.
	 *
	 * @param array<string, mixed> $payload
	 */
	private function invoke_schedule_cohort( object $scheduler, int $cohort_id, array $payload ): mixed {
		$reflection = new \ReflectionMethod( $scheduler, 'scheduleCohort' );
		$params     = $reflection->getParameters();

		if ( isset( $params[1] ) ) {
			$second_type = $params[1]->getType();
			if ( $second_type instanceof \ReflectionNamedType && $second_type->getName() === 'int' ) {
				return $scheduler->scheduleCohort(
					$cohort_id,
					$payload['practice_expedition_id'],
					$payload['qualifier_expedition_id'],
					$payload['member_resolutions']
				);
			}
		}

		if ( count( $params ) >= 3 ) {
			return $scheduler->scheduleCohort(
				$cohort_id,
				$payload,
				is_array( $payload['member_resolutions'] ?? null ) ? $payload['member_resolutions'] : array()
			);
		}

		return $scheduler->scheduleCohort( $cohort_id, $payload );
	}

	/**
	 * Invoke ExpeditionOfferDispatcher::dispatchBatch() supporting both payload-array
	 * and signup-ids-first signatures.
	 *
	 * @param array<string, mixed> $params
	 */
	private function invoke_dispatch_batch( object $dispatcher, array $params ): mixed {
		$reflection = new \ReflectionMethod( $dispatcher, 'dispatchBatch' );
		$method_params = $reflection->getParameters();

		if ( isset( $method_params[0] ) && in_array( $method_params[0]->getName(), array( 'expedition_signup_ids', 'signup_ids' ), true ) ) {
			return $dispatcher->dispatchBatch( $params['expedition_signup_ids'], true );
		}

		return $dispatcher->dispatchBatch( $params );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function get_request_body( \WP_REST_Request $request ): array {
		$json = $request->get_json_params();
		if ( is_array( $json ) && ! empty( $json ) ) {
			return $json;
		}
		if ( method_exists( $request, 'get_body_params' ) ) {
			return $request->get_body_params();
		}
		if ( method_exists( $request, 'get_params' ) ) {
			return $request->get_params();
		}
		return array();
	}

	/**
	 * @return array<int, int>
	 */
	private function extract_positive_int_list( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$ids = array();
		foreach ( $raw as $item ) {
			$val = absint( $item );
			if ( $val > 0 ) {
				$ids[] = $val;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * @return array<int, string>
	 */
	private function sanitize_resolutions( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$allowed   = array( 'assign_pending', 'split_date', 'remove_from_cohort', 'matched' );
		$sanitized = array();

		foreach ( $raw as $signup_id => $resolution ) {
			$id  = absint( $signup_id );
			$val = sanitize_key( (string) $resolution );
			if ( $id > 0 && in_array( $val, $allowed, true ) ) {
				$sanitized[ $id ] = $val;
			}
		}

		return $sanitized;
	}

	private function nullable_positive_int( mixed $value ): ?int {
		if ( $value === null || $value === '' ) {
			return null;
		}
		$int = absint( $value );
		return $int > 0 ? $int : null;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function id_arg(): array {
		return array(
			'type'              => 'integer',
			'required'          => true,
			'sanitize_callback' => 'absint',
		);
	}

	private function error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/**
	 * POST /wp-json/ems/v1/cohorts/{id}/members/{expedition_signup_id}/stage-override
	 */
	public function set_stage_override( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$cohort_id            = absint( $request->get_param( 'id' ) );
		$expedition_signup_id = absint( $request->get_param( 'expedition_signup_id' ) );
		$body                 = $this->get_request_body( $request );
		$stage                = sanitize_key( (string) ( $body['stage'] ?? '' ) );
		$target_cohort_id     = (int) ( $body['target_cohort_id'] ?? 0 );

		if ( ! in_array( $stage, array( 'practice', 'qualifier' ), true ) ) {
			return $this->error( 'ems_invalid_stage', 'stage must be "practice" or "qualifier".', 400 );
		}

		$home_cohort = $this->cohorts->find( $cohort_id );
		if ( ! $home_cohort ) {
			return $this->error( 'ems_cohort_not_found', 'Home team not found.', 404 );
		}

		$member = $this->members->findByCohortAndSignup( $cohort_id, $expedition_signup_id );
		if ( ! $member ) {
			return $this->error( 'ems_member_not_found', 'Member not found in this team.', 404 );
		}

		$col                 = $stage === 'practice' ? 'practice_team_post_id_override' : 'qualifier_team_post_id_override';
		$home_stage_team_id  = (int) ( $stage === 'practice' ? ( $home_cohort['practice_team_post_id'] ?? 0 ) : ( $home_cohort['qualifier_team_post_id'] ?? 0 ) );
		$target_team_post_id = 0;

		if ( $target_cohort_id > 0 && $target_cohort_id !== $cohort_id ) {
			$target_cohort = $this->cohorts->find( $target_cohort_id );
			if ( ! $target_cohort ) {
				return $this->error( 'ems_target_cohort_not_found', 'Target team not found.', 404 );
			}
			$target_team_post_id = (int) ( $stage === 'practice' ? ( $target_cohort['practice_team_post_id'] ?? 0 ) : ( $target_cohort['qualifier_team_post_id'] ?? 0 ) );
			if ( $target_team_post_id <= 0 ) {
				return $this->error(
					'ems_target_stage_unscheduled',
					sprintf( 'Target team "%s" does not have a scheduled %s event yet.', (string) ( $target_cohort['name'] ?? '' ), $stage ),
					400
				);
			}
		}

		global $wpdb;
		$cm_table = $wpdb->prefix . 'ems_cohort_members';
		$tm_table = $wpdb->prefix . 'ems_team_members';

		$other_override_col = $stage === 'practice' ? 'qualifier_team_post_id_override' : 'practice_team_post_id_override';
		$other_override_val = (int) ( $member[ $other_override_col ] ?? 0 );
		$new_member_status  = $target_team_post_id > 0
			? ( $stage === 'practice' ? 'split_practice' : 'split_qualifier' )
			: ( $other_override_val > 0 ? ( $stage === 'practice' ? 'split_qualifier' : 'split_practice' ) : 'active' );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$cm_table} SET {$col} = %s, member_status = %s, updated_at = %s WHERE cohort_id = %d AND expedition_signup_id = %d",
				$target_team_post_id > 0 ? $target_team_post_id : null,
				$new_member_status,
				current_time( 'mysql', true ),
				$cohort_id,
				$expedition_signup_id
			)
		);

		$prev_override_id = (int) ( $member[ $col ] ?? 0 );
		$dest_team_id     = $target_team_post_id > 0 ? $target_team_post_id : $home_stage_team_id;
		$remove_team_ids  = array_values(
			array_unique(
				array_filter(
					array( $prev_override_id, $home_stage_team_id ),
					static fn( int $id ): bool => $id > 0 && $id !== $dest_team_id
				)
			)
		);

		$existing_notif = 'draft';
		foreach ( $remove_team_ids as $old_tid ) {
			$prev_status = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT notification_status FROM {$tm_table} WHERE team_post_id = %d AND expedition_signup_id = %d LIMIT 1",
					$old_tid,
					$expedition_signup_id
				)
			);
			if ( is_string( $prev_status ) && $prev_status !== '' ) {
				$existing_notif = $prev_status;
			}
			$wpdb->delete(
				$tm_table,
				array(
					'team_post_id'         => $old_tid,
					'expedition_signup_id' => $expedition_signup_id,
				),
				array( '%d', '%d' )
			);
		}

		if ( $dest_team_id > 0 ) {
			$already = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$tm_table} WHERE team_post_id = %d AND expedition_signup_id = %d LIMIT 1",
					$dest_team_id,
					$expedition_signup_id
				)
			);
			$next_notif = ( $existing_notif === 'notified' || $existing_notif === 're_notify_needed' ) ? 're_notify_needed' : 'draft';
			if ( $already ) {
				$wpdb->update(
					$tm_table,
					array(
						'cohort_id'             => $cohort_id,
						'availability_override' => $target_team_post_id > 0 ? 'split_date' : null,
						'notification_status'   => $next_notif,
					),
					array( 'id' => (int) $already ),
					array( '%d', '%s', '%s' ),
					array( '%d' )
				);
			} else {
				$wpdb->insert(
					$tm_table,
					array(
						'team_post_id'          => $dest_team_id,
						'scout_id'              => (int) ( $member['scout_id'] ?? 0 ),
						'user_id'               => (int) ( $member['wp_user_id'] ?? 0 ),
						'expedition_signup_id'  => $expedition_signup_id,
						'cohort_id'             => $cohort_id,
						'availability_override' => $target_team_post_id > 0 ? 'split_date' : null,
						'notification_status'   => $next_notif,
						'added_by'              => (int) get_current_user_id(),
						'added_at'              => current_time( 'mysql', true ),
					),
					array( '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s' )
				);
			}
		}

		return new \WP_REST_Response(
			array(
				'updated'              => true,
				'cohort_id'            => $cohort_id,
				'expedition_signup_id' => $expedition_signup_id,
				'stage'                => $stage,
				'target_team_post_id'  => $target_team_post_id > 0 ? $target_team_post_id : null,
				'member_status'        => $new_member_status,
			),
			200
		);
	}

	/**
	 * POST /wp-json/ems/v1/cohorts/resolve-query
	 */
	public function resolve_member_query( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body                 = $this->get_request_body( $request );
		$cohort_id            = (int) ( $body['cohort_id'] ?? 0 );
		$expedition_signup_id = (int) ( $body['expedition_signup_id'] ?? 0 );
		$team_post_id         = (int) ( $body['team_post_id'] ?? 0 );
		$scout_id             = (int) ( $body['scout_id'] ?? 0 );
		$reply_message        = sanitize_textarea_field( (string) ( $body['reply_message'] ?? '' ) );
		$new_status           = sanitize_key( (string) ( $body['new_status'] ?? 'pending' ) );
		if ( ! in_array( $new_status, array( 'pending', 'accepted' ), true ) ) {
			$new_status = 'pending';
		}

		global $wpdb;
		$tm_table = $wpdb->prefix . 'ems_team_members';
		if ( $cohort_id > 0 && $expedition_signup_id > 0 ) {
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$tm_table} SET offer_status = %s, offer_query_message = NULL WHERE cohort_id = %d AND expedition_signup_id = %d",
					$new_status,
					$cohort_id,
					$expedition_signup_id
				)
			);
		} elseif ( $team_post_id > 0 && $scout_id > 0 ) {
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$tm_table} SET offer_status = %s, offer_query_message = NULL WHERE team_post_id = %d AND scout_id = %d",
					$new_status,
					$team_post_id,
					$scout_id
				)
			);
		} else {
			return $this->error( 'ems_invalid_params', 'Missing cohort_id/expedition_signup_id or team_post_id/scout_id.', 400 );
		}

		if ( $reply_message !== '' && $expedition_signup_id > 0 && function_exists( 'wp_mail' ) ) {
			$signup = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT explorer_email, parent_email, explorer_first_name, explorer_last_name FROM {$wpdb->prefix}ems_expedition_signups WHERE id = %d",
					$expedition_signup_id
				),
				ARRAY_A
			);
			if ( $signup ) {
				$to = array_filter(
					array_unique(
						array(
							sanitize_email( (string) ( $signup['explorer_email'] ?? '' ) ),
							sanitize_email( (string) ( $signup['parent_email'] ?? '' ) ),
						)
					)
				);
				if ( ! empty( $to ) ) {
					$name    = trim( ( $signup['explorer_first_name'] ?? '' ) . ' ' . ( $signup['explorer_last_name'] ?? '' ) );
					$subject = 'Re: Your DofE Expedition Place Offer Query';
					$body_tx = "Hi {$name},\n\n{$reply_message}\n\nYou can now log back into the Explorer Portal to respond to your Place Offer.\n";
					wp_mail( $to, $subject, $body_tx );
				}
			}
		}

		return new \WP_REST_Response(
			array(
				'resolved'     => true,
				'new_status'   => $new_status,
				'rows_updated' => (int) $updated,
			),
			200
		);
	}

	/**
	 * Fetches offer & notification statuses keyed by "{cohort_id}:{expedition_signup_id}".
	 *
	 * @param array<int, int> $cohort_ids
	 * @return array<string, array<string, mixed>>
	 */
	private function fetch_member_offer_statuses( array $cohort_ids ): array {
		if ( empty( $cohort_ids ) ) {
			return array();
		}
		global $wpdb;
		$tm_table     = $wpdb->prefix . 'ems_team_members';
		$placeholders = implode( ', ', array_fill( 0, count( $cohort_ids ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT cohort_id, expedition_signup_id, notification_status, offer_status, offer_query_message, offer_responded_at
				FROM {$tm_table}
				WHERE cohort_id IN ({$placeholders}) AND expedition_signup_id IS NOT NULL",
				...$cohort_ids
			),
			ARRAY_A
		) ?: array();

		$map = array();
		foreach ( $rows as $row ) {
			$key         = (int) $row['cohort_id'] . ':' . (int) $row['expedition_signup_id'];
			$map[ $key ] = array(
				'notification_status' => (string) ( $row['notification_status'] ?? 'draft' ),
				'offer_status'        => (string) ( $row['offer_status'] ?? 'pending' ),
				'offer_query_message' => $row['offer_query_message'] ?? null,
				'offer_responded_at'  => $row['offer_responded_at'] ?? null,
			);
		}
		return $map;
	}

	/**
	 * Fetches standalone event team CPT posts (not linked to any ems_cohorts row) matching filters.
	 *
	 * @param array<int, array<string, mixed>> $cohorts
	 * @param array<string, mixed>             $filters
	 * @return array<int, array<string, mixed>>
	 */
	private function fetch_standalone_event_teams( array $cohorts, array $filters ): array {
		global $wpdb;
		if ( ! function_exists( 'get_posts' ) || ! function_exists( 'get_post_meta' ) ) {
			return array();
		}

		$linked_team_ids = array();
		foreach ( $cohorts as $c ) {
			if ( ! empty( $c['practice_team_post_id'] ) ) {
				$linked_team_ids[ (int) $c['practice_team_post_id'] ] = true;
			}
			if ( ! empty( $c['qualifier_team_post_id'] ) ) {
				$linked_team_ids[ (int) $c['qualifier_team_post_id'] ] = true;
			}
		}

		$team_posts = get_posts(
			array(
				'post_type'      => 'team',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		if ( ! is_array( $team_posts ) || empty( $team_posts ) ) {
			return array();
		}

		$filter_level     = strtolower( (string) ( $filters['dofe_level'] ?? '' ) );
		$filter_transport = strtolower( (string) ( $filters['transport_mode'] ?? '' ) );
		$tm_table         = $wpdb->prefix . 'ems_team_members';
		$signups_table    = $wpdb->prefix . 'ems_expedition_signups';
		$explorers_table  = $wpdb->prefix . 'ems_osm_explorers';
		$standalone       = array();

		foreach ( $team_posts as $tp ) {
			$tid = (int) $tp->ID;
			if ( isset( $linked_team_ids[ $tid ] ) ) {
				continue;
			}
			$team_code = trim( (string) get_post_meta( $tid, 'ems_team_code', true ) );
			if ( strtoupper( $team_code ) === 'UNALLOCATED' || strtoupper( trim( (string) $tp->post_title ) ) === 'UNALLOCATED' ) {
				continue;
			}
			$exp_id = (int) $tp->post_parent;
			if ( $exp_id <= 0 ) {
				continue;
			}
			$ev_level     = strtolower( trim( (string) get_post_meta( $exp_id, 'ems_level', true ) ) );
			$ev_transport = strtolower( trim( (string) ( get_post_meta( $exp_id, 'ems_transport', true ) ?: 'foot' ) ) );
			if ( $ev_transport === 'walking' ) {
				$ev_transport = 'foot';
			} elseif ( $ev_transport === 'canoe' || $ev_transport === 'paddle' ) {
				$ev_transport = 'paddling';
			} elseif ( $ev_transport === 'bike' || $ev_transport === 'cycling' ) {
				$ev_transport = 'biking';
			}
			$ev_type = strtolower( trim( (string) get_post_meta( $exp_id, 'ems_type', true ) ) );

			if ( $filter_level !== '' && $ev_level !== $filter_level ) {
				continue;
			}
			if ( $filter_transport !== '' && $ev_transport !== $filter_transport ) {
				continue;
			}

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT tm.id, tm.team_post_id, tm.expedition_signup_id,
						COALESCE(NULLIF(s.scout_id, 0), tm.scout_id) AS scout_id,
						tm.availability_override, tm.notification_status, tm.offer_status, tm.offer_query_message, tm.offer_responded_at,
						s.unit_id, s.unit_name, s.dofe_level, s.first_aid_status, s.first_aid_expiry,
						COALESCE(NULLIF(e.first_name, ''), s.explorer_first_name, '') AS first_name,
						COALESCE(NULLIF(e.last_name, ''), s.explorer_last_name, '') AS last_name,
						e.first_aid_level, e.wp_user_id
					FROM {$tm_table} tm
					LEFT JOIN {$signups_table} s ON tm.expedition_signup_id = s.id
					LEFT JOIN {$explorers_table} e ON (COALESCE(NULLIF(s.scout_id, 0), tm.scout_id) > 0 AND COALESCE(NULLIF(s.scout_id, 0), tm.scout_id) = e.scout_id)
					WHERE tm.team_post_id = %d
					ORDER BY tm.id ASC",
					$tid
				),
				ARRAY_A
			) ?: array();

			$members = array();
			foreach ( $rows as $r ) {
				$members[] = array(
					'id'                    => (int) $r['id'],
					'cohort_id'             => -$tid,
					'expedition_signup_id'  => (int) ( $r['expedition_signup_id'] ?? 0 ),
					'scout_id'              => (int) ( $r['scout_id'] ?? 0 ),
					'availability_override' => $r['availability_override'] ?? null,
					'member_status'         => 'active',
					'notification_status'   => (string) ( $r['notification_status'] ?? 'draft' ),
					'offer_status'          => (string) ( $r['offer_status'] ?? 'pending' ),
					'offer_query_message'   => $r['offer_query_message'] ?? null,
					'offer_responded_at'    => $r['offer_responded_at'] ?? null,
					'notes'                 => null,
					'unit_id'               => isset( $r['unit_id'] ) ? (int) $r['unit_id'] : null,
					'unit_name'             => $r['unit_name'] ?? null,
					'first_name'            => (string) ( $r['first_name'] ?? '' ),
					'last_name'             => (string) ( $r['last_name'] ?? '' ),
					'explorer_first_name'   => (string) ( $r['first_name'] ?? '' ),
					'explorer_last_name'    => (string) ( $r['last_name'] ?? '' ),
					'explorer_email'        => '',
					'parent_email'          => null,
					'dofe_level'            => $ev_level,
					'expedition_preferences' => null,
					'additional_support_needs' => null,
					'asn_validated'         => null,
					'first_aid_status'      => (string) ( $r['first_aid_status'] ?? 'none' ),
					'first_aid_expiry'      => $r['first_aid_expiry'] ?? null,
					'first_aid_level'       => $r['first_aid_level'] ?? null,
					'wp_user_id'            => isset( $r['wp_user_id'] ) ? (int) $r['wp_user_id'] : null,
				);
			}

			$is_prac      = ( $ev_type === 'practice' );
			$is_qual      = in_array( $ev_type, array( 'qualifying', 'qualifier' ), true );
			$standalone[] = array(
				'id'                      => -$tid,
				'name'                    => $team_code !== '' ? $team_code : (string) $tp->post_title,
				'dofe_level'              => $ev_level,
				'transport_mode'          => $ev_transport,
				'practice_expedition_id'  => $is_prac ? $exp_id : null,
				'qualifier_expedition_id' => $is_qual ? $exp_id : null,
				'practice_team_post_id'   => $is_prac ? $tid : null,
				'qualifier_team_post_id'  => $is_qual ? $tid : null,
				'cohort_status'           => 'scheduled',
				'is_standalone_team'      => true,
				'notes'                   => null,
				'members'                 => $members,
				'member_count'            => count( $members ),
			);
		}

		return $standalone;
	}
}
