<?php
namespace EMS\Integrations\Exceptions;

class Api_Response_Exception extends \RuntimeException {

    public function __construct( string $message, string $url = '' ) {
        parent::__construct( sprintf( 'Unexpected OSM response on %s: %s', $url, $message ) );
    }
}
