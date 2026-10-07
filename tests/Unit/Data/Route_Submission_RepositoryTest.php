<?php
namespace EMS\Tests\Unit\Data;

use EMS\Data\Route_Submission_Repository;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;
use Mockery;

class Route_Submission_RepositoryTest extends EMSTestCase {

    private function mock_wpdb(): Mockery\MockInterface {
        $wpdb = Mockery::mock( 'stdClass' );
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive( 'prepare' )->andReturnUsing(
            static function ( $q, ...$args ) {
                $i = 0;
                return preg_replace_callback( '/%[ds]/', static function () use ( &$args, &$i ) { return $args[$i++] ?? ''; }, $q );
            }
        );
        return $wpdb;
    }

    public function test_create_submission_initial_version_is_one(): void {
        $wpdb = $this->mock_wpdb();
        $wpdb->insert_id = 101;
        // get_var returns null for MAX(version) when no previous submissions exist
        $wpdb->shouldReceive( 'get_var' )->once()->andReturn( null );
        $wpdb->shouldReceive( 'insert' )
            ->once()
            ->with(
                'wp_ems_route_submissions',
                Mockery::on( function ( $data ) {
                    return $data['team_post_id'] === 42
                        && $data['version'] === 1
                        && $data['file_type'] === 'pdf'
                        && $data['wp_media_id'] === 500
                        && $data['submitted_by'] === 1
                        && $data['status'] === 'pending';
                } ),
                Mockery::type( 'array' )
            )
            ->andReturn( 1 );

        Functions\when( 'current_time' )->justReturn( '2026-06-13 12:00:00' );

        $repo = new Route_Submission_Repository( $wpdb );
        $id   = $repo->create( 42, 'pdf', 500, 1 );

        $this->assertSame( 101, $id );
    }

    public function test_create_submission_increments_version(): void {
        $wpdb = $this->mock_wpdb();
        $wpdb->insert_id = 102;
        // Existing MAX(version) is 2
        $wpdb->shouldReceive( 'get_var' )->once()->andReturn( '2' );
        $wpdb->shouldReceive( 'insert' )
            ->once()
            ->with(
                'wp_ems_route_submissions',
                Mockery::on( function ( $data ) {
                    return $data['version'] === 3;
                } ),
                Mockery::type( 'array' )
            )
            ->andReturn( 1 );

        Functions\when( 'current_time' )->justReturn( '2026-06-13 12:00:00' );

        $repo = new Route_Submission_Repository( $wpdb );
        $id   = $repo->create( 42, 'pdf', 501, 1 );

        $this->assertSame( 102, $id );
    }

    public function test_create_submission_rejects_invalid_file_type(): void {
        $wpdb = $this->mock_wpdb();
        $repo = new Route_Submission_Repository( $wpdb );

        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessageMatches( '/invalid file type/i' );

        $repo->create( 42, 'docx', 500, 1 );
    }

    public function test_get_returns_submission_by_id(): void {
        $wpdb = $this->mock_wpdb();
        $expected = [
            'id'           => 101,
            'team_post_id' => 42,
            'version'      => 1,
            'file_type'    => 'pdf',
            'wp_media_id'  => 500,
            'submitted_by' => 1,
            'submitted_at' => '2026-06-13 12:00:00',
            'feedback'     => null,
            'status'       => 'pending',
        ];
        $wpdb->shouldReceive( 'get_row' )->once()->andReturn( $expected );

        $repo   = new Route_Submission_Repository( $wpdb );
        $result = $repo->get( 101 );

        $this->assertSame( $expected, $result );
    }

    public function test_list_by_team_returns_submissions(): void {
        $wpdb = $this->mock_wpdb();
        $rows = [
            [ 'id' => 102, 'version' => 2, 'file_type' => 'pdf' ],
            [ 'id' => 101, 'version' => 1, 'file_type' => 'pdf' ],
        ];
        $wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );

        $repo    = new Route_Submission_Repository( $wpdb );
        $results = $repo->list_by_team( 42, 'pdf' );

        $this->assertCount( 2, $results );
        $this->assertSame( 102, $results[0]['id'] );
    }

    public function test_get_latest_returns_highest_version(): void {
        $wpdb = $this->mock_wpdb();
        $row  = [ 'id' => 102, 'version' => 2, 'file_type' => 'pdf' ];
        $wpdb->shouldReceive( 'get_row' )->once()->andReturn( $row );

        $repo   = new Route_Submission_Repository( $wpdb );
        $latest = $repo->get_latest( 42, 'pdf' );

        $this->assertSame( 2, $latest['version'] );
    }

    public function test_update_status_updates_status_and_feedback(): void {
        $wpdb = $this->mock_wpdb();
        $wpdb->shouldReceive( 'update' )
            ->once()
            ->with(
                'wp_ems_route_submissions',
                Mockery::on( function ( $data ) {
                    return $data['status'] === 'approved'
                        && $data['feedback'] === 'Looks great';
                } ),
                [ 'id' => 101 ],
                Mockery::type( 'array' ),
                Mockery::type( 'array' )
            )
            ->andReturn( 1 );

        $repo   = new Route_Submission_Repository( $wpdb );
        $result = $repo->update_status( 101, 'approved', 'Looks great' );

        $this->assertTrue( $result );
    }

    public function test_update_status_rejects_invalid_status(): void {
        $wpdb = $this->mock_wpdb();
        $repo = new Route_Submission_Repository( $wpdb );

        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessageMatches( '/invalid status/i' );

        $repo->update_status( 101, 'unknown_status' );
    }
}
