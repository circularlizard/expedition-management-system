<?php
namespace EMS\Core;

class Meta_Validator {
    private const EXPEDITION_RULES = [
        'ems_level'           => [ 'enum'     => [ 'bronze', 'silver', 'gold' ] ],
        'ems_type'            => [ 'enum'     => [ 'practice', 'qualifying' ] ],
        'ems_status'          => [ 'enum'     => [ 'planning', 'open', 'confirmed', 'completed' ] ],
        'ems_expedition_code' => [ 'required' => true ],
        'ems_start_date'      => [ 'format'   => 'date' ],
        'ems_end_date'        => [ 'format'   => 'date' ],
        'ems_route_deadline'  => [ 'format'   => 'date' ],
        'ems_lic_id'          => [ 'min'      => 1 ],
        'ems_osm_event_id'    => [ 'min'      => 0 ],
    ];

    private const TEAM_RULES = [
        'ems_route_status'       => [ 'enum'     => [ 'pending', 'feedback_required', 'approved' ] ],
        'ems_team_code'          => [ 'required' => true ],
        'ems_gpx_file_id'        => [ 'min'      => 0 ],
        'ems_route_card_file_id' => [ 'min'      => 0 ],
    ];

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
