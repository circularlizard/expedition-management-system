<?php
namespace EMS\Data;

class Expedition_Repository {
    public function create( array $data ): int {
        $code = $data['ems_event_code'] ?? '';
        if ( empty( $code ) ) {
            throw new \InvalidArgumentException( 'ems_event_code is required.' );
        }

        $season_id = (int) ( $data['season_id'] ?? 0 );
        if ( $season_id > 0 && $this->code_exists_in_season( $code, $season_id ) ) {
            throw new \InvalidArgumentException( "Duplicate event code in season: {$code}." );
        }

        $post_data = [
            'post_type'   => 'expedition',
            'post_title'  => $data['post_title'] ?? '',
            'post_status' => 'publish',
            'post_parent' => $season_id > 0 ? $season_id : 0,
        ];

        $post_id = wp_insert_post( $post_data, true );

        if ( is_wp_error( $post_id ) ) {
            throw new \RuntimeException( $post_id->get_error_message() );
        }

        $meta_fields = [
            'ems_event_code',
            'ems_expedition_code',
            'ems_type',
            'ems_transport',
            'ems_level',
            'ems_lic_name',
            'ems_lic_email',
            'ems_lic_phone',
            'ems_lic_id',
            'ems_start_location',
            'ems_end_location',
            'ems_start_date',
            'ems_start_time',
            'ems_end_date',
            'ems_end_time',
            'ems_route_info',
            'ems_route_deadline',
            'ems_osm_event_id',
            'ems_status',
        ];

        foreach ( $meta_fields as $key ) {
            if ( isset( $data[ $key ] ) && $data[ $key ] !== '' ) {
                update_post_meta( $post_id, $key, $data[ $key ] );
            }
        }

        return (int) $post_id;
    }

    public function update( int $id, array $data ): bool {
        $post = get_post( $id );
        if ( ! $post || $post->post_type !== 'expedition' ) {
            return false;
        }

        $update = [];
        if ( isset( $data['post_title'] ) ) {
            $update['post_title'] = $data['post_title'];
        }
        if ( isset( $data['season_id'] ) ) {
            $update['post_parent'] = (int) $data['season_id'];
        }
        if ( ! empty( $update ) ) {
            $update['ID'] = $id;
            wp_update_post( $update );
        }

        $meta_fields = [
            'ems_event_code',
            'ems_expedition_code',
            'ems_type',
            'ems_transport',
            'ems_level',
            'ems_lic_name',
            'ems_lic_email',
            'ems_lic_phone',
            'ems_lic_id',
            'ems_start_location',
            'ems_end_location',
            'ems_start_date',
            'ems_start_time',
            'ems_end_date',
            'ems_end_time',
            'ems_route_info',
            'ems_route_deadline',
            'ems_osm_event_id',
            'ems_status',
        ];

        foreach ( $meta_fields as $key ) {
            if ( array_key_exists( $key, $data ) ) {
                if ( $data[ $key ] === '' ) {
                    delete_post_meta( $id, $key );
                } else {
                    update_post_meta( $id, $key, $data[ $key ] );
                }
            }
        }

        return true;
    }

    public function delete( int $id ): bool {
        $post = get_post( $id );
        if ( ! $post || $post->post_type !== 'expedition' ) {
            return false;
        }
        return wp_delete_post( $id, true ) !== false;
    }

    public function get_by_id( int $id ): ?array {
        $post = get_post( $id );

        if ( ! $post || $post->post_type !== 'expedition' ) {
            return null;
        }

        return $this->to_array( $post );
    }

    public function list_by_season( int $season_id ): array {
        $posts = get_posts( [
            'post_type'   => 'expedition',
            'post_status' => 'publish',
            'numberposts' => -1,
            'post_parent' => $season_id,
            'orderby'     => 'date',
            'order'       => 'DESC',
        ] );

        return array_map( [ $this, 'to_array' ], $posts );
    }

    public function list_all(): array {
        $posts = get_posts( [
            'post_type'   => 'expedition',
            'post_status' => 'publish',
            'numberposts' => -1,
        ] );

        return array_map( [ $this, 'to_array' ], $posts );
    }

    public function has_teams( int $id ): bool {
        $teams = get_posts( [
            'post_type'   => 'team',
            'post_status' => 'publish',
            'numberposts' => 1,
            'post_parent' => $id,
        ] );
        return ! empty( $teams );
    }

    private function code_exists_in_season( string $code, int $season_id ): bool {
        $existing = get_posts( [
            'post_type'   => 'expedition',
            'post_status' => 'publish',
            'numberposts' => 1,
            'post_parent' => $season_id,
            'meta_query'  => [
                [
                    'key'   => 'ems_event_code',
                    'value' => $code,
                ],
            ],
        ] );

        return ! empty( $existing );
    }

    private function to_array( object $post ): array {
        $result = [
            'ID'          => $post->ID,
            'post_title'  => $post->post_title,
            'post_status' => $post->post_status,
            'season_id'   => (int) $post->post_parent,
        ];

        $meta_fields = [
            'ems_event_code',
            'ems_expedition_code',
            'ems_type',
            'ems_transport',
            'ems_level',
            'ems_lic_name',
            'ems_lic_email',
            'ems_lic_phone',
            'ems_lic_id',
            'ems_start_location',
            'ems_end_location',
            'ems_start_date',
            'ems_start_time',
            'ems_end_date',
            'ems_end_time',
            'ems_route_info',
            'ems_route_deadline',
            'ems_osm_event_id',
            'ems_status',
        ];

        foreach ( $meta_fields as $key ) {
            $result[ $key ] = get_post_meta( $post->ID, $key, true );
        }

        return $result;
    }
}
