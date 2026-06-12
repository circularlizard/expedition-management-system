<?php
namespace EMS\Core;

class CPT_Registry {
    public function register(): void {
        register_post_type( 'expedition', [
            'labels'       => [ 'name' => 'Expeditions', 'singular_name' => 'Expedition' ],
            'public'       => true,
            'has_archive'  => false,
            'show_in_rest' => true,
            'menu_icon'    => 'dashicons-location-alt',
            'supports'     => [ 'title', 'editor', 'custom-fields' ],
        ] );

        register_post_type( 'team', [
            'labels'       => [ 'name' => 'Teams', 'singular_name' => 'Team' ],
            'public'       => true,
            'show_in_rest' => true,
            'menu_icon'    => 'dashicons-groups',
            'supports'     => [ 'title', 'custom-fields' ],
        ] );
    }

    public function get_expedition_meta_fields(): array {
        return [
            'ems_level'                 => [ 'type' => 'string',  'enum'     => [ 'bronze', 'silver', 'gold' ] ],
            'ems_type'                  => [ 'type' => 'string',  'enum'     => [ 'practice', 'qualifying' ] ],
            'ems_expedition_code'       => [ 'type' => 'string',  'required' => true ],
            'ems_start_date'            => [ 'type' => 'string',  'format'   => 'date' ],
            'ems_end_date'              => [ 'type' => 'string',  'format'   => 'date' ],
            'ems_route_deadline'        => [ 'type' => 'string',  'format'   => 'date' ],
            'ems_location_name'         => [ 'type' => 'string' ],
            'ems_location_coordinates'  => [ 'type' => 'string' ],
            'ems_lic_id'                => [ 'type' => 'integer', 'minimum'  => 1 ],
            'ems_osm_event_id'          => [ 'type' => 'integer', 'minimum'  => 0 ],
            'ems_status'                => [ 'type' => 'string',  'enum'     => [ 'planning', 'open', 'confirmed', 'completed' ] ],
        ];
    }

    public function get_team_meta_fields(): array {
        return [
            'ems_team_code'            => [ 'type' => 'string',  'required' => true ],
            'ems_route_status'         => [ 'type' => 'string',  'enum'     => [ 'pending', 'feedback_required', 'approved' ] ],
            'ems_route_feedback'       => [ 'type' => 'string' ],
            'ems_gpx_file_id'          => [ 'type' => 'integer', 'minimum'  => 0 ],
            'ems_route_card_file_id'   => [ 'type' => 'integer', 'minimum'  => 0 ],
        ];
    }
}
