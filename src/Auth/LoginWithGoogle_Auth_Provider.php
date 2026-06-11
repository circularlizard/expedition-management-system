<?php
namespace EMS\Auth;

class LoginWithGoogle_Auth_Provider implements Auth_Provider {
    private string $access_token = '';
    private array $user_data     = [];

    public function __construct() {
        add_action( 'rtcamp.google_user_logged_in', [ $this, 'capture' ], 10, 2 );
    }

    public function capture( \WP_User $user, array $data ): void {
        $this->access_token = $data['access_token'] ?? '';
        $this->user_data    = $data;
    }

    public function get_access_token(): string {
        return $this->access_token;
    }

    public function get_user_data(): array {
        return $this->user_data;
    }
}
