<?php
namespace EMS\Integrations\Drivers;

class Live_Driver implements Driver_Interface {
    private array $last_headers = [];
    private string $access_token = '';

    private function get_base_url(): string {
        return get_option( 'ems_osm_api_base_url', 'https://www.onlinescoutmanager.co.uk/api.php' );
    }

    private function request( string $url, array $args = [] ): array {
        if ( $this->access_token ) {
            $args['headers'] = array_merge( $args['headers'] ?? [], [
                'Authorization' => 'Bearer ' . $this->access_token,
            ] );
        }

        $response = wp_remote_get( $url, $args );

        if ( is_wp_error( $response ) ) {
            return [];
        }

        $this->last_headers = $this->parse_rate_limit_headers( wp_remote_retrieve_headers( $response ) );
        
        $body = wp_remote_retrieve_body( $response );
        return json_decode( $body, true ) ?? [];
    }

    private function parse_rate_limit_headers( $headers ): array {
        $parsed = [];
        $keys   = [ 'x-ratelimit-limit', 'x-ratelimit-remaining', 'x-ratelimit-reset' ];

        foreach ( $keys as $key ) {
            if ( isset( $headers[ $key ] ) ) {
                $parsed[ $key ] = $headers[ $key ];
            }
        }

        return $parsed;
    }

    public function get_last_response_headers(): array {
        return $this->last_headers;
    }

    public function set_access_token( string $token ): void {
        $this->access_token = $token;
    }

    public function get_data_payload( string $access_token ): array {
        $url = add_query_arg( [
            'action'      => 'getDataPayload',
            'client_time' => time() * 1000,
        ], $this->get_base_url() );

        return $this->request( $url, [
            'headers' => [ 'Authorization' => 'Bearer ' . $access_token ],
        ] );
    }

    public function get_section_members( int $section_id ): array {
        $url = add_query_arg( [
            'action'    => 'getListOfMembers',
            'sectionid' => $section_id,
        ], $this->get_base_url() );

        return $this->request( $url );
    }

    public function get_section_events( int $section_id ): array {
        $url = add_query_arg( [
            'action'    => 'get',
            'sectionid' => $section_id,
        ], $this->get_base_url() );

        return $this->request( $url );
    }

    public function get_flexi_records( int $section_id ): array {
        $url = add_query_arg( [
            'action'    => 'getFlexiRecords',
            'sectionid' => $section_id,
        ], $this->get_base_url() );

        return $this->request( $url );
    }

    public function get_flexi_record_structure( int $section_id, int $flexi_id ): array {
        $url = add_query_arg( [
            'action'    => 'getStructure',
            'sectionid' => $section_id,
            'extraid'   => $flexi_id,
        ], $this->get_base_url() );

        return $this->request( $url );
    }

    public function get_flexi_record_data( int $section_id, int $flexi_id ): array {
        $url = add_query_arg( [
            'action'    => 'getData',
            'sectionid' => $section_id,
            'extraid'   => $flexi_id,
        ], $this->get_base_url() );

        return $this->request( $url );
    }

    public function get_individual( int $section_id, int $member_id ): array {
        $url = add_query_arg( [
            'action'    => 'getIndividual',
            'sectionid' => $section_id,
            'scoutid'   => $member_id,
        ], $this->get_base_url() );

        return $this->request( $url );
    }
}
