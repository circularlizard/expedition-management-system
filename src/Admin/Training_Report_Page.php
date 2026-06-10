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

    private const PER_PAGE = 25;

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
        $page       = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
        $data       = $this->build_report_data( $page );
        $courses    = $data['courses'];
        $students   = $data['students'];
        $matrix     = $data['matrix'];
        $export_url = wp_nonce_url(
            add_query_arg( [ 'page' => 'ems-training-report', 'export' => 'csv' ], admin_url( 'admin.php' ) ),
            'ems_csv_export'
        );

        echo '<div class="wrap">';
        echo '<h1>Training Completion Report</h1>';
        // phpcs:ignore WordPress.Security.EscapeOutput
        echo $this->page_styles();
        echo '<p><a href="' . esc_url( $export_url ) . '" class="button button-primary">&#8595; Export CSV</a></p>';

        if ( empty( $students ) ) {
            echo '<p>No enrolled students found.</p></div>';
            return;
        }

        $this->render_pagination( $data );

        echo '<div class="ems-table-wrap">';
        echo '<table class="widefat striped ems-report-table">';
        echo '<thead><tr>';
        echo '<th class="ems-col-name">Student Name</th><th class="ems-col-email">Email</th>';
        foreach ( $courses as $course ) {
            echo '<th class="ems-col-course"><span>' . esc_html( $course->post_title ) . '</span></th>';
        }
        echo '</tr></thead><tbody>';

        foreach ( $students as $student ) {
            echo '<tr>';
            echo '<td class="ems-col-name">' . esc_html( $student->display_name ) . '</td>';
            echo '<td class="ems-col-email">' . esc_html( $student->user_email ) . '</td>';
            foreach ( $courses as $course ) {
                $status = $matrix[ $student->ID ][ $course->ID ] ?? 'not_enrolled';
                // phpcs:ignore WordPress.Security.EscapeOutput
                echo '<td class="ems-col-status">' . $this->render_status_badge( $status ) . '</td>';
            }
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';

        $this->render_pagination( $data );
        echo '</div>';
    }

    private function page_styles(): string {
        return '
        <style>
        .ems-table-wrap { overflow-x: auto; width: 100%; }
        .ems-report-table { border-collapse: collapse; }
        .ems-report-table .ems-col-name { min-width: 120px; white-space: nowrap; }
        .ems-report-table .ems-col-email { min-width: 160px; white-space: nowrap; }
        .ems-report-table .ems-col-course {
            width: 32px; min-width: 32px; max-width: 32px;
            height: 150px; vertical-align: bottom;
            padding: 4px 2px; text-align: center;
        }
        .ems-report-table .ems-col-course > span {
            display: inline-block;
            writing-mode: vertical-rl;
            transform: rotate(180deg);
            white-space: nowrap;
            font-size: 12px;
            max-height: 145px;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .ems-report-table .ems-col-status { text-align: center; padding: 4px 2px; }
        .ems-badge {
            display: inline-block; padding: 2px 6px;
            border-radius: 3px; font-size: 11px; line-height: 1.5;
            white-space: nowrap;
        }
        .ems-badge-complete    { background: #d4edda; color: #155724; }
        .ems-badge-in-progress { background: #cce5ff; color: #004085; }
        .ems-badge-not-enrolled { color: #aaa; font-size: 13px; }
        </style>
        ';
    }

    private function render_status_badge( string $status ): string {
        switch ( $status ) {
            case 'complete':
                return '<span class="ems-badge ems-badge-complete">&#10003; Complete</span>';
            case 'in_progress':
                return '<span class="ems-badge ems-badge-in-progress">In Progress</span>';
            default:
                return '<span class="ems-badge-not-enrolled">&mdash;</span>';
        }
    }

    private function render_pagination( array $data ): void {
        $from  = ( ( $data['page'] - 1 ) * self::PER_PAGE ) + 1;
        $to    = min( $data['page'] * self::PER_PAGE, $data['total'] );
        $label = sprintf( "Showing %d\u{2013}%d of %d students", $from, $to, $data['total'] );

        echo '<div class="tablenav">';
        echo '<div class="tablenav-pages">';
        echo '<span class="displaying-num">' . esc_html( $label ) . '</span>';

        if ( $data['pages'] > 1 ) {
            $base_url   = add_query_arg( 'page', 'ems-training-report', admin_url( 'admin.php' ) );
            $pagination = paginate_links( [
                'base'      => add_query_arg( 'paged', '%#%', $base_url ),
                'format'    => '',
                'current'   => $data['page'],
                'total'     => $data['pages'],
                'type'      => 'plain',
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
            ] );
            // phpcs:ignore WordPress.Security.EscapeOutput
            echo '<span class="pagination-links">' . $pagination . '</span>';
        }

        echo '</div><br class="clear"></div>';
    }

    public function build_report_data( int $page = 1 ): array {
        $courses    = $this->client->get_all_courses();
        $course_ids = array_map( fn( $c ) => $c->ID, $courses );

        $all_ids = $this->client->get_all_enrolled_user_ids( $course_ids );
        $total   = count( $all_ids );
        $pages   = max( 1, (int) ceil( $total / self::PER_PAGE ) );
        $page    = max( 1, min( $page, $pages ) );
        $ids     = array_slice( $all_ids, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE );

        $students = [];
        foreach ( $ids as $uid ) {
            $user = get_userdata( $uid );
            if ( $user ) {
                $students[] = $user;
            }
        }

        $matrix = $this->client->get_enrollment_matrix( $ids, $course_ids );

        return [
            'courses'  => $courses,
            'students' => $students,
            'matrix'   => $matrix,
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
        ];
    }

    public function generate_csv_rows(): array {
        $courses    = $this->client->get_all_courses();
        $course_ids = array_map( fn( $c ) => $c->ID, $courses );
        $all_ids    = $this->client->get_all_enrolled_user_ids( $course_ids );
        $matrix     = $this->client->get_enrollment_matrix( $all_ids, $course_ids );

        $header = [ 'Student Name', 'Email' ];
        foreach ( $courses as $course ) {
            $header[] = $course->post_title;
        }
        $rows = [ $header ];

        foreach ( $all_ids as $uid ) {
            $user = get_userdata( $uid );
            if ( ! $user ) {
                continue;
            }
            $row = [ $user->display_name, $user->user_email ];
            foreach ( $courses as $course ) {
                $status = $matrix[ $uid ][ $course->ID ] ?? 'not_enrolled';
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
