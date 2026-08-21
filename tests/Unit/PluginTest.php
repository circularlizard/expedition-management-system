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
        Functions\when( 'esc_url' )->alias( fn( $text ) => $text );
        Functions\when( 'site_url' )->alias( fn( $path ) => 'http://site' . $path );
        Functions\when( 'shortcode_atts' )->alias( function( $pairs, $atts, $shortcode = '' ) {
            return array_merge( $pairs, (array) $atts );
        } );
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
            'form_id'     => '10',
            'type'        => 'participant',
            'scout_field' => 'custom_child',
            'unit_field'  => 'custom_unit',
        ]);

        $this->assertSame( 10, $options['ems_fluent_participant_form_id'] );
        $this->assertSame( 'custom_child', $options['ems_participant_form_mappings']['scout_id_field'] );
        $this->assertSame( 'custom_unit', $options['ems_participant_form_mappings']['esu_patrol_field'] );
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
}
