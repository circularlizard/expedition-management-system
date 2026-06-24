<?php
namespace EMS\Tests\Unit\Data;

use EMS\Data\Expedition_Repository;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;

class Expedition_RepositoryTest extends EMSTestCase {
    public function test_create_expedition_with_valid_fields(): void {
        Functions\when( 'get_posts' )->alias(
            static function ( $args ) {
                return [];
            }
        );
        Functions\when( 'wp_insert_post' )->alias(
            static function ( $post ) {
                return 10;
            }
        );
        Functions\when( 'update_post_meta' )->justReturn( true );
        Functions\when( 'is_wp_error' )->justReturn( false );

        $repo = new Expedition_Repository();
        $id = $repo->create( [
            'post_title'     => 'Pentland Hills',
            'ems_event_code' => 'SP1',
            'ems_level'      => 'silver',
            'ems_type'       => 'qualifying',
            'ems_start_date' => '2026-08-01',
            'ems_end_date'   => '2026-08-03',
        ] );

        $this->assertEquals( 10, $id );
    }

    public function test_create_requires_expedition_code(): void {
        Functions\when( 'wp_insert_post' )->justReturn( 11 );
        Functions\when( 'update_post_meta' )->justReturn( true );

        $repo = new Expedition_Repository();

        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessageMatches( '/ems_event_code/i' );

        $repo->create( [
            'post_title' => 'No Code',
            'ems_level'  => 'bronze',
        ] );
    }

    public function test_create_rejects_duplicate_code(): void {
        Functions\when( 'get_posts' )->alias(
            static function ( $args ) {
                if ( isset( $args['meta_query'] ) ) {
                    return [ (object) [ 'ID' => 10 ] ];
                }
                return [];
            }
        );

        $repo = new Expedition_Repository();

        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessageMatches( '/duplicate.*code/i' );

        $repo->create( [
            'post_title'     => 'Duplicate',
            'ems_event_code' => 'SP1',
            'season_id'      => 5,
        ] );
    }

    public function test_get_by_id_returns_expedition(): void {
        Functions\when( 'get_post' )->alias(
            static function ( $id ) {
                $post = new \stdClass();
                $post->ID          = $id;
                $post->post_title  = 'Pentland Hills';
                $post->post_status = 'publish';
                $post->post_type   = 'expedition';
                return $post;
            }
        );
        Functions\when( 'get_post_meta' )->alias(
            static function ( $id, $key, $single ) {
                $meta = [
                    'ems_event_code'        => 'SP1',
                    'ems_level'             => 'silver',
                    'ems_first_aid_level'   => 'first_response',
                ];
                return $single && isset( $meta[$key] ) ? $meta[$key] : '';
            }
        );

        $repo = new Expedition_Repository();
        $expedition = $repo->get_by_id( 10 );

        $this->assertNotNull( $expedition );
        $this->assertEquals( 10, $expedition['ID'] );
        $this->assertEquals( 'SP1', $expedition['ems_event_code'] );
        $this->assertEquals( 'first_response', $expedition['ems_first_aid_level'] );
    }

    public function test_get_by_id_returns_null_for_missing(): void {
        Functions\when( 'get_post' )->justReturn( null );

        $repo = new Expedition_Repository();
        $result = $repo->get_by_id( 999 );

        $this->assertNull( $result );
    }

    public function test_get_by_id_returns_null_for_wrong_post_type(): void {
        Functions\when( 'get_post' )->alias(
            static function ( $id ) {
                $post = new \stdClass();
                $post->ID          = $id;
                $post->post_type   = 'post';
                return $post;
            }
        );

        $repo = new Expedition_Repository();
        $result = $repo->get_by_id( 999 );

        $this->assertNull( $result );
    }

    public function test_list_returns_all_expeditions(): void {
        Functions\when( 'get_posts' )->alias(
            static function ( $args ) {
                return [
                    (object) [ 'ID' => 10, 'post_title' => 'SP1', 'post_status' => 'publish', 'post_type' => 'expedition' ],
                    (object) [ 'ID' => 11, 'post_title' => 'SP2', 'post_status' => 'publish', 'post_type' => 'expedition' ],
                ];
            }
        );
        Functions\when( 'get_post_meta' )->justReturn( '' );

        $repo = new Expedition_Repository();
        $expeditions = $repo->list_all();

        $this->assertCount( 2, $expeditions );
    }
}
