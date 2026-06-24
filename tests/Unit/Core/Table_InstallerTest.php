<?php
namespace EMS\Tests\Unit\Core;

use EMS\Core\Table_Installer;
use EMS\Tests\EMSTestCase;

class Table_InstallerTest extends EMSTestCase {
    public function test_generate_sql_contains_team_members_columns(): void {
        $installer = new Table_Installer();
        $sql = $installer->generate_sql( 'wp_', '' );

        $team_members_sql = null;
        foreach ( $sql as $statement ) {
            if ( strpos( $statement, 'ems_team_members' ) !== false ) {
                $team_members_sql = $statement;
                break;
            }
        }

        $this->assertNotNull( $team_members_sql );
        $this->assertStringContainsString( 'team_post_id', $team_members_sql );
        $this->assertStringContainsString( 'scout_id', $team_members_sql );
        $this->assertStringContainsString( 'KEY idx_scout_id', $team_members_sql );
        $this->assertStringContainsString( 'user_id', $team_members_sql );
        $this->assertStringContainsString( 'added_by', $team_members_sql );
        $this->assertStringContainsString( 'added_at', $team_members_sql );
        $this->assertStringContainsString( 'KEY idx_team_post_id', $team_members_sql );
        $this->assertStringContainsString( 'KEY idx_user_id', $team_members_sql );
    }

    public function test_generate_sql_contains_all_tables(): void {
        $installer = new Table_Installer();
        $sql = $installer->generate_sql( 'wp_', '' );

        $this->assertCount( 7, $sql );

        $all_sql = implode( ' ', $sql );
        $this->assertStringContainsString( 'ems_team_members', $all_sql );
        $this->assertStringContainsString( 'ems_volunteer_availability', $all_sql );
        $this->assertStringContainsString( 'ems_route_submissions', $all_sql );
        $this->assertStringContainsString( 'ems_osm_explorers', $all_sql );
        $this->assertStringContainsString( 'ems_osm_events', $all_sql );
        $this->assertStringContainsString( 'ems_osm_event_attendance', $all_sql );
        $this->assertStringContainsString( 'ems_osm_patrols', $all_sql );
    }

    public function test_generate_sql_with_charset(): void {
        $installer = new Table_Installer();
        $sql = $installer->generate_sql( '', 'DEFAULT CHARSET=utf8mb4' );

        $all_sql = implode( ' ', $sql );
        $this->assertStringContainsString( 'DEFAULT CHARSET=utf8mb4', $all_sql );
    }

    public function test_get_table_names_returns_correct_prefix(): void {
        global $wpdb;
        $wpdb = (object) [
            'prefix' => 'wp_',
        ];

        $names = ( new Table_Installer() )->get_table_names();

        $this->assertEquals( 'wp_ems_team_members', $names['team_members'] );
        $this->assertEquals( 'wp_ems_volunteer_availability', $names['volunteer_availability'] );
        $this->assertEquals( 'wp_ems_route_submissions', $names['route_submissions'] );
        $this->assertEquals( 'wp_ems_osm_explorers', $names['osm_explorers'] );
        $this->assertEquals( 'wp_ems_osm_events', $names['osm_events'] );
        $this->assertEquals( 'wp_ems_osm_event_attendance', $names['osm_event_attendance'] );
        $this->assertEquals( 'wp_ems_osm_patrols', $names['osm_patrols'] );
    }

    public function test_route_submissions_includes_status_and_feedback(): void {
        $installer = new Table_Installer();
        $sql = $installer->generate_sql( '', '' );

        $route_sql = null;
        foreach ( $sql as $statement ) {
            if ( strpos( $statement, 'ems_route_submissions' ) !== false ) {
                $route_sql = $statement;
                break;
            }
        }

        $this->assertNotNull( $route_sql );
        $this->assertStringContainsString( 'status', $route_sql );
        $this->assertStringContainsString( "DEFAULT 'pending'", $route_sql );
        $this->assertStringContainsString( 'feedback', $route_sql );
        $this->assertStringContainsString( 'version', $route_sql );
    }

    public function test_osm_explorers_has_scout_id_unique_key(): void {
        $installer = new Table_Installer();
        $sql = $installer->generate_sql( '', '' );

        $explorers_sql = null;
        foreach ( $sql as $statement ) {
            if ( strpos( $statement, 'ems_osm_explorers' ) !== false ) {
                $explorers_sql = $statement;
                break;
            }
        }

        $this->assertNotNull( $explorers_sql );
        $this->assertStringContainsString( 'scout_id', $explorers_sql );
        $this->assertStringContainsString( 'wp_user_id', $explorers_sql );
        $this->assertStringContainsString( 'section_id', $explorers_sql );
        $this->assertStringContainsString( 'first_aid_level', $explorers_sql );
        $this->assertStringContainsString( "DEFAULT 'none'", $explorers_sql );
        $this->assertStringContainsString( 'UNIQUE KEY idx_scout_id', $explorers_sql );
    }

    public function test_volunteer_availability_includes_indexes(): void {
        $installer = new Table_Installer();
        $sql = $installer->generate_sql( '', '' );

        $avail_sql = null;
        foreach ( $sql as $statement ) {
            if ( strpos( $statement, 'ems_volunteer_availability' ) !== false ) {
                $avail_sql = $statement;
                break;
            }
        }

        $this->assertNotNull( $avail_sql );
        $this->assertStringContainsString( 'KEY idx_user_expedition', $avail_sql );
        $this->assertStringContainsString( 'KEY idx_date', $avail_sql );
    }
}
