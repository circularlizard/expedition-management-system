<?php
namespace EMS\Integrations;

use EMS\Data\Expedition_Repository;
use EMS\Data\Team_Repository;
use EMS\Data\Team_Member_Repository;

/**
 * Handles importing and bucketing flexi-record data from OSM.
 */
class Flexi_Record_Importer {

    private Flexi_Column_Map $column_map;
    private Expedition_Repository $expeditions;
    private Team_Repository $teams;
    private Team_Member_Repository $team_members;

    public function __construct(
        Flexi_Column_Map $column_map,
        Expedition_Repository $expeditions,
        Team_Repository $teams,
        Team_Member_Repository $team_members
    ) {
        $this->column_map   = $column_map;
        $this->expeditions  = $expeditions;
        $this->teams        = $teams;
        $this->team_members = $team_members;
    }

    /**
     * Buckets raw OSM flexi-record data based on the current column map.
     *
     * @param array  $raw_items The 'items' array from getFlexiRecords response.
     * @param string $identifier The identifier field name (e.g. 'scoutid').
     * @return array Bucketed data: [ 'clean' => [], 'partial' => [], 'unparseable' => [] ]
     */
    public function bucket_rows( array $raw_items, string $identifier = 'scoutid' ): array {
        $map      = $this->column_map->get();
        $buckets = [
            'clean'       => [],
            'partial'     => [],
            'unparseable' => [],
        ];

        foreach ( $raw_items as $item ) {
            $parsed = $this->parse_row( $item, $map, $identifier );
            $status = $this->get_row_status( $parsed );

            if ( $status === 'clean' ) {
                // Additional check: Does the scout ID exist in WP?
                $user_id = $this->find_user_by_scout_id( (int) $parsed['participant_scout_id'] );
                if ( ! $user_id ) {
                    $parsed['_error'] = __( 'Scout ID not found in WordPress users. Run Membership Pull first.', 'ems-plugin' );
                    $buckets['partial'][] = $parsed;
                } else {
                    $parsed['_user_id'] = $user_id;
                    $buckets['clean'][] = $parsed;
                }
            } else {
                $buckets[ $status ][] = $parsed;
            }
        }

        return $buckets;
    }

    /**
     * Commits clean rows to the database.
     *
     * @param array $clean_rows List of parsed rows (the 'clean' bucket).
     * @return int Number of records successfully processed/updated.
     */
    public function commit( array $clean_rows ): int {
        $count = 0;
        foreach ( $clean_rows as $row ) {
            try {
                // 1. Get or Create Expedition
                $exp_id = $this->get_or_create_expedition( $row['expedition_code'] );

                // 2. Get or Create Team
                $team_id = $this->get_or_create_team( $exp_id, $row['expedition_code'], $row['team_code'] );

                // 3. Assign Member (idempotency handled by repository)
                try {
                    $this->team_members->assign( $team_id, (int) $row['_user_id'], get_current_user_id() );
                } catch ( \InvalidArgumentException $e ) {
                    // Already assigned, ignore
                }

                $count++;
            } catch ( \Exception $e ) {
                error_log( 'EMS: Failed to commit flexi-record row: ' . $e->getMessage() );
            }
        }

        if ( $count > 0 ) {
            update_option( 'ems_osm_last_sync', current_time( 'iso' ) );
        }

        return $count;
    }

    private function parse_row( array $item, array $map, string $identifier = 'scoutid' ): array {
        $parsed = [
            '_raw' => $item,
        ];

        foreach ( $map as $ems_field => $osm_col ) {
            $parsed[ $ems_field ] = $item[ $osm_col ] ?? null;
        }

        // Auto-resolve scout ID from the identifier field (not a custom column)
        $parsed['participant_scout_id'] = $item[ $identifier ] ?? null;

        return $parsed;
    }

    private function get_row_status( array $parsed ): string {
        $has_exp   = ! empty( $parsed['expedition_code'] );
        $has_team  = ! empty( $parsed['team_code'] );
        $has_scout = ! empty( $parsed['participant_scout_id'] );

        if ( $has_exp && $has_team && $has_scout ) {
            return 'clean';
        }

        if ( $has_exp || $has_team || $has_scout ) {
            return 'partial';
        }

        return 'unparseable';
    }

    private function find_user_by_scout_id( int $scout_id ): int {
        $users = get_users( [
            'meta_key'   => 'ems_scout_id',
            'meta_value' => $scout_id,
            'number'     => 1,
            'fields'     => 'ID',
        ] );

        return ! empty( $users ) ? (int) $users[0] : 0;
    }

    private function get_or_create_expedition( string $code ): int {
        $existing = get_posts( [
            'post_type'   => 'expedition',
            'meta_key'    => 'ems_expedition_code',
            'meta_value'  => $code,
            'numberposts' => 1,
            'fields'      => 'ids',
        ] );

        if ( ! empty( $existing ) ) {
            return (int) $existing[0];
        }

        return $this->expeditions->create( [
            'ems_expedition_code' => $code,
            'post_title'          => "Expedition {$code}",
        ] );
    }

    private function get_or_create_team( int $exp_id, string $exp_code, string $team_code ): int {
        $existing = get_posts( [
            'post_type'   => 'team',
            'meta_query'  => [
                [ 'key' => 'ems_team_code', 'value' => $team_code ],
                [ 'key' => 'ems_expedition_id', 'value' => $exp_id ],
            ],
            'numberposts' => 1,
            'fields'      => 'ids',
        ] );

        if ( ! empty( $existing ) ) {
            return (int) $existing[0];
        }

        return $this->teams->create( $exp_id, $exp_code, $team_code );
    }
}
