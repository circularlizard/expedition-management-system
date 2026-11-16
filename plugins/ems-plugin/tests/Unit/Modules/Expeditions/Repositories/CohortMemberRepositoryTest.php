<?php
namespace EMS\Tests\Unit\Modules\Expeditions\Repositories;

use EMS\Modules\Expeditions\Repositories\CohortMemberRepository;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;

class CohortMemberRepositoryTest extends EMSTestCase {

	public function test_list_by_cohort_drives_from_expedition_signups_and_left_joins_osm_explorers(): void {
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

			public function get_results( string $sql, string $output = ARRAY_A ): array {
				$this->queries[] = $sql;
				return array(
					array(
						'id'                       => '11',
						'cohort_id'                => '3',
						'expedition_signup_id'     => '701',
						'scout_id'                 => '0',
						'availability_override'    => null,
						'member_status'            => 'active',
						'notes'                    => null,
						'added_by'                 => '2',
						'created_at'               => '2026-11-16 12:00:00',
						'updated_at'               => '2026-11-16 12:00:00',
						'parent_user_id'           => '99',
						'unit_id'                  => '4',
						'unit_name'                => 'Kelso',
						'first_name'               => 'Alex',
						'last_name'                => 'Guest',
						'explorer_first_name'      => 'Alex',
						'explorer_last_name'       => 'Guest',
						'explorer_email'           => 'alex@example.com',
						'parent_email'             => 'parent@example.com',
						'leader_email'             => 'leader@example.com',
						'dofe_level'               => 'bronze',
						'dob'                      => '2011-04-10',
						'dofe_registered'          => 'n',
						'dofe_number'              => null,
						'expedition_preferences'   => '{}',
						'additional_support_needs' => null,
						'asn_validated'            => null,
						'first_aid_status'         => 'none',
						'first_aid_expiry'         => null,
						'signup_status'            => 'submitted',
						'form_submission_id'       => '555',
						'wp_user_id'               => null,
						'section_id'               => null,
						'patrol'                   => null,
						'first_aid_level'          => null,
						'osm_email'                => null,
					),
				);
			}
		};

		$repo    = new CohortMemberRepository( $wpdb );
		$members = $repo->listByCohort( 3, 'active' );

		$this->assertCount( 1, $members );
		$this->assertSame( 701, $members[0]['expedition_signup_id'] );
		$this->assertSame( 0, $members[0]['scout_id'] );
		$this->assertSame( 'Alex', $members[0]['first_name'] );
		$this->assertStringContainsString( 'FROM wp_ems_expedition_signups s', $wpdb->queries[0] );
		$this->assertStringContainsString( 'INNER JOIN wp_ems_cohort_members cm ON cm.expedition_signup_id = s.id', $wpdb->queries[0] );
		$this->assertStringContainsString( 'LEFT JOIN wp_ems_osm_explorers e ON (s.scout_id > 0 AND s.scout_id = e.scout_id)', $wpdb->queries[0] );
		$this->assertStringContainsString( "cm.member_status = 'active'", $wpdb->queries[0] );
	}

	public function test_create_prevents_duplicate_signup_in_cohort(): void {
		$wpdb = new class {
			public string $prefix = 'wp_';

			public function prepare( string $sql, ...$args ): string {
				return $sql;
			}

			public function get_var( string $sql ): int {
				return 15;
			}
		};

		$repo = new CohortMemberRepository( $wpdb );

		$this->expectException( \InvalidArgumentException::class );
		$repo->create(
			array(
				'cohort_id'            => 3,
				'expedition_signup_id' => 701,
			)
		);
	}

	public function test_reconcile_scout_id_by_signup_updates_members(): void {
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

			public function query( string $sql ): int {
				$this->queries[] = $sql;
				return 2;
			}
		};

		Functions\when( 'current_time' )->justReturn( '2026-11-16 20:30:00' );

		$repo    = new CohortMemberRepository( $wpdb );
		$updated = $repo->reconcileScoutIdBySignup( 701, 55555 );

		$this->assertSame( 2, $updated );
		$this->assertStringContainsString( 'UPDATE wp_ems_cohort_members SET scout_id = 55555', $wpdb->queries[0] );
		$this->assertStringContainsString( 'WHERE expedition_signup_id = 701', $wpdb->queries[0] );
	}
}
