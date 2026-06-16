<?php
namespace EMS\Tests\Unit\Admin;

use EMS\Admin\Settings_Page;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;

class Settings_PageTest extends EMSTestCase {

    private function stored_capture(): array {
        $stored = [];
        Functions\when( 'update_option' )->alias( static function ( $key, $value ) use ( &$stored ): bool {
            $stored[ $key ] = $value;
            return true;
        } );
        return $stored;
    }

    public function test_register_adds_submenu_under_ems(): void {
        Functions\stubs( [ '__' ] );
        Functions\expect( 'add_submenu_page' )
            ->once()
            ->with( 'ems', \Mockery::any(), \Mockery::any(), 'manage_options', 'ems-settings', \Mockery::any() );

        ( new Settings_Page() )->register();
        $this->addToAssertionCount( 1 );
    }

    // -------------------------------------------------------------------------
    // save_general()
    // -------------------------------------------------------------------------

    public function test_save_general_stores_mock_mode(): void {
        $stored = [];
        Functions\when( 'update_option' )->alias( static function ( $k, $v ) use ( &$stored ) { $stored[$k] = $v; return true; } );

        ( new Settings_Page() )->save_general( [ 'ems_api_mode' => 'mock' ] );

        $this->assertSame( 'mock', $stored['ems_api_mode'] );
    }

    public function test_save_general_stores_live_mode(): void {
        $stored = [];
        Functions\when( 'update_option' )->alias( static function ( $k, $v ) use ( &$stored ) { $stored[$k] = $v; return true; } );

        ( new Settings_Page() )->save_general( [ 'ems_api_mode' => 'live' ] );

        $this->assertSame( 'live', $stored['ems_api_mode'] );
    }

    public function test_save_general_stores_live_auth_only_mode(): void {
        $stored = [];
        Functions\when( 'update_option' )->alias( static function ( $k, $v ) use ( &$stored ) { $stored[$k] = $v; return true; } );

        ( new Settings_Page() )->save_general( [ 'ems_api_mode' => 'live-auth-only' ] );

        $this->assertSame( 'live-auth-only', $stored['ems_api_mode'] );
    }

    public function test_save_general_stores_live_limited_mode(): void {
        $stored = [];
        Functions\when( 'update_option' )->alias( static function ( $k, $v ) use ( &$stored ) { $stored[$k] = $v; return true; } );

        ( new Settings_Page() )->save_general( [ 'ems_api_mode' => 'live-limited' ] );

        $this->assertSame( 'live-limited', $stored['ems_api_mode'] );
    }

    public function test_save_general_invalid_mode_defaults_to_mock(): void {
        $stored = [];
        Functions\when( 'update_option' )->alias( static function ( $k, $v ) use ( &$stored ) { $stored[$k] = $v; return true; } );

        ( new Settings_Page() )->save_general( [ 'ems_api_mode' => 'not_valid' ] );

        $this->assertSame( 'mock', $stored['ems_api_mode'] );
    }

    public function test_save_general_stores_sync_limit(): void {
        $stored = [];
        Functions\when( 'update_option' )->alias( static function ( $k, $v ) use ( &$stored ) { $stored[$k] = $v; return true; } );

        ( new Settings_Page() )->save_general( [ 'ems_api_mode' => 'live-limited', 'ems_sync_limit' => '10' ] );

        $this->assertSame( 10, $stored['ems_sync_limit'] );
    }

    public function test_save_general_sync_limit_minimum_is_one(): void {
        $stored = [];
        Functions\when( 'update_option' )->alias( static function ( $k, $v ) use ( &$stored ) { $stored[$k] = $v; return true; } );

        ( new Settings_Page() )->save_general( [ 'ems_api_mode' => 'mock', 'ems_sync_limit' => '-5' ] );

        $this->assertSame( 1, $stored['ems_sync_limit'] );
    }

    // -------------------------------------------------------------------------
    // save_connection()
    // -------------------------------------------------------------------------

    public function test_save_connection_stores_valid_https_base_url(): void {
        $stored = [];
        Functions\when( 'update_option' )->alias( static function ( $k, $v ) use ( &$stored ) { $stored[$k] = $v; return true; } );
        Functions\when( 'esc_url_raw' )->alias( static fn( $v ) => $v );

        ( new Settings_Page() )->save_connection( [
            'ems_osm_api_base_url' => 'https://www.onlinescoutmanager.co.uk',
        ] );

        $this->assertSame( 'https://www.onlinescoutmanager.co.uk', $stored['ems_osm_api_base_url'] );
    }

    public function test_save_connection_strips_legacy_api_php_suffix(): void {
        $stored = [];
        Functions\when( 'update_option' )->alias( static function ( $k, $v ) use ( &$stored ) { $stored[$k] = $v; return true; } );
        Functions\when( 'esc_url_raw' )->alias( static fn( $v ) => $v );

        ( new Settings_Page() )->save_connection( [
            'ems_osm_api_base_url' => 'https://www.onlinescoutmanager.co.uk/api.php',
        ] );

        $this->assertSame( 'https://www.onlinescoutmanager.co.uk', $stored['ems_osm_api_base_url'] );
    }

