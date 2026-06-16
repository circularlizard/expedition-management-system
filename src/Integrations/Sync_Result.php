<?php
namespace EMS\Integrations;

class Sync_Result {

    public string $mode;
    public string $started_at;
    public int $members_upserted   = 0;
    public int $members_failed     = 0;
    public int $events_upserted    = 0;
    public int $events_failed      = 0;
    public array $errors           = [];
    public bool $rate_limited      = false;
    public ?int $retry_after_seconds = null;
    public ?int $rate_limit_remaining = null;
    public ?int $rate_limit_reset_seconds = null;
    public bool $api_blocked       = false;
    public array $deprecated_endpoints = [];

    public function __construct( string $mode ) {
        $this->mode       = $mode;
        $this->started_at = gmdate( 'c' );
    }

    public function add_error( string $message ): void {
        $this->errors[] = $message;
    }

    public function to_array(): array {
        return [
            'mode'                    => $this->mode,
            'started_at'              => $this->started_at,
            'members_upserted'        => $this->members_upserted,
            'members_failed'          => $this->members_failed,
            'events_upserted'         => $this->events_upserted,
            'events_failed'           => $this->events_failed,
            'errors'                  => $this->errors,
            'rate_limited'            => $this->rate_limited,
            'retry_after_seconds'     => $this->retry_after_seconds,
            'rate_limit_remaining'    => $this->rate_limit_remaining,
            'rate_limit_reset_seconds'=> $this->rate_limit_reset_seconds,
            'api_blocked'             => $this->api_blocked,
            'deprecated_endpoints'    => $this->deprecated_endpoints,
        ];
    }
}
