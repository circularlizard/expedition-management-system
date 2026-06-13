<?php
namespace EMS\Data;

class Expedition_Repository {
    public function create( array $data ): int {
        if ( empty( $data['ems_expedition_code'] ) ) {
            throw new \InvalidArgumentException( 'Expedition code is required.' );
        }

        if ( $this->code_exists( $data['ems_expedition_code'] ) ) {
            throw new \InvalidArgumentException( "Duplicate expedition code: {$data['ems_expedition_code']}." );
        }

        $post_data = [
            'post_type'   => 'expedition',
            'post_title'  => $data['post_title'] ?? '',
            'post_status' => 'publish',
        ];

        $post_id = wp_insert_post( $post_data, true );

        if ( is_wp_error( $post_id ) ) {
            throw new \RuntimeException( $post_id->get_error_message() );
        }

        $meta_fields = [
            'ems_expedition_code',
            'ems_expedition_lic_name',
            'ems_expedition_lic_phone',
            'ems_expedition_lic_email',
            'ems_expedition_whatsapp_explorers',
            'ems_expedition_whatsapp_parents',
            'ems_expedition_route_info',
            'ems_route_received',
            'ems_route_approved',
            'ems_level',
            'ems_type',
            'ems_start_date',
            'ems_end_date',
            'ems_route_deadline',
            'ems_location_name',
            'ems_location_coordinates',
            'ems_lic_id',
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

    public function get_by_id( int $id ): ?array {
        $post = get_post( $id );

        if ( ! $post || $post->post_type !== 'expedition' ) {
            return null;
        }

        return $this->to_array( $post );
    }

    public function list_all(): array {
        $posts = get_posts( [
            'post_type'   => 'expedition',
            'post_status' => 'publish',
            'numberposts' => -1,
        ] );

        $expeditions = [];
        foreach ( $posts as $post ) {
            $expeditions[] = $this->to_array( $post );
        }

        return $expeditions;
    }

    private function code_exists( string $code ): bool {
        $existing = get_posts( [
            'post_type'   => 'expedition',
            'post_status' => 'publish',
            'numberposts' => 1,
            'meta_query'  => [
                [
                    'key'   => 'ems_expedition_code',
                    'value' => $code,
                ],
            ],
        ] );

        return ! empty( $existing );
    }

     private function to_array( object $post ): array {
        $result = [ 'ID' => $post->ID, 'post_title' => $post->post_title, 'post_status' => $post->post_status ];

        $meta_fields = [
            'ems_expedition_code',
            'ems_expedition_lic_name',
            'ems_expedition_lic_phone',
            'ems_expedition_lic_email',
            'ems_expedition_whatsapp_explorers',
            'ems_expedition_whatsapp_parents',
            'ems_expedition_route_info',
            'ems_route_received',
            'ems_route_approved',
            'ems_level',
            'ems_type',
            'ems_start_date',
            'ems_end_date',
            'ems_route_deadline',
            'ems_location_name',
            'ems_location_coordinates',
            'ems_lic_id',
            'ems_osm_event_id',
            'ems_status',
        ];

        foreach ( $meta_fields as $key ) {
            $result[ $key ] = get_post_meta( $post->ID, $key, true );
        }

        return $result;
    }
}
