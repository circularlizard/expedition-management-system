<?php
namespace EMS\Integrations\Exceptions;

class Rate_Limit_Exception extends \RuntimeException {

    private int $retry_after;
    private int $rate_limit_reset;

    public function __construct( int $retry_after = 0, int $rate_limit_reset = 0, string $url = '' ) {
        $this->retry_after       = $retry_after;
        $this->rate_limit_reset  = $rate_limit_reset;
        parent::__construct( sprintf( 'OSM rate limit hit on %s. Retry after %ds.', $url, $retry_after ) );
    }

    public function get_retry_after(): int {
        return $this->retry_after;
    }

    public function get_rate_limit_reset(): int {
        return $this->rate_limit_reset;
    }
}
