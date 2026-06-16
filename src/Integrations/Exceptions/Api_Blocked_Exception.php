<?php
namespace EMS\Integrations\Exceptions;

class Api_Blocked_Exception extends \RuntimeException {

    private string $blocked_header;

    public function __construct( string $blocked_header = '', string $url = '' ) {
        $this->blocked_header = $blocked_header;
        parent::__construct( sprintf( 'OSM blocked this application on %s. Header: %s', $url, $blocked_header ) );
    }

    public function get_blocked_header(): string {
        return $this->blocked_header;
    }
}
