<?php
namespace EMS\Integrations\Drivers;

class Live_Driver implements Driver_Interface {
    public function get_data_payload( string $access_token ): array {
        throw new \RuntimeException( 'Live_Driver not yet implemented — Phase 5.' );
    }

    public function get_section_members( int $section_id ): array {
        throw new \RuntimeException( 'Live_Driver not yet implemented — Phase 5.' );
    }

    public function get_section_events( int $section_id ): array {
        throw new \RuntimeException( 'Live_Driver not yet implemented — Phase 5.' );
    }

    public function get_flexi_records( int $section_id ): array {
        throw new \RuntimeException( 'Live_Driver not yet implemented — Phase 5.' );
    }

    public function get_flexi_record_structure( int $section_id, int $flexi_id ): array {
        throw new \RuntimeException( 'Live_Driver not yet implemented — Phase 5.' );
    }

    public function get_flexi_record_data( int $section_id, int $flexi_id ): array {
        throw new \RuntimeException( 'Live_Driver not yet implemented — Phase 5.' );
    }

    public function get_individual( int $section_id, int $member_id ): array {
        throw new \RuntimeException( 'Live_Driver not yet implemented — Phase 5.' );
    }
}
