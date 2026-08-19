<?php
namespace EMS\Admin;

use EMS\Data\OSM_Explorer_Repository;
use EMS\Integrations\TutorLMS_Client;

class Portal_Controller {
	private OSM_Explorer_Repository $explorer_repo;
	private TutorLMS_Client $tutor_client;

	public function __construct(
		?OSM_Explorer_Repository $explorer_repo = null,
		?TutorLMS_Client $tutor_client = null
	) {
		$this->explorer_repo = $explorer_repo ?: new OSM_Explorer_Repository();
		$this->tutor_client  = $tutor_client ?: new TutorLMS_Client();
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
	}

	public function get_me( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! is_user_logged_in() ) {
			return new \WP_REST_Response(
				array(
					'logged_in' => false,
				),
				200
			);
		}

		$user        = wp_get_current_user();
		$access_type = get_user_meta( $user->ID, 'ems_access_type', true ) ?: 'local';
		$scout_ids   = get_user_meta( $user->ID, 'ems_scout_ids', true ) ?: array();
		$children    = get_user_meta( $user->ID, 'ems_children', true ) ?: array();

		$profiles = array();

		if ( $access_type === 'parent' ) {
			foreach ( $children as $child ) {
				$profiles[] = array(
					'scout_id'   => (int) ( $child['scout_id'] ?? 0 ),
					'first_name' => $child['first_name'] ?? '',
					'last_name'  => $child['last_name'] ?? '',
					'patrol'     => $child['patrol'] ?? '',
				);
			}
		} elseif ( $access_type === 'member' ) {
			$explorer = $this->explorer_repo->find_by_wp_user_id( $user->ID );
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
					'first_name' => get_user_meta( $user->ID, 'first_name', true ) ?: $user->first_name,
					'last_name'  => get_user_meta( $user->ID, 'last_name', true ) ?: $user->last_name,
					'patrol'     => get_user_meta( $user->ID, 'ems_unit', true ) ?: '',
				);
			}
		}

		return new \WP_REST_Response(
			array(
				'logged_in'    => true,
				'access_type'  => $access_type,
				'display_name' => $user->display_name,
				'profiles'     => $profiles,
			),
			200
		);
	}

	public function get_explorer_detail( \WP_REST_Request $request ): \WP_REST_Response {
		$scout_id = (int) $request->get_param( 'scout_id' );
		$user_id  = get_current_user_id();

		// Access boundary check
		$authorized  = false;
		$access_type = get_user_meta( $user_id, 'ems_access_type', true );

		if ( $access_type === 'parent' ) {
			$parent_scout_ids = get_user_meta( $user_id, 'ems_scout_ids', true ) ?: array();
			if ( in_array( $scout_id, array_map( 'intval', $parent_scout_ids ), true ) ) {
				$authorized = true;
			}
		} elseif ( $access_type === 'member' ) {
			$explorer = $this->explorer_repo->find_by_scout_id( $scout_id );
			if ( $explorer && (int) $explorer['wp_user_id'] === $user_id ) {
				$authorized = true;
			} else {
				$member_scout_ids = get_user_meta( $user_id, 'ems_scout_ids', true ) ?: array();
				if ( in_array( $scout_id, array_map( 'intval', $member_scout_ids ), true ) ) {
					$authorized = true;
				}
			}
		}

		if ( ! $authorized && current_user_can( 'manage_options' ) ) {
			$authorized = true; // Admins can preview
		}

		if ( ! $authorized ) {
			return new \WP_REST_Response(
				array(
					'code'    => 'forbidden',
					'message' => 'You do not have permission to access this explorer record.',
				),
				403
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
			} elseif ( $access_type === 'member' ) {
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
		} elseif ( $access_type === 'member' ) {
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
				'team'               => $team_info,
			),
			200
		);
	}
}
