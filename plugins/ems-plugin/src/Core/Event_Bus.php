<?php
namespace EMS\Core;

class Event_Bus {

	/**
	 * Dispatch an event to WordPress action hooks with exception handling.
	 *
	 * @param string $event
	 * @param object $payload
	 */
	public static function dispatch( string $event, object $payload ): void {
		try {
			do_action( $event, $payload );
		} catch ( \Throwable $e ) {
			Audit_Logger::log( 'event_dispatch_failed:' . $event );
			error_log( sprintf( '[EMS] Event dispatch failed for %s: %s', $event, $e->getMessage() ) );
		}
	}

	/**
	 * Register an event listener wrapped with exception handling.
	 *
	 * @param string   $event
	 * @param callable $listener
	 * @param int      $priority
	 * @param int      $accepted_args
	 */
	public static function listen( string $event, callable $listener, int $priority = 10, int $accepted_args = 1 ): void {
		add_action(
			$event,
			static function ( ...$args ) use ( $event, $listener ): void {
				try {
					$listener( ...$args );
				} catch ( \Throwable $e ) {
					Audit_Logger::log( 'event_dispatch_failed:' . $event );
					error_log( sprintf( '[EMS] Event listener failed for %s: %s', $event, $e->getMessage() ) );
				}
			},
			$priority,
			$accepted_args
		);
	}
}
