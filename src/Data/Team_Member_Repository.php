<?php
namespace EMS\Data;

class Team_Member_Repository {
    private ?object $wpdb;
    private Team_Repository $teams;

    public function __construct( ?object $wpdb = null, ?Team_Repository $teams = null ) {
        $this->wpdb  = $wpdb;
        $this->teams = $teams ?: new Team_Repository( $this );
    }

    private function get_wpdb(): object {
        if ( $this->wpdb === null ) {
            global $wpdb;
            $this->wpdb = $wpdb;
        }
        return $this->wpdb;
    }

    public function assign( int $team_post_id, int $user_id, int $added_by ): int {
        $wpdb  = $this->get_wpdb();
        $table = $wpdb->prefix . 'ems_team_members';

        $existing = $wpdb->get_var( $wpdb->prepare(
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

        $id = $wpdb->insert(
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
            throw new \RuntimeException( "Failed to assign user to team: " . $wpdb->last_error );
        }

        return (int) $wpdb->insert_id;
    }

    public function remove( int $team_post_id, int $user_id ): bool {
        $wpdb  = $this->get_wpdb();
        $table = $wpdb->prefix . 'ems_team_members';

        $deleted = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE team_post_id = %d AND user_id = %d",
            $team_post_id,
            $user_id
        ) );

        if ( $deleted === false ) {
            return false;
        }

        if ( $deleted === 0 ) {
            return false;
        }

        $remaining = $this->list_by_team( $team_post_id );
        if ( empty( $remaining ) ) {
            $this->teams->delete( $team_post_id );
        }

        return true;
    }

    public function move( int $user_id, int $source_team_id, int $target_team_id, int $added_by ): bool {
        if ( $source_team_id === $target_team_id ) {
            return true;
        }

        $this->remove( $source_team_id, $user_id );
        $this->assign( $target_team_id, $user_id, $added_by );
        return true;
    }

    public function get_by_user( int $user_id, int $team_post_id ): ?array {
        $wpdb  = $this->get_wpdb();
        $table = $wpdb->prefix . 'ems_team_members';
        $row   = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, team_post_id, user_id, added_by, added_at FROM {$table} WHERE team_post_id = %d AND user_id = %d",
            $team_post_id,
            $user_id
        ), ARRAY_A );
        return $row ?: null;
    }

    public function list_by_team( int $team_post_id ): array {
        $wpdb  = $this->get_wpdb();
        $table = $wpdb->prefix . 'ems_team_members';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, team_post_id, user_id, added_by, added_at FROM {$table} WHERE team_post_id = %d ORDER BY id",
            $team_post_id
        ), ARRAY_A );

        return $rows ?: [];
    }

    public function list_by_expedition( int $expedition_id ): array {
        $wpdb  = $this->get_wpdb();
        $table = $wpdb->prefix . 'ems_team_members';

        $team_ids = get_posts( [
            'post_type'   => 'team',
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields'      => 'ids',
            'post_parent' => $expedition_id,
        ] );

        if ( empty( $team_ids ) ) {
            return [];
        }

        $placeholders = implode( ', ', array_fill( 0, count( $team_ids ), '%d' ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT tm.id, tm.team_post_id, tm.user_id, tm.added_by, tm.added_at
             FROM {$table} tm
             WHERE tm.team_post_id IN ({$placeholders})
             ORDER BY tm.team_post_id, tm.id",
            ...$team_ids
        ), ARRAY_A );

        return $rows ?: [];
    }

    public function list_unassigned( int $expedition_id ): array {
        $wpdb  = $this->get_wpdb();
        $table = $wpdb->prefix . 'ems_team_members';

        $team_ids = get_posts( [
            'post_type'   => 'team',
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields'      => 'ids',
            'post_parent' => $expedition_id,
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
                $row = $wpdb->get_row( $wpdb->prepare(
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
