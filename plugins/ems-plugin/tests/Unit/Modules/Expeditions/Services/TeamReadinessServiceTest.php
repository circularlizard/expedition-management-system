<?php
namespace EMS\Tests\Unit\Modules\Expeditions\Services;

use Brain\Monkey\Functions;
use EMS\Contracts\DTOs\TrainingStatusDTO;
use EMS\Contracts\TrainingComplianceInterface;
use EMS\Data\Expedition_Repository;
use EMS\Data\Route_Submission_Repository;
use EMS\Modules\Expeditions\Repositories\CohortMemberRepository;
use EMS\Modules\Expeditions\Repositories\CohortRepository;
use EMS\Modules\Expeditions\Services\TeamReadinessService;
use EMS\Tests\EMSTestCase;
use Mockery;

class TeamReadinessServiceTest extends EMSTestCase {

	/**
	 * (e) Verifies Rule of Two First Aid expiry check and graceful Tutor LMS degradation.
	 */
	public function test_rule_of_two_first_aid_and_tutor_lms_graceful_degradation(): void {
		$team_post = (object) array(
			'ID'          => 501,
			'post_title'  => 'H-BP1-A',
			'post_parent' => 101,
		);

		Functions\when( 'get_posts' )->justReturn( array( $team_post ) );
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $post_id, string $key, bool $single = false ): mixed {
				if ( $post_id === 501 && $key === 'ems_team_code' ) {
					return 'H-BP1-A';
				}
				if ( $post_id === 501 && $key === '_ems_cohort_id' ) {
					return 10;
				}
				return '';
			}
		);

		$wpdb = new class {
			public string $prefix = 'wp_';

			public function prepare( string $sql, ...$args ): string {
				return $sql;
			}

			/** @return array<int, array<string, mixed>> */
			public function get_results( string $sql, string $output = ARRAY_A ): array {
				return array(
					array(
						'team_post_id'          => '501',
						'expedition_signup_id'  => '701',
						'scout_id'              => '3001',
						'user_id'               => '11',
						'availability_override' => 'matched',
						'notification_status'   => 'notified',
						'first_name'            => 'Alice',
						'last_name'             => 'ValidFA',
						'first_aid_level'       => 'first_aid_plus',
						'first_aid_status'      => 'none',
						'first_aid_expiry'      => '2027-07-01',
						'offer_status'          => 'accepted',
					),
					array(
						'team_post_id'          => '501',
						'expedition_signup_id'  => '702',
						'scout_id'              => '3002',
						'user_id'               => '12',
						'availability_override' => 'matched',
						'notification_status'   => 'notified',
						'first_name'            => 'Bob',
						'last_name'             => 'ExpiredFA',
						'first_aid_level'       => 'first_aid_plus',
						'first_aid_status'      => 'none',
						'first_aid_expiry'      => '2027-04-01', // Expired before 2027-05-15!
						'offer_status'          => 'accepted',
					),
					array(
						'team_post_id'          => '501',
						'expedition_signup_id'  => '703',
						'scout_id'              => '0',
						'user_id'               => '0',
						'availability_override' => 'date_conflict_pending',
						'notification_status'   => 'draft',
						'first_name'            => 'Charlie',
						'last_name'             => 'NoneFA',
						'first_aid_level'       => 'none',
						'first_aid_status'      => 'none',
						'first_aid_expiry'      => null,
						'offer_status'          => 'pending',
					),
					array(
						'team_post_id'          => '501',
						'expedition_signup_id'  => '704',
						'scout_id'              => '3004',
						'user_id'               => '14',
						'availability_override' => 'matched',
						'notification_status'   => 'notified',
						'first_name'            => 'Dana',
						'last_name'             => 'NoneFA',
						'first_aid_level'       => 'none',
						'first_aid_status'      => 'none',
						'first_aid_expiry'      => null,
						'offer_status'          => 'queried',
					),
				);
			}
		};

		$cohorts        = Mockery::mock( CohortRepository::class );
		$cohorts->shouldReceive( 'all' )->andReturn( array() );
		$cohorts->shouldReceive( 'find' )->andReturn( null );
		$cohorts->shouldReceive( 'findByTeamPostId' )->andReturn( null );
		$cohorts->shouldReceive( 'listByFilters' )->andReturn( array() );
		$cohorts->shouldReceive( 'find' )->andReturn( null );
		$cohorts->shouldReceive( 'findByTeamPostId' )->andReturn( null );
		$cohorts->shouldReceive( 'listByFilters' )->andReturn( array() );
		$cohort_members = Mockery::mock( CohortMemberRepository::class );

		$exp_101 = array(
			'ID'             => 101,
			'post_title'     => 'H-BP1',
			'ems_level'      => 'bronze',
			'ems_transport'  => 'foot',
			'ems_type'       => 'practice',
			'ems_event_code' => 'H-BP1',
			'ems_start_date' => '2027-05-15',
		);
		$expeditions = Mockery::mock( Expedition_Repository::class );
		$expeditions->shouldReceive( 'get_all' )->andReturn( array( $exp_101 ) );
		$expeditions->shouldReceive( 'get_by_id' )->with( 101 )->andReturn( $exp_101 );

		$route_submissions = Mockery::mock( Route_Submission_Repository::class );
		$route_submissions->shouldReceive( 'get_latest' )->with( 501 )->andReturn(
			array(
				'version' => 2,
				'status'  => 'submitted',
			)
		);

		// Simulate Tutor LMS outage / unavailable DTO to verify graceful degradation.
		$training = Mockery::mock( TrainingComplianceInterface::class );
		$training->shouldReceive( 'is_available' )->andReturn( false );
		$training->shouldReceive( 'getExplorerCourseProgress' )->andReturn( TrainingStatusDTO::unavailable() );

		$service = new TeamReadinessService(
			$wpdb,
			$cohorts,
			$cohort_members,
			$expeditions,
			$route_submissions,
			$training
		);

		$rows = $service->getReadiness();

		$this->assertCount( 1, $rows );
		$row = $rows[0];

		$this->assertSame( 4, $row['member_count'] );
		$this->assertTrue( $row['size_valid'] );
		// Only 1 valid unexpired FA on 2027-05-15 (Bob expired on 2027-04-01) -> fails Rule of Two.
		$this->assertSame( 1, $row['first_aid_count'] );
		$this->assertFalse( $row['first_aid_valid'] );
		$this->assertSame( 3, $row['training_summary']['unavailable'] );
		$this->assertSame( 1, $row['training_summary']['unlinked_oidc'] );
		$this->assertSame( 'submitted', $row['route_plan']['status'] );
		$this->assertSame( 2, $row['route_plan']['version'] );
		$this->assertSame( '2027-05-01', $row['route_plan']['cutoff_date'] );
	}

	/**
	 * Verifies Tutor LMS status aggregation when Tutor LMS is available (complete + unlinked_oidc).
	 */
	public function test_tutor_lms_status_counts_when_available(): void {
		$team_post = (object) array(
			'ID'          => 502,
			'post_title'  => 'H-BQ1-A',
			'post_parent' => 102,
		);

		Functions\when( 'get_posts' )->justReturn( array( $team_post ) );
		Functions\when( 'get_post_meta' )->justReturn( '' );

		$wpdb = new class {
			public string $prefix = 'wp_';

			public function prepare( string $sql, ...$args ): string {
				return $sql;
			}

			/** @return array<int, array<string, mixed>> */
			public function get_results( string $sql, string $output = ARRAY_A ): array {
				return array(
					array(
						'team_post_id'          => '502',
						'expedition_signup_id'  => '710',
						'scout_id'              => '3010',
						'user_id'               => '55',
						'availability_override' => 'matched',
						'notification_status'   => 'notified',
						'first_name'            => 'Eve',
						'last_name'             => 'Linked',
						'first_aid_level'       => 'first_aid_plus',
						'first_aid_status'      => 'none',
						'first_aid_expiry'      => '2028-01-01',
						'offer_status'          => 'accepted',
					),
					array(
						'team_post_id'          => '502',
						'expedition_signup_id'  => '711',
						'scout_id'              => '0',
						'user_id'               => '0',
						'availability_override' => 'matched',
						'notification_status'   => 'draft',
						'first_name'            => 'Frank',
						'last_name'             => 'Unlinked',
						'first_aid_level'       => 'first_aid_plus',
						'first_aid_status'      => 'none',
						'first_aid_expiry'      => '2028-01-01',
						'offer_status'          => 'pending',
					),
				);
			}
		};

		$cohorts        = Mockery::mock( CohortRepository::class );
		$cohorts->shouldReceive( 'all' )->andReturn( array() );
		$cohorts->shouldReceive( 'find' )->andReturn( null );
		$cohorts->shouldReceive( 'findByTeamPostId' )->andReturn( null );
		$cohorts->shouldReceive( 'listByFilters' )->andReturn( array() );
		$cohorts->shouldReceive( 'find' )->andReturn( null );
		$cohorts->shouldReceive( 'findByTeamPostId' )->andReturn( null );
		$cohorts->shouldReceive( 'listByFilters' )->andReturn( array() );
		$cohort_members = Mockery::mock( CohortMemberRepository::class );
		$exp_102     = array(
			'ID'             => 102,
			'post_title'     => 'H-BQ1',
			'ems_level'      => 'bronze',
			'ems_transport'  => 'foot',
			'ems_type'       => 'qualifier',
			'ems_event_code' => 'H-BQ1',
			'ems_start_date' => '2027-07-10',
		);
		$expeditions = Mockery::mock( Expedition_Repository::class );
		$expeditions->shouldReceive( 'get_all' )->andReturn( array( $exp_102 ) );
		$expeditions->shouldReceive( 'get_by_id' )->with( 102 )->andReturn( $exp_102 );

		$route_submissions = Mockery::mock( Route_Submission_Repository::class );
		$route_submissions->shouldReceive( 'get_latest' )->with( 502 )->andReturn( null );

		$training = Mockery::mock( TrainingComplianceInterface::class );
		$training->shouldReceive( 'is_available' )->andReturn( true );
		$training->shouldReceive( 'getExplorerCourseProgress' )
			->with( 3010 )
			->andReturn( new TrainingStatusDTO( true, 'completed', 3, 3, '2027-01-15' ) );

		$service = new TeamReadinessService(
			$wpdb,
			$cohorts,
			$cohort_members,
			$expeditions,
			$route_submissions,
			$training
		);

		$rows = $service->getReadiness();

		$this->assertCount( 1, $rows );
		$this->assertTrue( $rows[0]['first_aid_valid'] );
		$this->assertTrue( $rows[0]['training_available'] );
		$this->assertSame( 1, $rows[0]['training_summary']['complete'] );
		$this->assertSame( 1, $rows[0]['training_summary']['unlinked_oidc'] );
	}
}
