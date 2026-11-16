<?php
namespace EMS\Tests\Unit\Modules\Expeditions\Services;

use Brain\Monkey\Functions;
use EMS\Data\Expedition_Repository;
use EMS\Data\Signup_Repository;
use EMS\Data\Team_Repository;
use EMS\Modules\Expeditions\Repositories\CohortMemberRepository;
use EMS\Modules\Expeditions\Repositories\CohortRepository;
use EMS\Modules\Expeditions\Services\CohortSchedulerService;
use EMS\Tests\EMSTestCase;
use Mockery;

class CohortSchedulerServiceTest extends EMSTestCase {

	private function makeWpdbSpy(): object {
		return new class {
			public string $prefix = 'wp_';
			/** @var array<int, array<string, mixed>> */
			public array $team_members = array();
			/** @var array<int, string> */
			public array $queries = array();

			public function prepare( string $sql, ...$args ): string {
				$i = 0;
				return (string) preg_replace_callback(
					'/%[sd]/',
					static function ( array $m ) use ( &$args, &$i ): string {
						$val = $args[ $i++ ] ?? '';
						return $m[0] === '%d' ? (string) (int) $val : "'" . (string) $val . "'";
					},
					$sql
				);
			}

			public function query( string $sql ): int|bool {
				$this->queries[] = $sql;
				if ( stripos( $sql, 'UPDATE wp_ems_team_members' ) !== false && stripos( $sql, "notification_status = 're_notify_needed'" ) !== false ) {
					$updated = 0;
					foreach ( $this->team_members as &$row ) {
						if ( ( $row['notification_status'] ?? '' ) === 'notified' ) {
							$row['notification_status'] = 're_notify_needed';
							++$updated;
						}
					}
					return $updated;
				}
				return 1;
			}

			public function get_row( string $sql, string $output = ARRAY_A ): ?array {
				$this->queries[] = $sql;
				foreach ( $this->team_members as $row ) {
					if ( str_contains( $sql, 'expedition_signup_id = ' . (int) $row['expedition_signup_id'] )
						&& str_contains( $sql, 'team_post_id = ' . (int) $row['team_post_id'] ) ) {
						return $row;
					}
				}
				return null;
			}

			/** @return array<int, array<string, mixed>> */
			public function get_results( string $sql, string $output = ARRAY_A ): array {
				$this->queries[] = $sql;
				return $this->team_members;
			}

			/** @param array<string, mixed> $data */
			public function insert( string $table, array $data ): int {
				if ( str_ends_with( $table, 'ems_team_members' ) ) {
					$data['id']           = count( $this->team_members ) + 1;
					$this->team_members[] = $data;
				}
				return 1;
			}

			/**
			 * @param array<string, mixed> $data
			 * @param array<string, mixed> $where
			 */
			public function update( string $table, array $data, array $where ): int {
				if ( str_ends_with( $table, 'ems_team_members' ) ) {
					foreach ( $this->team_members as &$row ) {
						if ( (int) ( $row['id'] ?? 0 ) === (int) ( $where['id'] ?? -1 ) ) {
							$row = array_merge( $row, $data );
							return 1;
						}
					}
				}
				return 0;
			}

			/** @param array<string, mixed> $where */
			public function delete( string $table, array $where ): int {
				return 1;
			}
		};
	}

	/**
	 * (a) Two unmatched signups (scout_id = 0) coexist in one cohort without collision.
	 */
	public function test_two_unmatched_signups_coexist_in_cohort_without_collision(): void {
		$wpdb = $this->makeWpdbSpy();
		Functions\expect( 'wp_mail' )->never();
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'current_time' )->justReturn( '2026-11-16 12:00:00' );

