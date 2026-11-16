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
		$members_by_cohort = array();

		foreach ( $all_members as $member ) {
			$cid                         = (int) $member['cohort_id'];
			$members_by_cohort[ $cid ][] = $member;
		}

		foreach ( $cohorts as &$cohort ) {
			$cid                    = (int) $cohort['id'];
			$cohort_members         = $members_by_cohort[ $cid ] ?? array();
			$cohort['members']      = $cohort_members;
			$cohort['member_count'] = count( $cohort_members );
		}
		unset( $cohort );

		return new \WP_REST_Response( $cohorts, 200 );
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
}
