<?php
namespace EMS\Integrations;

/**
 * Manages the mapping between EMS fields and OSM flexi-record columns.
 */
class Flexi_Column_Map {

    private const OPTION_NAME = 'ems_flexirecord_column_map';

    /**
     * Required EMS fields that must be mapped.
     */
    public const REQUIRED_FIELDS = [
        'expedition_code',
        'team_code',
        'participant_scout_id',
    ];

    /**
     * Saves the mapping to WordPress options.
     *
     * @param array $map Mapping of EMS field names to OSM column IDs (f_N).
     * @return bool|WP_Error True on success, WP_Error on validation failure.
     */
    public function save( array $map ) {
        foreach ( self::REQUIRED_FIELDS as $field ) {
            if ( empty( $map[ $field ] ) ) {
                return new \WP_Error(
                    'ems_missing_field',
                    sprintf( __( 'Missing required field mapping: %s', 'ems-plugin' ), $field )
                );
            }
        }

        update_option( self::OPTION_NAME, $map );
        return true;
    }

    /**
     * Retrieves the current mapping.
     *
     * @return array
     */
    public function get(): array {
        return (array) get_option( self::OPTION_NAME, [] );
    }
}
