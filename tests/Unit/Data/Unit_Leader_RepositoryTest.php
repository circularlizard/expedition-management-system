<?php
namespace EMS\Tests\Unit\Data;

use EMS\Data\Unit_Leader_Repository;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;

class Unit_Leader_RepositoryTest extends EMSTestCase {

    protected function setUp(): void {
        parent::setUp();
        // Stub is_email using PHP filter_var
        Functions\when( 'is_email' )->alias( function( $email ) {
            return filter_var( $email, FILTER_VALIDATE_EMAIL ) !== false;
        } );
    }

    public function test_create_leader_success(): void {
        $wpdb = new class {
            public $prefix = 'wp_';
            public $inserted = [];

            public function prepare( string $sql, ...$args ): string {
                return vsprintf( str_replace( '%s', "'%s'", str_replace( '%d', '%d', $sql ) ), $args );
            }

            public function get_var( string $sql ) {
                // Mock patrol check in ems_osm_explorers / ems_osm_patrols
                if ( strpos( $sql, 'ems_osm_explorers' ) !== false && strpos( $sql, "'Orion'" ) !== false ) {
                    return 1;
                }
                // Mock uniqueness check in ems_unit_leaders (returns 0: doesn't exist yet)
                if ( strpos( $sql, 'ems_unit_leaders' ) !== false && strpos( $sql, "'Orion'" ) !== false ) {
                    return 0;
                }
                return null;
            }

            public function insert( string $table, array $data, array $format ): int {
                $this->inserted[] = [ 'table' => $table, 'data' => $data ];
                return 1;
            }

            public $insert_id = 42;
        };

        $repo = new Unit_Leader_Repository( $wpdb );
        $id = $repo->create( [
            'unit_name'         => 'Orion',
            'leader_first_name' => 'John',
            'leader_last_name'  => 'Doe',
            'leader_email'      => 'john.doe@example.com',
        ] );

        $this->assertEquals( 42, $id );
        $this->assertCount( 1, $wpdb->inserted );
        $this->assertEquals( 'wp_ems_unit_leaders', $wpdb->inserted[0]['table'] );
        $this->assertEquals( 'Orion', $wpdb->inserted[0]['data']['unit_name'] );
        $this->assertEquals( 'john.doe@example.com', $wpdb->inserted[0]['data']['leader_email'] );
    }

    public function test_create_leader_throws_exception_on_invalid_email(): void {
        $wpdb = new class {
            public $prefix = 'wp_';
            public function prepare( string $sql, ...$args ): string {
                return vsprintf( str_replace( '%s', "'%s'", str_replace( '%d', '%d', $sql ) ), $args );
            }
            public function get_var( string $sql ) {
                return 1; // mock unit matches
            }
        };

        $repo = new Unit_Leader_Repository( $wpdb );

        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'Invalid leader email format' );

