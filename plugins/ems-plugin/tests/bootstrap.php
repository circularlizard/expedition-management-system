<?php
/**
 * PHPUnit Bootstrap
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/EMSTestCase.php';

if ( ! class_exists( 'EMSRoute\\Tests\\TestCase' ) ) {
    class_alias( \EMS\Tests\EMSTestCase::class, 'EMSRoute\\Tests\\TestCase' );
}

if ( class_exists( '\\EMS\\Core\\Legacy_Aliases' ) ) {
    \EMS\Core\Legacy_Aliases::register_aliases();
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
    define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! defined( 'ARRAY_A' ) ) {
    define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! defined( 'OBJECT' ) ) {
    define( 'OBJECT', 'OBJECT' );
}

if ( ! class_exists( 'WP_User' ) ) {
    class WP_User {
        public int $ID = 0;
    }
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
    class WP_REST_Response {
        private $data;
        private $status;
        private array $headers = [];
        public function __construct( $data = null, $status = 200 ) {
            $this->data   = $data;
            $this->status = $status;
        }
        public function get_data() {
            return $this->data;
        }
        public function get_status() {
            return $this->status;
        }
        public function header( string $key, $value ): void {
            $this->headers[ $key ] = $value;
        }
        public function get_headers(): array {
            return $this->headers;
        }
    }
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
    class WP_REST_Request {
        private array $params = [];
        private array $body   = [];
        public function set_param( string $key, $value ): void {
            $this->params[ $key ] = $value;
        }
        public function get_param( string $key ) {
            return $this->params[ $key ] ?? $this->body[ $key ] ?? null;
        }
        public function set_body_params( array $body ): void {
            $this->body = $body;
        }
        public function get_json_params(): array {
            return $this->body;
        }
        public function set_header( string $key, string $value ): void {}
    }
}

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private string $code;
        private string $message;
        private $data;
        public function __construct( string $code = '', string $message = '', $data = '' ) {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }
        public function get_error_code(): string {
            return $this->code;
        }
        public function get_error_message(): string {
            return $this->message;
        }
        public function get_error_data() {
            return $this->data;
        }
    }
}

if ( ! defined( 'EMS_PLUGIN_FILE' ) ) {
    define( 'EMS_PLUGIN_FILE', dirname( __DIR__ ) . '/ems-plugin.php' );
}

if ( ! defined( 'EMS_VERSION' ) ) {
    define( 'EMS_VERSION', '1.0.0' );
}

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
    define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/wp-content' );
}

if ( ! defined( 'DOFE_DEM_DATA_DIR' ) ) {
    define( 'DOFE_DEM_DATA_DIR', sys_get_temp_dir() . '/dofe-dem-test-data' );
}

if ( ! defined( 'EMS_ROUTE_CARD_VERSION' ) ) {
    define( 'EMS_ROUTE_CARD_VERSION', '1.0.21' );
}

if ( ! defined( 'EMS_ROUTE_CARD_FILE' ) ) {
    define( 'EMS_ROUTE_CARD_FILE', dirname( __DIR__ ) . '/ems-plugin.php' );
}

if ( ! defined( 'EMS_ROUTE_CARD_PATH' ) ) {
    define( 'EMS_ROUTE_CARD_PATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'EMS_ROUTE_CARD_URL' ) ) {
    define( 'EMS_ROUTE_CARD_URL', 'http://example.com/wp-content/plugins/ems-plugin/' );
}
