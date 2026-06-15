<?php
namespace EMS\Integrations\Drivers;

class Mock_Driver implements Driver_Interface {
    private string $mocks_dir;
    private string $data_payload_file;

    public function __construct(
        ?string $mocks_dir = null,
        string $data_payload_file = 'osm-get-data-payload-explorer.json'
    ) {
        $this->mocks_dir         = $mocks_dir ?? dirname( __DIR__, 3 ) . '/tests/mocks';
        $this->data_payload_file = $data_payload_file;
    }

    private function load( string $filename ): array {
        $path = $this->mocks_dir . '/' . $filename;
        if ( ! file_exists( $path ) ) {
            return [];
        }
        return json_decode( file_get_contents( $path ), true ) ?? [];
    }

    public function get_last_response_headers(): array {
        return [];
    }

    public function set_access_token( string $token ): void {
        // Mock driver ignores token
    }

    public function get_data_payload( string $access_token ): array {
        return $this->load( $this->data_payload_file );
    }

    public function get_section_members( int $section_id, int $term_id ): array {
        return $this->load( 'osm-list-of-members.json' );
    }

    public function get_section_events( int $section_id, int $term_id ): array {
        return $this->load( 'osm-events.json' );
    }

    public function get_member_detail( int $section_id, int $scout_id, int $term_id ): array {
        $map   = $this->load( 'osm-member-detail.json' );
        $entry = $map[ (string) $scout_id ] ?? [];
        if ( empty( $entry ) ) {
            return [];
        }
        return [
            'data' => [
                [
                    'group_id' => 6,
                    'columns'  => [
                        [ 'column_id' => 12, 'value' => $entry['email']        ?? '' ],
                        [ 'column_id' => 14, 'value' => $entry['parent_email'] ?? '' ],
                    ],
                ],
            ],
        ];
    }

    public function get_flexi_records( int $section_id ): array {
        return $this->load( 'osm-flexi-records.json' );
    }

    public function get_flexi_record_structure( int $section_id, int $flexi_id ): array {
        return $this->load( 'osm-flexi-record-structure.json' );
    }

    public function get_flexi_record_data( int $section_id, int $flexi_id ): array {
        return $this->load( 'osm-flexi-record-data.json' );
    }

    public function get_individual( int $section_id, int $member_id ): array {
        return $this->load( 'osm-get-individual.json' );
    }

    public function get_event_attendance( int $section_id, int $event_id ): array {
        return $this->load( 'osm-event-attendance.json' );
    }
}
