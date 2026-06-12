<?php
namespace EMS\Integrations;

class Mock_Gravity_Forms_Client extends Gravity_Forms_Client {
    private string $mock_file;

    public function __construct( string $mock_file = '' ) {
        $this->mock_file = $mock_file ?: __DIR__ . '/../../tests/mocks/gf-entries.json';
    }

    public function get_entries( int $form_id ): array {
        if ( ! file_exists( $this->mock_file ) ) {
            return [];
        }
        $data = json_decode( file_get_contents( $this->mock_file ), true );
        return $data['items'] ?? [];
    }
}
