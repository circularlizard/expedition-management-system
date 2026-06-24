<?php
namespace EMS\Data;

class Team_Repository {
    private Team_Member_Repository $team_members;

    public function __construct( ?Team_Member_Repository $team_members = null ) {
        $this->team_members = $team_members ?: new Team_Member_Repository();
    }

    public function create( int $event_id, string $event_code, ?string $team_code = null ): int {
        $team_code = $team_code ?: $this->generate_next_code( $event_id, $event_code );

        if ( $this->code_exists( $team_code ) ) {
            throw new \InvalidArgumentException( "Duplicate team code: {$team_code}." );
        }

        $post_id = wp_insert_post( [
            'post_type'   => 'team',
            'post_title'  => "Team {$team_code}",
            'post_status' => 'publish',
            'post_parent' => $event_id,
        ], true );

        if ( is_wp_error( $post_id ) ) {
            throw new \RuntimeException( $post_id->get_error_message() );
        }

        update_post_meta( $post_id, 'ems_team_code', $team_code );
        update_post_meta( $post_id, 'ems_team_number', $this->extract_number( $team_code ) );

        return (int) $post_id;
    }

    public function get_by_id( int $id ): ?array {
        $post = get_post( $id );

        if ( ! $post || $post->post_type !== 'team' ) {
            return null;
        }

        return $this->to_array( $post );
    }

    public function delete( int $id ): bool {
        $post = get_post( $id );
        if ( ! $post || $post->post_type !== 'team' ) {
            return false;
        }
        return wp_delete_post( $id, true ) !== false;
    }

    public function list_by_expedition( int $event_id ): array {
        $posts = get_posts( [
            'post_type'   => 'team',
            'post_status' => 'publish',
            'numberposts' => -1,
            'post_parent' => $event_id,
            'orderby'     => 'meta_value_num',
            'meta_key'    => 'ems_team_number',
            'order'       => 'ASC',
        ] );

        return array_map( [ $this, 'to_array' ], $posts );
    }

    public function move( int $team_id, int $target_event_id, string $target_event_code ): bool {
        $team = $this->get_by_id( $team_id );
        if ( ! $team ) {
            return false;
        }

        if ( $team['event_id'] === $target_event_id ) {
            throw new \InvalidArgumentException( 'Team is already in the target event.' );
        }

        $new_code = $this->generate_next_code( $target_event_id, $target_event_code );
        wp_update_post( [
            'ID'          => $team_id,
            'post_parent' => $target_event_id,
            'post_title'  => "Team {$new_code}",
        ] );
        update_post_meta( $team_id, 'ems_team_code', $new_code );
        update_post_meta( $team_id, 'ems_team_number', $this->extract_number( $new_code ) );

        $this->renumber_event( $team['event_id'] );
        $this->renumber_event( $target_event_id );

        return true;
    }

    public function duplicate( int $team_id, int $target_event_id, string $target_event_code ): int {
        $team = $this->get_by_id( $team_id );
        if ( ! $team ) {
            throw new \InvalidArgumentException( 'Team not found.' );
        }

        $new_code = $this->generate_next_code( $target_event_id, $target_event_code );
        $new_id   = wp_insert_post( [
            'post_type'   => 'team',
            'post_title'  => "Team {$new_code}",
            'post_status' => 'publish',
            'post_parent' => $target_event_id,
        ], true );

        if ( is_wp_error( $new_id ) ) {
            throw new \RuntimeException( $new_id->get_error_message() );
        }

        update_post_meta( $new_id, 'ems_team_code', $new_code );
        update_post_meta( $new_id, 'ems_team_number', $this->extract_number( $new_code ) );

        $members = $this->team_members->list_by_team( $team_id );
        foreach ( $members as $member ) {
            $this->team_members->assign(
                $new_id,
                (int) ( $member['scout_id'] ?? 0 ),
                (int) $member['added_by'],
                (int) ( $member['user_id'] ?? 0 )
            );
        }

        return (int) $new_id;
    }

    public function renumber_event( int $event_id ): void {
        $teams = $this->list_by_expedition( $event_id );
        $expected = 1;
        foreach ( $teams as $team ) {
            $current = (int) $team['ems_team_number'];
            if ( $current !== $expected ) {
                $code = $this->event_code( $event_id ) . '-' . $expected;
                wp_update_post( [
                    'ID'          => $team['ID'],
                    'post_title'  => "Team {$code}",
                ] );
                update_post_meta( $team['ID'], 'ems_team_code', $code );
                update_post_meta( $team['ID'], 'ems_team_number', $expected );
            }
            $expected++;
        }
    }

    public function populate_from_event( int $source_event_id, int $target_event_id, string $target_event_code ): array {
        $source_teams = $this->list_by_expedition( $source_event_id );
        $created_ids  = [];

        foreach ( $source_teams as $team ) {
            $new_id = $this->duplicate( $team['ID'], $target_event_id, $target_event_code );
            $created_ids[] = $new_id;
        }

        return $created_ids;
    }

    public function get_event_code( int $event_id ): string {
        return $this->event_code( $event_id );
    }

    private function generate_next_code( int $event_id, string $event_code ): string {
        $teams = $this->list_by_expedition( $event_id );
        $max   = 0;
        foreach ( $teams as $team ) {
            $max = max( $max, (int) ( $team['ems_team_number'] ?? 0 ) );
        }
        return $event_code . '-' . ( $max + 1 );
    }

    private function extract_number( string $code ): int {
        if ( preg_match( '/-(\d+)$/', $code, $m ) ) {
            return (int) $m[1];
        }
        return 0;
    }

    private function code_exists( string $code ): bool {
        $existing = get_posts( [
            'post_type'   => 'team',
            'post_status' => 'publish',
            'numberposts' => 1,
            'meta_query'  => [
                [
                    'key'   => 'ems_team_code',
                    'value' => $code,
                ],
            ],
        ] );

        return ! empty( $existing );
    }

    private function event_code( int $event_id ): string {
        return get_post_meta( $event_id, 'ems_event_code', true ) ?: 'TEAM';
    }

    private function to_array( object $post ): array {
        $result = [
            'ID'          => $post->ID,
            'post_title'  => $post->post_title,
            'post_status' => $post->post_status,
            'event_id'    => (int) $post->post_parent,
        ];

        $meta_fields = [
            'ems_team_code',
            'ems_team_number',
            'ems_route_status',
            'ems_route_feedback',
            'ems_gpx_file_id',
            'ems_route_card_file_id',
        ];

        foreach ( $meta_fields as $key ) {
            $result[ $key ] = get_post_meta( $post->ID, $key, true );
        }

        return $result;
    }
}
