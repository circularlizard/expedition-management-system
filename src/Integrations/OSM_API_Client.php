<?php
namespace EMS\Integrations;

use EMS\Integrations\Drivers\Driver_Interface;

class OSM_API_Client {
    private Driver_Interface $driver;
    private OSM_Parser $parser;
    private Rate_Limiter $rate_limiter;
    private ?OSM_Sync_Logger $logger;

    public function __construct(
        Driver_Interface $driver,
        OSM_Parser $parser,
        ?Rate_Limiter $rate_limiter = null,
        ?OSM_Sync_Logger $logger = null
    ) {
        $this->driver       = $driver;
        $this->parser       = $parser;
        $this->rate_limiter = $rate_limiter ?? new Rate_Limiter( 10, 1.0 );
        $this->logger       = $logger;
    }

    public function set_access_token( string $token ): void {
        $this->driver->set_access_token( $token );
    }

    public function get_data_payload( string $access_token ): array {
        $this->rate_limiter->consume();
        $start = microtime( true );
        $data  = $this->driver->get_data_payload( $access_token );
        $this->after_call( 'get_data_payload', $start );
        return $data;
    }

    public function get_section_participants( int $section_id, int $term_id ): array {
        $this->rate_limiter->consume();
        $start = microtime( true );
        $raw   = $this->driver->get_section_members( $section_id, $term_id );
        $this->after_call( 'get_section_members', $start );
        return $this->parser->parse_members( $raw );
    }

    public function get_section_events( int $section_id, int $term_id ): array {
        $this->rate_limiter->consume();
        $start = microtime( true );
        $raw   = $this->driver->get_section_events( $section_id, $term_id );
        $this->after_call( 'get_section_events', $start );
        return $this->parser->parse_events( $raw );
    }

    public function get_member_detail( int $section_id, int $scout_id, int $term_id ): array {
        $this->rate_limiter->consume();
        $start = microtime( true );
        $raw   = $this->driver->get_member_detail( $section_id, $scout_id, $term_id );
        $this->after_call( 'get_member_detail', $start );
        return $this->parser->parse_member_detail( $raw );
    }

    public function get_flexi_record_data( int $section_id, int $flexi_id ): array {
        $this->rate_limiter->consume();
        $start = microtime( true );
        $data  = $this->driver->get_flexi_record_data( $section_id, $flexi_id );
        $this->after_call( 'get_flexi_record_data', $start );
        return $data;
    }

    public function get_event_attendance( int $section_id, int $event_id ): array {
        $this->rate_limiter->consume();
        $start = microtime( true );
        $data  = $this->driver->get_event_attendance( $section_id, $event_id );
        $this->after_call( 'get_event_attendance', $start );
        return $data;
    }

    public function get_flexi_record_structure( int $section_id, int $flexi_id ): array {
        $this->rate_limiter->consume();
        $start = microtime( true );
        $data  = $this->driver->get_flexi_record_structure( $section_id, $flexi_id );
        $this->after_call( 'get_flexi_record_structure', $start );
        return $data;
    }

    private function after_call( string $call_type, float $start ): void {
        $headers     = $this->driver->get_last_response_headers();
        $duration_ms = ( microtime( true ) - $start ) * 1000;

        if ( ! empty( $headers ) ) {
            $this->rate_limiter->update_from_headers( $headers );
            set_transient( 'ems_rate_limit_status', $headers, HOUR_IN_SECONDS );
        }

        if ( $this->logger ) {
            $url = $headers['url'] ?? '';
            $this->logger->log( $call_type, $url, $headers, $duration_ms );

            if ( ! empty( $headers['x-deprecated'] ) ) {
                $this->logger->log_terminal( 'deprecated_warning', [
                    'endpoint'   => $url,
                    'deprecated' => $headers['x-deprecated'],
                ] );
            }
        }
    }
}
