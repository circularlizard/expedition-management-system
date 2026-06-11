<?php
namespace EMS\Integrations\Drivers;

interface Driver_Interface {
    public function get_data_payload( string $access_token ): array;
    public function get_section_members( int $section_id ): array;
    public function get_section_events( int $section_id ): array;
    public function get_flexi_records( int $section_id ): array;
    public function get_flexi_record_structure( int $section_id, int $flexi_id ): array;
    public function get_flexi_record_data( int $section_id, int $flexi_id ): array;
    public function get_individual( int $section_id, int $member_id ): array;
}
