<?php
namespace EMS\Admin;

use EMS\Integrations\TutorLMS_Client;

class Training_Report_Page {

    private TutorLMS_Client $client;

    private const STATUS_LABELS = [
        'complete'     => 'Complete',
        'in_progress'  => 'In Progress',
        'not_enrolled' => 'Not Enrolled',
    ];

    public function __construct( TutorLMS_Client $client ) {
        $this->client = $client;
    }

    public function register(): void {
        add_action( 'admin_init', [ $this, 'maybe_export_csv' ] );

        add_menu_page(
            'EMS',
            'EMS',
            'manage_options',
            'ems',
            '__return_null',
            'dashicons-location-alt',
            80
        );

        add_submenu_page(
            'ems',
            'Training Report',
            'Training Report',
            'manage_options',
            'ems-training-report',
            [ $this, 'render' ]
        );
    }

    public function maybe_export_csv(): void {
        if (
            ! isset( $_GET['page'], $_GET['export'] ) ||
            $_GET['page'] !== 'ems-training-report' ||
            $_GET['export'] !== 'csv'
        ) {
            return;
        }

        check_admin_referer( 'ems_csv_export' );
        $this->output_csv();
        exit;
    }

    public function render(): void {
        $data        = $this->build_report_data();
        $courses     = $data['courses'];
        $students    = $data['students'];
        $matrix      = $data['matrix'];
        $export_url  = wp_nonce_url(
            add_query_arg( [ 'page' => 'ems-training-report', 'export' => 'csv' ], admin_url( 'admin.php' ) ),
            'ems_csv_export'
        );

        echo '<div class="wrap">';
        echo '<h1>Training Completion Report</h1>';
        echo '<p><a href="' . esc_url( $export_url ) . '" class="button button-primary">Export CSV</a></p>';

        if ( empty( $students ) ) {
            echo '<p>No enrolled students found.</p></div>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>Student Name</th><th>Email</th>';
        foreach ( $courses as $course ) {
            echo '<th>' . esc_html( $course->post_title ) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ( $students as $student ) {
            echo '<tr>';
            echo '<td>' . esc_html( $student->display_name ) . '</td>';
            echo '<td>' . esc_html( $student->user_email ) . '</td>';
            foreach ( $courses as $course ) {
                $status = $matrix[ $student->ID ][ $course->ID ] ?? 'not_enrolled';
                $label  = self::STATUS_LABELS[ $status ] ?? $status;
                $class  = 'ems-status-' . str_replace( '_', '-', $status );
                echo '<td class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</td>';
            }
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    public function build_report_data(): array {
        $courses = $this->client->get_all_courses();

        $students_by_id = [];
        foreach ( $courses as $course ) {
            foreach ( $this->client->get_enrolled_students( $course->ID ) as $student ) {
                $students_by_id[ $student->ID ] = $student;
            }
        }

        $matrix = [];
        foreach ( $students_by_id as $user_id => $student ) {
            foreach ( $courses as $course ) {
                $matrix[ $user_id ][ $course->ID ] = $this->client->get_enrollment_status( $course->ID, $user_id );
            }
        }

        return [
            'courses'  => $courses,
            'students' => array_values( $students_by_id ),
            'matrix'   => $matrix,
        ];
    }

    public function generate_csv_rows(): array {
        $data = $this->build_report_data();

        $rows   = [];
        $header = [ 'Student Name', 'Email' ];
        foreach ( $data['courses'] as $course ) {
            $header[] = $course->post_title;
        }
        $rows[] = $header;

        foreach ( $data['students'] as $student ) {
            $row = [ $student->display_name, $student->user_email ];
            foreach ( $data['courses'] as $course ) {
                $status = $data['matrix'][ $student->ID ][ $course->ID ] ?? 'not_enrolled';
                $row[]  = self::STATUS_LABELS[ $status ] ?? $status;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function output_csv(): void {
        $filename = 'ems-training-report-' . gmdate( 'Y-m-d' ) . '.csv';
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );

        $output = fopen( 'php://output', 'w' );
        foreach ( $this->generate_csv_rows() as $row ) {
            fputcsv( $output, $row );
        }
        fclose( $output );
    }
}
