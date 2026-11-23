<?php
namespace EMS\Modules\Portal;

use EMS\Data\OSM_Explorer_Repository;
use EMS\Data\Signup_Repository;
use EMS\Integrations\TutorLMS_Client;

class Portal_Controller {
	private OSM_Explorer_Repository $explorer_repo;
	private TutorLMS_Client $tutor_client;
	private ?Signup_Repository $signup_repo;

	public function __construct(
		?OSM_Explorer_Repository $explorer_repo = null,
		?TutorLMS_Client $tutor_client = null,
		?Signup_Repository $signup_repo = null
	) {
		$this->explorer_repo = $explorer_repo ?: new OSM_Explorer_Repository();
		$this->tutor_client  = $tutor_client ?: new TutorLMS_Client();
		$this->signup_repo   = $signup_repo;
	}

	private function get_signup_repository(): Signup_Repository {
		if ( $this->signup_repo === null ) {
			$this->signup_repo = new Signup_Repository();
		}
		return $this->signup_repo;
	}

	public function register_routes(): void {
		register_rest_route(
			'ems/v1',
			'/portal/me',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_me' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'ems/v1',
			'/portal/explorer/(?P<scout_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_explorer_detail' ),
				'permission_callback' => 'is_user_logged_in',
			)
		);

		register_rest_route(
			'ems/v1',
			'/portal/offers/respond',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'respond_to_offer' ),
				'permission_callback' => 'is_user_logged_in',
			)
		);

		register_rest_route(
			'ems/v1',
			'/leader-dashboard/signups',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_leader_dashboard_signups' ),
				'permission_callback' => array( $this, 'check_leader_dashboard_permission' ),
			)
		);

		register_rest_route(
			'ems/v1',
			'/portal/admin/preview-targets',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_admin_preview_targets' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);
	}

	public function check_admin_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	public function get_admin_preview_targets( \WP_REST_Request $request ): \WP_REST_Response {
		$parent_users = get_users( array(
			'meta_key'   => 'ems_access_type',
			'meta_value' => 'parent',
		) );

		$parents = array();
		foreach ( (array) $parent_users as $u ) {
			if ( ! ( $u instanceof \WP_User ) ) {
				continue;
			}
			$children    = get_user_meta( $u->ID, 'ems_children', true ) ?: array();
			$child_names = array();
			$scout_ids   = array();
			foreach ( (array) $children as $c ) {
				$c_name = trim( ( $c['first_name'] ?? '' ) . ' ' . ( $c['last_name'] ?? '' ) );
				if ( ! empty( $c_name ) ) {
					$child_names[] = $c_name;
				}
				if ( ! empty( $c['scout_id'] ) ) {
					$scout_ids[] = (int) $c['scout_id'];
				}
			}
			$desc = ! empty( $child_names ) ? 'Children: ' . implode( ', ', $child_names ) : 'No children linked';
			$parents[] = array(
				'id'          => (int) $u->ID,
				'name'        => $u->display_name ?: ( ( $u->first_name ?? '' ) . ' ' . ( $u->last_name ?? '' ) ),
				'email'       => $u->user_email ?? '',
				'description' => $desc,
				'scout_ids'   => $scout_ids,
			);
		}

		$member_users = get_users( array(
			'meta_key'     => 'ems_access_type',
			'meta_value'   => array( 'member', 'network_member' ),
			'meta_compare' => 'IN',
		) );

		$members = array();
		foreach ( (array) $member_users as $u ) {
			if ( ! ( $u instanceof \WP_User ) ) {
				continue;
			}
			$scout_ids = get_user_meta( $u->ID, 'ems_scout_ids', true ) ?: array();
			$members[] = array(
				'id'        => (int) $u->ID,
				'name'      => $u->display_name ?: ( ( $u->first_name ?? '' ) . ' ' . ( $u->last_name ?? '' ) ),
				'email'     => $u->user_email ?? '',
				'scout_ids' => array_map( 'intval', (array) $scout_ids ),
			);
		}

		$explorer_rows = $this->explorer_repo->list_all();
		$explorers     = array();
		foreach ( (array) $explorer_rows as $row ) {
			$explorers[] = array(
				'scout_id'   => (int) ( $row['scout_id'] ?? 0 ),
				'first_name' => $row['first_name'] ?? '',
				'last_name'  => $row['last_name'] ?? '',
				'patrol'     => $row['patrol'] ?? '',
				'unit_name'  => $row['unit_name'] ?? '',
			);
		}

		return new \WP_REST_Response(
			array(
				'parents'   => $parents,
				'members'   => $members,
				'explorers' => $explorers,
			),
			200
		);
	}

	public function check_leader_dashboard_permission(): bool {
		return current_user_can( 'access_ems_leader_portal' ) || current_user_can( 'manage_options' );
	}

	public function get_leader_dashboard_signups( \WP_REST_Request $request ): \WP_REST_Response {
		$repo         = $this->get_signup_repository();
		$participants = $repo->get_participant_signups( 'all' );
		$expeditions  = $repo->get_expedition_signups( 'all' );

		$results = array();

		foreach ( $participants as $row ) {
			if ( ( $row['signup_status'] ?? '' ) === 'archived' ) {
				continue;
			}
			$results[] = array(
				'id'                     => (int) $row['id'],
				'type'                   => 'participant',
				'scout_id'               => (int) ( $row['scout_id'] ?? 0 ),
				'unit_id'                => ! empty( $row['unit_id'] ) ? (int) $row['unit_id'] : null,
				'unit_name'              => ! empty( $row['unit_name'] ) ? $row['unit_name'] : 'Unassigned',
				'district'               => $row['district'] ?? '',
				'explorer_first_name'    => $row['explorer_first_name'] ?? '',
				'explorer_last_name'     => $row['explorer_last_name'] ?? '',
				'dofe_level'             => $row['dofe_level'] ?? '',
				'expedition_preferences' => null,
				'signup_status'          => $row['signup_status'] ?? 'submitted',
				'created_at'             => $row['created_at'] ?? '',
			);
		}

		foreach ( $expeditions as $row ) {
			if ( ( $row['signup_status'] ?? '' ) === 'archived' ) {
				continue;
			}
			$prefs     = ! empty( $row['expedition_preferences'] ) ? json_decode( (string) $row['expedition_preferences'], true ) : null;
			$results[] = array(
				'id'                     => (int) $row['id'],
				'type'                   => 'expedition',
				'scout_id'               => (int) ( $row['scout_id'] ?? 0 ),
				'unit_id'                => ! empty( $row['unit_id'] ) ? (int) $row['unit_id'] : null,
				'unit_name'              => ! empty( $row['unit_name'] ) ? $row['unit_name'] : 'Unassigned',
				'district'               => $row['district'] ?? '',
				'explorer_first_name'    => $row['explorer_first_name'] ?? '',
				'explorer_last_name'     => $row['explorer_last_name'] ?? '',
				'dofe_level'             => $row['dofe_level'] ?? '',
				'expedition_preferences' => is_array( $prefs ) ? array( 'exped_type' => $prefs['exped_type'] ?? '' ) : null,
				'signup_status'          => $row['signup_status'] ?? 'submitted',
				'created_at'             => $row['created_at'] ?? '',
			);
		}

		return new \WP_REST_Response( $results, 200 );
	}

	public function get_me( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! is_user_logged_in() ) {
			return new \WP_REST_Response(
				array(
					'logged_in' => false,
					'is_admin'  => false,
				),
				200
			);
		}

		$is_admin = current_user_can( 'manage_options' );
		$user     = wp_get_current_user();

		// Handle admin preview impersonation
		if ( $is_admin ) {
			$preview_user_id  = (int) $request->get_param( 'preview_user_id' );
			$preview_scout_id = (int) $request->get_param( 'preview_scout_id' );

			if ( $preview_user_id > 0 ) {
				$target_user = get_user_by( 'id', $preview_user_id );
				if ( $target_user && $target_user->exists() ) {
					$access_type = get_user_meta( $target_user->ID, 'ems_access_type', true ) ?: 'member';
					$children    = get_user_meta( $target_user->ID, 'ems_children', true ) ?: array();
					$scout_ids   = get_user_meta( $target_user->ID, 'ems_scout_ids', true ) ?: array();
					$profiles    = array();

					if ( $access_type === 'parent' ) {
						foreach ( (array) $children as $child ) {
							$profiles[] = array(
								'scout_id'   => (int) ( $child['scout_id'] ?? 0 ),
								'first_name' => $child['first_name'] ?? '',
								'last_name'  => $child['last_name'] ?? '',
								'patrol'     => $child['patrol'] ?? '',
							);
						}
					} else {
						$explorer = $this->explorer_repo->find_by_wp_user_id( $target_user->ID );
						if ( $explorer ) {
							$profiles[] = array(
								'scout_id'   => (int) $explorer['scout_id'],
								'first_name' => $explorer['first_name'] ?? '',
								'last_name'  => $explorer['last_name'] ?? '',
								'patrol'     => $explorer['patrol'] ?? '',
							);
						} elseif ( ! empty( $scout_ids ) ) {
							$profiles[] = array(
								'scout_id'   => (int) $scout_ids[0],
								'first_name' => get_user_meta( $target_user->ID, 'first_name', true ) ?: $target_user->first_name,
								'last_name'  => get_user_meta( $target_user->ID, 'last_name', true ) ?: $target_user->last_name,
								'patrol'     => get_user_meta( $target_user->ID, 'ems_unit', true ) ?: '',
							);
						}
					}

					return new \WP_REST_Response(
						array(
							'logged_in'      => true,
							'is_admin'       => true,
							'is_preview'     => true,
							'access_type'    => $access_type,
							'display_name'   => $target_user->display_name,
							'profiles'       => $profiles,
							'preview_target' => array(
								'type' => 'user',
								'id'   => $target_user->ID,
								'name' => $target_user->display_name,
							),
						),
						200
					);
				}
			} elseif ( $preview_scout_id > 0 ) {
				$explorer = $this->explorer_repo->find_by_scout_id( $preview_scout_id );
				$profiles = array();
				$name     = 'Explorer #' . $preview_scout_id;
				if ( $explorer ) {
					$name       = trim( ( $explorer['first_name'] ?? '' ) . ' ' . ( $explorer['last_name'] ?? '' ) );
					$profiles[] = array(
						'scout_id'   => (int) $explorer['scout_id'],
						'first_name' => $explorer['first_name'] ?? '',
						'last_name'  => $explorer['last_name'] ?? '',
						'patrol'     => $explorer['patrol'] ?? '',
					);
				} else {
					global $wpdb;
					$signup = $wpdb->get_row(
						$wpdb->prepare(
							"SELECT explorer_first_name, explorer_last_name FROM {$wpdb->prefix}ems_participant_signups WHERE scout_id = %d ORDER BY id DESC LIMIT 1",
							$preview_scout_id
						),
						ARRAY_A
					);
					if ( $signup ) {
						$name       = trim( ( $signup['explorer_first_name'] ?? '' ) . ' ' . ( $signup['explorer_last_name'] ?? '' ) );
						$profiles[] = array(
							'scout_id'   => $preview_scout_id,
							'first_name' => $signup['explorer_first_name'] ?? '',
							'last_name'  => $signup['explorer_last_name'] ?? '',
							'patrol'     => '',
						);
					}
				}

				return new \WP_REST_Response(
					array(
						'logged_in'      => true,
						'is_admin'       => true,
						'is_preview'     => true,
						'access_type'    => 'member',
						'display_name'   => $name,
						'profiles'       => $profiles,
						'preview_target' => array(
							'type'     => 'explorer',
							'scout_id' => $preview_scout_id,
							'name'     => $name,
						),
					),
					200
				);
			}
		}

		$access_type = get_user_meta( $user->ID, 'ems_access_type', true ) ?: 'local';
		$scout_ids   = get_user_meta( $user->ID, 'ems_scout_ids', true ) ?: array();
		$children    = get_user_meta( $user->ID, 'ems_children', true ) ?: array();

		$profiles = array();

		if ( $access_type === 'parent' ) {
			foreach ( (array) $children as $child ) {
				$profiles[] = array(
					'scout_id'     => (int) ( $child['scout_id'] ?? 0 ),
					'first_name'   => $child['first_name'] ?? '',
					'last_name'    => $child['last_name'] ?? '',
					'patrol'       => $child['patrol'] ?? '',
					'relationship' => 'child',
					'label'        => trim( ( $child['first_name'] ?? '' ) . ' ' . ( $child['last_name'] ?? '' ) ),
				);
			}
		} elseif ( $access_type === 'member' || $access_type === 'network_member' ) {
			$explorer = $this->explorer_repo->find_by_wp_user_id( $user->ID );
			if ( $explorer ) {
				$profiles[] = array(
					'scout_id'     => (int) $explorer['scout_id'],
					'first_name'   => $explorer['first_name'] ?? '',
					'last_name'    => $explorer['last_name'] ?? '',
					'patrol'       => $explorer['patrol'] ?? '',
					'relationship' => 'self',
					'label'        => 'Myself',
				);
			} elseif ( ! empty( $scout_ids ) ) {
				$profiles[] = array(
					'scout_id'     => (int) $scout_ids[0],
					'first_name'   => get_user_meta( $user->ID, 'first_name', true ) ?: $user->first_name,
					'last_name'    => get_user_meta( $user->ID, 'last_name', true ) ?: $user->last_name,
					'patrol'       => get_user_meta( $user->ID, 'ems_unit', true ) ?: '',
					'relationship' => 'self',
					'label'        => 'Myself',
				);
			}

			// Networker support: also include any linked children alongside "Myself".
			$self_sid = ! empty( $profiles ) ? (int) $profiles[0]['scout_id'] : 0;
			foreach ( (array) $children as $child ) {
				$child_sid = (int) ( $child['scout_id'] ?? 0 );
				if ( $child_sid > 0 && $child_sid !== $self_sid ) {
					$profiles[] = array(
						'scout_id'     => $child_sid,
						'first_name'   => $child['first_name'] ?? '',
						'last_name'    => $child['last_name'] ?? '',
						'patrol'       => $child['patrol'] ?? '',
						'relationship' => 'child',
						'label'        => trim( ( $child['first_name'] ?? '' ) . ' ' . ( $child['last_name'] ?? '' ) ),
					);
				}
			}
		}

		return new \WP_REST_Response(
			array(
				'logged_in'    => true,
				'is_admin'     => $is_admin,
				'is_preview'   => false,
				'access_type'  => $access_type,
				'display_name' => $user->display_name,
				'profiles'     => $profiles,
			),
			200
		);
	}

	public function get_explorer_detail( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$scout_id = (int) $request->get_param( 'scout_id' );
		$user_id  = get_current_user_id();
		$is_admin = current_user_can( 'manage_options' );

		// Access boundary check
		$authorized  = false;
		$access_type = get_user_meta( $user_id, 'ems_access_type', true );

		if ( $is_admin && $request->get_param( 'preview_access_type' ) ) {
			$access_type = sanitize_key( (string) $request->get_param( 'preview_access_type' ) );
		}

		if ( $access_type === 'parent' ) {
			$parent_scout_ids = get_user_meta( $user_id, 'ems_scout_ids', true ) ?: array();
			if ( in_array( $scout_id, array_map( 'intval', $parent_scout_ids ), true ) ) {
				$authorized = true;
			}
		} elseif ( $access_type === 'member' || $access_type === 'network_member' ) {
			$explorer = $this->explorer_repo->find_by_scout_id( $scout_id );
			if ( $explorer && (int) $explorer['wp_user_id'] === $user_id ) {
				$authorized = true;
			} else {
				$member_scout_ids = get_user_meta( $user_id, 'ems_scout_ids', true ) ?: array();
				$children         = get_user_meta( $user_id, 'ems_children', true ) ?: array();
				$child_scout_ids  = array_map( static fn( $c ) => (int) ( $c['scout_id'] ?? 0 ), (array) $children );
				if (
					in_array( $scout_id, array_map( 'intval', $member_scout_ids ), true )
					|| in_array( $scout_id, $child_scout_ids, true )
				) {
					$authorized = true;
				}
			}
		}

		if ( ! $authorized && current_user_can( 'manage_options' ) ) {
			$authorized = true; // Admins can preview
		}

		if ( ! $authorized ) {
			return new \WP_Error(
				'ems_forbidden',
				'You do not have permission to access this explorer record.',
				array( 'status' => 403 )
			);
		}

		global $wpdb;

		$explorer   = $this->explorer_repo->find_by_scout_id( $scout_id );
		$first_name = '';
		$last_name  = '';
		if ( $explorer ) {
			$first_name      = $explorer['first_name'] ?? '';
			$last_name       = $explorer['last_name'] ?? '';
			$first_aid_level = $explorer['first_aid_level'] ?? 'none';
		} else {
			if ( $access_type === 'parent' ) {
				$children = get_user_meta( $user_id, 'ems_children', true ) ?: array();
				foreach ( $children as $child ) {
					if ( (int) ( $child['scout_id'] ?? 0 ) === $scout_id ) {
						$first_name = $child['first_name'] ?? '';
						$last_name  = $child['last_name'] ?? '';
						break;
					}
				}
			} elseif ( $access_type === 'member' || $access_type === 'network_member' ) {
				$first_name = get_user_meta( $user_id, 'first_name', true );
				$last_name  = get_user_meta( $user_id, 'last_name', true );
			}

			if ( empty( $first_name ) && empty( $last_name ) ) {
				$signup_name = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT explorer_first_name, explorer_last_name FROM {$wpdb->prefix}ems_participant_signups WHERE scout_id = %d ORDER BY id DESC LIMIT 1",
						$scout_id
					),
					ARRAY_A
				);
				if ( ! $signup_name ) {
					$signup_name = $wpdb->get_row(
						$wpdb->prepare(
							"SELECT explorer_first_name, explorer_last_name FROM {$wpdb->prefix}ems_expedition_signups WHERE scout_id = %d ORDER BY id DESC LIMIT 1",
							$scout_id
						),
						ARRAY_A
					);
				}
				if ( $signup_name ) {
					$first_name = $signup_name['explorer_first_name'];
					$last_name  = $signup_name['explorer_last_name'];
				}
			}

			$fa_status = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT first_aid_status FROM {$wpdb->prefix}ems_expedition_signups WHERE scout_id = %d ORDER BY id DESC LIMIT 1",
					$scout_id
				)
			);
			$first_aid_level = $fa_status ?: 'none';
		}

		$all_courses = $this->tutor_client->get_all_courses() ?: array();
		$course_map  = array();
		foreach ( $all_courses as $c ) {
			$course_map[ (int) $c->ID ] = $c->post_title;
		}

		$decode_field = function ( $val ) {
			if ( empty( $val ) ) {
				return null;
			}
			$decoded = json_decode( $val, true );
			if ( json_last_error() === JSON_ERROR_NONE ) {
				return $decoded;
			}
			if ( is_serialized( $val ) ) {
				return maybe_unserialize( $val );
			}
			return $val;
		};

		$map_completion = function ( $completion_data ) use ( $course_map ) {
			if ( ! is_array( $completion_data ) ) {
				return $completion_data;
			}
			$mapped = array();
			foreach ( $completion_data as $key => $val ) {
				$course_id = (int) preg_replace( '/[^0-9]/', '', $key );
				if ( $course_id && isset( $course_map[ $course_id ] ) ) {
					$new_key = $course_map[ $course_id ];
				} else {
					$new_key = ucwords( str_replace( '_', ' ', $key ) );
				}
				$mapped[ $new_key ] = $val;
			}
			return $mapped;
		};

		// Fetch signups
		$participant_signups = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}ems_participant_signups WHERE scout_id = %d",
				$scout_id
			),
			ARRAY_A
		) ?: array();

		$expedition_signups = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}ems_expedition_signups WHERE scout_id = %d",
				$scout_id
			),
			ARRAY_A
		) ?: array();

		$signups = array();
		foreach ( $participant_signups as $s ) {
			$signups[] = array(
				'id'                 => (int) $s['id'],
				'dofe_level'         => $s['dofe_level'],
				'signup_status'      => $s['signup_status'],
				'payment_status'     => $s['payment_status'],
				'created_at'         => $s['created_at'],
				'type'               => 'participant',
				'dob'                => $s['dob'],
				'dofe_registered'    => $s['dofe_registered'],
				'dofe_number'        => $s['dofe_number'],
				'dofe_org'           => $s['dofe_org'],
				'bronze_completion'  => ! empty( $s['bronze_completion'] ) ? $map_completion( $decode_field( $s['bronze_completion'] ) ) : null,
				'silver_completion'  => ! empty( $s['silver_completion'] ) ? $map_completion( $decode_field( $s['silver_completion'] ) ) : null,
				'form_submission_id' => (int) $s['form_submission_id'],
			);
		}
		foreach ( $expedition_signups as $s ) {
			$signups[] = array(
				'id'                       => (int) $s['id'],
				'dofe_level'               => $s['dofe_level'],
				'signup_status'            => $s['signup_status'],
				'created_at'               => $s['created_at'],
				'type'                     => 'expedition',
				'expedition_preferences'   => ! empty( $s['expedition_preferences'] ) ? $decode_field( $s['expedition_preferences'] ) : null,
				'additional_support_needs' => $s['additional_support_needs'],
				'first_aid_status'         => $s['first_aid_status'],
				'first_aid_expiry'         => $s['first_aid_expiry'],
				'form_submission_id'       => (int) $s['form_submission_id'],
			);
		}

		// Fetch team memberships and related expeditions
		$team_memberships = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT team_post_id FROM {$wpdb->prefix}ems_team_members WHERE scout_id = %d",
				$scout_id
			),
			ARRAY_A
		) ?: array();

		$events = array(
			'training'   => array(),
			'practice'   => array(),
			'qualifying' => array(),
		);

		$team_info        = null;
		$required_courses = array();

		foreach ( $team_memberships as $tm ) {
			$team_id   = (int) $tm['team_post_id'];
			$team_post = get_post( $team_id );
			if ( ! $team_post || $team_post->post_type !== 'team' ) {
				continue;
			}

			$expedition_id   = (int) $team_post->post_parent;
			$expedition_post = get_post( $expedition_id );
			if ( ! $expedition_post || $expedition_post->post_type !== 'expedition' ) {
				continue;
			}

			$type      = get_post_meta( $expedition_id, 'ems_type', true ) ?: 'training';
			$level     = get_post_meta( $expedition_id, 'ems_level', true ) ?: 'bronze';
			$team_code = get_post_meta( $team_id, 'ems_team_code', true ) ?: '';

			// WhatsApp link: explorers see explorer_link, parents see parent_link
			$whatsapp_meta_key = ( $access_type === 'parent' ) ? 'ems_expedition_whatsapp_parents' : 'ems_expedition_whatsapp_explorers';
			$whatsapp_link     = get_post_meta( $expedition_id, $whatsapp_meta_key, true ) ?: null;

			$event_data = array(
				'id'                       => $expedition_id,
				'name'                     => $expedition_post->post_title,
				'start_date'               => get_post_meta( $expedition_id, 'ems_start_date', true ) ?: '',
				'start_time'               => get_post_meta( $expedition_id, 'ems_start_time', true ) ?: '',
				'end_date'                 => get_post_meta( $expedition_id, 'ems_end_date', true ) ?: '',
				'end_time'                 => get_post_meta( $expedition_id, 'ems_end_time', true ) ?: '',
				'location'                 => get_post_meta( $expedition_id, 'ems_start_location', true ) ?: '',
				'end_location'             => get_post_meta( $expedition_id, 'ems_end_location', true ) ?: '',
				'type'                     => $type,
				'level'                    => $level,
				'event_code'               => get_post_meta( $expedition_id, 'ems_expedition_code', true ) ?: get_post_meta( $expedition_id, 'ems_event_code', true ) ?: '',
				'required_first_aid_level' => get_post_meta( $expedition_id, 'ems_first_aid_level', true ) ?: 'none',
				'route_deadline'           => get_post_meta( $expedition_id, 'ems_route_deadline', true ) ?: '',
				'route_info'               => get_post_meta( $expedition_id, 'ems_route_info', true ) ?: get_post_meta( $expedition_id, 'ems_expedition_route_info', true ) ?: '',
				'whatsapp_explorers'       => get_post_meta( $expedition_id, 'ems_expedition_whatsapp_explorers', true ) ?: null,
				'whatsapp_parents'         => get_post_meta( $expedition_id, 'ems_expedition_whatsapp_parents', true ) ?: null,
				'osm_event_url'            => get_post_meta( $expedition_id, 'ems_osm_event_url', true ) ?: null,
				'leader_in_charge'         => array(
					'name'  => get_post_meta( $expedition_id, 'ems_lic_name', true ) ?: '',
					'email' => get_post_meta( $expedition_id, 'ems_lic_email', true ) ?: '',
					'phone' => get_post_meta( $expedition_id, 'ems_lic_phone', true ) ?: '',
				),
			);

			if ( in_array( $type, array( 'training', 'practice', 'qualifying' ), true ) ) {
				$events[ $type ][] = $event_data;
			} else {
				$events['training'][] = $event_data;
			}

			// Gather required courses for training checklists
			$event_courses = get_post_meta( $expedition_id, 'ems_training_requirements', true ) ?: array();
			if ( is_array( $event_courses ) ) {
				$required_courses = array_merge( $required_courses, $event_courses );
			}

			// If the team is not UNALLOCATED, populate team teammates and status
			if ( $team_code !== 'UNALLOCATED' && empty( $team_info ) ) {
				// Fetch teammates
				$teammate_rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT scout_id FROM {$wpdb->prefix}ems_team_members WHERE team_post_id = %d AND scout_id != %d",
						$team_id,
						$scout_id
					),
					ARRAY_A
				) ?: array();

				$teammates = array();
				foreach ( $teammate_rows as $tr ) {
					$tm_id       = (int) $tr['scout_id'];
					$tm_explorer = $this->explorer_repo->find_by_scout_id( $tm_id );
					if ( $tm_explorer ) {
						$last_initial = ! empty( $tm_explorer['last_name'] ) ? substr( $tm_explorer['last_name'], 0, 1 ) . '.' : '';
						$teammates[]  = array(
							'first_name'   => $tm_explorer['first_name'] ?? '',
							'last_initial' => $last_initial,
							'patrol'       => $tm_explorer['patrol'] ?? '',
						);
					}
				}

				$team_info = array(
					'team_code'     => $team_code,
					'route_status'  => get_post_meta( $team_id, 'ems_route_status', true ) ?: 'pending',
					'whatsapp_link' => $whatsapp_link,
					'teammates'     => $teammates,
				);
			}
		}

		// Training checklist details
		$required_courses    = array_unique( array_map( 'intval', $required_courses ) );
		$training_checklist  = array();
		$explorer_wp_user_id = null;
		if ( $explorer && ! empty( $explorer['wp_user_id'] ) ) {
			$explorer_wp_user_id = (int) $explorer['wp_user_id'];
		} elseif ( $access_type === 'member' || $access_type === 'network_member' ) {
			$explorer_wp_user_id = $user_id;
		}

		$matrix = array();
		if ( $explorer_wp_user_id && ! empty( $required_courses ) ) {
			$matrix = $this->tutor_client->get_enrollment_matrix( array( $explorer_wp_user_id ), $required_courses );
		}

		foreach ( $required_courses as $course_id ) {
			$completed = false;
			if ( $explorer_wp_user_id ) {
				$status    = $matrix[ $explorer_wp_user_id ][ $course_id ] ?? 'not_enrolled';
				$completed = ( $status === 'complete' );
			}
			$training_checklist[] = array(
				'course_name'     => $course_map[ $course_id ] ?? 'Unknown Course',
				'completed'       => $completed,
				'completion_date' => null, // Placeholder or fetch if stored
				'course_url'      => get_permalink( $course_id ) ?: '',
			);
		}

		$latest_support_needs = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT additional_support_needs FROM {$wpdb->prefix}ems_expedition_signups WHERE scout_id = %d AND additional_support_needs != '' ORDER BY id DESC LIMIT 1",
				$scout_id
			)
		) ?: '';

		$is_viewing_child = ( $access_type === 'parent' );
		if ( $access_type === 'network_member' ) {
			$own_explorer     = $this->explorer_repo->find_by_wp_user_id( $user_id );
			$is_viewing_child = ! $own_explorer || (int) $own_explorer['scout_id'] !== $scout_id;
		}

		$offers = $this->build_offers_for_scout( $wpdb, $scout_id );

		return new \WP_REST_Response(
			array(
				'explorer'           => array(
					'scout_id'                 => $scout_id,
					'first_name'               => $first_name,
					'last_name'                => $last_name,
					'first_aid_level'          => $first_aid_level,
					'additional_support_needs' => $latest_support_needs,
				),
				'signups'            => $signups,
				'events'             => $events,
				'training_checklist' => $training_checklist,
				'training_readonly'  => $is_viewing_child,
				'team'               => $team_info,
				'offers'             => $offers,
			),
			200
		);
	}

	/**
	 * Handles Place Offer Accept / Query / Decline atomically across all team_members rows
	 * sharing (cohort_id, expedition_signup_id).
	 */
	public function respond_to_offer( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body                 = $request->get_json_params() ?: $request->get_params();
		$scout_id             = (int) ( $body['scout_id'] ?? 0 );
		$cohort_id            = (int) ( $body['cohort_id'] ?? 0 );
		$expedition_signup_id = (int) ( $body['expedition_signup_id'] ?? 0 );
		$team_post_id         = (int) ( $body['team_post_id'] ?? 0 );
		$action               = sanitize_key( (string) ( $body['action'] ?? '' ) );
		$message              = sanitize_textarea_field( (string) ( $body['message'] ?? '' ) );

		$status_map = array(
			'accept'  => 'accepted',
			'query'   => 'queried',
			'decline' => 'declined',
		);

		$has_signup_key = ( $expedition_signup_id > 0 );
		$has_cohort_key = ( $cohort_id > 0 && $expedition_signup_id > 0 );
		$has_team_key   = ( $team_post_id > 0 && $scout_id > 0 );

		if ( ( ! $has_signup_key && ! $has_team_key ) || ! isset( $status_map[ $action ] ) ) {
			return new \WP_Error(
				'ems_invalid_offer_response',
				'Missing or invalid offer identifier or action.',
				array( 'status' => 400 )
			);
		}

		if ( $action === 'query' && trim( $message ) === '' ) {
			return new \WP_Error(
				'ems_query_message_required',
				'Please enter a message explaining your query.',
				array( 'status' => 400 )
			);
		}

		if ( $action === 'query' ) {
			$msg_len = function_exists( 'mb_strlen' ) ? mb_strlen( $message, 'UTF-8' ) : strlen( $message );
			if ( $msg_len > 1000 ) {
				return new \WP_Error(
					'ems_query_message_too_long',
					'Query message must be 1000 characters or fewer.',
					array( 'status' => 400 )
				);
			}
		}

		$user_id  = get_current_user_id();
		$is_admin = current_user_can( 'manage_options' );
		if ( ! $is_admin ) {
			$access_type     = (string) get_user_meta( $user_id, 'ems_access_type', true );
			if ( ! in_array( $access_type, array( 'parent', 'network_member' ), true ) ) {
				return new \WP_Error(
					'ems_forbidden',
					'Only parents, guardians, or networkers can respond to place offers.',
					array( 'status' => 403 )
				);
			}
			$member_sids     = array_map( 'intval', (array) ( get_user_meta( $user_id, 'ems_scout_ids', true ) ?: array() ) );
			$children        = (array) ( get_user_meta( $user_id, 'ems_children', true ) ?: array() );
			$child_sids      = array_map( static fn( $c ) => (int) ( $c['scout_id'] ?? 0 ), $children );
			$own_explorer    = $this->explorer_repo->find_by_wp_user_id( $user_id );
			$own_sid         = $own_explorer ? (int) $own_explorer['scout_id'] : 0;
			$allowed_sids    = array_filter( array_merge( $member_sids, $child_sids, array( $own_sid ) ) );
			$signup_parent   = 0;

			global $wpdb;
			$signup_row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT scout_id, parent_user_id FROM {$wpdb->prefix}ems_expedition_signups WHERE id = %d",
					$expedition_signup_id
				),
				ARRAY_A
			);
			if ( $signup_row ) {
				$signup_parent = (int) ( $signup_row['parent_user_id'] ?? 0 );
				if ( $scout_id <= 0 ) {
					$scout_id = (int) ( $signup_row['scout_id'] ?? 0 );
				}
			}

			if (
				$signup_parent !== $user_id
				&& ! in_array( $scout_id, $allowed_sids, true )
				&& ( $access_type === '' || ! in_array( $scout_id, $allowed_sids, true ) )
			) {
				return new \WP_Error( 'ems_forbidden', 'Unauthorized to respond to this offer.', array( 'status' => 403 ) );
			}
		}

		global $wpdb;
		$tm_table = $wpdb->prefix . 'ems_team_members';
		if ( $has_signup_key ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, notified_at, notification_status FROM {$tm_table} WHERE expedition_signup_id = %d",
					$expedition_signup_id
				),
				ARRAY_A
			) ?: array();
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, notified_at, notification_status FROM {$tm_table} WHERE team_post_id = %d AND scout_id = %d",
					$team_post_id,
					$scout_id
				),
				ARRAY_A
			) ?: array();
		}

		if ( empty( $rows ) ) {
			return new \WP_Error( 'ems_offer_not_found', 'No active place offer found for this team and registration.', array( 'status' => 404 ) );
		}

		$notified_at = $rows[0]['notified_at'] ?? null;
		$deadline    = ( new \EMS\Services\DeadlinePolicyService() )->evaluate_cutoff( 'place_offer', $notified_at ? (string) $notified_at : null );
		if ( empty( $deadline['allowed'] ) ) {
			return new \WP_Error( 'ems_offer_cutoff_passed', (string) ( $deadline['reason'] ?: 'The response cutoff has passed.' ), array( 'status' => 403 ) );
		}

		$new_status   = $status_map[ $action ];
		$responded_at = current_time( 'mysql' );
		$wpdb->query( 'START TRANSACTION' );
		if ( $has_signup_key ) {
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$tm_table}
					SET offer_status = %s,
						offer_query_message = %s,
						offer_responded_at = %s,
						offer_responded_by = %d
					WHERE expedition_signup_id = %d",
					$new_status,
					$action === 'query' ? $message : null,
					$responded_at,
					$user_id,
					$expedition_signup_id
				)
			);
		} else {
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$tm_table}
					SET offer_status = %s,
						offer_query_message = %s,
						offer_responded_at = %s,
						offer_responded_by = %d
					WHERE team_post_id = %d AND scout_id = %d",
					$new_status,
					$action === 'query' ? $message : null,
					$responded_at,
					$user_id,
					$team_post_id,
					$scout_id
				)
			);
		}

		if ( $updated === false ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'ems_db_error', 'Failed to record offer response.', array( 'status' => 500 ) );
		}
		$wpdb->query( 'COMMIT' );

		if ( $action === 'query' ) {
			$query_email = trim( (string) get_option( 'ems_offer_query_email', '' ) );
			$admin_email = $query_email !== '' ? $query_email : (string) get_option( 'admin_email', '' );
			if ( $admin_email !== '' && function_exists( 'wp_mail' ) ) {
				$posts_table    = $wpdb->prefix . 'posts';
				$postmeta_table = $wpdb->prefix . 'postmeta';
				if ( $team_post_id > 0 ) {
					$team_label = (string) ( $wpdb->get_var(
						$wpdb->prepare(
							"SELECT COALESCE(NULLIF(pm.meta_value, ''), p.post_title)
							 FROM {$posts_table} p
							 LEFT JOIN {$postmeta_table} pm ON pm.post_id = p.ID AND pm.meta_key = 'ems_team_code'
							 WHERE p.ID = %d LIMIT 1",
							$team_post_id
						)
					) ?: '' );
				} else {
					$team_label = (string) ( $wpdb->get_var(
						$wpdb->prepare(
							"SELECT COALESCE(NULLIF(pm.meta_value, ''), p.post_title)
							 FROM {$wpdb->prefix}ems_team_members tm
							 INNER JOIN {$posts_table} p ON p.ID = tm.team_post_id
							 LEFT JOIN {$postmeta_table} pm ON pm.post_id = p.ID AND pm.meta_key = 'ems_team_code'
							 WHERE tm.expedition_signup_id = %d
							 ORDER BY tm.id ASC LIMIT 1",
							$expedition_signup_id
						)
					) ?: '' );
				}
				if ( $team_label === '' ) {
					$team_label = $team_post_id > 0 ? "Team #{$team_post_id}" : "Signup #{$expedition_signup_id}";
				}
				$subject = sprintf( '[EMS] Place Offer Query — %s (Signup #%d)', $team_label, $expedition_signup_id );
				$body_tx = sprintf(
					"A Place Offer query was submitted.\n\nTeam: %s\nExpedition Signup ID: %d\nScout ID: %d\nMessage:\n%s\n",
					$team_label,
					$expedition_signup_id,
					$scout_id,
					$message
				);
				wp_mail( $admin_email, $subject, $body_tx );
			}
		}

		return new \WP_REST_Response(
			array(
				'updated'              => true,
				'cohort_id'            => $cohort_id,
				'expedition_signup_id' => $expedition_signup_id,
				'offer_status'         => $new_status,
				'offer_query_message'  => $action === 'query' ? $message : null,
				'offer_responded_at'   => $responded_at,
				'rows_updated'         => (int) $updated,
			),
			200
		);
	}

	/**
	 * Builds grouped Place Offers for a given scout_id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function build_offers_for_scout( object $wpdb, int $scout_id ): array {
		$tm_table = $wpdb->prefix . 'ems_team_members';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tm.*, t.post_title AS team_name, exp.ID AS expedition_id, exp.post_title AS expedition_name
				FROM {$tm_table} tm
				LEFT JOIN {$wpdb->posts} t ON t.ID = tm.team_post_id
				LEFT JOIN {$wpdb->posts} exp ON exp.ID = t.post_parent
				WHERE tm.scout_id = %d
				ORDER BY tm.id ASC",
				$scout_id
			),
			ARRAY_A
		) ?: array();

		$grouped            = array();
		$deadline_svc       = new \EMS\Services\DeadlinePolicyService();
		$teammates_by_team  = array();
		$signups_table      = $wpdb->prefix . 'ems_expedition_signups';
		foreach ( $rows as $row ) {
			$esid   = (int) ( $row['expedition_signup_id'] ?? 0 );
			$tpid   = (int) ( $row['team_post_id'] ?? 0 );
			$exp_id = (int) ( $row['expedition_id'] ?? 0 );
			$t_code = $tpid > 0 ? trim( (string) get_post_meta( $tpid, 'ems_team_code', true ) ) : '';
			if ( strtoupper( $t_code ) === 'UNALLOCATED' ) {
				continue;
			}
			$resolved_team_code = $t_code ?: (string) ( $row['team_name'] ?? '' );

			if ( $tpid > 0 && ! isset( $teammates_by_team[ $tpid ] ) ) {
				$stage_teammates = array();
				$tm_ids          = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT scout_id FROM {$tm_table} WHERE team_post_id = %d AND scout_id != %d",
						$tpid,
						$scout_id
					)
				);
				foreach ( ( $tm_ids ?: array() ) as $tm_id ) {
					$tm_id       = (int) $tm_id;
					$tm_explorer = $this->explorer_repo->find_by_scout_id( $tm_id );
					if ( $tm_explorer ) {
						$ln                = (string) ( $tm_explorer['last_name'] ?? '' );
						$stage_teammates[] = array(
							'first_name'   => (string) ( $tm_explorer['first_name'] ?? '' ),
							'last_initial' => '' !== $ln ? mb_substr( $ln, 0, 1 ) . '.' : '',
							'patrol'       => (string) ( $tm_explorer['patrol'] ?? '' ),
						);
						continue;
					}
					$signup_row = $wpdb->get_row(
						$wpdb->prepare(
							"SELECT first_name, last_name, unit_name FROM {$signups_table} WHERE scout_id = %d ORDER BY id DESC LIMIT 1",
							$tm_id
						),
						ARRAY_A
					);
					if ( $signup_row ) {
						$ln                = (string) ( $signup_row['last_name'] ?? '' );
						$stage_teammates[] = array(
							'first_name'   => (string) ( $signup_row['first_name'] ?? '' ),
							'last_initial' => '' !== $ln ? mb_substr( $ln, 0, 1 ) . '.' : '',
							'patrol'       => (string) ( $signup_row['unit_name'] ?? '' ),
						);
					}
				}
				$teammates_by_team[ $tpid ] = $stage_teammates;
			}
			$stage_teammates = $tpid > 0 ? ( $teammates_by_team[ $tpid ] ?? array() ) : array();

			$key = $esid > 0 ? "signup:{$esid}" : "team:{$tpid}";
			if ( ! isset( $grouped[ $key ] ) ) {
				$notified_at     = $row['notified_at'] ?? null;
				$eval            = $deadline_svc->evaluate_cutoff( 'place_offer', $notified_at ? (string) $notified_at : null );
				$level           = $exp_id > 0 ? (string) ( get_post_meta( $exp_id, 'ems_level', true ) ?: '' ) : '';
				$transport       = $exp_id > 0 ? (string) ( get_post_meta( $exp_id, 'ems_transport', true ) ?: '' ) : '';
				$grouped[ $key ] = array(
					'cohort_id'            => 0,
					'expedition_signup_id' => $esid,
					'team_post_id'         => $tpid,
					'team_name'            => $resolved_team_code ?: ( $row['expedition_name'] ?: "Team #{$tpid}" ),
					'dofe_level'           => $level,
					'transport_mode'       => $transport,
					'notification_status'  => (string) ( $row['notification_status'] ?? 'draft' ),
					'offer_status'         => (string) ( $row['offer_status'] ?: 'pending' ),
					'offer_query_message'  => $row['offer_query_message'] ?? null,
					'offer_responded_at'   => $row['offer_responded_at'] ?? null,
					'notified_at'          => $notified_at,
					'deadline'             => $eval,
					'stages'               => array(),
					'_team_codes'          => array(),
				);
			}
			if ( '' !== $resolved_team_code && ! in_array( $resolved_team_code, $grouped[ $key ]['_team_codes'], true ) ) {
				$grouped[ $key ]['_team_codes'][] = $resolved_team_code;
				$grouped[ $key ]['team_name']     = implode( ' / ', $grouped[ $key ]['_team_codes'] );
			}
			$grouped[ $key ]['stages'][] = array(
				'expedition_id'   => $exp_id,
				'expedition_name' => $row['expedition_name'] ?? '',
				'team_post_id'    => $tpid,
				'team_code'       => $resolved_team_code,
				'teammates'       => $stage_teammates,
				'type'            => $exp_id > 0 ? ( get_post_meta( $exp_id, 'ems_type', true ) ?: '' ) : '',
				'start_date'      => $exp_id > 0 ? ( get_post_meta( $exp_id, 'ems_start_date', true ) ?: '' ) : '',
				'end_date'        => $exp_id > 0 ? ( get_post_meta( $exp_id, 'ems_end_date', true ) ?: '' ) : '',
			);
		}

		$result = array();
		foreach ( $grouped as $entry ) {
			unset( $entry['_team_codes'] );
			$result[] = $entry;
		}
		return $result;
	}
}
