<?php
namespace EMS\Integrations\Drivers;

class Mock_Driver implements Driver_Interface {
    private string $mocks_dir;
    private string $data_payload_file;
    private array $last_headers = [];
    private int $mock_limit;
    private int $mock_remaining;
    private int $mock_reset;

    public function __construct(
        ?string $mocks_dir = null,
        string $data_payload_file = 'osm-get-data-payload-explorer.json',
        ?int $mock_limit = null
    ) {
        $this->mocks_dir         = $mocks_dir ?? dirname( __DIR__, 3 ) . '/tests/mocks';
        $this->data_payload_file = $data_payload_file;
        
        // Configurable mock limit (default 10)
        $this->mock_limit     = $mock_limit ?? (int) get_option( 'ems_mock_rate_limit', 10 );
        $this->mock_remaining = $this->mock_limit;
        $this->mock_reset     = time() + 60;
    }

    private function load( string $filename ): array {
        // Simulate rate limit decrement
        if ( $this->mock_remaining > 0 ) {
            $this->mock_remaining--;
        }

        $this->last_headers = [
            'x-ratelimit-limit'     => $this->mock_limit,
            'x-ratelimit-remaining' => $this->mock_remaining,
            'x-ratelimit-reset'     => $this->mock_reset,
        ];

        $path = $this->mocks_dir . '/' . $filename;
        if ( ! file_exists( $path ) ) {
            return [];
        }
        return json_decode( file_get_contents( $path ), true ) ?? [];
    }

    public function get_last_response_headers(): array {
        return $this->last_headers;
    }

    public function set_access_token( string $token ): void {
        // Mock driver ignores token
    }

    public function get_data_payload( string $access_token ): array {
        return $this->load( $this->data_payload_file );
    }

    public function get_section_members( int $section_id ): array {
        return $this->load( 'members.json' );
    }

    public function get_section_events( int $section_id ): array {
        return $this->load( 'osm-events.json' );
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
}
