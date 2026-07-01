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
}
