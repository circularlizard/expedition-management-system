<?php
namespace EMS\Integrations;

class Rate_Limiter {
    private int $capacity;
    private float $refill_rate;
    private float $tokens;
    private float $last_refill;
    private \Closure $time_fn;
    private \Closure $sleep_fn;

    public function __construct(
        int $capacity,
        float $refill_rate,
        ?\Closure $time_fn = null,
        ?\Closure $sleep_fn = null
    ) {
        $this->capacity    = $capacity;
        $this->refill_rate = $refill_rate;
        $this->time_fn     = $time_fn  ?? static fn(): float => microtime( true );
        $this->sleep_fn    = $sleep_fn ?? static function ( int $us ): void { usleep( $us ); };
        $this->tokens      = (float) $capacity;
        $this->last_refill = ( $this->time_fn )();
    }

    public function consume(): void {
        $now                = ( $this->time_fn )();
        $elapsed            = $now - $this->last_refill;
        $this->tokens       = min( (float) $this->capacity, $this->tokens + $elapsed * $this->refill_rate );
        $this->last_refill  = $now;

        if ( $this->tokens < 1.0 ) {
            $wait_us = (int) ( ( ( 1.0 - $this->tokens ) / $this->refill_rate ) * 1_000_000 );
            ( $this->sleep_fn )( $wait_us );
            $this->tokens = 0.0;
        } else {
            $this->tokens -= 1.0;
        }
    }

    /**
     * Updates the rate limiter state from HTTP response headers.
     *
     * @param array $headers associative array of headers (lowercase keys)
     */
    public function update_from_headers( array $headers ): void {
        $limit     = $headers['x-ratelimit-limit'] ?? null;
        $remaining = $headers['x-ratelimit-remaining'] ?? null;
        $reset     = $headers['x-ratelimit-reset'] ?? null;

        if ( null !== $limit ) {
            $this->capacity = (int) $limit;
        }

        if ( null !== $remaining ) {
            $this->tokens = (float) $remaining;
        }

        if ( null !== $reset ) {
            $time_to_reset = (float) $reset;
            if ( $time_to_reset > 0 && $this->capacity > 0 ) {
                $this->refill_rate = $this->capacity / $time_to_reset;
            }
        }
        
        $this->last_refill = ( $this->time_fn )();
    }

    public function get_token_count(): float {
        return $this->tokens;
    }
}
