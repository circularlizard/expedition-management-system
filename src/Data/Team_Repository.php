<?php
namespace EMS\Data;

class Team_Repository {
    public function create( int $expedition_id, string $expedition_code, ?string $team_code = null ): int {
        if ( $team_code === null ) {
            $team_code = $this->generate_next_code( $expedition_code );
        }

        if ( $this->code_exists( $team_code ) ) {
            throw new \InvalidArgumentException( "Duplicate team code: {$team_code}." );
        }

        $post_id = wp_insert_post( [
            'post_type'   => 'team',
            'post_title'  => "Team {$team_code}",
            'post_status' => 'publish',
        ], true );

        if ( is_wp_error( $post_id ) ) {
            throw new \RuntimeException( $post_id->get_error_message() );
        }

        update_post_meta( $post_id, 'ems_team_code', $team_code );
        update_post_meta( $post_id, 'ems_expedition_id', $expedition_id );

        return (int) $post_id;
    }

    public function get_by_id( int $id ): ?array {
        $post = get_post( $id );

        if ( ! $post || $post->post_type !== 'team' ) {
            return null;
        }

        return $this->to_array( $post );
    }

    public function list_by_expedition( int $expedition_id ): array {
        $posts = get_posts( [
            'post_type'   => 'team',
            'post_status' => 'publish',
            'numberposts' => -1,
            'meta_query'  => [
                [
                    'key'   => 'ems_expedition_id',
                    'value' => (string) $expedition_id,
                ],
            ],
        ] );

        $teams = [];
        foreach ( $posts as $post ) {
            $teams[] = $this->to_array( $post );
        }

        return $teams;
    }

    private function generate_next_code( string $expedition_code ): string {
        $existing = get_posts( [
            'post_type'   => 'team',
            'post_status' => 'publish',
            'numberposts' => -1,
        ] );

        $max = 0;
        foreach ( $existing as $post ) {
            $code = get_post_meta( $post->ID, 'ems_team_code', true );
            if ( preg_match( '/' . preg_quote( $expedition_code, '/' ) . '-(\d+)/', $code, $m ) ) {
                $max = max( $max, (int) $m[1] );
            }
        }

        return $expedition_code . '-' . ( $max + 1 );
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

    private function to_array( object $post ): array {
        $result = [ 'ID' => $post->ID, 'post_title' => $post->post_title, 'post_status' => $post->post_status ];

        $meta_fields = [
            'ems_team_code',
            'ems_expedition_id',
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
