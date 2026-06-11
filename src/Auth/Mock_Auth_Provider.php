<?php
namespace EMS\Auth;

class Mock_Auth_Provider implements Auth_Provider {
    private string $access_token;
    private array $user_data;

    public function __construct(
        string $access_token = 'mock-access-token',
        string $payload_file = 'osm-get-data-payload-explorer.json'
    ) {
        $this->access_token = $access_token;
        $path               = dirname( __DIR__, 2 ) . '/tests/mocks/' . $payload_file;
        $this->user_data    = file_exists( $path )
            ? ( json_decode( file_get_contents( $path ), true ) ?? [] )
            : [];
    }

    public function get_access_token(): string {
        return $this->access_token;
    }

    public function get_user_data(): array {
        return $this->user_data;
    }
}
