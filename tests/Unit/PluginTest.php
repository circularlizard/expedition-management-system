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
        Functions\when( 'is_user_logged_in' )->justReturn( false );
        Functions\when( 'is_admin' )->justReturn( false );
        Functions\when( 'home_url' )->alias( fn( $path = '' ) => 'http://home' . $path );
    }

    public function test_render_signup_banner_shortcode_does_not_update_options(): void {
        $options = [
            'ems_fluent_participant_form_id' => 6,
            'ems_fluent_expedition_form_id'  => 3,
        ];
        Functions\when( 'get_option' )->alias( function( $key, $default = null ) use ( &$options ) {
            return $options[ $key ] ?? $default;
        } );
        $update_calls = [];
        Functions\when( 'update_option' )->alias( function( $key, $val ) use ( &$update_calls ) {
            $update_calls[ $key ] = $val;
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

        $this->assertEmpty( $update_calls );
        $this->assertSame( 6, $options['ems_fluent_participant_form_id'] );
        $this->assertSame( 3, $options['ems_fluent_expedition_form_id'] );
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

    public function test_is_logged_in_parent_or_network_returns_false_if_logged_out(): void {
        Functions\when( 'is_user_logged_in' )->justReturn( false );

        $plugin = new Plugin();
        $this->assertFalse( $plugin->is_logged_in_parent_or_network() );
    }

    public function test_is_logged_in_parent_or_network_returns_false_for_explorer(): void {
        Functions\when( 'is_user_logged_in' )->justReturn( true );
        $user = \Mockery::mock( \WP_User::class );
        $user->roles = [ 'ems_explorer' ];
        Functions\when( 'wp_get_current_user' )->justReturn( $user );

        $plugin = new Plugin();
        $this->assertFalse( $plugin->is_logged_in_parent_or_network() );
    }

    public function test_is_logged_in_parent_or_network_returns_true_for_parent(): void {
        Functions\when( 'is_user_logged_in' )->justReturn( true );
        $user = \Mockery::mock( \WP_User::class );
        $user->roles = [ 'ems_parent' ];
        Functions\when( 'wp_get_current_user' )->justReturn( $user );

        $plugin = new Plugin();
        $this->assertTrue( $plugin->is_logged_in_parent_or_network() );
    }

    public function test_is_logged_in_parent_or_network_returns_true_for_network_member(): void {
        Functions\when( 'is_user_logged_in' )->justReturn( true );
        $user = \Mockery::mock( \WP_User::class );
        $user->roles = [ 'ems_network_member' ];
        Functions\when( 'wp_get_current_user' )->justReturn( $user );

        $plugin = new Plugin();
        $this->assertTrue( $plugin->is_logged_in_parent_or_network() );
    }

    public function test_is_logged_in_parent_or_network_returns_true_for_administrator(): void {
        Functions\when( 'is_user_logged_in' )->justReturn( true );
        $user = \Mockery::mock( \WP_User::class );
        $user->roles = [ 'administrator' ];
        Functions\when( 'wp_get_current_user' )->justReturn( $user );

        $plugin = new Plugin();
        $this->assertTrue( $plugin->is_logged_in_parent_or_network() );
    }

    public function test_restrict_signup_page_access_does_nothing_if_logged_out(): void {
        Functions\when( 'is_user_logged_in' )->justReturn( false );
        Functions\expect( 'get_post' )->never();

        $plugin = new Plugin();
        $plugin->restrict_signup_page_access();
        $this->assertTrue( true );
    }

    public function test_restrict_signup_page_access_does_nothing_if_not_signup_page(): void {
        Functions\when( 'is_user_logged_in' )->justReturn( true );
        
        $post = new \stdClass();
        $post->post_content = 'Some normal page content';
        Functions\when( 'get_post' )->justReturn( $post );
        Functions\when( 'has_shortcode' )->justReturn( false );

        $plugin = new Plugin();

        // Should not add filter
        Functions\expect( 'add_filter' )->never();

        $plugin->restrict_signup_page_access();
        $this->assertTrue( true );
    }

    public function test_restrict_signup_page_access_allows_parent_on_signup_page(): void {
        Functions\when( 'is_user_logged_in' )->justReturn( true );
        
        $user = \Mockery::mock( \WP_User::class );
        $user->roles = [ 'ems_parent' ];
        Functions\when( 'wp_get_current_user' )->justReturn( $user );

        $post = new \stdClass();
        $post->post_content = '[ems_signup_banner]';
        Functions\when( 'get_post' )->justReturn( $post );
        Functions\when( 'has_shortcode' )->justReturn( true );

        $plugin = new Plugin();

        // Should not add filter
        Functions\expect( 'add_filter' )->never();

        $plugin->restrict_signup_page_access();
        $this->assertTrue( true );
    }

    public function test_restrict_signup_page_access_hooks_content_rejection_for_explorer(): void {
        Functions\when( 'is_user_logged_in' )->justReturn( true );
        
        $user = \Mockery::mock( \WP_User::class );
        $user->roles = [ 'ems_explorer' ];
        Functions\when( 'wp_get_current_user' )->justReturn( $user );

        $post = new \stdClass();
        $post->post_content = '[ems_signup_banner]';
        Functions\when( 'get_post' )->justReturn( $post );
        Functions\when( 'has_shortcode' )->justReturn( true );

        $plugin = new Plugin();

        Functions\expect( 'add_filter' )
            ->once()
            ->with( 'the_content', \Mockery::any(), 999 );

        $plugin->restrict_signup_page_access();
        $this->assertTrue( true );
    }
}
