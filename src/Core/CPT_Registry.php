<?php
namespace EMS\Core;

class CPT_Registry {
	public function register(): void {
		register_post_type(
			'expedition',
			array(
				'labels'       => array(
					'name'          => 'Events',
					'singular_name' => 'Event',
				),
				'public'       => true,
				'has_archive'  => false,
				'show_in_rest' => true,
				'show_in_menu' => false,
				'menu_icon'    => 'dashicons-location-alt',
				'supports'     => array( 'title', 'editor', 'custom-fields', 'page-attributes' ),
			)
		);

		register_post_type(
			'team',
			array(
				'labels'       => array(
					'name'          => 'Teams',
					'singular_name' => 'Team',
				),
				'public'       => true,
				'show_in_rest' => true,
				'show_in_menu' => false,
				'menu_icon'    => 'dashicons-groups',
				'supports'     => array( 'title', 'custom-fields', 'page-attributes' ),
			)
		);
	}

	public function get_expedition_meta_fields(): array {
		return array(
			'ems_level'                         => array(
				'type' => 'string',
				'enum' => array( 'bronze', 'silver', 'gold' ),
			),
			'ems_type'                          => array(
				'type' => 'string',
				'enum' => array( 'training', 'practice', 'qualifying' ),
			),
			'ems_transport'                     => array(
				'type' => 'string',
				'enum' => array( 'hillwalking', 'biking', 'paddling' ),
			),
			'ems_event_code'                    => array(
				'type'     => 'string',
				'required' => true,
			),
			'ems_expedition_code'               => array( 'type' => 'string' ),
			'ems_start_date'                    => array(
				'type'   => 'string',
				'format' => 'date',
			),
			'ems_start_time'                    => array( 'type' => 'string' ),
			'ems_end_date'                      => array(
				'type'   => 'string',
				'format' => 'date',
			),
			'ems_end_time'                      => array( 'type' => 'string' ),
			'ems_route_deadline'                => array(
				'type'   => 'string',
				'format' => 'date',
			),
			'ems_start_location'                => array( 'type' => 'string' ),
			'ems_end_location'                  => array( 'type' => 'string' ),
			'ems_location_name'                 => array( 'type' => 'string' ),
			'ems_location_coordinates'          => array( 'type' => 'string' ),
			'ems_lic_id'                        => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'ems_lic_name'                      => array( 'type' => 'string' ),
			'ems_lic_email'                     => array( 'type' => 'string' ),
			'ems_lic_phone'                     => array( 'type' => 'string' ),
			'ems_expedition_lic_name'           => array( 'type' => 'string' ),
			'ems_expedition_lic_phone'          => array( 'type' => 'string' ),
			'ems_expedition_lic_email'          => array( 'type' => 'string' ),
			'ems_expedition_whatsapp_explorers' => array( 'type' => 'string' ),
			'ems_expedition_whatsapp_parents'   => array( 'type' => 'string' ),
			'ems_route_info'                    => array( 'type' => 'string' ),
			'ems_expedition_route_info'         => array( 'type' => 'string' ),
			'ems_route_received'                => array(
				'type' => 'string',
				'enum' => array( 'not_received', 'changes_requested', 'received' ),
			),
			'ems_route_approved'                => array(
				'type' => 'string',
				'enum' => array( 'pending', 'under_review', 'approved', 'changes_requested' ),
			),
			'ems_osm_event_id'                  => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'ems_status'                        => array(
				'type' => 'string',
				'enum' => array( 'active', 'archived' ),
			),
			'ems_route_status'                  => array(
				'type' => 'string',
				'enum' => array( 'draft', 'confirmed' ),
			),
		);
	}

	public function get_team_meta_fields(): array {
		return array(
			'ems_team_code'          => array(
				'type'     => 'string',
				'required' => true,
			),
			'ems_team_number'        => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'ems_route_status'       => array(
				'type' => 'string',
				'enum' => array( 'pending', 'feedback_required', 'approved' ),
			),
			'ems_route_feedback'     => array( 'type' => 'string' ),
			'ems_gpx_file_id'        => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'ems_route_card_file_id' => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
		);
	}
}
