<?php
namespace EMS\Auth;

interface Auth_Provider {
    public function get_access_token(): string;
    public function get_user_data(): array;
}
