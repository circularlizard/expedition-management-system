<?php
namespace EMS\Integrations;

/**
 * Orchestrates the full OSM reference data sync:
 * members → ems_osm_explorers
 * events  → ems_osm_events
 * attendance per event → ems_osm_event_attendance
 */
class OSM_Reference_Sync {

    private OSM_API_Client $api_client;
    private OSM_Parser $parser;

    public function __construct( OSM_API_Client $api_client, OSM_Parser $parser ) {
        $this->api_client = $api_client;
        $this->parser     = $parser;
    }

    /**
     * Runs the full sync for all managed sections.
     *
     * Requires a getDataPayload response to resolve term IDs.
     *
     * @param int[]  $section_ids
     * @param array  $payload  Result of OSM_API_Client::get_data_payload() — used to resolve term IDs.
     */
    public function sync( array $section_ids, array $payload ): void {
        global $wpdb;

        $now   = current_time( 'mysql' );
        $terms = $this->parser->parse_terms( $payload );

        foreach ( $section_ids as $section_id ) {
            $section_id = (int) $section_id;
            $term       = $this->parser->find_current_term( $terms, $section_id );

            if ( $term === null ) {
                continue;
            }

            $term_id = $term['term_id'];
            $this->sync_members( $wpdb, $section_id, $term_id, $now );
            $this->sync_events_and_attendance( $wpdb, $section_id, $term_id, $now );
        }

        update_option( 'ems_osm_last_sync', current_time( 'mysql' ) );
    }

    /**
     * Syncs members for a section into ems_osm_explorers.
     * Fetches basic list via getListOfMembers, then per-member getData for email addresses.
     */
    private function sync_members( \wpdb $wpdb, int $section_id, int $term_id, string $now ): void {
        $members = $this->api_client->get_section_participants( $section_id, $term_id );
        $table   = $wpdb->prefix . 'ems_osm_explorers';

        foreach ( $members as $member ) {
            $scout_id = (int) ( $member['member_id'] ?? 0 );
            if ( ! $scout_id ) {
                continue;
            }

            $detail = $this->api_client->get_member_detail( $section_id, $scout_id, $term_id );

            $wpdb->replace(
                $table,
                [
                    'scout_id'     => $scout_id,
                    'section_id'   => $section_id,
                    'first_name'   => $member['first_name'] ?? '',
                    'last_name'    => $member['last_name']  ?? '',
                    'email'        => $detail['email']        ?? '',
                    'parent_email' => $detail['parent_email'] ?? '',
                    'patrol'       => $member['patrol']     ?? '',
                    'synced_at'    => $now,
                ],
                [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
            );
        }
    }

    /**
     * Syncs events and per-event attendance for a section.
     */
    private function sync_events_and_attendance( \wpdb $wpdb, int $section_id, int $term_id, string $now ): void {
        $raw_events  = $this->api_client->get_section_events( $section_id, $term_id );
        $events_table     = $wpdb->prefix . 'ems_osm_events';
        $attendance_table = $wpdb->prefix . 'ems_osm_event_attendance';

        foreach ( $raw_events as $event ) {
            $event_id = (int) ( $event['event_id'] ?? 0 );
            if ( ! $event_id ) {
                continue;
            }

            $wpdb->replace(
                $events_table,
                [
                    'event_id'   => $event_id,
                    'section_id' => $section_id,
                    'name'       => $event['name'] ?? '',
                    'start_date' => $event['start_date'] ?? null,
                    'end_date'   => $event['end_date'] ?? null,
                    'location'   => $event['location'] ?? '',
                    'synced_at'  => $now,
                ],
                [ '%d', '%d', '%s', '%s', '%s', '%s', '%s' ]
            );

            $attendance = $this->api_client->get_event_attendance( $section_id, $event_id );
            $items      = $attendance['items'] ?? [];

            foreach ( $items as $row ) {
                $scout_id = (int) ( $row['scoutid'] ?? 0 );
                if ( ! $scout_id ) {
                    continue;
                }

                $wpdb->replace(
                    $attendance_table,
                    [
                        'event_id'  => $event_id,
                        'scout_id'  => $scout_id,
                        'status'    => $row['attending'] ?? '',
                        'synced_at' => $now,
                    ],
                    [ '%d', '%d', '%s', '%s' ]
                );
            }
        }
    }

    /**
     * Returns all explorers from the reference table for the given sections.
     *
     * @param int[] $section_ids
     * @return array
     */
    public function get_explorers( array $section_ids ): array {
        global $wpdb;

        if ( empty( $section_ids ) ) {
            return [];
        }

        $table        = $wpdb->prefix . 'ems_osm_explorers';
        $placeholders = implode( ', ', array_fill( 0, count( $section_ids ), '%d' ) );

        return $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE section_id IN ({$placeholders})", ...$section_ids ),
            ARRAY_A
        ) ?? [];
    }

    /**
     * Returns all events from the reference table for the given sections.
     *
     * @param int[] $section_ids
     * @return array
     */
    public function get_events( array $section_ids ): array {
        global $wpdb;

        if ( empty( $section_ids ) ) {
            return [];
        }

        $table        = $wpdb->prefix . 'ems_osm_events';
        $placeholders = implode( ', ', array_fill( 0, count( $section_ids ), '%d' ) );

        return $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE section_id IN ({$placeholders})", ...$section_ids ),
            ARRAY_A
        ) ?? [];
    }

    /**
     * Returns attendance rows for a given event.
     */
    public function get_attendance_for_event( int $event_id ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'ems_osm_event_attendance';

        return $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d", $event_id ),
            ARRAY_A
        ) ?? [];
    }
}
