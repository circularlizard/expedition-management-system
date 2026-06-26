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

    public function test_link_wp_user_by_email_returns_zero_for_blank_email(): void {
        $wpdb = new class {
            public $prefix  = 'wp_';
            public array $queries = [];
            public function prepare( string $sql, ...$args ): string { return $sql; }
            public function get_results( string $sql, $output = OBJECT ): array { return []; }
            public function query( string $sql ): int { $this->queries[] = $sql; return 0; }
        };

        $repo   = new OSM_Explorer_Repository( $wpdb );
        $result = $repo->link_wp_user_by_email( '', 42 );

        $this->assertSame( 0, $result );
        $this->assertEmpty( $wpdb->queries );
    }

    public function test_link_wp_user_by_email_writes_wp_user_id_when_unlinked(): void {
        $wpdb = new class {
            public $prefix   = 'wp_';
            public array $queries = [];
            public function prepare( string $sql, ...$args ): string {
                return vsprintf( str_replace( ['%s', '%d'], ["'%s'", '%d'], $sql ), $args );
            }
            public function get_results( string $sql, $output = OBJECT ): array {
                return [
                    (object) [ 'scout_id' => 30001, 'wp_user_id' => null ],
                ];
            }
            public function query( string $sql ): int {
                $this->queries[] = $sql;
                return 1;
            }
        };

        $repo   = new OSM_Explorer_Repository( $wpdb );
        $result = $repo->link_wp_user_by_email( 'alice@example.com', 42 );

        $this->assertSame( 1, $result );
        $this->assertCount( 1, $wpdb->queries );
        $this->assertStringContainsString( 'wp_user_id', $wpdb->queries[0] );
        $this->assertStringContainsString( '42', $wpdb->queries[0] );
    }

    public function test_link_wp_user_by_email_is_noop_when_already_linked_to_same_user(): void {
        $wpdb = new class {
            public $prefix = 'wp_';
            public array $queries = [];
            public function prepare( string $sql, ...$args ): string {
                return vsprintf( str_replace( ['%s', '%d'], ["'%s'", '%d'], $sql ), $args );
            }
            public function get_results( string $sql, $output = OBJECT ): array {
                return [
                    (object) [ 'scout_id' => 30001, 'wp_user_id' => 42 ],
                ];
            }
            public function query( string $sql ): int {
                $this->queries[] = $sql;
                return 1;
            }
        };

        $repo   = new OSM_Explorer_Repository( $wpdb );
        $result = $repo->link_wp_user_by_email( 'alice@example.com', 42 );

        $this->assertSame( 0, $result );
        $this->assertEmpty( $wpdb->queries );
    }

    public function test_link_wp_user_by_email_does_not_overwrite_different_user(): void {
        $wpdb = new class {
            public $prefix = 'wp_';
            public array $queries = [];
            public function prepare( string $sql, ...$args ): string {
                return vsprintf( str_replace( ['%s', '%d'], ["'%s'", '%d'], $sql ), $args );
            }
            public function get_results( string $sql, $output = OBJECT ): array {
                return [
                    (object) [ 'scout_id' => 30001, 'wp_user_id' => 99 ],
                ];
            }
            public function query( string $sql ): int {
                $this->queries[] = $sql;
                return 1;
            }
        };

        $repo   = new OSM_Explorer_Repository( $wpdb );
        $result = $repo->link_wp_user_by_email( 'alice@example.com', 42 );

        $this->assertSame( 0, $result );
        $this->assertEmpty( $wpdb->queries, 'Must not UPDATE when already linked to a different user' );
    }

    public function test_link_wp_user_by_email_returns_zero_when_no_matching_explorer(): void {
        $wpdb = new class {
            public $prefix = 'wp_';
            public array $queries = [];
            public function prepare( string $sql, ...$args ): string { return $sql; }
            public function get_results( string $sql, $output = OBJECT ): array { return []; }
            public function query( string $sql ): int { $this->queries[] = $sql; return 0; }
        };

        $repo   = new OSM_Explorer_Repository( $wpdb );
        $result = $repo->link_wp_user_by_email( 'unknown@example.com', 42 );

        $this->assertSame( 0, $result );
        $this->assertEmpty( $wpdb->queries );
    }

    public function test_link_wp_user_by_email_links_all_unlinked_rows_with_same_email(): void {
        $wpdb = new class {
            public $prefix = 'wp_';
            public array $queries = [];
            public function prepare( string $sql, ...$args ): string {
                return vsprintf( str_replace( ['%s', '%d'], ["'%s'", '%d'], $sql ), $args );
            }
            public function get_results( string $sql, $output = OBJECT ): array {
                return [
                    (object) [ 'scout_id' => 30001, 'wp_user_id' => null ],
                    (object) [ 'scout_id' => 30002, 'wp_user_id' => null ],
                ];
            }
            public function query( string $sql ): int {
                $this->queries[] = $sql;
                return 1;
            }
        };

        $repo   = new OSM_Explorer_Repository( $wpdb );
        $result = $repo->link_wp_user_by_email( 'twins@example.com', 42 );

        $this->assertSame( 2, $result );
        $this->assertCount( 2, $wpdb->queries );
    }
}
