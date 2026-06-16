<?php
namespace EMS\Integrations;

use EMS\Integrations\Exceptions\Api_Blocked_Exception;
use EMS\Integrations\Exceptions\Api_Response_Exception;
use EMS\Integrations\Exceptions\Rate_Limit_Exception;

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
     * @param int[]           $section_ids
     * @param array           $payload       Result of OSM_API_Client::get_data_payload()
     * @param string          $mode          API mode string (stored in result)
     * @param int             $member_limit  Max members per section (0 = unlimited)
     * @param OSM_Sync_Logger|null $logger   Optional call logger
     */
    public function sync(
        array $section_ids,
        array $payload,
        string $mode = 'mock',
        int $member_limit = 0,
        ?OSM_Sync_Logger $logger = null
    ): Sync_Result {
        global $wpdb;

        $result = new Sync_Result( $mode );
        $this->api_client->set_sync_result( $result );
        $now    = current_time( 'mysql' );
        $terms  = $this->parser->parse_terms( $payload );

        try {
            foreach ( $section_ids as $section_id ) {
                $section_id = (int) $section_id;
                $term       = $this->parser->find_current_term( $terms, $section_id );

                if ( $term === null ) {
                    $result->add_error( "No current term found for section {$section_id}" );
                    continue;
                }

                $term_id = $term['term_id'];
                $this->sync_members( $wpdb, $section_id, $term_id, $now, $result, $member_limit );
                $this->sync_events_and_attendance( $wpdb, $section_id, $term_id, $now, $result );
            }

        } catch ( Rate_Limit_Exception $e ) {
            $result->rate_limited           = true;
            $result->retry_after_seconds    = $e->get_retry_after();
            $result->rate_limit_reset_seconds = $e->get_rate_limit_reset();
            $result->add_error( $e->getMessage() );
            if ( $logger ) {
                $logger->log_terminal( 'rate_limited', [
                    'http_status'  => 429,
                    'retry_after'  => $e->get_retry_after(),
                    'reset_seconds'=> $e->get_rate_limit_reset(),
                    'message'      => $e->getMessage(),
                ] );
            }

        } catch ( Api_Blocked_Exception $e ) {
            $result->api_blocked = true;
            $result->add_error( $e->getMessage() );
            update_option( 'ems_api_blocked', true );
            if ( $logger ) {
                $logger->log_terminal( 'api_blocked', [
                    'blocked_header' => $e->get_blocked_header(),
                    'message'        => $e->getMessage(),
                ] );
            }

        } catch ( Api_Response_Exception $e ) {
            $result->add_error( $e->getMessage() );
            if ( $logger ) {
                $logger->log_terminal( 'response_error', [ 'message' => $e->getMessage() ] );
            }
        }

        if ( $logger ) {
            $logger->persist();
        }

        set_transient( 'ems_last_sync_result', $result->to_array(), DAY_IN_SECONDS );

        return $result;
    }

    /**
     * Syncs members for a section into ems_osm_explorers.
     * Fetches basic list via getListOfMembers, then per-member getData for email addresses.
     */
    private function sync_members( \wpdb $wpdb, int $section_id, int $term_id, string $now, Sync_Result $result, int $member_limit = 0 ): void {
        $members = $this->api_client->get_section_participants( $section_id, $term_id );
        $table   = $wpdb->prefix . 'ems_osm_explorers';

        if ( $member_limit > 0 ) {
            $members = array_slice( $members, 0, $member_limit );
        }

        foreach ( $members as $member ) {
            $scout_id = (int) ( $member['member_id'] ?? 0 );
            if ( ! $scout_id ) {
                continue;
            }

            $detail = $this->api_client->get_member_detail( $section_id, $scout_id, $term_id );

            $rows = $wpdb->replace(
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

            if ( $rows === false ) {
                $result->members_failed++;
                $result->add_error( "Failed to upsert member {$scout_id}: " . $wpdb->last_error );
            } else {
                $result->members_upserted++;
            }
        }
    }

    /**
     * Syncs events and per-event attendance for a section.
     */
    private function sync_events_and_attendance( \wpdb $wpdb, int $section_id, int $term_id, string $now, Sync_Result $result ): void {
        $raw_events  = $this->api_client->get_section_events( $section_id, $term_id );
        $events_table     = $wpdb->prefix . 'ems_osm_events';
        $attendance_table = $wpdb->prefix . 'ems_osm_event_attendance';

        foreach ( $raw_events as $event ) {
            $event_id = (int) ( $event['event_id'] ?? 0 );
            if ( ! $event_id ) {
                continue;
            }

            $rows = $wpdb->replace(
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
            if ( $rows === false ) {
                $result->events_failed++;
                $result->add_error( "Failed to upsert event {$event_id}: " . $wpdb->last_error );
            } else {
                $result->events_upserted++;
            }

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
