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
        register_rest_route( 'ems/v1', '/portal/me', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_me' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( 'ems/v1', '/portal/explorer/(?P<scout_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_explorer_detail' ],
            'permission_callback' => 'is_user_logged_in',
        ] );
    }

    public function get_me( \WP_REST_Request $request ): \WP_REST_Response {
        if ( ! is_user_logged_in() ) {
            return new \WP_REST_Response( [
                'logged_in' => false,
            ], 200 );
        }

        $user = wp_get_current_user();
        $access_type = get_user_meta( $user->ID, 'ems_access_type', true ) ?: 'local';
        $scout_ids   = get_user_meta( $user->ID, 'ems_scout_ids', true ) ?: [];
        $children    = get_user_meta( $user->ID, 'ems_children', true ) ?: [];

        $profiles = [];

        if ( $access_type === 'parent' ) {
            foreach ( $children as $child ) {
                $profiles[] = [
                    'scout_id'   => (int) ( $child['scout_id'] ?? 0 ),
                    'first_name' => $child['first_name'] ?? '',
                    'last_name'  => $child['last_name'] ?? '',
                    'patrol'     => $child['patrol'] ?? '',
                ];
            }
        } elseif ( $access_type === 'member' ) {
            $explorer = $this->explorer_repo->find_by_wp_user_id( $user->ID );
            if ( $explorer ) {
                $profiles[] = [
                    'scout_id'   => (int) $explorer['scout_id'],
                    'first_name' => $explorer['first_name'] ?? '',
                    'last_name'  => $explorer['last_name'] ?? '',
                    'patrol'     => $explorer['patrol'] ?? '',
                ];
            }
        }

        return new \WP_REST_Response( [
            'logged_in'    => true,
            'access_type'  => $access_type,
            'display_name' => $user->display_name,
            'profiles'     => $profiles,
        ], 200 );
    }

    public function get_explorer_detail( \WP_REST_Request $request ): \WP_REST_Response {
        $scout_id = (int) $request->get_param( 'scout_id' );
        $user_id  = get_current_user_id();

        // Access boundary check
        $authorized = false;
        $access_type = get_user_meta( $user_id, 'ems_access_type', true );

        if ( $access_type === 'parent' ) {
            $parent_scout_ids = get_user_meta( $user_id, 'ems_scout_ids', true ) ?: [];
            if ( in_array( $scout_id, array_map( 'intval', $parent_scout_ids ), true ) ) {
                $authorized = true;
            }
        } elseif ( $access_type === 'member' ) {
            $explorer = $this->explorer_repo->find_by_scout_id( $scout_id );
            if ( $explorer && (int) $explorer['wp_user_id'] === $user_id ) {
                $authorized = true;
            }
        }

        if ( ! $authorized && current_user_can( 'manage_options' ) ) {
            $authorized = true; // Admins can preview
        }

        if ( ! $authorized ) {
            return new \WP_REST_Response( [
                'code'    => 'forbidden',
                'message' => 'You do not have permission to access this explorer record.',
            ], 403 );
        }

        $explorer = $this->explorer_repo->find_by_scout_id( $scout_id );
        if ( ! $explorer ) {
            return new \WP_REST_Response( [
                'code'    => 'not_found',
                'message' => 'Explorer record not found.',
            ], 404 );
        }

        global $wpdb;

        // Fetch signups
        $participant_signups = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, dofe_level, signup_status, payment_status, created_at FROM {$wpdb->prefix}ems_participant_signups WHERE scout_id = %d",
            $scout_id
        ), ARRAY_A ) ?: [];

        $expedition_signups = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, dofe_level, signup_status, created_at FROM {$wpdb->prefix}ems_expedition_signups WHERE scout_id = %d",
            $scout_id
        ), ARRAY_A ) ?: [];

        $signups = [];
        foreach ( $participant_signups as $s ) {
            $signups[] = [
                'id'             => (int) $s['id'],
                'dofe_level'     => $s['dofe_level'],
                'signup_status'  => $s['signup_status'],
                'payment_status' => $s['payment_status'],
                'created_at'     => $s['created_at'],
                'type'           => 'participant',
            ];
        }
        foreach ( $expedition_signups as $s ) {
            $signups[] = [
                'id'            => (int) $s['id'],
                'dofe_level'    => $s['dofe_level'],
                'signup_status' => $s['signup_status'],
                'created_at'    => $s['created_at'],
                'type'          => 'expedition',
            ];
        }

        // Fetch team memberships and related expeditions
        $team_memberships = $wpdb->get_results( $wpdb->prepare(
            "SELECT team_post_id FROM {$wpdb->prefix}ems_team_members WHERE scout_id = %d",
            $scout_id
        ), ARRAY_A ) ?: [];

        $events = [
            'training'   => [],
            'practice'   => [],
            'qualifying' => [],
        ];

        $team_info = null;
        $required_courses = [];

        foreach ( $team_memberships as $tm ) {
            $team_id = (int) $tm['team_post_id'];
            $team_post = get_post( $team_id );
            if ( ! $team_post || $team_post->post_type !== 'team' ) {
                continue;
            }

            $expedition_id = (int) $team_post->post_parent;
            $expedition_post = get_post( $expedition_id );
            if ( ! $expedition_post || $expedition_post->post_type !== 'expedition' ) {
                continue;
            }

            $type = get_post_meta( $expedition_id, 'ems_type', true ) ?: 'training';
            $level = get_post_meta( $expedition_id, 'ems_level', true ) ?: 'bronze';
            $team_code = get_post_meta( $team_id, 'ems_team_code', true ) ?: '';

            // WhatsApp link: explorers see explorer_link, parents see parent_link
            $whatsapp_meta_key = ( $access_type === 'parent' ) ? 'ems_expedition_whatsapp_parents' : 'ems_expedition_whatsapp_explorers';
            $whatsapp_link = get_post_meta( $expedition_id, $whatsapp_meta_key, true ) ?: null;

            $event_data = [
                'id'               => $expedition_id,
                'name'             => $expedition_post->post_title,
                'start_date'       => get_post_meta( $expedition_id, 'ems_start_date', true ) ?: '',
                'end_date'         => get_post_meta( $expedition_id, 'ems_end_date', true ) ?: '',
                'location'         => get_post_meta( $expedition_id, 'ems_start_location', true ) ?: '',
                'osm_event_url'    => get_post_meta( $expedition_id, 'ems_osm_event_url', true ) ?: null,
                'leader_in_charge' => [
                    'name'  => get_post_meta( $expedition_id, 'ems_lic_name', true ) ?: '',
                    'email' => get_post_meta( $expedition_id, 'ems_lic_email', true ) ?: '',
                    'phone' => get_post_meta( $expedition_id, 'ems_lic_phone', true ) ?: '',
                ],
            ];

            if ( in_array( $type, [ 'training', 'practice', 'qualifying' ], true ) ) {
                $events[ $type ][] = $event_data;
            } else {
                $events['training'][] = $event_data;
            }

            // Gather required courses for training checklists
            $event_courses = get_post_meta( $expedition_id, 'ems_training_requirements', true ) ?: [];
            if ( is_array( $event_courses ) ) {
                $required_courses = array_merge( $required_courses, $event_courses );
            }

            // If the team is not UNALLOCATED, populate team teammates and status
            if ( $team_code !== 'UNALLOCATED' && empty( $team_info ) ) {
                // Fetch teammates
                $teammate_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT scout_id FROM {$wpdb->prefix}ems_team_members WHERE team_post_id = %d AND scout_id != %d",
                    $team_id,
                    $scout_id
                ), ARRAY_A ) ?: [];

                $teammates = [];
                foreach ( $teammate_rows as $tr ) {
                    $tm_id = (int) $tr['scout_id'];
                    $tm_explorer = $this->explorer_repo->find_by_scout_id( $tm_id );
                    if ( $tm_explorer ) {
                        $last_initial = ! empty( $tm_explorer['last_name'] ) ? substr( $tm_explorer['last_name'], 0, 1 ) . '.' : '';
                        $teammates[] = [
                            'first_name'   => $tm_explorer['first_name'] ?? '',
                            'last_initial' => $last_initial,
                            'patrol'       => $tm_explorer['patrol'] ?? '',
                        ];
                    }
                }

                $team_info = [
                    'team_code'     => $team_code,
                    'route_status'  => get_post_meta( $team_id, 'ems_route_status', true ) ?: 'pending',
                    'whatsapp_link' => $whatsapp_link,
                    'teammates'     => $teammates,
                ];
            }
        }

        // Training checklist details
        $required_courses = array_unique( array_map( 'intval', $required_courses ) );
        $training_checklist = [];
        $explorer_wp_user_id = $explorer['wp_user_id'] ? (int) $explorer['wp_user_id'] : null;

        $all_courses = $this->tutor_client->get_all_courses() ?: [];
        $course_map = [];
        foreach ( $all_courses as $c ) {
            $course_map[ (int) $c->ID ] = $c->post_title;
        }

        foreach ( $required_courses as $course_id ) {
            $completed = false;
            if ( $explorer_wp_user_id ) {
                $completed = (bool) get_user_meta( $explorer_wp_user_id, '_tutor_completed_course_' . $course_id, true );
            }
            $training_checklist[] = [
                'course_name'     => $course_map[ $course_id ] ?? 'Unknown Course',
                'completed'       => $completed,
                'completion_date' => null, // Placeholder or fetch if stored
                'course_url'      => get_permalink( $course_id ) ?: '',
            ];
        }

        return new \WP_REST_Response( [
            'explorer'           => [
                'scout_id'   => $scout_id,
                'first_name' => $explorer['first_name'] ?? '',
                'last_name'  => $explorer['last_name'] ?? '',
            ],
            'signups'            => $signups,
            'events'             => $events,
            'training_checklist' => $training_checklist,
            'team'               => $team_info,
        ], 200 );
    }
}
