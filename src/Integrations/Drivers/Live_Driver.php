<?php
namespace EMS\Integrations\Drivers;

use EMS\Integrations\Exceptions\Api_Blocked_Exception;
use EMS\Integrations\Exceptions\Api_Response_Exception;
use EMS\Integrations\Exceptions\Rate_Limit_Exception;

class Live_Driver implements Driver_Interface {
    private array $last_headers = [];
    private string $access_token = '';

    private function get_base_url(): string {
        return get_option( 'ems_osm_api_base_url', 'https://www.onlinescoutmanager.co.uk/api.php' );
    }

    /**
     * @throws Rate_Limit_Exception on HTTP 429
     * @throws Api_Blocked_Exception on X-Blocked header
     * @throws Api_Response_Exception on WP_Error or unparseable response
     */
    private function request( string $url, array $args = [] ): array {
        if ( $this->access_token ) {
            $args['headers'] = array_merge( $args['headers'] ?? [], [
                'Authorization' => 'Bearer ' . $this->access_token,
            ] );
        }

        $response = wp_remote_get( $url, $args );

        if ( is_wp_error( $response ) ) {
            throw new Api_Response_Exception( $response->get_error_message(), $url );
        }

        $http_status    = (int) wp_remote_retrieve_response_code( $response );
        $headers        = wp_remote_retrieve_headers( $response );
        $this->last_headers = $this->parse_all_headers( $headers, $http_status, $url );

        if ( ! empty( $this->last_headers['x-blocked'] ) ) {
            throw new Api_Blocked_Exception( (string) $this->last_headers['x-blocked'], $url );
        }

        if ( $http_status === 429 ) {
            throw new Rate_Limit_Exception(
                (int) ( $this->last_headers['retry-after'] ?? 0 ),
                (int) ( $this->last_headers['x-ratelimit-reset'] ?? 0 ),
                $url
            );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! is_array( $data ) ) {
            throw new Api_Response_Exception( 'Response was not valid JSON', $url );
        }

        return $data;
    }

    private function parse_all_headers( $headers, int $http_status, string $url ): array {
        $parsed = [
            'http_status'          => $http_status,
            'url'                  => $url,
            'x-ratelimit-limit'    => isset( $headers['x-ratelimit-limit'] )    ? (int) $headers['x-ratelimit-limit']    : null,
            'x-ratelimit-remaining'=> isset( $headers['x-ratelimit-remaining'] ) ? (int) $headers['x-ratelimit-remaining'] : null,
            'x-ratelimit-reset'    => isset( $headers['x-ratelimit-reset'] )     ? (int) $headers['x-ratelimit-reset']     : null,
            'retry-after'          => isset( $headers['retry-after'] )           ? (int) $headers['retry-after']           : null,
            'x-deprecated'         => $headers['x-deprecated'] ?? null,
            'x-blocked'            => $headers['x-blocked']    ?? null,
        ];

        return $parsed;
    }

    public function get_last_response_headers(): array {
        return $this->last_headers;
    }

    public function set_access_token( string $token ): void {
        $this->access_token = $token;
    }

    public function get_data_payload(): array {
        $url = add_query_arg( [
            'action'      => 'getDataPayload',
            'client_time' => time() * 1000,
        ], $this->get_base_url() );

        return $this->request( $url );
    }

    public function get_section_members( int $section_id, int $term_id ): array {
        $url = add_query_arg( [
            'action'    => 'getListOfMembers',
            'sort'      => 'dob',
            'sectionid' => $section_id,
            'termid'    => $term_id,
            'section'   => 'explorers',
        ], 'https://www.onlinescoutmanager.co.uk/ext/members/contact/' );

        return $this->request( $url );
    }

    public function get_section_events( int $section_id, int $term_id ): array {
        $url = add_query_arg( [
            'action'    => 'get',
            'sectionid' => $section_id,
            'termid'    => $term_id,
        ], 'https://www.onlinescoutmanager.co.uk/ext/events/summary/' );

        return $this->request( $url );
    }

    public function get_member_detail( int $section_id, int $scout_id, int $term_id ): array {
        $url = add_query_arg( [
            'action'               => 'getData',
            'section_id'          => $section_id,
            'associated_id'       => $scout_id,
            'associated_type'     => 'member',
            'associated_is_section' => 'null',
            'varname_filter'      => 'null',
            'context'             => 'members',
            'group_order'         => 'section',
        ], 'https://www.onlinescoutmanager.co.uk/ext/customdata/' );

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

    public function get_event_attendance( int $section_id, int $event_id ): array {
        $url = add_query_arg( [
            'action'    => 'getEventAttendance',
            'sectionid' => $section_id,
            'eventid'   => $event_id,
        ], $this->get_base_url() );

        return $this->request( $url );
    }
}
