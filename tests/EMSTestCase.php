<?php
namespace EMS\Tests;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class EMSTestCase extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\stubs( [ 'delete_transient', 'get_transient', 'set_transient', 'esc_html__', 'esc_html_e', '__', 'esc_attr_e', 'esc_attr__', 'admin_url', 'sanitize_text_field', 'sanitize_email', 'sanitize_textarea_field', 'esc_url_raw', 'update_option', 'current_time', 'get_current_user_id', 'get_users', 'wp_unslash' ] );
        Functions\when( 'wp_unslash' )->alias( fn( $text ) => $text );
        Functions\when( 'esc_html__' )->alias( fn( $text ) => $text );
        Functions\when( 'esc_html_e' )->alias( fn( $text ) => $text );
        Functions\when( '__' )->alias( fn( $text ) => $text );
        Functions\when( 'sanitize_text_field' )->alias( fn( $text ) => $text );
        Functions\when( 'sanitize_email' )->alias( fn( $text ) => $text );
        Functions\when( 'sanitize_textarea_field' )->alias( fn( $text ) => $text );
        Functions\when( 'esc_url_raw' )->alias( fn( $text ) => $text );
        Functions\when( 'current_time' )->justReturn( '2026-06-13 20:00:00' );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'wp_get_session_token' )->justReturn( 'mock-session-token' );
        Functions\when( 'wp_die' )->alias( function( $message ) {
            throw new \Exception( $message );
        } );
        Functions\when( 'wp_safe_remote_get' )->alias( function( $url, $args = null ) {
            if ( func_num_args() > 1 ) {
                return wp_remote_get( $url, $args );
            }
            return wp_remote_get( $url );
        } );
        Functions\when( 'wp_safe_remote_post' )->alias( function( $url, $args = null ) {
            if ( func_num_args() > 1 ) {
                return wp_remote_post( $url, $args );
            }
            return wp_remote_post( $url );
        } );

        global $wpdb;
        $wpdb = null;
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb = null;

        // Reset all properties on the test class instance to prevent Mockery mock memory leakage / destructor crashes.
        // We do this BEFORE closing Mockery/Monkey so that destructors run while the container is still open.
        $refl = new \ReflectionClass( $this );
        foreach ( $refl->getProperties() as $prop ) {
            if ( ! $prop->isStatic() && ! str_starts_with( $prop->getDeclaringClass()->getName(), 'PHPUnit\\' ) ) {
                $name = $prop->getName();
                $declaringClass = $prop->getDeclaringClass()->getName();
                $unsetter = \Closure::bind( function( $name ) {
                    unset( $this->$name );
                }, $this, $declaringClass );
                $unsetter( $name );
            }
        }

        Monkey\tearDown();
        if ( class_exists( '\Mockery' ) ) {
            \Mockery::close();
        }

        parent::tearDown();
    }
}
