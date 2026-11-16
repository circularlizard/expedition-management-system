<?php
namespace EMS\Tests\Unit\Modules\Expeditions\Rest;

use Brain\Monkey\Functions;
use EMS\Modules\Expeditions\Repositories\CohortMemberRepository;
use EMS\Modules\Expeditions\Repositories\CohortRepository;
use EMS\Modules\Expeditions\Rest\CohortController;
use EMS\Tests\EMSTestCase;
use Mockery;

class CohortControllerTest extends EMSTestCase {

	public function test_check_permission_requires_manage_options(): void {
		Functions\when( 'current_user_can' )->alias(
			static fn( string $cap ): bool => $cap === 'manage_options'
		);

		$controller = new CohortController(
			Mockery::mock( CohortRepository::class ),
			Mockery::mock( CohortMemberRepository::class )
		);

		$this->assertTrue( $controller->check_permission() );
	}

	/**
	 * (d) wp_mail is never called on preview or unconfirmed dispatch, and only fires when confirm_dispatch === true.
	 */
	public function test_notifications_dispatch_requires_confirm_dispatch_true_and_fires_wp_mail_only_on_confirm(): void {
		Functions\when( 'absint' )->alias( static fn( mixed $v ): int => abs( (int) $v ) );

		$dispatcher = new class {
			/** @var array<int, string> */
			public array $calls = array();

			/** @param array<string, mixed> $params */
			public function previewBatch( array $params ): array {
				$this->calls[] = 'preview';
				return array(
					'recipients'   => array( array( 'expedition_signup_id' => 701 ) ),
					'sample_email' => array( 'subject' => 'Offer', 'html' => '<p>Offer</p>' ),
					'warnings'     => array(),
				);
			}

			/** @param array<string, mixed> $params */
			public function dispatchBatch( array $params ): array {
				$this->calls[] = 'dispatch';
				wp_mail( 'alex@example.com', 'Offer', '<p>Offer</p>' );
				return array(
					'sent_count' => 1,
					'sent_ids'   => $params['expedition_signup_ids'] ?? array(),
				);
			}
		};

		$controller = new CohortController(
			Mockery::mock( CohortRepository::class ),
			Mockery::mock( CohortMemberRepository::class ),
			null,
			null,
			$dispatcher
		);

		// 1. Preview never calls wp_mail.
		Functions\expect( 'wp_mail' )->never();
		$preview_req = new \WP_REST_Request( 'POST', '/ems/v1/expeditions/notifications/preview' );
		$preview_req->set_param( 'scope', 'cohort' );
		$preview_req->set_param( 'cohort_id', 10 );
		$preview_res = $controller->preview_notifications( $preview_req );
		$this->assertInstanceOf( \WP_REST_Response::class, $preview_res );
		$this->assertSame( 200, $preview_res->get_status() );

		// 2. Unconfirmed dispatch returns 400 WP_Error and never calls wp_mail.
		$unconfirmed_req = Mockery::mock( \WP_REST_Request::class );
		$unconfirmed_req->shouldReceive( 'get_json_params' )->andReturn(
			array(
				'confirm_dispatch'      => false,
				'expedition_signup_ids' => array( 701 ),
			)
		);
		$unconfirmed_res = $controller->dispatch_notifications( $unconfirmed_req );
		$this->assertInstanceOf( \WP_Error::class, $unconfirmed_res );
		$this->assertSame( 'ems_confirmation_required', $unconfirmed_res->get_error_code() );

		// 3. Confirmed dispatch fires wp_mail once.
		Functions\expect( 'wp_mail' )
			->once()
			->with( 'alex@example.com', 'Offer', '<p>Offer</p>' )
			->andReturn( true );

		$confirmed_req = Mockery::mock( \WP_REST_Request::class );
		$confirmed_req->shouldReceive( 'get_json_params' )->andReturn(
			array(
				'confirm_dispatch'      => true,
				'expedition_signup_ids' => array( 701 ),
			)
		);
		$confirmed_res = $controller->dispatch_notifications( $confirmed_req );

		$this->assertInstanceOf( \WP_REST_Response::class, $confirmed_res );
		$this->assertSame( 200, $confirmed_res->get_status() );
		$this->assertSame( array( 'preview', 'dispatch' ), $dispatcher->calls );
	}
}
