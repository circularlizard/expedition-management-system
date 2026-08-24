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

    public function test_sync_patrol_creates_or_updates(): void {
        $wpdb = new class {
            public $prefix = 'wp_';
            public $queries = [];
            public $last_args = [];

            public function prepare( string $sql, ...$args ): string {
                $this->last_args = $args;
                return vsprintf( str_replace( '%s', "'%s'", str_replace( '%d', '%d', $sql ) ), $args );
            }

            public function query( string $sql ) {
                $this->queries[] = $sql;
                return 1;
            }

            public function get_var( string $sql ) {
                return 12;
            }
        };

        $repo = new Unit_Repository( $wpdb );
        $id = $repo->sync_patrol( [
            'patrol_id'  => 101,
            'section_id' => 99001,
            'name'       => 'Orion',
            'active'     => 1,
        ] );

        $this->assertEquals( 12, $id );
        $this->assertCount( 1, $wpdb->queries );
        $this->assertStringContainsString( 'INSERT INTO wp_ems_units', $wpdb->queries[0] );
        $this->assertStringContainsString( "ON DUPLICATE KEY UPDATE", $wpdb->queries[0] );
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
            'unit_id'           => 4200,
            'short_code'        => 'ORION-ESU',
            'leader_first_name' => 'Jane',
            'leader_last_name'  => 'Smith',
            'leader_email'      => 'jane.smith@example.com',
        ] );

        $this->assertTrue( $result );
        $this->assertCount( 1, $wpdb->updated );
        $this->assertEquals( 'wp_ems_units', $wpdb->updated[0]['table'] );
        $this->assertEquals( 4200, $wpdb->updated[0]['data']['unit_id'] );
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
                    'patrol_id'  => 101,
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

    public function test_update_custom_mappings_allows_updating_name(): void {
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
            'name' => 'Updated Custom Unit Name',
        ] );

        $this->assertTrue( $result );
        $this->assertCount( 1, $wpdb->updated );
        $this->assertEquals( 'Updated Custom Unit Name', $wpdb->updated[0]['data']['name'] );
    }

    public function test_add_custom_unit_creates_unit_with_negative_patrol_id(): void {
        $wpdb = new class {
            public $prefix = 'wp_';
            public $queries = [];
            public $inserted = [];

            public function get_var( string $sql ) {
                // If it's querying for MIN(patrol_id)
                if ( str_contains( $sql, 'MIN(patrol_id)' ) ) {
                    return -5; // existing min is -5
                }
                // Return new insert ID
                return 99;
            }

            public function prepare( string $sql, ...$args ): string {
                return vsprintf( str_replace( '%s', "'%s'", str_replace( '%d', '%d', $sql ) ), $args );
            }

            public function query( string $sql ) {
                $this->queries[] = $sql;
                return 1;
            }

            public function insert( string $table, array $data, array $format = [] ) {
                $this->inserted[] = [ 'table' => $table, 'data' => $data ];
                return 1;
            }
        };

        $repo = new Unit_Repository( $wpdb );
        $new_id = $repo->add_custom_unit( [
            'name'              => 'Orion ESU',
            'short_code'        => 'ORION',
            'unit_id'           => 12345,
            'leader_first_name' => 'Leader',
            'leader_last_name'  => 'Name',
            'leader_email'      => 'leader@example.com',
        ] );

        $this->assertEquals( 99, $new_id );
        $this->assertCount( 1, $wpdb->inserted );
        $this->assertEquals( 'wp_ems_units', $wpdb->inserted[0]['table'] );
        // min was -5, so next negative patrol_id should be -6
        $this->assertEquals( -6, $wpdb->inserted[0]['data']['patrol_id'] );
        $this->assertEquals( 0, $wpdb->inserted[0]['data']['section_id'] );
        $this->assertEquals( 'Orion ESU', $wpdb->inserted[0]['data']['name'] );
        $this->assertEquals( 12345, $wpdb->inserted[0]['data']['unit_id'] );
        $this->assertEquals( 'ORION', $wpdb->inserted[0]['data']['short_code'] );
    }

    public function test_delete_custom_unit_only_deletes_custom_units(): void {
        $wpdb = new class {
            public $prefix = 'wp_';
            public $deleted = [];
            public $patrol_id_to_return = -3;

            public function get_row( string $query, string $output = 'OBJECT' ) {
                return [
                    'id'        => 5,
                    'patrol_id' => $this->patrol_id_to_return,
                ];
            }

            public function prepare( string $sql, ...$args ): string {
                return vsprintf( str_replace( '%s', "'%s'", str_replace( '%d', '%d', $sql ) ), $args );
            }

            public function delete( string $table, array $where, array $where_format = [] ) {
                $this->deleted[] = [ 'table' => $table, 'where' => $where ];
                return 1;
            }
        };

        // Try deleting a custom unit (patrol_id < 0)
        $repo = new Unit_Repository( $wpdb );
        $res = $repo->delete_custom_unit( 5 );
        $this->assertTrue( $res );
        $this->assertCount( 1, $wpdb->deleted );
        $this->assertEquals( 5, $wpdb->deleted[0]['where']['id'] );

        // Try deleting a synced unit (patrol_id > 0)
        $wpdb->deleted = [];
        $wpdb->patrol_id_to_return = 101;
        $res2 = $repo->delete_custom_unit( 5 );
        $this->assertFalse( $res2 );
        $this->assertEmpty( $wpdb->deleted );
    }
}

