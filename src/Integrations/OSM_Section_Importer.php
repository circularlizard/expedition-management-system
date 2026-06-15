<?php
namespace EMS\Integrations;

/**
 * Imports OSM section members into the ems_osm_explorers reference table.
 * WP User account creation is deferred until a member actually logs in.
 */
class OSM_Section_Importer {

    private OSM_API_Client $api_client;

    public function __construct( OSM_API_Client $api_client ) {
        $this->api_client = $api_client;
    }

    /**
     * Imports members for all managed sections.
     */
    public function import_all(): void {
        $managed_sections = (array) get_option( 'ems_managed_sections', [] );
        $section_ids      = array_keys( $managed_sections );

        foreach ( $section_ids as $section_id ) {
            $this->import_section( (int) $section_id );
        }
    }

    /**
     * Imports members for a list of section IDs.
     *
     * @param int[] $section_ids
     */
    public function import_sections( array $section_ids ): void {
        foreach ( $section_ids as $section_id ) {
            $this->import_section( (int) $section_id );
        }
    }

    /**
     * Imports members for a specific section into ems_osm_explorers.
     */
    public function import_section( int $section_id ): void {
        global $wpdb;

        $members = $this->api_client->get_section_participants( $section_id );
        $table   = $wpdb->prefix . 'ems_osm_explorers';
        $now     = current_time( 'mysql' );

        foreach ( $members as $member ) {
            $scout_id = (int) ( $member['member_id'] ?? 0 );
            if ( ! $scout_id ) {
                continue;
            }

            $wpdb->replace(
                $table,
                [
                    'scout_id'     => $scout_id,
                    'section_id'   => $section_id,
                    'first_name'   => $member['first_name'] ?? '',
                    'last_name'    => $member['last_name'] ?? '',
                    'email'        => $member['email'] ?? '',
                    'parent_email' => $member['parent_email'] ?? '',
                    'patrol'       => $member['patrol'] ?? '',
                    'synced_at'    => $now,
                ],
                [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
            );
        }
    }

    /**
     * Looks up an explorer record from ems_osm_explorers by scout_id.
     *
     * @return array|null Row array or null if not found.
     */
    public function find_by_scout_id( int $scout_id ): ?array {
        global $wpdb;

        $table = $wpdb->prefix . 'ems_osm_explorers';
        $row   = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE scout_id = %d LIMIT 1", $scout_id ),
            ARRAY_A
        );

        return $row ?: null;
    }
}
