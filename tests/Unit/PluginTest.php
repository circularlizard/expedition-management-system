<?php
namespace EMS\Tests\Unit;

use EMS\Plugin;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;

class PluginTest extends EMSTestCase {
    protected function setUp(): void {
        parent::setUp();
        global $wpdb;
        $wpdb = (object) [ 'prefix' => 'wp_' ];
        Functions\stubs( [ 'add_shortcode' ] );
        Functions\when( 'esc_attr' )->alias( fn( $text ) => $text );
        Functions\when( 'esc_html' )->alias( fn( $text ) => $text );
        Functions\when( 'esc_url' )->alias( fn( $text ) => $text );
        Functions\when( 'site_url' )->alias( fn( $path ) => 'http://site' . $path );
        Functions\when( 'shortcode_atts' )->alias( function( $pairs, $atts, $shortcode = '' ) {
            return array_merge( $pairs, (array) $atts );
        } );
        Functions\when( 'get_option' )->alias( fn( $key, $default = null ) => $default );
        Functions\stubs( [ 'update_option', 'wp_enqueue_style' ] );
    }

    public function test_render_signup_banner_shortcode_updates_options(): void {
        $options = [];
        Functions\when( 'get_option' )->alias( function( $key, $default = null ) use ( &$options ) {
            return $options[ $key ] ?? $default;
        } );
        Functions\when( 'update_option' )->alias( function( $key, $val ) use ( &$options ) {
            $options[ $key ] = $val;
            return true;
        } );
        Functions\when( 'wp_enqueue_style' )->justReturn( true );
        Functions\when( 'is_user_logged_in' )->justReturn( false );
        Functions\when( 'wp_login_url' )->justReturn( 'http://login' );
        Functions\when( 'get_permalink' )->justReturn( 'http://current' );

        $plugin = new Plugin();
        $output = $plugin->render_signup_banner_shortcode([
            'form_id'            => '10',
            'type'               => 'participant',
            'scout_field'        => 'custom_child',
            'unit_field'         => 'custom_unit',
            'parent_email_field' => 'custom_parent_email',
        ]);

        $this->assertSame( 10, $options['ems_fluent_participant_form_id'] );
        $this->assertSame( 'custom_child', $options['ems_participant_form_mappings']['scout_id_field'] );
        $this->assertSame( 'custom_unit', $options['ems_participant_form_mappings']['esu_patrol_field'] );
        $this->assertSame( 'custom_parent_email', $options['ems_participant_form_mappings']['parent_email_field'] );
    }

    public function test_render_signup_banner_shortcode_logged_in(): void {
        Functions\when( 'get_option' )->alias( fn( $key, $default = null ) => $default );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'wp_enqueue_style' )->justReturn( true );
        Functions\when( 'is_user_logged_in' )->justReturn( true );

        $plugin = new Plugin();
        $output = $plugin->render_signup_banner_shortcode([
            'form_id'     => '6',
            'type'        => 'participant',
            'scout_field' => 'signup_child',
            'unit_field'  => 'signup_unit',
        ]);

        $this->assertStringContainsString( '<style>', $output );
        $this->assertStringContainsString( 'display: none !important;', $output );
        $this->assertStringContainsString( 'signup_child_name', $output );
        $this->assertStringNotContainsString( 'Speed up your DofE registration', $output );
    }

    public function test_render_signup_banner_shortcode_logged_out(): void {
        Functions\when( 'get_option' )->alias( fn( $key, $default = null ) => $default );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'wp_enqueue_style' )->justReturn( true );
        Functions\when( 'is_user_logged_in' )->justReturn( false );
        Functions\when( 'wp_login_url' )->alias( fn($url) => "http://login?redirect=" . urlencode($url) );
        Functions\when( 'get_permalink' )->justReturn( 'http://current' );

        $plugin = new Plugin();
        $output = $plugin->render_signup_banner_shortcode([
            'form_id'     => '6',
            'type'        => 'participant',
            'scout_field' => 'signup_child',
            'unit_field'  => 'signup_unit',
        ]);

        $this->assertStringContainsString( '<style>', $output );
        $this->assertStringContainsString( 'signup_child', $output );
        $this->assertStringContainsString( 'Speed up your DofE registration', $output );
        $this->assertStringContainsString( 'http://login?redirect=http%3A%2F%2Fcurrent', $output );
        $this->assertStringContainsString( 'oauth-login-button-container', $output );
        $this->assertStringContainsString( 'oauth-login-button--osm', $output );
        $this->assertStringContainsString( 'osm-logo-wo.webp', $output );
        $this->assertStringContainsString( 'Login with OSM', $output );
    }

    public function test_render_signup_banner_shortcode_custom_text(): void {
        Functions\when( 'get_option' )->alias( fn( $key, $default = null ) => $default );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'wp_enqueue_style' )->justReturn( true );
        Functions\when( 'is_user_logged_in' )->justReturn( false );
        Functions\when( 'wp_login_url' )->justReturn( 'http://login' );
        Functions\when( 'get_permalink' )->justReturn( 'http://current' );

        $plugin = new Plugin();
        $output = $plugin->render_signup_banner_shortcode([
            'headline' => 'Custom Headline Text',
            'message'  => 'Custom Message Text Goes Here',
        ]);

        $this->assertStringContainsString( 'Custom Headline Text', $output );
        $this->assertStringContainsString( 'Custom Message Text Goes Here', $output );
    }

    public function test_enforce_user_login_role_restrictions_allows_administrator(): void {
        $user = \Mockery::mock( \WP_User::class );
        $user->roles = [ 'administrator' ];

        $plugin = new Plugin();
        $plugin->enforce_user_login_role_restrictions( 'admin_username', $user );

        // If it returns without exception/wp_die, it passed!
        $this->assertTrue( true );
    }

    public function test_enforce_user_login_role_restrictions_allows_parent(): void {
        $user = \Mockery::mock( \WP_User::class );
        $user->roles = [ 'ems_parent' ];

        $plugin = new Plugin();
        $plugin->enforce_user_login_role_restrictions( 'parent_username', $user );

        $this->assertTrue( true );
    }

    public function test_enforce_user_login_role_restrictions_allows_network_member(): void {
        $user = \Mockery::mock( \WP_User::class );
        $user->roles = [ 'ems_network_member' ];

        $plugin = new Plugin();
        $plugin->enforce_user_login_role_restrictions( 'network_username', $user );

        $this->assertTrue( true );
    }

    public function test_enforce_user_login_role_restrictions_denies_other_roles(): void {
        $user = \Mockery::mock( \WP_User::class );
        $user->roles = [ 'ems_explorer' ];

        Functions\expect( 'wp_logout' )->once();

        $plugin = new Plugin();

        $this->expectException( \Exception::class );
        $plugin->enforce_user_login_role_restrictions( 'explorer_username', $user );
    }
}
