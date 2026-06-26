<?php
namespace EMS\Tests\Unit\Data;

use EMS\Data\OSM_Explorer_Repository;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;

class OSM_Explorer_RepositoryTest extends EMSTestCase {

    public function test_update_first_aid_level_accepts_allowed_values(): void {
        $wpdb = new class {
            public $prefix = 'wp_';
            public function prepare( string $sql, ...$args ): string {
                return vsprintf( str_replace( '%s', "'%s'", str_replace( '%d', '%d', $sql ) ), $args );
            }
            public function query( string $sql ) {
                $this->last_query = $sql;
                return 1;
            }
        };

        $repo = new OSM_Explorer_Repository( $wpdb );
        $result = $repo->update_first_aid_level( 30001, 'first_response' );

        $this->assertTrue( $result );
        $this->assertStringContainsString( "UPDATE wp_ems_osm_explorers", $wpdb->last_query );
        $this->assertStringContainsString( "first_aid_level = 'first_response'", $wpdb->last_query );
        $this->assertStringContainsString( 'WHERE scout_id = 30001', $wpdb->last_query );
    }

    public function test_update_first_aid_level_rejects_invalid_value(): void {
        $wpdb = new class {
            public $prefix = 'wp_';
            public function prepare( string $sql, ...$args ): string {
                return vsprintf( str_replace( '%s', "'%s'", str_replace( '%d', '%d', $sql ) ), $args );
            }
            public function query( string $sql ) {
                return 1;
            }
        };

        $repo = new OSM_Explorer_Repository( $wpdb );
        $result = $repo->update_first_aid_level( 30001, 'doctor' );

        $this->assertFalse( $result );
    }

    public function test_update_first_aid_level_stamps_last_local_update_at(): void {
        $wpdb = new class {
            public $prefix = 'wp_';
            public function prepare( string $sql, ...$args ): string {
                return vsprintf( str_replace( '%s', "'%s'", str_replace( '%d', '%d', $sql ) ), $args );
            }
            public function query( string $sql ) {
                $this->last_query = $sql;
                return 1;
            }
        };

        $repo = new OSM_Explorer_Repository( $wpdb );
        $repo->update_first_aid_level( 30001, 'first_response' );

        $this->assertStringContainsString( 'last_local_update_at', $wpdb->last_query );
    }
}
