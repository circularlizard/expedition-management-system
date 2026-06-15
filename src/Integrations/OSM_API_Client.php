<?php
namespace EMS\Integrations;

use EMS\Integrations\Drivers\Driver_Interface;

class OSM_API_Client {
    private Driver_Interface $driver;
    private OSM_Parser $parser;
    private Rate_Limiter $rate_limiter;

    public function __construct(
        Driver_Interface $driver,
        OSM_Parser $parser,
        ?Rate_Limiter $rate_limiter = null
    ) {
        $this->driver       = $driver;
        $this->parser       = $parser;
        $this->rate_limiter = $rate_limiter ?? new Rate_Limiter( 10, 1.0 );
    }

    public function set_access_token( string $token ): void {
        $this->driver->set_access_token( $token );
    }

    public function get_data_payload( string $access_token ): array {
        $this->rate_limiter->consume();
        $data = $this->driver->get_data_payload( $access_token );
        $this->sync_rate_limiter();
        return $data;
    }

    public function get_section_participants( int $section_id, int $term_id ): array {
        $this->rate_limiter->consume();
        $raw = $this->driver->get_section_members( $section_id, $term_id );
        $this->sync_rate_limiter();
        return $this->parser->parse_members( $raw );
    }

    public function get_section_events( int $section_id, int $term_id ): array {
        $this->rate_limiter->consume();
        $raw = $this->driver->get_section_events( $section_id, $term_id );
        $this->sync_rate_limiter();
        return $this->parser->parse_events( $raw );
    }

    public function get_member_detail( int $section_id, int $scout_id, int $term_id ): array {
        $this->rate_limiter->consume();
        $raw = $this->driver->get_member_detail( $section_id, $scout_id, $term_id );
        $this->sync_rate_limiter();
        return $this->parser->parse_member_detail( $raw );
    }

    public function get_flexi_record_data( int $section_id, int $flexi_id ): array {
        $this->rate_limiter->consume();
        $data = $this->driver->get_flexi_record_data( $section_id, $flexi_id );
        $this->sync_rate_limiter();
        return $data;
    }

    public function get_event_attendance( int $section_id, int $event_id ): array {
        $this->rate_limiter->consume();
        $data = $this->driver->get_event_attendance( $section_id, $event_id );
        $this->sync_rate_limiter();
        return $data;
    }

    public function get_flexi_record_structure( int $section_id, int $flexi_id ): array {
        $this->rate_limiter->consume();
        $data = $this->driver->get_flexi_record_structure( $section_id, $flexi_id );
        $this->sync_rate_limiter();
        return $data;
    }

    private function sync_rate_limiter(): void {
        $headers = $this->driver->get_last_response_headers();
        if ( ! empty( $headers ) ) {
            $this->rate_limiter->update_from_headers( $headers );
            set_transient( 'ems_rate_limit_status', $headers, HOUR_IN_SECONDS );
        }
    }
}
