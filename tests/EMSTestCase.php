<?php
namespace EMS\Tests;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class EMSTestCase extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\stubs( [ 'delete_transient', 'get_transient', 'set_transient', 'esc_html__', 'esc_html_e', '__', 'esc_attr_e', 'esc_attr__', 'admin_url', 'sanitize_text_field', 'esc_url_raw', 'update_option', 'current_time', 'get_current_user_id', 'get_users' ] );
        Functions\when( 'esc_html__' )->alias( fn( $text ) => $text );
        Functions\when( 'esc_html_e' )->alias( fn( $text ) => $text );
        Functions\when( '__' )->alias( fn( $text ) => $text );
        Functions\when( 'sanitize_text_field' )->alias( fn( $text ) => $text );
        Functions\when( 'esc_url_raw' )->alias( fn( $text ) => $text );
        Functions\when( 'current_time' )->justReturn( '2026-06-13 20:00:00' );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'wp_die' )->alias( function( $message ) {
            throw new \Exception( $message );
        } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }
}
