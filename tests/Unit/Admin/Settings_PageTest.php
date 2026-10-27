<?php
namespace EMS\Tests\Unit\Admin;

use EMS\Admin\Settings_Page;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;

class Settings_PageTest extends EMSTestCase {

    protected function setUp(): void {
        parent::setUp();
        global $wpdb;
        $wpdb = (object) [ 'prefix' => 'wp_' ];
    }

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
        $this->assertTrue( \Brain\Monkey\Actions\has( 'admin_init' ) );
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

    public function test_save_general_stores_log_guard_enabled(): void {
        $stored = [];
        Functions\when( 'update_option' )->alias( static function ( $k, $v ) use ( &$stored ) { $stored[$k] = $v; return true; } );

        ( new Settings_Page() )->save_general( [ 'ems_api_mode' => 'mock', 'ems_debug_log_guard' => '1' ] );

        $this->assertSame( 1, $stored['ems_debug_log_guard'] );
    }

    public function test_save_general_stores_log_guard_disabled(): void {
        $stored = [];
        Functions\when( 'update_option' )->alias( static function ( $k, $v ) use ( &$stored ) { $stored[$k] = $v; return true; } );

        ( new Settings_Page() )->save_general( [ 'ems_api_mode' => 'mock' ] ); // absent checkbox

        $this->assertSame( 0, $stored['ems_debug_log_guard'] );
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

    public function test_save_form_mappings_stores_participant_and_expedition_ids(): void {
        $stored = [];
        Functions\when( 'update_option' )->alias( static function ( $k, $v ) use ( &$stored ) { $stored[$k] = $v; return true; } );

        $page = new Settings_Page();
        
        $reflected = new \ReflectionClass(Settings_Page::class);
        $method = $reflected->getMethod('save_form_mappings');
        $method->setAccessible(true);
        
        ob_start();
        $method->invoke( $page, [
            'ems_fluent_participant_form_id' => '8',
            'ems_fluent_expedition_form_id'  => '9',
        ] );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Form configurations saved.', (string) $output );
        $this->assertEquals( 8, $stored['ems_fluent_participant_form_id'] );
        $this->assertEquals( 9, $stored['ems_fluent_expedition_form_id'] );
    }

    public function test_save_form_mappings_stores_field_mappings(): void {
        $stored = [];
        Functions\when( 'update_option' )->alias( static function ( $k, $v ) use ( &$stored ) { $stored[$k] = $v; return true; } );

        $page = new Settings_Page();
        
        $reflected = new \ReflectionClass(Settings_Page::class);
        $method = $reflected->getMethod('save_form_mappings');
        $method->setAccessible(true);
        
        ob_start();
        $method->invoke( $page, [
            'ems_fluent_participant_form_id' => '8',
            'ems_fluent_expedition_form_id'  => '9',
            'ems_participant_form_mappings'  => [
                'scout_id_field' => 'custom_scout_field_p',
            ],
            'ems_expedition_form_mappings'   => [
                'silver_practice_dates_field' => 'custom_practice_field_e',
            ],
        ] );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Form configurations saved.', (string) $output );
        $this->assertEquals( 8, $stored['ems_fluent_participant_form_id'] );
        $this->assertEquals( 9, $stored['ems_fluent_expedition_form_id'] );
        $this->assertEquals( [ 'scout_id_field' => 'custom_scout_field_p' ], $stored['ems_participant_form_mappings'] );
        $this->assertEquals( [ 'silver_practice_dates_field' => 'custom_practice_field_e' ], $stored['ems_expedition_form_mappings'] );
    }

    public function test_save_access_control_saves_settings(): void {
        $stored = [];
        Functions\when( 'update_option' )->alias( static function ( $k, $v ) use ( &$stored ) { $stored[$k] = $v; return true; } );

        $page = new Settings_Page();
        
        $reflected = new \ReflectionClass(Settings_Page::class);
        $method = $reflected->getMethod('save_access_control');
        $method->setAccessible(true);
        
        ob_start();
        $method->invoke( $page, [
            'ems_page_roles'        => [
                '42' => [ 'ems_explorer' ],
                '43' => [ 'ems_leader' ],
            ],
            'ems_protect_tutor_lms' => '1',
        ] );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Access control settings saved.', (string) $output );
        $this->assertEquals( [
            42 => [ 'ems_explorer' ],
            43 => [ 'ems_leader' ],
        ], $stored['ems_page_roles'] );
        $this->assertTrue( $stored['ems_protect_tutor_lms'] );
    }

    public function test_save_access_control_sanitizes_empty_inputs(): void {
        $stored = [];
        Functions\when( 'update_option' )->alias( static function ( $k, $v ) use ( &$stored ) { $stored[$k] = $v; return true; } );

        $page = new Settings_Page();
        
        $reflected = new \ReflectionClass(Settings_Page::class);
        $method = $reflected->getMethod('save_access_control');
        $method->setAccessible(true);
        
        ob_start();
        $method->invoke( $page, [] );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Access control settings saved.', (string) $output );
        $this->assertEquals( [], $stored['ems_page_roles'] );
        $this->assertFalse( $stored['ems_protect_tutor_lms'] );
    }

    public function test_register_hooks_admin_init_for_export(): void {
        Functions\stubs( [ '__' ] );
        Functions\when( 'add_submenu_page' )->justReturn( 'hook' );

        $page = new Settings_Page();
        $page->register();

        $this->assertTrue( \Brain\Monkey\Actions\has( 'admin_init' ) );
    }

    public function test_resync_fluent_forms_outputs_summary_notice(): void {
        $sync_mock = \Mockery::mock( \EMS\Integrations\Fluent_Forms_Sync::class );
        $sync_mock->shouldReceive( 'resync_existing_signups' )
            ->once()
            ->andReturn( [
                'updated'  => 4,
                'archived' => 2,
            ] );

        $page = new Settings_Page( $sync_mock );

        ob_start();
        $page->handle_resync_fluent_forms();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString( '4', $output );
        $this->assertStringContainsString( '2', $output );
        $this->assertStringContainsString( 'archived', strtolower( $output ) );
    }

    public function test_save_leader_invite_stores_subject_and_body(): void {
        $stored = [];
        Functions\when( 'update_option' )->alias( static function ( $k, $v ) use ( &$stored ) { $stored[$k] = $v; return true; } );

        $page = new Settings_Page();
        ob_start();
        $page->save_leader_invite( [
            'ems_leader_invite_email_subject' => 'Custom Subject for {{unit_name}}',
            'ems_leader_invite_email_body'    => 'Custom Body for {{dashboard_url}}',
        ] );
        $output = (string) ob_get_clean();

        $this->assertStringContainsString( 'Leader invitation email settings saved.', $output );
        $this->assertSame( 'Custom Subject for {{unit_name}}', $stored['ems_leader_invite_email_subject'] );
        $this->assertSame( 'Custom Body for {{dashboard_url}}', $stored['ems_leader_invite_email_body'] );
    }

    public function test_save_settings_routes_save_leader_invite(): void {
        $stored = [];
        Functions\when( 'update_option' )->alias( static function ( $k, $v ) use ( &$stored ) { $stored[$k] = $v; return true; } );

        $page = new Settings_Page();
        ob_start();
        $page->save_settings( [
            'ems_save_leader_invite'          => '1',
            'ems_leader_invite_email_subject' => 'Routed Subject',
            'ems_leader_invite_email_body'    => 'Routed Body',
        ] );
        ob_get_clean();

        $this->assertSame( 'Routed Subject', $stored['ems_leader_invite_email_subject'] );
        $this->assertSame( 'Routed Body', $stored['ems_leader_invite_email_body'] );
    }

    public function test_render_leader_invite_tab_outputs_fields_and_placeholders(): void {
        Functions\when( 'wp_nonce_field' )->justReturn( '' );
        Functions\when( 'selected' )->justReturn( '' );
        Functions\when( 'checked' )->justReturn( '' );
        Functions\when( 'get_option' )->alias( function( $k, $default = false ) {
            if ( $k === 'ems_leader_invite_email_subject' ) {
                return 'Existing Subject';
            }
            if ( $k === 'ems_leader_invite_email_body' ) {
                return 'Existing Body with {{unit_name}}';
            }
            return $default;
        } );
        Functions\when( 'esc_textarea' )->alias( fn( $v ) => $v );

        $_GET['tab'] = 'leader_invite';
        $page = new Settings_Page();
        try {
            ob_start();
            $page->render();
            $output = (string) ob_get_clean();
        } finally {
            unset( $_GET['tab'] );
        }

        $this->assertStringContainsString( 'tab=leader_invite', $output );
        $this->assertStringContainsString( 'nav-tab-active', $output );
        $this->assertStringContainsString( 'name="ems_leader_invite_email_subject"', $output );
        $this->assertStringContainsString( 'Existing Subject', $output );
        $this->assertStringContainsString( 'name="ems_leader_invite_email_body"', $output );
        $this->assertStringContainsString( 'Existing Body with {{unit_name}}', $output );
        $this->assertStringContainsString( 'name="ems_save_leader_invite"', $output );
        $this->assertStringContainsString( '{{unit_name}}', $output );
        $this->assertStringContainsString( '{{dashboard_url}}', $output );
    }

    public function test_render_access_control_tab_no_longer_contains_leader_invite_fields(): void {
        Functions\when( 'wp_nonce_field' )->justReturn( '' );
        Functions\when( 'get_pages' )->justReturn( [] );
        Functions\when( 'checked' )->justReturn( '' );
        Functions\when( 'selected' )->justReturn( '' );
        Functions\when( 'esc_textarea' )->alias( fn( $v ) => $v );
        Functions\when( 'translate_user_role' )->alias( fn( $v ) => $v );
        $mock_roles = new class {
            public function get_names(): array {
                return [ 'ems_leader' => 'Leader', 'ems_explorer' => 'Explorer' ];
            }
        };
        Functions\when( 'wp_roles' )->justReturn( $mock_roles );
        Functions\when( 'get_option' )->alias( function( $k, $default = false ) {
            if ( $k === 'ems_page_roles' ) {
                return [];
            }
            if ( $k === 'ems_protect_tutor_lms' ) {
                return false;
            }
            if ( $k === 'ems_leader_dashboard_page_id' ) {
                return 0;
            }
            return $default;
        } );

        $_GET['tab'] = 'access_control';
        $page = new Settings_Page();
        try {
            ob_start();
            $page->render();
            $output = (string) ob_get_clean();
        } finally {
            unset( $_GET['tab'] );
        }

        $this->assertStringContainsString( 'name="ems_save_access_control"', $output );
        $this->assertStringNotContainsString( 'name="ems_leader_invite_email_subject"', $output );
        $this->assertStringNotContainsString( 'name="ems_leader_invite_email_body"', $output );
    }
}
