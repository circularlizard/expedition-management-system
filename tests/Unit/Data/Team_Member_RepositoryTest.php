<?php
namespace EMS\Tests\Unit\Data;

use EMS\Data\Team_Member_Repository;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;

class Team_Member_RepositoryTest extends EMSTestCase {
    protected function setUp(): void {
        parent::setUp();
        if ( ! defined( 'ARRAY_A' ) ) {
            define( 'ARRAY_A', 2 );
        }
    }

    protected function tearDown(): void {
        \Mockery::close();
        parent::tearDown();
    }

    private function mock_wpdb(): \Mockery\MockInterface {
        $wpdb = \Mockery::mock( 'stdClass' );
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive( 'prepare' )->andReturnUsing(
            static function ( $q, ...$args ) {
                $i = 0;
                return preg_replace_callback( '/%d/', static function () use ( &$args, &$i ) { return $args[$i++] ?? 0; }, $q );
            }
        );
        return $wpdb;
    }

    public function test_assign_explorer_to_team(): void {
        $wpdb = $this->mock_wpdb();
        $wpdb->insert_id = 1;
        $wpdb->shouldReceive( 'get_var' )->andReturn( null );
        $wpdb->shouldReceive( 'insert' )->andReturn( 1 );

        Functions\when( 'current_time' )->justReturn( '2026-06-13 12:00:00' );

        $repo = new Team_Member_Repository( $wpdb );
        $id = $repo->assign( 20, 50, 1 );

        $this->assertEquals( 1, $id );
    }

    public function test_prevents_duplicate_assignment(): void {
        $wpdb = $this->mock_wpdb();
        $wpdb->shouldReceive( 'get_var' )->andReturn( 1 );

        $repo = new Team_Member_Repository( $wpdb );

        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessageMatches( '/already assigned/i' );

        $repo->assign( 20, 50, 1 );
    }

    public function test_list_by_team(): void {
        $wpdb = $this->mock_wpdb();
        $wpdb->shouldReceive( 'get_results' )->andReturn( [
            [ 'id' => 1, 'user_id' => 50, 'added_by' => 1, 'added_at' => '2026-06-13 12:00:00' ],
            [ 'id' => 2, 'user_id' => 51, 'added_by' => 1, 'added_at' => '2026-06-13 12:01:00' ],
        ] );

        $repo = new Team_Member_Repository( $wpdb );
        $members = $repo->list_by_team( 20 );

        $this->assertCount( 2, $members );
    }

    public function test_list_by_expedition(): void {
        Functions\when( 'get_posts' )->justReturn( [ 20, 21 ] );

        $wpdb = $this->mock_wpdb();
        $wpdb->shouldReceive( 'get_results' )->andReturn( [
            [ 'id' => 1, 'team_post_id' => 20, 'user_id' => 50 ],
            [ 'id' => 2, 'team_post_id' => 21, 'user_id' => 51 ],
        ] );

        $repo = new Team_Member_Repository( $wpdb );
        $members = $repo->list_by_expedition( 10 );

        $this->assertCount( 2, $members );
    }

    public function test_list_unassigned_for_expedition(): void {
        Functions\when( 'get_posts' )->justReturn( [ 20 ] );
        Functions\when( 'get_users' )->alias(
            static function ( $args ) {
                return [
                    50 => (object) [ 'ID' => 50 ],
                    51 => (object) [ 'ID' => 51 ],
                ];
            }
        );
        Functions\when( 'get_user_meta' )->justReturn( '' );

        $wpdb = $this->mock_wpdb();
        $wpdb->shouldReceive( 'get_row' )->andReturn( null );

        $repo = new Team_Member_Repository( $wpdb );
        $unassigned = $repo->list_unassigned( 10 );

        $this->assertCount( 2, $unassigned );
    }

    public function test_list_unassigned_excludes_assigned_users(): void {
        Functions\when( 'get_posts' )->justReturn( [ 20 ] );
        Functions\when( 'get_users' )->alias(
            static function ( $args ) {
                return [
                    50 => (object) [ 'ID' => 50 ],
                    51 => (object) [ 'ID' => 51 ],
                ];
            }
        );
        Functions\when( 'get_user_meta' )->justReturn( '' );

        $wpdb = $this->mock_wpdb();
        $wpdb->shouldReceive( 'get_row' )
            ->with( \Mockery::on( static function ( $q ) { return strpos( $q, 'user_id = 50' ) !== false; } ) )
            ->andReturn( (object) [ 'id' => 1 ] )
            ->once();
        $wpdb->shouldReceive( 'get_row' )
            ->with( \Mockery::on( static function ( $q ) { return strpos( $q, 'user_id = 51' ) !== false; } ) )
            ->andReturn( null )
            ->once();

        $repo = new Team_Member_Repository( $wpdb );
        $unassigned = $repo->list_unassigned( 10 );

        $this->assertCount( 1, $unassigned );
        $this->assertEquals( 51, $unassigned[0]['ID'] );
    }
}
