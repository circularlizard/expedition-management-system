<?php
declare(strict_types=1);

namespace EMS\Tests\Unit\Services;

use Brain\Monkey;
use Brain\Monkey\Functions;
use EMS\Services\DeadlinePolicyService;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class DeadlinePolicyServiceTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_evaluate_cutoff_enforces_hard_date_and_rolling_grace(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'place_offer' => array(
					'communicated_date' => '2026-06-01',
					'hard_cutoff_date'  => '2026-06-05',
					'grace_days'        => 7,
				),
			)
		);

		$svc = new DeadlinePolicyService();

		// Before hard cutoff (2026-06-04 12:00 UTC) -> allowed
		$before = $svc->evaluate_cutoff( 'place_offer', '2026-05-20 10:00:00', strtotime( '2026-06-04 12:00:00 UTC' ) );
		$this->assertTrue( $before['allowed'] );
		$this->assertFalse( $before['grace_applied'] );
		$this->assertSame( '2026-06-05', $before['effective_hard_cutoff'] );

		// After hard cutoff (2026-06-07 12:00 UTC) with early allocation -> blocked
		$after = $svc->evaluate_cutoff( 'place_offer', '2026-05-20 10:00:00', strtotime( '2026-06-07 12:00:00 UTC' ) );
		$this->assertFalse( $after['allowed'] );

		// Late allocation (2026-06-03) + 7 grace days => effective cutoff 2026-06-10 -> allowed on 2026-06-07
		$grace = $svc->evaluate_cutoff( 'place_offer', '2026-06-03 10:00:00', strtotime( '2026-06-07 12:00:00 UTC' ) );
		$this->assertTrue( $grace['allowed'] );
		$this->assertTrue( $grace['grace_applied'] );
		$this->assertSame( '2026-06-10', $grace['effective_hard_cutoff'] );
	}
}
