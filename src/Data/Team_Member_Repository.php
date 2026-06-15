<?php
namespace EMS\Data;

class Team_Member_Repository {
    private object $wpdb;

    public function __construct( ?object $wpdb = null ) {
        if ( $wpdb === null ) {
            global $wpdb;
        }
        $this->wpdb = $wpdb;
    }

    public function assign( int $team_post_id, int $user_id, int $added_by ): int {
        $table = $this->wpdb->prefix . 'ems_team_members';

        $existing = $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT id FROM {$table} WHERE team_post_id = %d AND user_id = %d",
            $team_post_id,
            $user_id
        ) );

        if ( $existing ) {
            throw new \InvalidArgumentException(
                "User {$user_id} is already assigned to team {$team_post_id}."
            );
        }

        $added_at = current_time( 'mysql', true );

        $id = $this->wpdb->insert(
            $table,
            [
                'team_post_id' => $team_post_id,
                'user_id'      => $user_id,
                'added_by'     => $added_by,
                'added_at'     => $added_at,
            ],
            [ '%d', '%d', '%d', '%s' ]
        );

        if ( $id === false ) {
            throw new \RuntimeException( "Failed to assign user to team: " . $this->wpdb->last_error );
        }

        return (int) $id;
    }

    /**
     * List all members of a specific team.
     */
    public function list_by_team( int $team_post_id ): array {
        $table = $this->wpdb->prefix . 'ems_team_members';

        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT id, user_id, added_by, added_at FROM {$table} WHERE team_post_id = %d ORDER BY id",
            $team_post_id
        ), ARRAY_A );

        return $rows ?: [];
    }

    /**
     * List all members across all teams of an expedition.
     */
    public function list_by_expedition( int $expedition_id ): array {
        $table = $this->wpdb->prefix . 'ems_team_members';

        $team_ids = get_posts( [
            'post_type'   => 'team',
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields'      => 'ids',
            'meta_query'  => [
                [
                    'key'   => 'ems_expedition_id',
                    'value' => (string) $expedition_id,
                ],
            ],
        ] );

        if ( empty( $team_ids ) ) {
            return [];
        }

        $placeholders = implode( ', ', array_fill( 0, count( $team_ids ), '%d' ) );

        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT tm.id, tm.team_post_id, tm.user_id, tm.added_by, tm.added_at
             FROM {$table} tm
             WHERE tm.team_post_id IN ({$placeholders})
             ORDER BY tm.team_post_id, tm.id",
            ...$team_ids
        ), ARRAY_A );

        return $rows ?: [];
    }

    /**
     * List explorers with a scout ID for the expedition who are not yet assigned to any team.
     */
    public function list_unassigned( int $expedition_id ): array {
        $table = $this->wpdb->prefix . 'ems_team_members';

        $team_ids = get_posts( [
            'post_type'   => 'team',
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields'      => 'ids',
            'meta_query'  => [
                [
                    'key'   => 'ems_expedition_id',
                    'value' => (string) $expedition_id,
                ],
            ],
        ] );

        $explorers = get_users( [
            'meta_key'     => 'ems_scout_id',
            'meta_compare' => 'EXISTS',
        ] );

        $unassigned = [];
        foreach ( $explorers as $user ) {
            $user_id = $user->ID;

            $assigned = false;
            foreach ( $team_ids as $team_id ) {
                $row = $this->wpdb->get_row( $this->wpdb->prepare(
                    "SELECT id FROM {$table} WHERE team_post_id = %d AND user_id = %d",
                    $team_id,
                    $user_id
                ) );
                if ( $row ) {
                    $assigned = true;
                    break;
                }
            }

            if ( ! $assigned ) {
                $unassigned[] = [
                    'ID'            => $user_id,
                    'ems_scout_id'  => get_user_meta( $user_id, 'ems_scout_id', true ),
                    'ems_first_name' => get_user_meta( $user_id, 'ems_first_name', true ),
                    'ems_last_name' => get_user_meta( $user_id, 'ems_last_name', true ),
                    'ems_explorer_email' => get_user_meta( $user_id, 'ems_explorer_email', true ),
                    'ems_parent_email' => get_user_meta( $user_id, 'ems_parent_email', true ),
                    'ems_unit'      => get_user_meta( $user_id, 'ems_unit', true ),
                ];
            }
        }

        return $unassigned;
    }
}
