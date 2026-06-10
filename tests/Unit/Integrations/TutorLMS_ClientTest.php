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
                'author'      => 5,
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

    /**
     * Regression: quiz-less courses whose lessons sit directly on the course
     * (no topic wrapper) must return 'complete' once all lessons are done.
     */
    public function test_get_enrollment_matrix_completes_lesson_only_course_without_topic(): void {
        global $wpdb;
        $wpdb         = \Mockery::mock( 'wpdb' );
        $wpdb->posts    = 'wp_posts';
        $wpdb->usermeta = 'wp_usermeta';
        $wpdb->prefix   = 'wp_';
        $wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $sql ) => $sql );

        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [ (object) [ 'post_author' => 7, 'post_parent' => 42 ] ] );
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [] );
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [ (object) [ 'content_id' => 101, 'content_type' => 'tutor_lesson', 'course_id' => 42 ] ] );
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [ (object) [ 'user_id' => 7, 'meta_key' => '_tutor_completed_lesson_id_101' ] ] );
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [] ); // 4b
        $wpdb->shouldReceive( 'get_var' )->once()->ordered()->andReturn( null ); // 5.6

        $matrix = ( new TutorLMS_Client() )->get_enrollment_matrix( [ 7 ], [ 42 ] );
        $this->assertSame( 'complete', $matrix[7][42] );
    }

    public function test_get_enrollment_matrix_in_progress_when_direct_lesson_not_done(): void {
        global $wpdb;
        $wpdb         = \Mockery::mock( 'wpdb' );
        $wpdb->posts    = 'wp_posts';
        $wpdb->usermeta = 'wp_usermeta';
        $wpdb->prefix   = 'wp_';
        $wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $sql ) => $sql );

        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [ (object) [ 'post_author' => 7, 'post_parent' => 42 ] ] );
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [] );
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [ (object) [ 'content_id' => 101, 'content_type' => 'tutor_lesson', 'course_id' => 42 ] ] );
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [] ); // Step 4
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [] ); // Step 4b
        $wpdb->shouldReceive( 'get_var' )->once()->ordered()->andReturn( null ); // Step 5.6

        $matrix = ( new TutorLMS_Client() )->get_enrollment_matrix( [ 7 ], [ 42 ] );
        $this->assertSame( 'in_progress', $matrix[7][42] );
    }

    public function test_get_enrollment_matrix_completes_assignment_only_course_when_submitted(): void {
        global $wpdb;
        $wpdb         = \Mockery::mock( 'wpdb' );
        $wpdb->posts    = 'wp_posts';
        $wpdb->usermeta = 'wp_usermeta';
        $wpdb->prefix   = 'wp_';
        $wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $sql ) => $sql );

        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [ (object) [ 'post_author' => 7, 'post_parent' => 42 ] ] );
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [] );
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [ (object) [ 'content_id' => 200, 'content_type' => 'tutor_assignments', 'course_id' => 42 ] ] );
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [ (object) [ 'user_id' => 7, 'meta_key' => '_tutor_completed_lesson_id_200' ] ] );
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [] ); // Step 4b
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [] ); // Step 5.5
        $wpdb->shouldReceive( 'get_var' )->once()->ordered()->andReturn( null ); // Step 5.6

        $matrix = ( new TutorLMS_Client() )->get_enrollment_matrix( [ 7 ], [ 42 ] );
        $this->assertSame( 'complete', $matrix[7][42] );
    }

    public function test_get_enrollment_matrix_in_progress_when_assignment_not_submitted(): void {
        global $wpdb;
        $wpdb         = \Mockery::mock( 'wpdb' );
        $wpdb->posts    = 'wp_posts';
        $wpdb->usermeta = 'wp_usermeta';
        $wpdb->prefix   = 'wp_';
        $wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $sql ) => $sql );

        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [ (object) [ 'post_author' => 7, 'post_parent' => 42 ] ] );
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [] );
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [ (object) [ 'content_id' => 200, 'content_type' => 'tutor_assignments', 'course_id' => 42 ] ] );
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [] ); // Step 4
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [] ); // Step 4b
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [] ); // Step 5.5
        $wpdb->shouldReceive( 'get_var' )->once()->ordered()->andReturn( null ); // Step 5.6

        // Diagnostic might fire depending on test order
        $wpdb->shouldReceive( 'get_results' )->zeroOrMoreTimes()->andReturn( [] );
        Functions\expect( 'set_transient' )->zeroOrMoreTimes();

        $matrix = ( new TutorLMS_Client() )->get_enrollment_matrix( [ 7 ], [ 42 ] );
        $this->assertSame( 'in_progress', $matrix[7][42] );
    }

    public function test_get_enrollment_matrix_completes_lesson_and_assignment_course_when_both_done(): void {
        global $wpdb;
        $wpdb         = \Mockery::mock( 'wpdb' );
        $wpdb->posts    = 'wp_posts';
        $wpdb->usermeta = 'wp_usermeta';
        $wpdb->prefix   = 'wp_';
        $wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $sql ) => $sql );
        Functions\expect( 'maybe_unserialize' )->never();

        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [ (object) [ 'post_author' => 7, 'post_parent' => 42 ] ] );
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [] );
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [
            (object) [ 'content_id' => 101, 'content_type' => 'tutor_lesson',      'course_id' => 42 ],
            (object) [ 'content_id' => 200, 'content_type' => 'tutor_assignments',  'course_id' => 42 ],
        ] );
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [ (object) [ 'user_id' => 7, 'meta_key' => '_tutor_completed_lesson_id_101' ] ] );
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [] ); // 4b
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [ (object) [ 'post_author' => 7, 'post_parent' => 200 ] ] ); // 5.5
        $wpdb->shouldReceive( 'get_var' )->once()->ordered()->andReturn( null ); // 5.6

        $matrix = ( new TutorLMS_Client() )->get_enrollment_matrix( [ 7 ], [ 42 ] );
        $this->assertSame( 'complete', $matrix[7][42] );
    }

    public function test_get_enrollment_matrix_in_progress_when_lesson_done_but_assignment_not_submitted(): void {
        global $wpdb;
        $wpdb         = \Mockery::mock( 'wpdb' );
        $wpdb->posts    = 'wp_posts';
        $wpdb->usermeta = 'wp_usermeta';
        $wpdb->prefix   = 'wp_';
        $wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $sql ) => $sql );
        Functions\expect( 'maybe_unserialize' )->never();

        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [ (object) [ 'post_author' => 7, 'post_parent' => 42 ] ] );
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [] );
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [
            (object) [ 'content_id' => 101, 'content_type' => 'tutor_lesson',      'course_id' => 42 ],
            (object) [ 'content_id' => 200, 'content_type' => 'tutor_assignments',  'course_id' => 42 ],
        ] );
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [ (object) [ 'user_id' => 7, 'meta_key' => '_tutor_completed_lesson_id_101' ] ] );
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [] ); // 4b
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [] ); // 5.5
        $wpdb->shouldReceive( 'get_var' )->once()->ordered()->andReturn( null ); // 5.6

        // Diagnostic might fire depending on test order
        $wpdb->shouldReceive( 'get_results' )->zeroOrMoreTimes()->andReturn( [] );
        Functions\expect( 'set_transient' )->zeroOrMoreTimes();

        $matrix = ( new TutorLMS_Client() )->get_enrollment_matrix( [ 7 ], [ 42 ] );
        $this->assertSame( 'in_progress', $matrix[7][42] );
    }

    public function test_get_enrollment_matrix_completes_lesson_course_via_reading_info_meta(): void {
        global $wpdb;
        $wpdb         = \Mockery::mock( 'wpdb' );
        $wpdb->posts    = 'wp_posts';
        $wpdb->usermeta = 'wp_usermeta';
        $wpdb->prefix   = 'wp_';
        $wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $sql ) => $sql );

        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [ (object) [ 'post_author' => 7, 'post_parent' => 42 ] ] );
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [] );
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [ (object) [ 'content_id' => 101, 'content_type' => 'tutor_lesson', 'course_id' => 42 ] ] );
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [] ); // 4
        $reading_info_value = serialize( [ 101 => true ] );
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [ (object) [ 'user_id' => 7, 'meta_key' => '_tutor_reading_info_42', 'meta_value' => $reading_info_value ] ] );
        Functions\expect( 'maybe_unserialize' )->once()->andReturn( [ 101 => true ] );
        $wpdb->shouldReceive( 'get_var' )->once()->ordered()->andReturn( null ); // 5.6

        $matrix = ( new TutorLMS_Client() )->get_enrollment_matrix( [ 7 ], [ 42 ] );
        $this->assertSame( 'complete', $matrix[7][42] );
    }

    public function test_get_enrollment_matrix_not_enrolled_when_no_enrollment_record(): void {
        global $wpdb;
        $wpdb         = \Mockery::mock( 'wpdb' );
        $wpdb->posts    = 'wp_posts';
        $wpdb->usermeta = 'wp_usermeta';
        $wpdb->prefix   = 'wp_';
        $wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $sql ) => $sql );

        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [] );
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [] );
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [] );
        $wpdb->shouldReceive( 'get_var' )->zeroOrMoreTimes()->andReturn( null );

        $matrix = ( new TutorLMS_Client() )->get_enrollment_matrix( [ 7 ], [ 42 ] );
        $this->assertSame( 'not_enrolled', $matrix[7][42] );
    }

    public function test_get_enrollment_matrix_uses_cb_content_usage_table(): void {
        global $wpdb;
        $wpdb         = \Mockery::mock( 'wpdb' );
        $wpdb->posts    = 'wp_posts';
        $wpdb->usermeta = 'wp_usermeta';
        $wpdb->prefix   = 'wp_';
        $wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $sql ) => $sql );

        // Step 1: Enrollments
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [ (object) [ 'post_author' => 7, 'post_parent' => 42 ] ] );
        // Step 2: Explicit
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [] );
        // Step 3: Content (1 assignment)
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [ (object) [ 'content_id' => 300, 'content_type' => 'tutor_assignments', 'course_id' => 42 ] ] );
        // Step 4: Meta LIKE
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [] );
        // Step 4b: Reading info
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [] );
        // Step 5.5: Assignments (wp_posts)
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [] );
        // Step 5.6: usage table
        $wpdb->shouldReceive( 'get_var' )->once()->ordered()->andReturn( 'wp_tutor_cb_content_usage' );
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [ (object) [ 'user_id' => 7, 'content_id' => 300 ] ] );

        $matrix = ( new TutorLMS_Client() )->get_enrollment_matrix( [ 7 ], [ 42 ] );
        $this->assertSame( 'complete', $matrix[7][42] );
    }

    public function test_get_enrollment_matrix_completes_assignment_via_comments(): void {
        global $wpdb;
        $wpdb         = \Mockery::mock( 'wpdb' );
        $wpdb->posts    = 'wp_posts';
        $wpdb->usermeta = 'wp_usermeta';
        $wpdb->comments = 'wp_comments';
        $wpdb->prefix   = 'wp_';
        $wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $sql ) => $sql );

        // Step 1: Enrollments
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [ (object) [ 'post_author' => 7, 'post_parent' => 42 ] ] );
        // Step 2: Explicit
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [] );
        // Step 3: Content (1 assignment)
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [ (object) [ 'content_id' => 300, 'content_type' => 'tutor_assignments', 'course_id' => 42 ] ] );
        // Step 4: Meta LIKE
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [] );
        // Step 4b: Reading info
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [] );
        // Step 5.5: Assignments (wp_posts)
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [] );
        // Step 5.5.5: Assignments (wp_comments)
        $wpdb->shouldReceive( 'get_results' )->once()->ordered()->andReturn( [ (object) [ 'user_id' => 7, 'comment_post_ID' => 300 ] ] );
        // Step 5.6: usage table
        $wpdb->shouldReceive( 'get_var' )->once()->ordered()->andReturn( null );

        $matrix = ( new TutorLMS_Client() )->get_enrollment_matrix( [ 7 ], [ 42 ] );
        $this->assertSame( 'complete', $matrix[7][42] );
    }
}
