<?php
namespace EMS\Tests\Unit\Admin;

use EMS\Admin\Settings_Page;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;

class Settings_PageTest extends EMSTestCase {

    public function test_register_adds_submenu_under_ems(): void {
        Functions\stubs( [ '__' ] );
        Functions\expect( 'add_submenu_page' )
            ->once()
            ->with( 'ems', \Mockery::any(), \Mockery::any(), 'manage_options', 'ems-settings', \Mockery::any() );

        ( new Settings_Page() )->register();
        $this->addToAssertionCount( 1 );
    }

    public function test_save_settings_stores_mock_mode(): void {
        $stored = [];
        Functions\when( 'update_option' )->alias( static function ( $key, $value ) use ( &$stored ): bool {
            $stored[ $key ] = $value;
            return true;
        } );
        Functions\when( 'esc_url_raw' )->alias( static fn( $v ) => $v );

        ( new Settings_Page() )->save_settings( [ 'ems_api_mode' => 'mock' ] );

        $this->assertSame( 'mock', $stored['ems_api_mode'] );
    }

    public function test_save_settings_stores_live_mode(): void {
        $stored = [];
        Functions\when( 'update_option' )->alias( static function ( $key, $value ) use ( &$stored ): bool {
            $stored[ $key ] = $value;
            return true;
        } );
        Functions\when( 'esc_url_raw' )->alias( static fn( $v ) => $v );

        ( new Settings_Page() )->save_settings( [ 'ems_api_mode' => 'live' ] );

        $this->assertSame( 'live', $stored['ems_api_mode'] );
    }

    public function test_save_settings_invalid_mode_defaults_to_mock(): void {
        $stored = [];
        Functions\when( 'update_option' )->alias( static function ( $key, $value ) use ( &$stored ): bool {
            $stored[ $key ] = $value;
            return true;
        } );
        Functions\when( 'esc_url_raw' )->alias( static fn( $v ) => $v );

        ( new Settings_Page() )->save_settings( [ 'ems_api_mode' => 'not_a_valid_mode' ] );

        $this->assertSame( 'mock', $stored['ems_api_mode'] );
    }

    public function test_save_settings_stores_valid_https_base_url(): void {
        $stored = [];
        Functions\when( 'update_option' )->alias( static function ( $key, $value ) use ( &$stored ): bool {
            $stored[ $key ] = $value;
            return true;
        } );
        Functions\when( 'esc_url_raw' )->alias( static fn( $v ) => $v );

        ( new Settings_Page() )->save_settings( [
            'ems_api_mode'         => 'mock',
            'ems_osm_api_base_url' => 'https://www.onlinescoutmanager.co.uk/api.php',
        ] );

        $this->assertSame( 'https://www.onlinescoutmanager.co.uk/api.php', $stored['ems_osm_api_base_url'] );
    }

    public function test_save_settings_rejects_non_https_base_url(): void {
        $stored = [];
        Functions\when( 'update_option' )->alias( static function ( $key, $value ) use ( &$stored ): bool {
            $stored[ $key ] = $value;
            return true;
        } );
        Functions\when( 'esc_url_raw' )->alias( static fn( $v ) => $v );

        ( new Settings_Page() )->save_settings( [
            'ems_api_mode'         => 'mock',
            'ems_osm_api_base_url' => 'http://www.onlinescoutmanager.co.uk/api.php',
        ] );

        $this->assertArrayNotHasKey( 'ems_osm_api_base_url', $stored );
    }

    public function test_save_settings_empty_url_is_not_stored(): void {
        $stored = [];
        Functions\when( 'update_option' )->alias( static function ( $key, $value ) use ( &$stored ): bool {
            $stored[ $key ] = $value;
            return true;
        } );
        Functions\when( 'esc_url_raw' )->alias( static fn( $v ) => $v );

        ( new Settings_Page() )->save_settings( [
            'ems_api_mode'         => 'mock',
            'ems_osm_api_base_url' => '',
        ] );

        $this->assertArrayNotHasKey( 'ems_osm_api_base_url', $stored );
    }
}
