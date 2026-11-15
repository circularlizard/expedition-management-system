<?php
namespace EMS\Tests\Unit\Core;

use EMS\Core\Event_Bus;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Actions;
use Mockery;

class Event_BusTest extends EMSTestCase {

	public function test_dispatch_calls_do_action(): void {
		$payload = (object) array( 'id' => 123 );

		Actions\expectDone( 'ems_test_event' )
			->once()
			->with( $payload );

		Event_Bus::dispatch( 'ems_test_event', $payload );
		$this->addToAssertionCount( 1 );
	}

	public function test_dispatch_catches_listener_exception(): void {
		global $wpdb;

		$wpdb = Mockery::mock( 'stdClass' );
		$wpdb->prefix = 'wp_';

		$auditLogged = false;
		$wpdb->shouldReceive( 'insert' )
			->once()
			->with(
				'wp_ems_audit_logs',
				Mockery::on( function ( $data ) use ( &$auditLogged ) {
					if ( isset( $data['action'] ) && $data['action'] === 'event_dispatch_failed:ems_failing_event' ) {
						$auditLogged = true;
					}
					return true;
				} ),
				Mockery::any()
			)
			->andReturn( true );

		$payload = (object) array( 'id' => 456 );

		Actions\expectDone( 'ems_failing_event' )
			->once()
			->with( $payload )
			->andThrow( new \RuntimeException( 'Listener crashed' ) );

		Event_Bus::dispatch( 'ems_failing_event', $payload );

		$this->assertTrue( $auditLogged );
	}

	public function test_listen_catches_exceptions_inside_callback(): void {
		global $wpdb;

		$wpdb = Mockery::mock( 'stdClass' );
		$wpdb->prefix = 'wp_';

		$auditLogged = false;
		$wpdb->shouldReceive( 'insert' )
			->once()
			->with(
				'wp_ems_audit_logs',
				Mockery::on( function ( $data ) use ( &$auditLogged ) {
					if ( isset( $data['action'] ) && $data['action'] === 'event_dispatch_failed:ems_callback_event' ) {
						$auditLogged = true;
					}
					return true;
				} ),
				Mockery::any()
			)
			->andReturn( true );

		$capturedCallback = null;
		Actions\expectAdded( 'ems_callback_event' )
			->once()
			->whenHappen( function ( $callback ) use ( &$capturedCallback ) {
				$capturedCallback = $callback;
			} );

		Event_Bus::listen( 'ems_callback_event', function () {
			throw new \RuntimeException( 'Inner listener failed' );
		} );

		$this->assertIsCallable( $capturedCallback );
		// Invoking the wrapped callback should not throw
		$capturedCallback();

		$this->assertTrue( $auditLogged );
	}
}