        $repo->create( [
            'unit_name'         => 'Orion',
            'leader_first_name' => 'John',
            'leader_last_name'  => 'Doe',
            'leader_email'      => 'invalid-email',
        ] );
    }

    public function test_create_leader_throws_exception_on_nonexistent_unit(): void {
        $wpdb = new class {
            public $prefix = 'wp_';
            public function prepare( string $sql, ...$args ): string {
                return vsprintf( str_replace( '%s', "'%s'", str_replace( '%d', '%d', $sql ) ), $args );
            }
            public function get_var( string $sql ) {
                // Returns 0 for explorer patrol search
                return 0;
            }
        };

        $repo = new Unit_Leader_Repository( $wpdb );

        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'Unit name does not exist as a synced patrol' );

        $repo->create( [
            'unit_name'         => 'Nonexistent Patrol',
            'leader_first_name' => 'John',
            'leader_last_name'  => 'Doe',
            'leader_email'      => 'john.doe@example.com',
        ] );
    }

    public function test_create_leader_throws_exception_on_duplicate_unit(): void {
        $wpdb = new class {
            public $prefix = 'wp_';
            public function prepare( string $sql, ...$args ): string {
                return vsprintf( str_replace( '%s', "'%s'", str_replace( '%d', '%d', $sql ) ), $args );
            }
            public function get_var( string $sql ) {
                // If checking patrol list: returns 1
                if ( strpos( $sql, 'ems_osm_explorers' ) !== false || strpos( $sql, 'ems_osm_patrols' ) !== false ) {
                    return 1;
                }
                // If checking unit leaders uniqueness: returns 1 (already exists)
                if ( strpos( $sql, 'ems_unit_leaders' ) !== false ) {
                    return 1;
                }
                return null;
            }
        };

        $repo = new Unit_Leader_Repository( $wpdb );

        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'Leader mapping for this unit already exists' );

        $repo->create( [
            'unit_name'         => 'Orion',
            'leader_first_name' => 'John',
            'leader_last_name'  => 'Doe',
            'leader_email'      => 'john.doe@example.com',
        ] );
    }

    public function test_update_leader_success(): void {
        $wpdb = new class {
            public $prefix = 'wp_';
            public $updated = [];

            public function prepare( string $sql, ...$args ): string {
                return vsprintf( str_replace( '%s', "'%s'", str_replace( '%d', '%d', $sql ) ), $args );
            }

            public function get_var( string $sql ) {
                if ( strpos( $sql, 'ems_osm_explorers' ) !== false ) {
                    return 1;
                }
                // Uniqueness: if querying another row with Orion, check if id matches
                return 0; 
            }

            public function update( string $table, array $data, array $where, array $format = [], array $where_format = [] ): int {
                $this->updated[] = [ 'table' => $table, 'data' => $data, 'where' => $where ];
                return 1;
            }
        };

        $repo = new Unit_Leader_Repository( $wpdb );
        $result = $repo->update( 5, [
            'unit_name'         => 'Orion',
            'leader_first_name' => 'Jane',
            'leader_last_name'  => 'Smith',
            'leader_email'      => 'jane.smith@example.com',
        ] );

        $this->assertTrue( $result );
        $this->assertCount( 1, $wpdb->updated );
        $this->assertEquals( 'wp_ems_unit_leaders', $wpdb->updated[0]['table'] );
        $this->assertEquals( 'Jane', $wpdb->updated[0]['data']['leader_first_name'] );
        $this->assertEquals( 5, $wpdb->updated[0]['where']['id'] );
    }

    public function test_update_leader_throws_exception_on_duplicate_unit(): void {
        $wpdb = new class {
            public $prefix = 'wp_';
            public function prepare( string $sql, ...$args ): string {
                return vsprintf( str_replace( '%s', "'%s'", str_replace( '%d', '%d', $sql ) ), $args );
            }
            public function get_var( string $sql ) {
                if ( strpos( $sql, 'ems_osm_explorers' ) !== false ) {
                    return 1;
                }
                // Mock lookup for unit leaders where unit_name = Orion AND id != 5: returns 1 (another row has Orion)
                return 1;
            }
        };

        $repo = new Unit_Leader_Repository( $wpdb );

        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'Leader mapping for this unit already exists' );

        $repo->update( 5, [
            'unit_name'         => 'Orion',
            'leader_first_name' => 'Jane',
            'leader_last_name'  => 'Smith',
            'leader_email'      => 'jane.smith@example.com',
        ] );
    }

    public function test_delete_leader(): void {
        $wpdb = new class {
            public $prefix = 'wp_';
            public $deleted = [];

            public function delete( string $table, array $where, array $format = [] ): int {
                $this->deleted[] = [ 'table' => $table, 'where' => $where ];
                return 1;
            }
        };

        $repo = new Unit_Leader_Repository( $wpdb );
        $result = $repo->delete( 5 );

        $this->assertTrue( $result );
        $this->assertCount( 1, $wpdb->deleted );
        $this->assertEquals( 'wp_ems_unit_leaders', $wpdb->deleted[0]['table'] );
        $this->assertEquals( 5, $wpdb->deleted[0]['where']['id'] );
    }

    public function test_find_by_unit_name(): void {
        $wpdb = new class {
            public $prefix = 'wp_';
            public function prepare( string $sql, ...$args ): string {
                return vsprintf( str_replace( '%s', "'%s'", str_replace( '%d', '%d', $sql ) ), $args );
            }
            public function get_row( string $query, string $output = 'OBJECT' ) {
                $this->last_query = $query;
                return [
                    'id'                => 10,
                    'unit_name'         => 'Orion',
                    'leader_first_name' => 'John',
                    'leader_last_name'  => 'Doe',
                    'leader_email'      => 'john.doe@example.com',
                ];
            }
        };

        $repo = new Unit_Leader_Repository( $wpdb );
        $row = $repo->find_by_unit_name( 'Orion' );

        $this->assertNotNull( $row );
        $this->assertEquals( 10, $row['id'] );
        $this->assertStringContainsString( "unit_name = 'Orion'", $wpdb->last_query );
    }
}
