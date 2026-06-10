<?php
namespace EMS\Tests\Unit\Integrations;

use EMS\Tests\EMSTestCase;
use EMS\Integrations\TutorLMS_Client;
use Brain\Monkey\Functions;

class TutorLMS_ClientTest extends EMSTestCase {

    public function test_get_all_courses_queries_courses_post_type(): void {
        $mock_posts = [
            (object) [ 'ID' => 1, 'post_title' => 'Course A', 'post_status' => 'publish' ],
            (object) [ 'ID' => 2, 'post_title' => 'Course B', 'post_status' => 'publish' ],
        ];

        Functions\expect( 'get_posts' )
            ->once()
            ->with( \Mockery::subset( [
                'post_type'   => 'courses',
                'post_status' => 'publish',
            ] ) )
            ->andReturn( $mock_posts );

        $client = new TutorLMS_Client();
        $result = $client->get_all_courses();

        $this->assertCount( 2, $result );
        $this->assertEquals( 1, $result[0]->ID );
        $this->assertEquals( 'Course B', $result[1]->post_title );
    }

    public function test_get_all_courses_returns_empty_array_when_none_exist(): void {
        Functions\expect( 'get_posts' )->once()->andReturn( [] );

        $client = new TutorLMS_Client();
        $this->assertSame( [], $client->get_all_courses() );
    }

    public function test_get_enrolled_students_returns_user_objects_for_course(): void {
        $enrollments = [
            (object) [ 'ID' => 10, 'post_author' => 5, 'post_parent' => 1 ],
            (object) [ 'ID' => 11, 'post_author' => 6, 'post_parent' => 1 ],
        ];

        Functions\expect( 'get_posts' )
            ->once()
            ->with( \Mockery::subset( [
                'post_type'   => 'tutor_enrolled',
                'post_parent' => 1,
            ] ) )
            ->andReturn( $enrollments );

        $user_5 = (object) [ 'ID' => 5, 'user_email' => 'alice@example.com', 'display_name' => 'Alice Smith' ];
        $user_6 = (object) [ 'ID' => 6, 'user_email' => 'bob@example.com',   'display_name' => 'Bob Jones'  ];

        Functions\expect( 'get_userdata' )
            ->twice()
            ->andReturnUsing( function ( $id ) use ( $user_5, $user_6 ) {
                return $id === 5 ? $user_5 : $user_6;
            } );

        $client = new TutorLMS_Client();
        $result = $client->get_enrolled_students( 1 );

        $this->assertCount( 2, $result );
        $this->assertEquals( 'alice@example.com', $result[0]->user_email );
        $this->assertEquals( 'Bob Jones', $result[1]->display_name );
    }

    public function test_get_enrolled_students_skips_invalid_user(): void {
        $enrollments = [
            (object) [ 'ID' => 10, 'post_author' => 5, 'post_parent' => 1 ],
            (object) [ 'ID' => 11, 'post_author' => 99, 'post_parent' => 1 ],
        ];

        Functions\expect( 'get_posts' )->once()->andReturn( $enrollments );

        $user_5 = (object) [ 'ID' => 5, 'user_email' => 'alice@example.com', 'display_name' => 'Alice' ];
        Functions\expect( 'get_userdata' )
            ->twice()
            ->andReturnUsing( function ( $id ) use ( $user_5 ) {
                return $id === 5 ? $user_5 : false;
            } );

        $client = new TutorLMS_Client();
        $result = $client->get_enrolled_students( 1 );

        $this->assertCount( 1, $result );
        $this->assertEquals( 5, $result[0]->ID );
    }

    public function test_get_enrollment_status_returns_not_enrolled_when_no_enrollment_post(): void {
        Functions\expect( 'get_posts' )
            ->once()
            ->with( \Mockery::subset( [
                'post_type'   => 'tutor_enrolled',
                'post_author' => 5,
                'post_parent' => 1,
            ] ) )
            ->andReturn( [] );

        $client = new TutorLMS_Client();
        $this->assertEquals( 'not_enrolled', $client->get_enrollment_status( 1, 5 ) );
    }

    public function test_get_enrollment_status_returns_complete_when_meta_is_set(): void {
        Functions\expect( 'get_posts' )
            ->once()
            ->andReturn( [ (object) [ 'ID' => 10, 'post_author' => 5, 'post_parent' => 1 ] ] );

        Functions\expect( 'get_user_meta' )
            ->once()
            ->with( 5, '_tutor_completed_course_1', true )
            ->andReturn( 1718000000 );

        $client = new TutorLMS_Client();
        $this->assertEquals( 'complete', $client->get_enrollment_status( 1, 5 ) );
    }

    public function test_get_enrollment_status_returns_in_progress_when_enrolled_but_no_completion_meta(): void {
        Functions\expect( 'get_posts' )
            ->once()
            ->andReturn( [ (object) [ 'ID' => 10, 'post_author' => 5, 'post_parent' => 1 ] ] );

        Functions\expect( 'get_user_meta' )
            ->once()
            ->with( 5, '_tutor_completed_course_1', true )
            ->andReturn( '' );

        $client = new TutorLMS_Client();
        $this->assertEquals( 'in_progress', $client->get_enrollment_status( 1, 5 ) );
    }
}
