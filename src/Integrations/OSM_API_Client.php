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

    public function get_data_payload( string $access_token ): array {
        $this->rate_limiter->consume();
        return $this->driver->get_data_payload( $access_token );
    }

    public function get_section_participants( int $section_id ): array {
        $this->rate_limiter->consume();
        $raw = $this->driver->get_section_members( $section_id );
        return $this->parser->parse_members( $raw );
    }

    public function get_section_events( int $section_id ): array {
        $this->rate_limiter->consume();
        $raw = $this->driver->get_section_events( $section_id );
        return $this->parser->parse_events( $raw );
    }

    public function get_flexi_record_data( int $section_id, int $flexi_id ): array {
        $this->rate_limiter->consume();
        return $this->driver->get_flexi_record_data( $section_id, $flexi_id );
    }
}
