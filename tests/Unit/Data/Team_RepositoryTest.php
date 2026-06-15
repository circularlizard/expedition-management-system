<?php
namespace EMS\Tests\Unit\Data;

use EMS\Data\Team_Repository;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;

class Team_RepositoryTest extends EMSTestCase {
    public function test_create_team_linked_to_expedition(): void {
        Functions\when( 'wp_insert_post' )->alias(
            static function ( $post ) {
                return 20;
            }
        );
        Functions\when( 'update_post_meta' )->justReturn( true );
        Functions\when( 'get_posts' )->justReturn( [] );

        $repo = new Team_Repository();
        $id = $repo->create( 10, 'SP1' );

        $this->assertEquals( 20, $id );
    }

    public function test_create_auto_generates_team_code(): void {
        Functions\when( 'wp_insert_post' )->alias(
            static function ( $post ) {
                return 21;
            }
        );
        Functions\when( 'update_post_meta' )->justReturn( true );
        Functions\when( 'get_posts' )->alias(
            static function ( $args ) {
                if ( isset( $args['meta_query'] ) ) {
                    return [];
                }
                return [
                    (object) [ 'ID' => 20, 'post_title' => 'Team 1', 'post_status' => 'publish', 'post_type' => 'team' ],
                ];
            }
        );
        Functions\when( 'get_post_meta' )->alias(
            static function ( $id, $key, $single ) {
                if ( $key === 'ems_team_code' ) {
                    return 'SP1-1';
                }
                return '';
            }
        );

        $repo = new Team_Repository();
        $id = $repo->create( 10, 'SP1' );

        $this->assertEquals( 21, $id );
    }

    public function test_create_rejects_duplicate_team_code(): void {
        Functions\when( 'wp_insert_post' )->justReturn( 21 );
        Functions\when( 'update_post_meta' )->justReturn( true );
        Functions\when( 'get_posts' )->alias(
            static function ( $args ) {
                if ( isset( $args['meta_query'] ) ) {
                    return [ (object) [ 'ID' => 20 ] ];
                }
                return [];
            }
        );

        $repo = new Team_Repository();

        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessageMatches( '/duplicate.*code/i' );

        $repo->create( 10, 'SP1', 'SP1-1' );
    }

    public function test_get_by_id_returns_team(): void {
        Functions\when( 'get_post' )->alias(
            static function ( $id ) {
                $post = new \stdClass();
                $post->ID          = $id;
                $post->post_title  = 'Team A';
                $post->post_status = 'publish';
                $post->post_type   = 'team';
                return $post;
            }
        );
        Functions\when( 'get_post_meta' )->alias(
            static function ( $id, $key, $single ) {
                $meta = [
                    'ems_team_code'    => 'SP1-1',
                    'ems_expedition_id'=> 10,
                ];
                return $single && isset( $meta[$key] ) ? $meta[$key] : '';
            }
        );

        $repo = new Team_Repository();
        $team = $repo->get_by_id( 20 );

        $this->assertNotNull( $team );
        $this->assertEquals( 20, $team['ID'] );
        $this->assertEquals( 'SP1-1', $team['ems_team_code'] );
    }

    public function test_get_by_id_returns_null_for_wrong_type(): void {
        Functions\when( 'get_post' )->alias(
            static function ( $id ) {
                $post = new \stdClass();
                $post->ID        = $id;
                $post->post_type = 'post';
                return $post;
            }
        );

        $repo = new Team_Repository();
        $result = $repo->get_by_id( 20 );

        $this->assertNull( $result );
    }

    public function test_list_by_expedition(): void {
        Functions\when( 'get_posts' )->alias(
            static function ( $args ) {
                return [
                    (object) [ 'ID' => 20, 'post_title' => 'Team A', 'post_status' => 'publish', 'post_type' => 'team' ],
                    (object) [ 'ID' => 21, 'post_title' => 'Team B', 'post_status' => 'publish', 'post_type' => 'team' ],
                ];
            }
        );
        Functions\when( 'get_post_meta' )->justReturn( '' );

        $repo = new Team_Repository();
        $teams = $repo->list_by_expedition( 10 );

        $this->assertCount( 2, $teams );
    }
}
