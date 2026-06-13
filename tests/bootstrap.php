<?php
/**
 * PHPUnit Bootstrap
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
    define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! class_exists( 'WP_User' ) ) {
    class WP_User {
        public int $ID = 0;
    }
}

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private string $code;
        private string $message;
        public function __construct( string $code = '', string $message = '' ) {
            $this->code = $code;
            $this->message = $message;
        }
        public function get_error_code(): string {
            return $this->code;
        }
        public function get_error_message(): string {
            return $this->message;
        }
    }
}
