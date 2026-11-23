<?php
declare(strict_types=1);

namespace EMS\Tests\Unit\Modules\Notifications;

use Brain\Monkey\Functions;
use EMS\Modules\Notifications\Services\DeadlineReminderCron;
use EMS\Services\DeadlinePolicyService;
use EMS\Tests\EMSTestCase;
use Mockery;

class DeadlineReminderCronTest extends EMSTestCase {

	public function test_resolve_reminder_window_matches_3d_and_0d_only(): void {
		$cron = new DeadlineReminderCron();

		$this->assertSame( '3d', $cron->resolve_reminder_window( '2026-06-10', strtotime( '2026-06-07 10:00:00' ) ) );
		$this->assertSame( '3d', $cron->resolve_reminder_window( '2026-06-10', strtotime( '2026-06-09 10:00:00' ) ) );
		$this->assertSame( '0d', $cron->resolve_reminder_window( '2026-06-10', strtotime( '2026-06-10 08:00:00' ) ) );
		$this->assertNull( $cron->resolve_reminder_window( '2026-06-10', strtotime( '2026-06-05 10:00:00' ) ) );
		$this->assertNull( $cron->resolve_reminder_window( '2026-06-10', strtotime( '2026-06-11 10:00:00' ) ) );
		$this->assertNull( $cron->resolve_reminder_window( '', strtotime( '2026-06-10 10:00:00' ) ) );
	}

	public function test_run_sends_and_deduplicates_place_offer_reminders(): void {
		$wpdb           = Mockery::mock( 'wpdb' );
		$wpdb->prefix   = 'wp_';
		$wpdb->posts    = 'wp_posts';
		$wpdb->postmeta = 'wp_postmeta';

		$policy = Mockery::mock( DeadlinePolicyService::class );
		$policy->shouldReceive( 'get_policies' )->once()->andReturn(
			array(
				'place_offer' => array(
					'communicated_date' => '2026-06-10',
					'hard_cutoff_date'  => '2026-06-15',
					'grace_days'        => 7,
				),
				'route_card'  => array(
					'communicated_date' => '',
					'hard_cutoff_date'  => '',
					'grace_days'        => 7,
				),
			)
		);

		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_results' )->once()->andReturn(
			array(
				array(
					'id'                   => 1,
					'team_post_id'         => 50,
					'scout_id'             => 101,
					'expedition_signup_id' => 201,
					'team_code'            => 'S1-W-A',
					'explorer_first_name'  => 'Alice',
					'explorer_last_name'   => 'Smith',
					'explorer_email'       => 'alice@example.org',
					'parent_email'         => 'parent@example.org',
				),
				array(
					'id'                   => 2,
					'team_post_id'         => 51,
					'scout_id'             => 102,
					'expedition_signup_id' => 202,
					'team_code'            => 'S1-W-B',
					'explorer_first_name'  => 'Bob',
					'explorer_last_name'   => 'Jones',
					'explorer_email'       => 'bob@example.org',
					'parent_email'         => 'bob-parent@example.org',
				),
			)
		);

		// First member not yet sent; second member already has a sent log for this dedupe_key.
		$wpdb->shouldReceive( 'get_var' )->twice()->with( 'SQL' )->andReturn( null, '99' );
		$wpdb->shouldReceive( 'insert' )
			->once()
			->with(
				'wp_ems_notification_logs',
				Mockery::on(
					static function ( array $data ): bool {
						return $data['template_slug'] === 'place_offer_reminder'
							&& $data['trigger_type'] === 'cron'
							&& $data['recipient_email'] === 'parent@example.org'
							&& $data['dedupe_key'] === 'place_offer_reminder:es:201:2026-06-10:3d'
							&& $data['status'] === 'sent';
					}
				)
			)
			->andReturn( 1 );

		Functions\when( 'get_option' )->alias(
			static fn( string $key, mixed $default = false ) => $default
		);
		Functions\when( 'get_bloginfo' )->justReturn( 'EMS Scouts' );
		Functions\when( 'home_url' )->justReturn( 'https://example.org/explorer-portal/' );
		Functions\when( 'is_email' )->alias( static fn( $e ) => is_string( $e ) && str_contains( $e, '@' ) );
		Functions\when( 'current_time' )->justReturn( '2026-06-08 09:00:00' );

		$mailed = array();
		Functions\when( 'wp_mail' )->alias(
			static function ( $to, $subject ) use ( &$mailed ) {
				$mailed[] = $to;
				return true;
			}
		);

		$cron    = new DeadlineReminderCron( $policy, $wpdb );
		$summary = $cron->run( strtotime( '2026-06-08 09:00:00' ) );

		$this->assertSame( array( 'parent@example.org' ), $mailed );
		$this->assertSame( 1, $summary['place_offer_sent'] );
		$this->assertSame( 1, $summary['place_offer_skipped'] );
	}
}
