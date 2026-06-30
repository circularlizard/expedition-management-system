<?php
namespace EMS\Core;

class CPT_Registry {
    public function register(): void {
        register_post_type( 'season', [
            'labels'       => [ 'name' => 'Seasons', 'singular_name' => 'Season' ],
            'public'       => false,
            'show_ui'      => true,
            'show_in_rest' => true,
            'show_in_menu' => false,
            'menu_icon'    => 'dashicons-calendar-alt',
            'supports'     => [ 'title', 'custom-fields' ],
        ] );

        register_post_type( 'expedition', [
            'labels'       => [ 'name' => 'Events', 'singular_name' => 'Event' ],
            'public'       => true,
            'has_archive'  => false,
            'show_in_rest' => true,
            'show_in_menu' => false,
            'menu_icon'    => 'dashicons-location-alt',
            'supports'     => [ 'title', 'editor', 'custom-fields', 'page-attributes' ],
        ] );

        register_post_type( 'team', [
            'labels'       => [ 'name' => 'Teams', 'singular_name' => 'Team' ],
            'public'       => true,
            'show_in_rest' => true,
            'show_in_menu' => false,
            'menu_icon'    => 'dashicons-groups',
            'supports'     => [ 'title', 'custom-fields', 'page-attributes' ],
        ] );
    }

    public function get_expedition_meta_fields(): array {
        return [
            'ems_level'                          => [ 'type' => 'string',  'enum'     => [ 'bronze', 'silver', 'gold' ] ],
            'ems_type'                           => [ 'type' => 'string',  'enum'     => [ 'training', 'practice', 'qualifying' ] ],
            'ems_transport'                      => [ 'type' => 'string',  'enum'     => [ 'hillwalking', 'biking', 'paddling' ] ],
            'ems_event_code'                     => [ 'type' => 'string',  'required' => true ],
            'ems_expedition_code'                => [ 'type' => 'string' ],
            'ems_start_date'                     => [ 'type' => 'string',  'format'   => 'date' ],
            'ems_start_time'                     => [ 'type' => 'string' ],
            'ems_end_date'                       => [ 'type' => 'string',  'format'   => 'date' ],
            'ems_end_time'                       => [ 'type' => 'string' ],
            'ems_route_deadline'                 => [ 'type' => 'string',  'format'   => 'date' ],
            'ems_start_location'                 => [ 'type' => 'string' ],
            'ems_end_location'                   => [ 'type' => 'string' ],
            'ems_location_name'                  => [ 'type' => 'string' ],
            'ems_location_coordinates'           => [ 'type' => 'string' ],
            'ems_lic_id'                         => [ 'type' => 'integer', 'minimum'  => 1 ],
            'ems_lic_name'                       => [ 'type' => 'string' ],
            'ems_lic_email'                      => [ 'type' => 'string' ],
            'ems_lic_phone'                      => [ 'type' => 'string' ],
            'ems_expedition_lic_name'            => [ 'type' => 'string' ],
            'ems_expedition_lic_phone'           => [ 'type' => 'string' ],
            'ems_expedition_lic_email'           => [ 'type' => 'string' ],
            'ems_expedition_whatsapp_explorers'  => [ 'type' => 'string' ],
            'ems_expedition_whatsapp_parents'    => [ 'type' => 'string' ],
            'ems_route_info'                     => [ 'type' => 'string' ],
            'ems_expedition_route_info'          => [ 'type' => 'string' ],
            'ems_route_received'                 => [ 'type' => 'string', 'enum' => [ 'not_received', 'changes_requested', 'received' ] ],
            'ems_route_approved'                 => [ 'type' => 'string', 'enum' => [ 'pending', 'under_review', 'approved', 'changes_requested' ] ],
            'ems_osm_event_id'                   => [ 'type' => 'integer', 'minimum'  => 0 ],
            'ems_status'                         => [ 'type' => 'string',  'enum'     => [ 'planning', 'open', 'confirmed', 'completed' ] ],
        ];
    }

    public function get_season_meta_fields(): array {
        return [
            'ems_season_year'   => [ 'type' => 'string' ],
            'ems_season_status' => [ 'type' => 'string', 'enum' => [ 'active', 'archived' ] ],
        ];
    }

    public function get_team_meta_fields(): array {
        return [
            'ems_team_code'            => [ 'type' => 'string',  'required' => true ],
            'ems_team_number'          => [ 'type' => 'integer', 'minimum'  => 1 ],
            'ems_route_status'         => [ 'type' => 'string',  'enum'     => [ 'pending', 'feedback_required', 'approved' ] ],
            'ems_route_feedback'       => [ 'type' => 'string' ],
            'ems_gpx_file_id'          => [ 'type' => 'integer', 'minimum'  => 0 ],
            'ems_route_card_file_id'   => [ 'type' => 'integer', 'minimum'  => 0 ],
        ];
    }
}