    public function test_save_connection_rejects_non_https_base_url(): void {
        $stored = [];
        Functions\when( 'update_option' )->alias( static function ( $k, $v ) use ( &$stored ) { $stored[$k] = $v; return true; } );
        Functions\when( 'esc_url_raw' )->alias( static fn( $v ) => $v );

        ( new Settings_Page() )->save_connection( [
            'ems_osm_api_base_url' => 'http://www.onlinescoutmanager.co.uk/api.php',
        ] );

        $this->assertArrayNotHasKey( 'ems_osm_api_base_url', $stored );
    }

    public function test_save_connection_empty_url_is_not_stored(): void {
        $stored = [];
        Functions\when( 'update_option' )->alias( static function ( $k, $v ) use ( &$stored ) { $stored[$k] = $v; return true; } );
        Functions\when( 'esc_url_raw' )->alias( static fn( $v ) => $v );

        ( new Settings_Page() )->save_connection( [ 'ems_osm_api_base_url' => '' ] );

        $this->assertArrayNotHasKey( 'ems_osm_api_base_url', $stored );
    }

    // -------------------------------------------------------------------------
    // save_sections()
    // -------------------------------------------------------------------------

    public function test_save_sections_stores_checked_ids_from_available_transient(): void {
        $stored = [];
        Functions\when( 'update_option' )->alias( static function ( $k, $v ) use ( &$stored ) { $stored[$k] = $v; return true; } );
        Functions\when( 'get_transient' )->alias( static function ( $key ) {
            if ( $key === 'ems_available_sections' ) {
                return [ 10001 => [ 'name' => 'Silver ESU' ], 10002 => [ 'name' => 'Gold ESU' ] ];
            }
            return false;
        } );
        Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => $v );

        ( new Settings_Page() )->save_sections( [ 'ems_managed_section_ids' => [ '10001' ] ] );

        $this->assertArrayHasKey( 10001, $stored['ems_managed_sections'] );
        $this->assertArrayNotHasKey( 10002, $stored['ems_managed_sections'] );
        $this->assertSame( 'Silver ESU', $stored['ems_managed_sections'][10001]['name'] );
    }

    public function test_save_sections_ignores_ids_not_in_available_transient(): void {
        $stored = [];
        Functions\when( 'update_option' )->alias( static function ( $k, $v ) use ( &$stored ) { $stored[$k] = $v; return true; } );
        Functions\when( 'get_transient' )->alias( static function ( $key ) {
            if ( $key === 'ems_available_sections' ) {
                return [ 10001 => [ 'name' => 'Silver ESU' ] ];
            }
            return false;
        } );
        Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => $v );

        ( new Settings_Page() )->save_sections( [ 'ems_managed_section_ids' => [ '99999' ] ] );

        $this->assertEmpty( $stored['ems_managed_sections'] );
    }

    public function test_save_sections_stores_empty_when_none_checked(): void {
        $stored = [];
        Functions\when( 'update_option' )->alias( static function ( $k, $v ) use ( &$stored ) { $stored[$k] = $v; return true; } );
        Functions\when( 'get_transient' )->alias( static function ( $key ) {
            if ( $key === 'ems_available_sections' ) {
                return [ 10001 => [ 'name' => 'Silver ESU' ] ];
            }
            return false;
        } );

        ( new Settings_Page() )->save_sections( [] );

        $this->assertEmpty( $stored['ems_managed_sections'] );
    }

    public function test_save_sections_does_not_store_extraid(): void {
        $stored = [];
        Functions\when( 'update_option' )->alias( static function ( $k, $v ) use ( &$stored ) { $stored[$k] = $v; return true; } );
        Functions\when( 'get_transient' )->alias( static function ( $key ) {
            if ( $key === 'ems_available_sections' ) {
                return [ 10001 => [ 'name' => 'Silver ESU', 'extraid' => '73848' ] ];
            }
            return false;
        } );
        Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => $v );

        ( new Settings_Page() )->save_sections( [ 'ems_managed_section_ids' => [ '10001' ] ] );

        $this->assertArrayNotHasKey( 'extraid', $stored['ems_managed_sections'][10001] );
    }

    // -------------------------------------------------------------------------
    // save_settings() legacy routing
    // -------------------------------------------------------------------------

    public function test_save_settings_routes_to_general_by_default(): void {
        $stored = [];
        Functions\when( 'update_option' )->alias( static function ( $k, $v ) use ( &$stored ) { $stored[$k] = $v; return true; } );

        ( new Settings_Page() )->save_settings( [ 'ems_api_mode' => 'mock' ] );

        $this->assertSame( 'mock', $stored['ems_api_mode'] );
    }

    public function test_save_settings_routes_to_connection_tab(): void {
        $stored = [];
        Functions\when( 'update_option' )->alias( static function ( $k, $v ) use ( &$stored ) { $stored[$k] = $v; return true; } );
        Functions\when( 'esc_url_raw' )->alias( static fn( $v ) => $v );

        ( new Settings_Page() )->save_settings( [
            'ems_save_connection'  => '1',
            'ems_osm_api_base_url' => 'https://www.onlinescoutmanager.co.uk/api.php',
        ] );

        $this->assertSame( 'https://www.onlinescoutmanager.co.uk', $stored['ems_osm_api_base_url'] );
    }
}
