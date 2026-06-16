<?php
namespace EMS\Integrations;

/**
 * Collects per-API-call log entries during a sync run, then writes the whole
 * log to the ems_last_sync_log transient in one shot.
 *
 * Log is overwritten on each new sync — never appended.
 */
class OSM_Sync_Logger {

    private array $entries = [];

    public function log( string $call_type, string $url, array $headers, float $duration_ms ): void {
        $this->entries[] = [
            'timestamp'              => gmdate( 'c' ),
            'call_type'              => $call_type,
            'url'                    => $url,
            'http_status'            => $headers['http_status']           ?? null,
            'rate_limit_limit'       => $headers['x-ratelimit-limit']     ?? null,
            'rate_limit_remaining'   => $headers['x-ratelimit-remaining'] ?? null,
            'rate_limit_reset_seconds' => $headers['x-ratelimit-reset']   ?? null,
            'retry_after'            => $headers['retry-after']           ?? null,
            'deprecated_header'      => $headers['x-deprecated']         ?? null,
            'blocked_header'         => $headers['x-blocked']            ?? null,
            'duration_ms'            => round( $duration_ms, 2 ),
        ];
    }

    public function log_terminal( string $call_type, array $extra = [] ): void {
        $this->entries[] = array_merge( [
            'timestamp' => gmdate( 'c' ),
            'call_type' => $call_type,
        ], $extra );
    }

    public function persist(): void {
        set_transient( 'ems_last_sync_log', $this->entries, DAY_IN_SECONDS );
    }

    public function get_entries(): array {
        return $this->entries;
    }
}