		$members = array(
			array(
				'id'                     => 1,
				'cohort_id'              => 10,
				'expedition_signup_id'   => 701,
				'scout_id'               => 0,
				'wp_user_id'             => 0,
				'first_name'             => 'Alex',
				'last_name'              => 'GuestOne',
				'first_aid_level'        => 'none',
				'first_aid_status'       => 'first_aider',
				'first_aid_expiry'       => '2027-09-01',
				'additional_support_needs' => '',
				'expedition_preferences' => wp_json_encode( array( 'practice_dates' => array( '101' ) ) ),
			),
			array(
				'id'                     => 2,
				'cohort_id'              => 10,
				'expedition_signup_id'   => 702,
				'scout_id'               => 0,
				'wp_user_id'             => 0,
				'first_name'             => 'Sam',
				'last_name'              => 'GuestTwo',
				'first_aid_level'        => 'none',
				'first_aid_status'       => 'first_aider',
				'first_aid_expiry'       => '2027-09-01',
				'additional_support_needs' => '',
				'expedition_preferences' => wp_json_encode( array( 'practice_dates' => array( '101' ) ) ),
			),
			array(
				'id'                     => 3,
				'cohort_id'              => 10,
				'expedition_signup_id'   => 703,
				'scout_id'               => 3003,
				'wp_user_id'             => 13,
				'first_name'             => 'Jamie',
				'last_name'              => 'Matched',
				'first_aid_level'        => 'none',
				'first_aid_status'       => 'none',
				'first_aid_expiry'       => null,
				'additional_support_needs' => '',
				'expedition_preferences' => wp_json_encode( array( 'practice_dates' => array( '101' ) ) ),
			),
			array(
				'id'                     => 4,
				'cohort_id'              => 10,
				'expedition_signup_id'   => 704,
				'scout_id'               => 3004,
				'wp_user_id'             => 14,
				'first_name'             => 'Taylor',
				'last_name'              => 'Matched',
				'first_aid_level'        => 'none',
				'first_aid_status'       => 'none',
				'first_aid_expiry'       => null,
				'additional_support_needs' => '',
				'expedition_preferences' => wp_json_encode( array( 'practice_dates' => array( '101' ) ) ),
			),
		);

		$cohorts = Mockery::mock( CohortRepository::class );
		$cohorts->shouldReceive( 'find' )->with( 10 )->andReturn(
			array(
				'id'                      => 10,
				'name'                    => 'Bronze Cohort A',
				'dofe_level'              => 'bronze',
				'transport_mode'          => 'foot',
				'practice_expedition_id'  => null,
				'qualifier_expedition_id' => null,
				'practice_team_post_id'   => null,
				'qualifier_team_post_id'  => null,
				'cohort_status'           => 'forming',
			)
		);
		$cohorts->shouldReceive( 'update' )->once()->andReturn( true );

		$cohort_members = Mockery::mock( CohortMemberRepository::class );
		$cohort_members->shouldReceive( 'listByCohort' )->with( 10 )->andReturn( $members );

		$expeditions = Mockery::mock( Expedition_Repository::class );
		$expeditions->shouldReceive( 'get_by_id' )->with( 101 )->andReturn(
			array(
				'ID'             => 101,
				'post_title'     => 'H-BP1',
				'ems_level'      => 'bronze',
				'ems_transport'  => 'foot',
				'ems_type'       => 'practice',
				'ems_event_code' => 'H-BP1',
				'ems_start_date' => '2027-05-15',
			)
		);

		$teams = Mockery::mock( Team_Repository::class );
		$teams->shouldReceive( 'create' )->once()->with( 101, 'H-BP1' )->andReturn( 501 );

		$service = new CohortSchedulerService( $wpdb, $cohorts, $cohort_members, $expeditions, $teams );
		$result  = $service->scheduleCohort( 10, array( 'practice_expedition_id' => 101 ) );

		$this->assertSame( 501, $result['practice_team_post_id'] );
		$this->assertCount( 4, $wpdb->team_members );

