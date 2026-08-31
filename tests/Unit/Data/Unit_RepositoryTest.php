<?php
namespace EMS\Tests\Unit\Data;

use EMS\Data\Unit_Repository;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;

class Unit_RepositoryTest extends EMSTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'is_email' )->alias( function( $email ) {
			return filter_var( $email, FILTER_VALIDATE_EMAIL ) !== false;
		} );
	}

	public function test_sync_patrol_creates_or_updates_and_matches(): void {
		$wpdb = new class {
			public $prefix = 'wp_';
			public $queries = [];
			public $last_args = [];
			public $insert_args = [];

			public function prepare( string $sql, ...$args ): string {
				if ( str_contains( $sql, 'INSERT INTO' ) ) {
					$this->insert_args = $args;
				}
				$this->last_args = $args;
				return vsprintf( str_replace( '%s', "'%s'", str_replace( '%d', '%d', $sql ) ), $args );
			}

			public function query( string $sql ) {
				$this->queries[] = $sql;
				return 1;
			}

			public function get_results( string $sql, string $output = 'OBJECT' ) {
				// Return master units for matching logic
				return [
					[ 'id' => 1, 'unit_id' => 10, 'short_code' => 'BO-Orion', 'name' => 'Orion ESU' ]
				];
			}

			public function get_var( string $sql ) {
				return 99;
			}
		};

		$repo = new Unit_Repository( $wpdb );
		$id = $repo->sync_patrol( [
			'patrol_id'  => 101,
			'section_id' => 99001,
			'name'       => 'Orion', // matches 'Orion ESU' by substring
			'active'     => 1,
		] );

		$this->assertEquals( 99, $id );
		$this->assertCount( 1, $wpdb->queries );
		$this->assertStringContainsString( 'INSERT INTO wp_ems_unit_patrols', $wpdb->queries[0] );
		// unit_id argument in INSERT should be 10 (the matched unit_id)
		$this->assertEquals( 10, $wpdb->insert_args[0] );
	}

	public function test_find_matching_unit(): void {
		$repo = new Unit_Repository();
		$master_units = [
			[ 'unit_id' => 10, 'short_code' => 'BO-Kelso', 'name' => 'Kelso ESU' ],
			[ 'unit_id' => 20, 'short_code' => 'BO-Orion', 'name' => 'Orion' ]
		];

		// 1. Exact match on short code
		$match = $repo->find_matching_unit( 'BO-Kelso', $master_units );
		$this->assertEquals( 10, $match['unit_id'] );

		// 2. Exact match on name
		$match = $repo->find_matching_unit( 'Orion', $master_units );
		$this->assertEquals( 20, $match['unit_id'] );

		// 3. Substring match
		$match = $repo->find_matching_unit( 'Kelso', $master_units );
		$this->assertEquals( 10, $match['unit_id'] );
	}

	public function test_update_custom_mappings_success(): void {
		$wpdb = new class {
			public $prefix = 'wp_';
			public $updated = [];

			public function update( string $table, array $data, array $where, array $format = [], array $where_format = [] ): int {
				$this->updated[] = [ 'table' => $table, 'data' => $data, 'where' => $where ];
				return 1;
			}
		};

		$repo = new Unit_Repository( $wpdb );
		$result = $repo->update_custom_mappings( 12, [
			'unit_id'      => 4200,
			'district'     => 'Braid',
			'short_code'   => 'ORION-ESU',
			'leader_email' => 'jane.smith@example.com',
		] );

		$this->assertTrue( $result );
		$this->assertCount( 1, $wpdb->updated );
		$this->assertEquals( 'wp_ems_units', $wpdb->updated[0]['table'] );
		$this->assertEquals( 4200, $wpdb->updated[0]['data']['unit_id'] );
		$this->assertEquals( 'Braid', $wpdb->updated[0]['data']['district'] );
		$this->assertEquals( 'ORION-ESU', $wpdb->updated[0]['data']['short_code'] );
		$this->assertEquals( 'jane.smith@example.com', $wpdb->updated[0]['data']['leader_email'] );
		$this->assertEquals( 12, $wpdb->updated[0]['where']['id'] );
	}

	public function test_update_custom_mappings_throws_on_invalid_email(): void {
		$wpdb = new class {
			public $prefix = 'wp_';
		};

		$repo = new Unit_Repository( $wpdb );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Invalid leader email format' );

		$repo->update_custom_mappings( 12, [
			'leader_email' => 'bad-email',
		] );
	}

	public function test_find_by_short_code(): void {
		$wpdb = new class {
			public $prefix = 'wp_';
			public $last_query = '';

			public function prepare( string $sql, ...$args ): string {
				return vsprintf( str_replace( '%s', "'%s'", str_replace( '%d', '%d', $sql ) ), $args );
			}

			public function get_row( string $query, string $output = 'OBJECT' ) {
				$this->last_query = $query;
				return [
					'id'         => 12,
					'short_code' => 'ORION-ESU',
					'unit_id'    => 4200,
				];
			}
		};

		$repo = new Unit_Repository( $wpdb );
		$row = $repo->find_by_short_code( 'ORION-ESU' );

		$this->assertNotNull( $row );
		$this->assertEquals( 12, $row['id'] );
		$this->assertStringContainsString( "short_code = 'ORION-ESU'", $wpdb->last_query );
	}

	public function test_add_custom_unit_creates_unit(): void {
		$wpdb = new class {
			public $prefix = 'wp_';
			public $inserted = [];

			public function insert( string $table, array $data, array $format = [] ) {
				$this->inserted[] = [ 'table' => $table, 'data' => $data ];
				return 1;
			}

			public function prepare( string $sql, ...$args ): string {
				return vsprintf( str_replace( '%s', "'%s'", str_replace( '%d', '%d', $sql ) ), $args );
			}

			public function get_var( string $sql ) {
				return 99;
			}
		};

		$repo = new Unit_Repository( $wpdb );
		$new_id = $repo->add_custom_unit( [
			'name'         => 'Orion ESU',
			'district'     => 'Braid',
			'short_code'   => 'ORION',
			'unit_id'      => 12345,
			'leader_email' => 'leader@example.com',
		] );

		$this->assertEquals( 99, $new_id );
		$this->assertCount( 1, $wpdb->inserted );
		$this->assertEquals( 'wp_ems_units', $wpdb->inserted[0]['table'] );
		$this->assertEquals( 12345, $wpdb->inserted[0]['data']['unit_id'] );
		$this->assertEquals( 'Braid', $wpdb->inserted[0]['data']['district'] );
		$this->assertEquals( 'ORION', $wpdb->inserted[0]['data']['short_code'] );
	}

	public function test_delete_custom_unit_deletes_from_units_and_nulls_patrol_links(): void {
		$wpdb = new class {
			public $prefix = 'wp_';
			public $deleted = [];
			public $updated = [];

			public function get_row( string $query, string $output = 'OBJECT' ) {
				return [
					'id'      => 5,
					'unit_id' => 12345,
				];
			}

			public function prepare( string $sql, ...$args ): string {
				return vsprintf( str_replace( '%s', "'%s'", str_replace( '%d', '%d', $sql ) ), $args );
			}

			public function update( string $table, array $data, array $where, array $format = [], array $where_format = [] ): int {
				$this->updated[] = [ 'table' => $table, 'data' => $data, 'where' => $where ];
				return 1;
			}

			public function delete( string $table, array $where, array $where_format = [] ) {
				$this->deleted[] = [ 'table' => $table, 'where' => $where ];
				return 1;
			}
		};

		$repo = new Unit_Repository( $wpdb );
		$res = $repo->delete_custom_unit( 5 );

		$this->assertTrue( $res );
		$this->assertCount( 1, $wpdb->updated );
		$this->assertEquals( 'wp_ems_unit_patrols', $wpdb->updated[0]['table'] );
		$this->assertNull( $wpdb->updated[0]['data']['unit_id'] );
		$this->assertEquals( 12345, $wpdb->updated[0]['where']['unit_id'] );

		$this->assertCount( 1, $wpdb->deleted );
		$this->assertEquals( 'wp_ems_units', $wpdb->deleted[0]['table'] );
		$this->assertEquals( 5, $wpdb->deleted[0]['where']['id'] );
	}

	public function test_consolidate_duplicate_units(): void {
		$wpdb = new class {
			public $prefix = 'wp_';
			public $queries = [];

			public function prepare( string $sql, ...$args ): string {
				return vsprintf( str_replace( '%s', "'%s'", str_replace( '%d', '%d', $sql ) ), $args );
			}

			public function query( string $sql ) {
				$this->queries[] = $sql;
				return 1;
			}

			public function get_results( string $sql, string $output = 'OBJECT' ) {
				if ( str_contains( $sql, 'SELECT * FROM wp_ems_units' ) ) {
					return [
						[ 'id' => 10, 'unit_id' => 46461, 'district' => 'Braid', 'name' => 'BR-ALBATROSS', 'short_code' => 'BR-Albatross', 'leader_email' => 'shaun@example.com' ],
						[ 'id' => 11, 'unit_id' => 46502, 'district' => '',      'name' => 'BR-ALBATROSS', 'short_code' => 'BR-Albatross', 'leader_email' => 'shaun@example.com' ],
						[ 'id' => 20, 'unit_id' => 99201, 'district' => 'Pentland', 'name' => 'PE-Castle', 'short_code' => 'PE-Castle', 'leader_email' => 'jon@example.com' ],
					];
				}
				if ( str_contains( $sql, 'SELECT * FROM wp_ems_unit_patrols' ) ) {
					return [];
				}
				return [];
			}
		};

		$repo = new Unit_Repository( $wpdb );
		$res  = $repo->consolidate_duplicate_units();

		$this->assertSame( 1, $res['merged_count'] );
		$this->assertCount( 1, $res['details'] );
		$this->assertStringContainsString( 'BR-ALBATROSS: Merged 1 duplicate(s) into Unit ID 46461', $res['details'][0] );

		// Check that UPDATE and DELETE queries were executed
		$this->assertCount( 2, $wpdb->queries );
		$this->assertStringContainsString( 'UPDATE wp_ems_unit_patrols SET unit_id = 46461 WHERE unit_id IN (46502)', $wpdb->queries[0] );
		$this->assertStringContainsString( 'DELETE FROM wp_ems_units WHERE id IN (11)', $wpdb->queries[1] );
	}
}
