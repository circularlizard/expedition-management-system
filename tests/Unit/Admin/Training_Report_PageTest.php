<?php
namespace EMS\Tests\Unit\Admin;

use EMS\Tests\EMSTestCase;
use EMS\Admin\Training_Report_Page;
use EMS\Integrations\TutorLMS_Client;
use Brain\Monkey\Functions;

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
        $client  = $this->make_client();
        $courses = [ $this->make_course( 1, 'Course A' ), $this->make_course( 2, 'Course B' ) ];

        $client->shouldReceive( 'get_all_courses' )->once()->andReturn( $courses );
        $client->shouldReceive( 'get_all_enrolled_user_ids' )->andReturn( [] );
        $client->shouldReceive( 'get_enrollment_matrix' )->andReturn( [] );

        $page = new Training_Report_Page( $client );
        $data = $page->build_report_data();

        $this->assertSame( $courses, $data['courses'] );
    }

    public function test_build_report_data_returns_students_for_current_page(): void {
        $client  = $this->make_client();
        $alice   = $this->make_user( 5, 'Alice', 'alice@example.com' );
        $bob     = $this->make_user( 6, 'Bob',   'bob@example.com'   );

        $client->shouldReceive( 'get_all_courses' )->once()->andReturn( [ $this->make_course( 1, 'C1' ) ] );
        $client->shouldReceive( 'get_all_enrolled_user_ids' )->andReturn( [ 5, 6 ] );
        $client->shouldReceive( 'get_enrollment_matrix' )->andReturn( [] );

        Functions\expect( 'get_userdata' )
            ->twice()
            ->andReturnUsing( fn( $id ) => $id === 5 ? $alice : $bob );

        $page = new Training_Report_Page( $client );
        $data = $page->build_report_data();

        $this->assertCount( 2, $data['students'] );
        $ids = array_column( $data['students'], 'ID' );
        $this->assertContains( 5, $ids );
        $this->assertContains( 6, $ids );
    }

    public function test_build_report_data_matrix_delegates_to_client(): void {
        $client  = $this->make_client();
        $alice   = $this->make_user( 5, 'Alice', 'alice@example.com' );
        $matrix  = [ 5 => [ 1 => 'complete', 2 => 'in_progress' ] ];

        $client->shouldReceive( 'get_all_courses' )->once()->andReturn( [
            $this->make_course( 1, 'C1' ),
            $this->make_course( 2, 'C2' ),
        ] );
        $client->shouldReceive( 'get_all_enrolled_user_ids' )->andReturn( [ 5 ] );
        $client->shouldReceive( 'get_enrollment_matrix' )->with( [ 5 ], [ 1, 2 ] )->andReturn( $matrix );

        Functions\expect( 'get_userdata' )->once()->andReturn( $alice );

        $page = new Training_Report_Page( $client );
        $data = $page->build_report_data();

        $this->assertEquals( 'complete',    $data['matrix'][5][1] );
        $this->assertEquals( 'in_progress', $data['matrix'][5][2] );
    }

    public function test_build_report_data_paginates_to_second_page(): void {
        $client  = $this->make_client();
        $all_ids = range( 1, 30 );

        $client->shouldReceive( 'get_all_courses' )->andReturn( [ $this->make_course( 1, 'C1' ) ] );
        $client->shouldReceive( 'get_all_enrolled_user_ids' )->andReturn( $all_ids );
        $client->shouldReceive( 'get_enrollment_matrix' )
            ->with( range( 26, 30 ), [ 1 ] )
            ->andReturn( [] );

        Functions\expect( 'get_userdata' )
            ->times( 5 )
            ->andReturnUsing( fn( $id ) => $this->make_user( $id, "User {$id}", "u{$id}@e.com" ) );

        $page = new Training_Report_Page( $client );
        $data = $page->build_report_data( 2 );

        $this->assertEquals( 2,  $data['page'] );
        $this->assertEquals( 2,  $data['pages'] );
        $this->assertEquals( 30, $data['total'] );
        $this->assertCount( 5,   $data['students'] );
        $this->assertEquals( 26, $data['students'][0]->ID );
    }

    public function test_build_report_data_returns_pagination_metadata(): void {
        $client = $this->make_client();

        $client->shouldReceive( 'get_all_courses' )->andReturn( [ $this->make_course( 1, 'C1' ) ] );
        $client->shouldReceive( 'get_all_enrolled_user_ids' )->andReturn( range( 1, 50 ) );
        $client->shouldReceive( 'get_enrollment_matrix' )->andReturn( [] );

        Functions\expect( 'get_userdata' )
            ->times( 25 )
            ->andReturnUsing( fn( $id ) => $this->make_user( $id, "User {$id}", "u{$id}@e.com" ) );

        $page = new Training_Report_Page( $client );
        $data = $page->build_report_data( 1 );

        $this->assertEquals( 1,  $data['page'] );
        $this->assertEquals( 2,  $data['pages'] );
        $this->assertEquals( 50, $data['total'] );
    }

    // --- generate_csv_rows --------------------------------------------------

    public function test_generate_csv_rows_header_contains_student_columns_then_course_titles(): void {
        $client = $this->make_client();
        $client->shouldReceive( 'get_all_courses' )->andReturn( [
            $this->make_course( 1, 'Course A' ),
            $this->make_course( 2, 'Course B' ),
        ] );
        $client->shouldReceive( 'get_all_enrolled_user_ids' )->andReturn( [] );
        $client->shouldReceive( 'get_enrollment_matrix' )->andReturn( [] );

        $page = new Training_Report_Page( $client );
        $rows = $page->generate_csv_rows();

        $this->assertEquals( [ 'Student Name', 'Email', 'Course A', 'Course B' ], $rows[0] );
    }

    public function test_generate_csv_rows_maps_status_values_to_human_labels(): void {
        $client = $this->make_client();
        $alice  = $this->make_user( 5, 'Alice Smith', 'alice@example.com' );
        $matrix = [ 5 => [ 1 => 'complete', 2 => 'in_progress', 3 => 'not_enrolled' ] ];

        $client->shouldReceive( 'get_all_courses' )->andReturn( [
            $this->make_course( 1, 'C1' ),
            $this->make_course( 2, 'C2' ),
            $this->make_course( 3, 'C3' ),
        ] );
        $client->shouldReceive( 'get_all_enrolled_user_ids' )->andReturn( [ 5 ] );
        $client->shouldReceive( 'get_enrollment_matrix' )->andReturn( $matrix );

        Functions\expect( 'get_userdata' )->once()->andReturn( $alice );

        $page = new Training_Report_Page( $client );
        $rows = $page->generate_csv_rows();

        $this->assertEquals(
            [ 'Alice Smith', 'alice@example.com', 'Complete', 'In Progress', 'Not Enrolled' ],
            $rows[1]
        );
    }

    public function test_generate_csv_rows_exports_all_students_not_just_current_page(): void {
        $client  = $this->make_client();
        $all_ids = range( 1, 30 );

        $client->shouldReceive( 'get_all_courses' )->andReturn( [ $this->make_course( 1, 'C1' ) ] );
        $client->shouldReceive( 'get_all_enrolled_user_ids' )->with( [ 1 ] )->andReturn( $all_ids );
        $client->shouldReceive( 'get_enrollment_matrix' )->with( $all_ids, [ 1 ] )->andReturn( [] );

        Functions\expect( 'get_userdata' )
            ->times( 30 )
            ->andReturnUsing( fn( $id ) => $this->make_user( $id, "User {$id}", "u{$id}@e.com" ) );

        $page = new Training_Report_Page( $client );
        $rows = $page->generate_csv_rows();

        $this->assertCount( 31, $rows ); // 1 header + 30 data rows
    }

    public function test_generate_csv_rows_returns_only_header_when_no_students(): void {
        $client = $this->make_client();
        $client->shouldReceive( 'get_all_courses' )->andReturn( [ $this->make_course( 1, 'C1' ) ] );
        $client->shouldReceive( 'get_all_enrolled_user_ids' )->andReturn( [] );
        $client->shouldReceive( 'get_enrollment_matrix' )->andReturn( [] );

        $page = new Training_Report_Page( $client );
        $rows = $page->generate_csv_rows();

        $this->assertCount( 1, $rows );
    }
}
