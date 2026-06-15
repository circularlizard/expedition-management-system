<?php
namespace EMS\Integrations;

/**
 * Parses the structure of an OSM flexi-record.
 */
class Flexi_Structure_Parser {

    /**
     * Parses the getStructure response into a flat list of columns.
     *
     * @param array $raw The raw response from OSM.
     * @return array List of columns with 'id' and 'name'.
     */
    public function parse( array $raw ): array {
        $config = json_decode( $raw['config'] ?? '[]', true );
        if ( ! is_array( $config ) ) {
            return [];
        }

        $columns = [];
        foreach ( $config as $col ) {
            if ( isset( $col['id'], $col['name'] ) ) {
                $columns[] = [
                    'id'   => $col['id'],
                    'name' => $col['name'],
                ];
            }
        }

        return $columns;
    }
}
