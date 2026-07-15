<?php
namespace EMS\Core;

class Meta_Validator {
	private const EXPEDITION_RULES = array(
		'ems_level'                         => array( 'enum' => array( 'bronze', 'silver', 'gold' ) ),
		'ems_type'                          => array( 'enum' => array( 'practice', 'qualifying' ) ),
		'ems_status'                        => array( 'enum' => array( 'planning', 'open', 'confirmed', 'completed' ) ),
		'ems_expedition_code'               => array( 'required' => true ),
		'ems_start_date'                    => array( 'format' => 'date' ),
		'ems_end_date'                      => array( 'format' => 'date' ),
		'ems_route_deadline'                => array( 'format' => 'date' ),
		'ems_lic_id'                        => array( 'min' => 1 ),
		'ems_expedition_lic_name'           => array(),
		'ems_expedition_lic_phone'          => array(),
		'ems_expedition_lic_email'          => array(),
		'ems_expedition_whatsapp_explorers' => array(),
		'ems_expedition_whatsapp_parents'   => array(),
		'ems_expedition_route_info'         => array(),
		'ems_route_received'                => array( 'enum' => array( 'not_received', 'changes_requested', 'received' ) ),
		'ems_route_approved'                => array( 'enum' => array( 'pending', 'under_review', 'approved', 'changes_requested' ) ),
		'ems_osm_event_id'                  => array( 'min' => 0 ),
	);

	private const TEAM_RULES = array(
		'ems_route_status'       => array( 'enum' => array( 'pending', 'feedback_required', 'approved' ) ),
		'ems_team_code'          => array( 'required' => true ),
		'ems_gpx_file_id'        => array( 'min' => 0 ),
		'ems_route_card_file_id' => array( 'min' => 0 ),
	);

	public function validate_expedition( string $key, $value ): bool {
		return $this->apply( self::EXPEDITION_RULES, $key, $value );
	}

	public function validate_team( string $key, $value ): bool {
		return $this->apply( self::TEAM_RULES, $key, $value );
	}

	private function apply( array $rules, string $key, $value ): bool {
		if ( ! isset( $rules[ $key ] ) ) {
			return true;
		}
		$rule = $rules[ $key ];

		if ( isset( $rule['enum'] ) ) {
			return in_array( $value, $rule['enum'], true );
		}

		if ( isset( $rule['required'] ) && $rule['required'] ) {
			return $value !== '' && $value !== null;
		}

		if ( isset( $rule['format'] ) && $rule['format'] === 'date' ) {
			return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $value )
				&& \DateTime::createFromFormat( 'Y-m-d', (string) $value ) !== false;
		}

		if ( isset( $rule['min'] ) ) {
			return is_numeric( $value ) && (int) $value >= $rule['min'];
		}

		return true;
	}
}
