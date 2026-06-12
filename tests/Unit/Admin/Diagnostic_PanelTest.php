<?php
namespace EMS\Tests\Unit\Admin;

use EMS\Admin\Diagnostic_Panel;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;

class Diagnostic_PanelTest extends EMSTestCase {

    public function test_shows_no_osm_account_for_local_user(): void {
        Functions\when( 'get_user_meta' )->alias( static function ( $uid, $key, $single ) {
            return $key === 'ems_access_type' ? 'local' : '';
        } );
        Functions\when( 'esc_html__' )->alias( static fn( $t, $d ) => $t );
        Functions\when( 'esc_html' )->alias( static fn( $v ) => $v );

        $html = ( new Diagnostic_Panel() )->get_html( 42 );

        $this->assertStringContainsString( 'No OSM account linked.', $html );
    }

    public function test_shows_no_osm_account_when_no_meta(): void {
        Functions\when( 'get_user_meta' )->alias( static function ( $uid, $key, $single ) {
            return '';
        } );
        Functions\when( 'esc_html__' )->alias( static fn( $t, $d ) => $t );
        Functions\when( 'esc_html' )->alias( static fn( $v ) => $v );

        $html = ( new Diagnostic_Panel() )->get_html( 42 );

        $this->assertStringContainsString( 'No OSM account linked.', $html );
    }

    public function test_shows_access_type_for_osm_user(): void {
        Functions\when( 'get_user_meta' )->alias( static function ( $uid, $key, $single ) {
            return match ( $key ) {
                'ems_access_type' => 'explorer',
                'ems_section_ids' => [ 10001 ],
                'ems_scout_ids'   => [ 30001 ],
                default           => '',
            };
        } );
        Functions\when( 'esc_html__' )->alias( static fn( $t, $d ) => $t );
        Functions\when( 'esc_html' )->alias( static fn( $v ) => $v );

        $html = ( new Diagnostic_Panel() )->get_html( 42 );

        $this->assertStringContainsString( 'explorer', $html );
        $this->assertStringNotContainsString( 'No OSM account linked.', $html );
    }

    public function test_shows_section_ids_for_osm_user(): void {
        Functions\when( 'get_user_meta' )->alias( static function ( $uid, $key, $single ) {
            return match ( $key ) {
                'ems_access_type' => 'leader',
                'ems_section_ids' => [ 10001, 10002 ],
                'ems_scout_ids'   => [],
                default           => '',
            };
        } );
        Functions\when( 'esc_html__' )->alias( static fn( $t, $d ) => $t );
        Functions\when( 'esc_html' )->alias( static fn( $v ) => $v );

        $html = ( new Diagnostic_Panel() )->get_html( 42 );

        $this->assertStringContainsString( '10001', $html );
        $this->assertStringContainsString( '10002', $html );
    }

    public function test_shows_scout_ids_for_parent_user(): void {
        Functions\when( 'get_user_meta' )->alias( static function ( $uid, $key, $single ) {
            return match ( $key ) {
                'ems_access_type' => 'parent',
                'ems_section_ids' => [],
                'ems_scout_ids'   => [ 30001, 30002 ],
                default           => '',
            };
        } );
        Functions\when( 'esc_html__' )->alias( static fn( $t, $d ) => $t );
        Functions\when( 'esc_html' )->alias( static fn( $v ) => $v );

        $html = ( new Diagnostic_Panel() )->get_html( 42 );

        $this->assertStringContainsString( '30001', $html );
        $this->assertStringContainsString( '30002', $html );
    }
}
