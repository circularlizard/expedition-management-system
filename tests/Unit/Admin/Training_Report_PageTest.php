<?php
namespace EMS\Tests\Unit\Admin;

use EMS\Tests\EMSTestCase;
use EMS\Admin\Training_Report_Page;
use EMS\Integrations\TutorLMS_Client;

class Training_Report_PageTest extends EMSTestCase {

    private function make_client(): TutorLMS_Client {
        return \Mockery::mock( TutorLMS_Client::class );
    }

    private function make_course( int $id, string $title ): object {
        return (object) [ 'ID' => $id, 'post_title' => $title ];
    }

    private function make_user( int $id, string $name, string $email ): object {
        return (object) [ 'ID' => $id, 'display_name' => $name, 'user_email' => $email ];
    }

    // --- build_report_data --------------------------------------------------

    public function test_build_report_data_returns_courses_list(): void {
        $client = $this->make_client();
        $courses = [ $this->make_course( 1, 'Course A' ), $this->make_course( 2, 'Course B' ) ];

        $client->shouldReceive( 'get_all_courses' )->once()->andReturn( $courses );
        $client->shouldReceive( 'get_enrolled_students' )->andReturn( [] );

        $page = new Training_Report_Page( $client );
        $data = $page->build_report_data();

        $this->assertSame( $courses, $data['courses'] );
    }

    public function test_build_report_data_aggregates_unique_students_across_courses(): void {
        $client  = $this->make_client();
        $course1 = $this->make_course( 1, 'Course A' );
        $course2 = $this->make_course( 2, 'Course B' );
        $alice   = $this->make_user( 5, 'Alice', 'alice@example.com' );
        $bob     = $this->make_user( 6, 'Bob',   'bob@example.com'   );

        $client->shouldReceive( 'get_all_courses' )->once()->andReturn( [ $course1, $course2 ] );
        $client->shouldReceive( 'get_enrolled_students' )->with( 1 )->andReturn( [ $alice, $bob ] );
        $client->shouldReceive( 'get_enrolled_students' )->with( 2 )->andReturn( [ $alice ] );
        $client->shouldReceive( 'get_enrollment_status' )->andReturn( 'complete' );

        $page = new Training_Report_Page( $client );
        $data = $page->build_report_data();

        $this->assertCount( 2, $data['students'] );
        $student_ids = array_column( $data['students'], 'ID' );
        $this->assertContains( 5, $student_ids );
        $this->assertContains( 6, $student_ids );
    }

    public function test_build_report_data_matrix_contains_status_for_each_student_and_course(): void {
        $client  = $this->make_client();
        $course1 = $this->make_course( 1, 'Course A' );
        $course2 = $this->make_course( 2, 'Course B' );
        $alice   = $this->make_user( 5, 'Alice', 'alice@example.com' );

        $client->shouldReceive( 'get_all_courses' )->once()->andReturn( [ $course1, $course2 ] );
        $client->shouldReceive( 'get_enrolled_students' )->with( 1 )->andReturn( [ $alice ] );
        $client->shouldReceive( 'get_enrolled_students' )->with( 2 )->andReturn( [ $alice ] );
        $client->shouldReceive( 'get_enrollment_status' )->with( 1, 5 )->andReturn( 'complete' );
        $client->shouldReceive( 'get_enrollment_status' )->with( 2, 5 )->andReturn( 'in_progress' );

        $page = new Training_Report_Page( $client );
        $data = $page->build_report_data();

        $this->assertEquals( 'complete',    $data['matrix'][5][1] );
        $this->assertEquals( 'in_progress', $data['matrix'][5][2] );
    }

    public function test_build_report_data_matrix_shows_not_enrolled_for_unenrolled_course(): void {
        $client  = $this->make_client();
        $course1 = $this->make_course( 1, 'Course A' );
        $course2 = $this->make_course( 2, 'Course B' );
        $alice   = $this->make_user( 5, 'Alice', 'alice@example.com' );

        $client->shouldReceive( 'get_all_courses' )->once()->andReturn( [ $course1, $course2 ] );
        $client->shouldReceive( 'get_enrolled_students' )->with( 1 )->andReturn( [ $alice ] );
        $client->shouldReceive( 'get_enrolled_students' )->with( 2 )->andReturn( [] );
        $client->shouldReceive( 'get_enrollment_status' )->with( 1, 5 )->andReturn( 'complete' );
        $client->shouldReceive( 'get_enrollment_status' )->with( 2, 5 )->andReturn( 'not_enrolled' );

        $page = new Training_Report_Page( $client );
        $data = $page->build_report_data();

        $this->assertEquals( 'not_enrolled', $data['matrix'][5][2] );
    }

    // --- generate_csv_rows --------------------------------------------------

    public function test_generate_csv_rows_header_contains_student_columns_then_course_titles(): void {
        $client  = $this->make_client();
        $client->shouldReceive( 'get_all_courses' )->andReturn( [
            $this->make_course( 1, 'Course A' ),
            $this->make_course( 2, 'Course B' ),
        ] );
        $client->shouldReceive( 'get_enrolled_students' )->andReturn( [] );

        $page = new Training_Report_Page( $client );
        $rows = $page->generate_csv_rows();

        $this->assertEquals( [ 'Student Name', 'Email', 'Course A', 'Course B' ], $rows[0] );
    }

    public function test_generate_csv_rows_maps_status_values_to_human_labels(): void {
        $client  = $this->make_client();
        $alice   = $this->make_user( 5, 'Alice Smith', 'alice@example.com' );

        $client->shouldReceive( 'get_all_courses' )->andReturn( [
            $this->make_course( 1, 'C1' ),
            $this->make_course( 2, 'C2' ),
            $this->make_course( 3, 'C3' ),
        ] );
        $client->shouldReceive( 'get_enrolled_students' )->with( 1 )->andReturn( [ $alice ] );
        $client->shouldReceive( 'get_enrolled_students' )->with( 2 )->andReturn( [ $alice ] );
        $client->shouldReceive( 'get_enrolled_students' )->with( 3 )->andReturn( [ $alice ] );
        $client->shouldReceive( 'get_enrollment_status' )->with( 1, 5 )->andReturn( 'complete' );
        $client->shouldReceive( 'get_enrollment_status' )->with( 2, 5 )->andReturn( 'in_progress' );
        $client->shouldReceive( 'get_enrollment_status' )->with( 3, 5 )->andReturn( 'not_enrolled' );

        $page = new Training_Report_Page( $client );
        $rows = $page->generate_csv_rows();

        $this->assertEquals( [ 'Alice Smith', 'alice@example.com', 'Complete', 'In Progress', 'Not Enrolled' ], $rows[1] );
    }

    public function test_generate_csv_rows_returns_only_header_when_no_students(): void {
        $client = $this->make_client();
        $client->shouldReceive( 'get_all_courses' )->andReturn( [ $this->make_course( 1, 'C1' ) ] );
        $client->shouldReceive( 'get_enrolled_students' )->andReturn( [] );

        $page = new Training_Report_Page( $client );
        $rows = $page->generate_csv_rows();

        $this->assertCount( 1, $rows );
    }
}
