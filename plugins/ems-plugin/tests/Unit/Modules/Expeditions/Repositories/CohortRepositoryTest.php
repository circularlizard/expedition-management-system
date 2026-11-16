<?php
namespace EMS\Tests\Unit\Modules\Expeditions\Repositories;

use EMS\Modules\Expeditions\Repositories\CohortRepository;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;

class CohortRepositoryTest extends EMSTestCase {

	public function test_create_inserts_cohort_with_defaults(): void {
		$wpdb = new class {
			public string $prefix = 'wp_';
			public int $insert_id = 42;
			public string $last_error = '';
			/** @var array<int, array<string, mixed>> */
			public array $inserted = array();

			public function insert( string $table, array $data, array $format = array() ): int {
				$this->inserted[] = compact( 'table', 'data', 'format' );
				return 1;
			}
		};

		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'current_time' )->justReturn( '2026-11-16 20:00:00' );

		$repo = new CohortRepository( $wpdb );
		$id   = $repo->create(
			array(
				'name'       => 'Gold Foot A',
				'dofe_level' => 'Gold',
			)
		);

		$this->assertSame( 42, $id );
		$this->assertCount( 1, $wpdb->inserted );
		$this->assertSame( 'wp_ems_cohorts', $wpdb->inserted[0]['table'] );
		$this->assertSame( 'Gold Foot A', $wpdb->inserted[0]['data']['name'] );
		$this->assertSame( 'gold', $wpdb->inserted[0]['data']['dofe_level'] );
		$this->assertSame( 'foot', $wpdb->inserted[0]['data']['transport_mode'] );
		$this->assertSame( 'forming', $wpdb->inserted[0]['data']['cohort_status'] );
		$this->assertNull( $wpdb->inserted[0]['data']['practice_expedition_id'] );
		$this->assertSame( 7, $wpdb->inserted[0]['data']['created_by'] );
	}

	public function test_create_throws_when_name_missing(): void {
		$wpdb = new class {
			public string $prefix = 'wp_';
		};

		$repo = new CohortRepository( $wpdb );

		$this->expectException( \InvalidArgumentException::class );
		$repo->create( array( 'dofe_level' => 'silver' ) );
	}

	public function test_find_and_list_by_filters(): void {
		$wpdb = new class {
			public string $prefix = 'wp_';
			/** @var array<int, string> */
			public array $queries = array();

			public function esc_like( string $text ): string {
				return addcslashes( $text, '_%\\' );
			}

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
					'id'                      => '5',
					'name'                    => 'Silver Canoe 1',
					'dofe_level'              => 'silver',
					'transport_mode'          => 'canoe',
					'practice_expedition_id'  => '101',
					'qualifier_expedition_id' => null,
					'practice_team_post_id'   => '201',
					'qualifier_team_post_id'  => null,
					'cohort_status'           => 'forming',
					'notes'                   => 'Test notes',
					'created_by'              => '3',
					'created_at'              => '2026-11-16 10:00:00',
					'updated_at'              => '2026-11-16 10:00:00',
				);
			}

			public function get_results( string $sql, string $output = ARRAY_A ): array {
				$this->queries[] = $sql;
				return array( $this->get_row( $sql, $output ) );
			}
		};

		$repo   = new CohortRepository( $wpdb );
		$cohort = $repo->find( 5 );

		$this->assertNotNull( $cohort );
		$this->assertSame( 5, $cohort['id'] );
		$this->assertSame( 101, $cohort['practice_expedition_id'] );
		$this->assertNull( $cohort['qualifier_expedition_id'] );

		$list = $repo->listByFilters(
			array(
				'dofe_level'    => 'Silver',
				'expedition_id' => 101,
				'search'        => 'Canoe',
			)
		);

		$this->assertCount( 1, $list );
		$this->assertStringContainsString( "dofe_level = 'silver'", $wpdb->queries[1] );
		$this->assertStringContainsString( '(practice_expedition_id = 101 OR qualifier_expedition_id = 101)', $wpdb->queries[1] );
		$this->assertStringContainsString( "name LIKE '%Canoe%'", $wpdb->queries[1] );
	}

	public function test_update_and_delete(): void {
		$wpdb = new class {
			public string $prefix = 'wp_';
			/** @var array<int, array<string, mixed>> */
			public array $updated = array();
			/** @var array<int, array<string, mixed>> */
			public array $deleted = array();

			public function prepare( string $sql, ...$args ): string {
				return $sql;
			}

			public function get_row( string $sql, string $output = ARRAY_A ): array {
				return array(
					'id'             => 9,
					'name'           => 'Bronze A',
					'dofe_level'     => 'bronze',
					'transport_mode' => 'foot',
					'cohort_status'  => 'forming',
					'created_by'     => 1,
					'created_at'     => '2026-11-16 10:00:00',
					'updated_at'     => '2026-11-16 10:00:00',
				);
			}

			public function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() ): int {
				$this->updated[] = compact( 'table', 'data', 'where' );
				return 1;
			}

			public function delete( string $table, array $where, array $where_format = array() ): int {
				$this->deleted[] = compact( 'table', 'where' );
				return 1;
			}
		};

		Functions\when( 'current_time' )->justReturn( '2026-11-16 20:30:00' );

		$repo = new CohortRepository( $wpdb );
		$this->assertTrue( $repo->update( 9, array( 'cohort_status' => 'ready', 'qualifier_expedition_id' => 55 ) ) );
		$this->assertSame( 'ready', $wpdb->updated[0]['data']['cohort_status'] );
		$this->assertSame( 55, $wpdb->updated[0]['data']['qualifier_expedition_id'] );

		$this->assertTrue( $repo->delete( 9 ) );
		$this->assertSame( array( 'id' => 9 ), $wpdb->deleted[0]['where'] );
	}
}
