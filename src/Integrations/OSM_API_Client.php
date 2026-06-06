<?php
namespace EMS\Integrations;

interface OSM_Driver {
    public function get_members( int $section_id ): array;
    public function get_events( int $section_id ): array;
}

class OSM_Mock_Driver implements OSM_Driver {
    public function get_members( int $section_id ): array {
        $file = __DIR__ . '/../../tests/mocks/members.json';
        if ( file_exists( $file ) ) {
            return json_decode( file_get_contents( $file ), true ) ?: [];
        }
        return [];
    }

    public function get_events( int $section_id ): array {
        $file = __DIR__ . '/../../tests/mocks/events.json';
        if ( file_exists( $file ) ) {
            return json_decode( file_get_contents( $file ), true ) ?: [];
        }
        return [];
    }
}

class OSM_API_Client {
    private OSM_Driver $driver;

    public function __construct( OSM_Driver $driver ) {
        $this->driver = $driver;
    }

    public function get_members( int $section_id ): array {
        return $this->driver->get_members( $section_id );
    }

    public function get_events( int $section_id ): array {
        return $this->driver->get_events( $section_id );
    }
}
