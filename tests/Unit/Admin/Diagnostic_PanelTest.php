<?php
namespace EMS\Tests\Unit\Admin;

use EMS\Admin\Diagnostic_Panel;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;

class Diagnostic_PanelTest extends EMSTestCase {

    private function stub_wp_fns(): void {
        Functions\when( 'esc_html__' )->alias( static fn( $t, $d ) => $t );
        Functions\when( 'esc_html' )->alias( static fn( $v ) => $v );
        Functions\when( 'esc_attr' )->alias( static fn( $v ) => $v );
    }

    // -------------------------------------------------------------------------
    // get_system_html()
    // -------------------------------------------------------------------------

    private function make_wpdb_mock( $count = 0 ) {
        global $wpdb;
        $wpdb         = \Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive( 'get_var' )->andReturn( (string) $count );
        return $wpdb;
    }

    public function test_system_html_shows_api_mode(): void {
        $this->stub_wp_fns();
        Functions\when( 'get_option' )->alias( static function ( $key, $default = false ) {
            return match ( $key ) {
                'ems_api_mode'         => 'mock',
                'ems_osm_client_id'    => '',
                'ems_managed_sections' => [],
                'ems_osm_last_sync'    => '',
                default                => $default,
            };
        } );
        Functions\when( 'get_transient' )->justReturn( false );
        $this->make_wpdb_mock();

        $html = ( new Diagnostic_Panel() )->get_system_html();

        $this->assertStringContainsString( 'mock', $html );
        $this->assertStringContainsString( 'API Mode', $html );
    }

    public function test_system_html_shows_client_id_not_configured(): void {
        $this->stub_wp_fns();
        Functions\when( 'get_option' )->alias( static function ( $key, $default = false ) {
            return match ( $key ) {
                'ems_api_mode'         => 'live',
                'ems_osm_client_id'    => '',
                'ems_managed_sections' => [],
                'ems_osm_last_sync'    => '',
                default                => $default,
            };
        } );
        Functions\when( 'get_transient' )->justReturn( false );
        $this->make_wpdb_mock();

        $html = ( new Diagnostic_Panel() )->get_system_html();

        $this->assertStringContainsString( 'No', $html );
        $this->assertStringNotContainsString( 'color:green', $html );
    }

    public function test_system_html_shows_client_id_configured(): void {
        $this->stub_wp_fns();
        Functions\when( 'get_option' )->alias( static function ( $key, $default = false ) {
            return match ( $key ) {
                'ems_api_mode'         => 'live',
                'ems_osm_client_id'    => 'abc123',
                'ems_managed_sections' => [],
                'ems_osm_last_sync'    => '',
                default                => $default,
            };
        } );
        Functions\when( 'get_transient' )->justReturn( false );
        $this->make_wpdb_mock( 5 );

        $html = ( new Diagnostic_Panel() )->get_system_html();

        $this->assertStringContainsString( 'color:green', $html );
        $this->assertStringContainsString( 'Yes', $html );
    }

    public function test_system_html_shows_never_when_no_last_sync(): void {
        $this->stub_wp_fns();
        Functions\when( 'get_option' )->alias( static function ( $key, $default = false ) {
            return match ( $key ) {
                'ems_api_mode'         => 'mock',
                'ems_osm_client_id'    => '',
                'ems_managed_sections' => [],
                'ems_osm_last_sync'    => '',
                default                => $default,
            };
        } );
        Functions\when( 'get_transient' )->justReturn( false );
        $this->make_wpdb_mock();

        $html = ( new Diagnostic_Panel() )->get_system_html();

        $this->assertStringContainsString( 'Never', $html );
    }

    public function test_system_html_shows_db_row_counts(): void {
        $this->stub_wp_fns();
        Functions\when( 'get_option' )->alias( static function ( $key, $default = false ) {
            return match ( $key ) {
                'ems_api_mode'         => 'mock',
                'ems_osm_client_id'    => '',
                'ems_managed_sections' => [],
                'ems_osm_last_sync'    => '',
                default                => $default,
            };
        } );
        Functions\when( 'get_transient' )->justReturn( false );

        global $wpdb;
        $wpdb         = \Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive( 'get_var' )->andReturnValues( [ '42', '3', '127' ] );

        $html = ( new Diagnostic_Panel() )->get_system_html();

        $this->assertStringContainsString( '42', $html );
        $this->assertStringContainsString( '127', $html );
    }

    // -------------------------------------------------------------------------
    // get_user_html() / get_html() backward compat
    // -------------------------------------------------------------------------

    public function test_user_html_shows_no_osm_account_for_local_user(): void {
        Functions\when( 'get_user_meta' )->alias( static function ( $uid, $key, $single ) {
            return $key === 'ems_access_type' ? 'local' : '';
        } );
        $this->stub_wp_fns();

        $html = ( new Diagnostic_Panel() )->get_user_html( 42 );

        $this->assertStringContainsString( 'No OSM account linked', $html );
    }

    public function test_user_html_shows_no_osm_account_when_no_meta(): void {
        Functions\when( 'get_user_meta' )->justReturn( '' );
        $this->stub_wp_fns();

        $html = ( new Diagnostic_Panel() )->get_user_html( 42 );

        $this->assertStringContainsString( 'No OSM account linked', $html );
    }

    public function test_user_html_shows_access_type_for_osm_user(): void {
        Functions\when( 'get_user_meta' )->alias( static function ( $uid, $key, $single ) {
            return match ( $key ) {
                'ems_access_type' => 'explorer',
                'ems_section_ids' => [ 10001 ],
                'ems_scout_ids'   => [ 30001 ],
                default           => '',
            };
        } );
        $this->stub_wp_fns();

        $html = ( new Diagnostic_Panel() )->get_user_html( 42 );

        $this->assertStringContainsString( 'explorer', $html );
        $this->assertStringNotContainsString( 'No OSM account linked', $html );
    }

    public function test_user_html_shows_section_ids(): void {
        Functions\when( 'get_user_meta' )->alias( static function ( $uid, $key, $single ) {
            return match ( $key ) {
                'ems_access_type' => 'leader',
                'ems_section_ids' => [ 10001, 10002 ],
                'ems_scout_ids'   => [],
                default           => '',
            };
        } );
        $this->stub_wp_fns();

        $html = ( new Diagnostic_Panel() )->get_user_html( 42 );

        $this->assertStringContainsString( '10001', $html );
        $this->assertStringContainsString( '10002', $html );
    }

    public function test_user_html_shows_scout_ids_for_parent(): void {
        Functions\when( 'get_user_meta' )->alias( static function ( $uid, $key, $single ) {
            return match ( $key ) {
                'ems_access_type' => 'parent',
                'ems_section_ids' => [],
                'ems_scout_ids'   => [ 30001, 30002 ],
                default           => '',
            };
        } );
        $this->stub_wp_fns();

        $html = ( new Diagnostic_Panel() )->get_user_html( 42 );

        $this->assertStringContainsString( '30001', $html );
        $this->assertStringContainsString( '30002', $html );
    }

    public function test_get_html_is_alias_for_get_user_html(): void {
        Functions\when( 'get_user_meta' )->alias( static function ( $uid, $key, $single ) {
            return $key === 'ems_access_type' ? 'local' : '';
        } );
        $this->stub_wp_fns();

        $panel = new Diagnostic_Panel();
        $this->assertSame( $panel->get_user_html( 42 ), $panel->get_html( 42 ) );
    }
}
