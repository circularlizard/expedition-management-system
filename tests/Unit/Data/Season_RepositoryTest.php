<?php
namespace EMS\Tests\Unit\Data;

use EMS\Data\Season_Repository;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;

class Season_RepositoryTest extends EMSTestCase {

    public function test_create_season_persists_year_and_status(): void {
        Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => $v );
        Functions\when( 'get_posts' )->justReturn( [] );
        Functions\when( 'wp_insert_post' )->alias( static fn( $p ) => 42 );
        Functions\when( 'update_post_meta' )->justReturn( true );
        Functions\when( 'is_wp_error' )->justReturn( false );

        $repo = new Season_Repository();
        $id   = $repo->create( [ 'year' => '2026-27', 'status' => 'active' ] );

        $this->assertSame( 42, $id );
    }

    public function test_create_season_defaults_blank_title_to_year_season(): void {
        Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => $v );
        Functions\when( 'get_posts' )->justReturn( [] );
        Functions\when( 'update_post_meta' )->justReturn( true );
        Functions\when( 'is_wp_error' )->justReturn( false );

        $captured_title = null;
        Functions\when( 'wp_insert_post' )->alias( static function ( $p ) use ( &$captured_title ) {
            $captured_title = $p['post_title'];
            return 42;
        } );

        $repo = new Season_Repository();
        $repo->create( [ 'year' => '2026-27', 'post_title' => '' ] );

        $this->assertSame( '2026-27 Season', $captured_title );
    }

    public function test_create_season_rejects_duplicate_year(): void {
        Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => $v );
        Functions\when( 'get_posts' )->alias(
            static function ( $args ) {
                return isset( $args['meta_query'] ) ? [ (object) [ 'ID' => 10 ] ] : [];
            }
        );

        $repo = new Season_Repository();

        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessageMatches( '/already exists/i' );

        $repo->create( [ 'year' => '2026-27' ] );
    }

    public function test_list_all_returns_newest_first(): void {
        Functions\when( 'get_posts' )->alias(
            static function ( $args ) {
                return [
                    (object) [ 'ID' => 10, 'post_title' => '2025-26', 'post_status' => 'publish' ],
                    (object) [ 'ID' => 11, 'post_title' => '2026-27', 'post_status' => 'publish' ],
                ];
            }
        );
        Functions\when( 'get_post_meta' )->alias(
            static function ( $id, $key, $single ) {
                $meta = [
                    10 => [ 'ems_season_year' => '2025-26', 'ems_season_status' => 'archived' ],
                    11 => [ 'ems_season_year' => '2026-27', 'ems_season_status' => 'active' ],
                ];
                return $meta[$id][$key] ?? '';
            }
        );

        $repo    = new Season_Repository();
        $seasons = $repo->list_all();

        $this->assertCount( 2, $seasons );
        $years = array_column( $seasons, 'ems_season_year' );
        $this->assertContains( '2026-27', $years );
        $this->assertContains( '2025-26', $years );
    }

    public function test_archive_updates_status(): void {
        Functions\when( 'get_post' )->alias(
            static function ( $id ) {
                $post = new \stdClass();
                $post->ID          = $id;
                $post->post_type   = 'season';
                $post->post_status = 'publish';
                return $post;
            }
        );
        Functions\when( 'update_post_meta' )->justReturn( true );
        Functions\when( 'get_post_meta' )->alias(
            static function ( $id, $key, $single ) {
                return $key === 'ems_season_status' ? 'archived' : '';
            }
        );

        $repo   = new Season_Repository();
        $result = $repo->archive( 10 );

        $this->assertTrue( $result );
        $this->assertSame( 'archived', $repo->get_by_id( 10 )['ems_season_status'] );
    }

    public function test_archive_non_existent_returns_false(): void {
        Functions\when( 'get_post' )->justReturn( null );

        $repo   = new Season_Repository();
        $result = $repo->archive( 999 );

        $this->assertFalse( $result );
    }
}