		$signup_ids = array_column( $wpdb->team_members, 'expedition_signup_id' );
		$this->assertSame( array( 701, 702, 703, 704 ), $signup_ids );
		$this->assertSame( 0, $wpdb->team_members[0]['scout_id'] );
		$this->assertSame( 0, $wpdb->team_members[1]['scout_id'] );
	}

	/**
	 * (b) Atomic scout_id promotion on reconciliation updates ems_cohort_members & ems_team_members.
	 */
	public function test_atomic_scout_id_promotion_updates_cohort_and_team_members(): void {
		$wpdb = new class {
			public string $prefix = 'wp_';
			/** @var array<int, string> */
			public array $queries = array();

			public function prepare( string $sql, ...$args ): string {
				$i = 0;
				return (string) preg_replace_callback(
					'/%[sd]/',
					static function ( array $m ) use ( &$args, &$i ): string {
						$val = $args[ $i++ ] ?? '';
						return $m[0] === '%d' ? (string) (int) $val : "'" . (string) $val . "'";
					},
					$sql
				);
			}

			public function get_row( string $sql, string $output = ARRAY_A ): ?array {
				$this->queries[] = $sql;
				return array(
					'id'                 => 701,
					'scout_id'           => 0,
					'form_submission_id' => 555,
					'dofe_level'         => 'bronze',
					'wp_user_id'         => 0,
				);
			}

			public function query( string $sql ): int {
				$this->queries[] = $sql;
				return 1;
			}
		};

		$repo = new Signup_Repository( $wpdb );
		$ok   = $repo->reconcile_signup( 701, 'expedition', 9001 );

		$this->assertTrue( $ok );
		$joined = implode( "\n", $wpdb->queries );
		$this->assertStringContainsString( 'START TRANSACTION', $joined );
		$this->assertStringContainsString( 'UPDATE wp_ems_expedition_signups SET scout_id = 9001', $joined );
		$this->assertStringContainsString( 'UPDATE wp_ems_cohort_members SET scout_id = 9001 WHERE expedition_signup_id = 701', $joined );
		$this->assertStringContainsString( 'UPDATE wp_ems_team_members SET scout_id = 9001 WHERE expedition_signup_id = 701', $joined );
		$this->assertStringContainsString( 'COMMIT', $joined );
	}

	/**
	 * (c) 5/6 partial availability resolution (split_date and assign_pending) + (d) zero wp_mail calls.
	 */
	public function test_five_of_six_partial_availability_split_date_and_assign_pending_without_wp_mail(): void {
		Functions\expect( 'wp_mail' )->never();
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'current_time' )->justReturn( '2026-11-16 12:00:00' );

		$members = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$members[] = array(
				'id'                     => $i,
				'cohort_id'              => 20,
				'expedition_signup_id'   => 800 + $i,
				'scout_id'               => 4000 + $i,
				'wp_user_id'             => 20 + $i,
				'first_name'             => 'Avail' . $i,
				'last_name'              => 'Explorer',
				'first_aid_level'        => $i <= 2 ? 'first_aid_plus' : 'none',
				'first_aid_status'       => 'none',
				'first_aid_expiry'       => '2027-12-31',
				'additional_support_needs' => '',
				'expedition_preferences' => wp_json_encode( array( 'exped_silver_practice_dates' => array( 'H-SP1' ) ) ),
			);
		}
		// 6th member is unavailable for expedition H-SP1.
		$members[] = array(
			'id'                     => 6,
			'cohort_id'              => 20,
			'expedition_signup_id'   => 806,
			'scout_id'               => 4006,
			'wp_user_id'             => 26,
			'first_name'             => 'Conflict',
			'last_name'              => 'Explorer',
			'first_aid_level'        => 'none',
			'first_aid_status'       => 'none',
			'first_aid_expiry'       => null,
			'additional_support_needs' => '',
			'expedition_preferences' => wp_json_encode( array( 'exped_silver_practice_dates' => array( 'H-SP2' ) ) ),
		);

		$expedition = array(
			'ID'             => 201,
			'post_title'     => 'H-SP1',
			'ems_level'      => 'silver',
			'ems_transport'  => 'foot',
			'ems_type'       => 'practice',
			'ems_event_code' => 'H-SP1',
			'ems_start_date' => '2027-06-10',
		);

		$cohorts = Mockery::mock( CohortRepository::class );
		$cohorts->shouldReceive( 'find' )->with( 20 )->andReturn(
			array(
				'id'                      => 20,
				'name'                    => 'Silver Cohort B',
				'dofe_level'              => 'silver',
				'transport_mode'          => 'foot',
				'practice_expedition_id'  => null,
				'qualifier_expedition_id' => null,
				'practice_team_post_id'   => null,
				'qualifier_team_post_id'  => null,
				'cohort_status'           => 'forming',
			)
		);
		$cohorts->shouldReceive( 'update' )->twice()->andReturn( true );

		$cohort_members = Mockery::mock( CohortMemberRepository::class );
		$cohort_members->shouldReceive( 'listByCohort' )->with( 20 )->andReturn( $members );
		$cohort_members->shouldReceive( 'update' )
			->once()
			->with( 6, array( 'member_status' => 'split_practice' ) )
			->andReturn( true );

		$expeditions = Mockery::mock( Expedition_Repository::class );
		$expeditions->shouldReceive( 'get_by_id' )->with( 201 )->andReturn( $expedition );

		$teams = Mockery::mock( Team_Repository::class );
		$teams->shouldReceive( 'create' )->twice()->with( 201, 'H-SP1' )->andReturn( 601, 602 );

		// First verify 5/6 intersection scoring.
		$wpdb_assign = $this->makeWpdbSpy();
		$service     = new CohortSchedulerService( $wpdb_assign, $cohorts, $cohort_members, $expeditions, $teams );
		$scored      = $service->scoreCandidates( $members, array( $expedition ), 'silver', 'foot', 'practice' );

		$this->assertCount( 1, $scored );
		$this->assertSame( 5, $scored[0]['available_count'] );
		$this->assertSame( 6, $scored[0]['total_count'] );
		$this->assertCount( 1, $scored[0]['unavailable_members'] );
		$this->assertSame( 806, $scored[0]['unavailable_members'][0]['expedition_signup_id'] );
		$this->assertSame( 2, $scored[0]['first_aid_count'] );
		$this->assertTrue( $scored[0]['size_valid'] );

		// Case 1: assign_pending -> all 6 inserted into ems_team_members, 806 has date_conflict_pending.
		$service->scheduleCohort( 20, array( 'practice_expedition_id' => 201 ), array( 806 => 'assign_pending' ) );
		$this->assertCount( 6, $wpdb_assign->team_members );
		$this->assertSame( 'date_conflict_pending', $wpdb_assign->team_members[5]['availability_override'] );
		$this->assertSame( 'draft', $wpdb_assign->team_members[5]['notification_status'] );

		// Case 2: split_date -> only 5 inserted into team post, 806 kept in cohort with split_practice.
		$wpdb_split    = $this->makeWpdbSpy();
		$service_split = new CohortSchedulerService( $wpdb_split, $cohorts, $cohort_members, $expeditions, $teams );
		$res_split     = $service_split->scheduleCohort( 20, array( 'practice_expedition_id' => 201 ), array( 806 => 'split_date' ) );

		$this->assertCount( 5, $wpdb_split->team_members );
		$this->assertSame( 1, $res_split['stages']['practice']['split_count'] );
	}

	/**
	 * Re-notify protection: notified member gets notification_status = re_notify_needed when date changes.
	 */
	public function test_renotify_protection_flags_notified_members_when_expedition_date_changes(): void {
		$wpdb                 = $this->makeWpdbSpy();
		$wpdb->team_members[] = array(
			'id'                   => 1,
			'team_post_id'         => 501,
			'expedition_signup_id' => 701,
			'scout_id'             => 3001,
			'notification_status'  => 'notified',
		);

		Functions\expect( 'wp_mail' )->never();
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'current_time' )->justReturn( '2026-11-16 12:00:00' );

		$cohorts = Mockery::mock( CohortRepository::class );
		$cohorts->shouldReceive( 'find' )->with( 10 )->andReturn(
			array(
				'id'                      => 10,
				'name'                    => 'Bronze Cohort A',
				'dofe_level'              => 'bronze',
				'transport_mode'          => 'foot',
				'practice_expedition_id'  => 101,
				'qualifier_expedition_id' => null,
				'practice_team_post_id'   => 501,
				'qualifier_team_post_id'  => null,
				'cohort_status'           => 'scheduled',
			)
		);
		$cohorts->shouldReceive( 'update' )->once()->andReturn( true );

		$cohort_members = Mockery::mock( CohortMemberRepository::class );
		$cohort_members->shouldReceive( 'listByCohort' )->with( 10 )->andReturn( array() );

		$expeditions = Mockery::mock( Expedition_Repository::class );
		$expeditions->shouldReceive( 'get_by_id' )->with( 101 )->andReturn(
			array(
				'ID'             => 101,
				'ems_event_code' => 'H-BP1',
				'ems_start_date' => '2027-05-15',
			)
		);
		$expeditions->shouldReceive( 'get_by_id' )->with( 102 )->andReturn(
			array(
				'ID'             => 102,
				'ems_event_code' => 'H-BP2',
				'ems_start_date' => '2027-06-15',
			)
		);

		$teams = Mockery::mock( Team_Repository::class );
		$teams->shouldReceive( 'get_by_id' )->with( 501 )->andReturn(
			array(
				'ID'       => 501,
				'event_id' => 101,
			)
		);
		$teams->shouldReceive( 'move' )->once()->with( 501, 102, 'H-BP2' )->andReturn( true );

		$service = new CohortSchedulerService( $wpdb, $cohorts, $cohort_members, $expeditions, $teams );
		$result  = $service->scheduleCohort( 10, array( 'practice_expedition_id' => 102 ) );

		$this->assertSame( 1, $result['stages']['practice']['re_notified_count'] );
		$this->assertSame( 're_notify_needed', $wpdb->team_members[0]['notification_status'] );
	}
}
