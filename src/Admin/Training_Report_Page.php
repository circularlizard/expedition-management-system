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

        // phpcs:ignore WordPress.Security.EscapeOutput
        echo $this->render_legend();
        $this->render_pagination( $data );

        echo '<div class="ems-table-wrap">';
        echo '<table class="widefat striped ems-report-table" id="ems-report-table">';
        echo '<thead><tr>';
        echo '<th class="ems-col-name ems-sortable" data-col="0">Student Name <span class="ems-sort-ind"></span></th>';
        echo '<th class="ems-col-email ems-sortable" data-col="1">Email <span class="ems-sort-ind"></span></th>';
        $col_i = 2;
        foreach ( $courses as $course ) {
            printf(
                '<th class="ems-col-course ems-sortable" data-col="%d" title="%s"><span class="ems-col-text">%s</span><span class="ems-sort-ind"></span></th>',
                $col_i++,
                esc_attr( $course->post_title ),
                esc_html( $course->post_title )
            );
        }
        echo '</tr></thead><tbody>';

        foreach ( $students as $student ) {
            echo '<tr>';
            echo '<td class="ems-col-name">' . esc_html( $student->display_name ) . '</td>';
            echo '<td class="ems-col-email">' . esc_html( $student->user_email ) . '</td>';
            foreach ( $courses as $course ) {
                $status   = $matrix[ $student->ID ][ $course->ID ] ?? 'not_enrolled';
                $sort_val = match ( $status ) { 'complete' => 2, 'in_progress' => 1, default => 0 };
                // phpcs:ignore WordPress.Security.EscapeOutput
                echo '<td class="ems-col-status" data-sort="' . $sort_val . '">' . $this->render_status_icon( $status ) . '</td>';
            }
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';

        // phpcs:ignore WordPress.Security.EscapeOutput
        echo $this->render_legend();
        $this->render_pagination( $data );
        // phpcs:ignore WordPress.Security.EscapeOutput
        echo $this->sort_script();

        $diag = get_transient( 'ems_completion_diag' );
        if ( $diag ) {
            echo '<details style="margin-top:24px;border:1px solid #c3c4c7;padding:12px;background:#fff;">';
            echo '<summary style="cursor:pointer;font-weight:600;">&#128270; EMS Completion Diagnostic (first unresolved user)</summary>';
            printf(
                '<p>User ID: <strong>%d</strong> &nbsp;|&nbsp; Course ID: <strong>%d</strong> &nbsp;|&nbsp; Done: <strong>%d / %d</strong></p>',
                (int) $diag['user_id'],
                (int) $diag['course_id'],
                (int) $diag['done'],
                (int) $diag['total']
            );

            printf(
                '<p><strong>Content query — lessons found:</strong> %s</p>',
                esc_html( implode( ', ', $diag['content_lessons'] ) ?: '(none)' )
            );
            printf(
                '<p><strong>Content query — quizzes found:</strong> %s</p>',
                esc_html( implode( ', ', $diag['content_quizzes'] ) ?: '(none)' )
            );
            printf(
                '<p><strong>Content query — assignments found:</strong> %s</p>',
                esc_html( implode( ', ', $diag['content_assigns'] ) ?: '(none)' )
            );
            printf(
                '<p><strong>Lessons marked done by step 4:</strong> %s</p>',
                esc_html( implode( ', ', $diag['lesson_done_ids'] ) ?: '(none)' )
            );
            printf(
                '<p><strong>Assignments marked done by step 5.5:</strong> %s</p>',
                esc_html( implode( ', ', $diag['assignment_done_ids'] ?? [] ) ?: '(none)' )
            );

            if ( ! empty( $diag['cb_sample'] ) ) {
                $cols = array_keys( (array) $diag['cb_sample'][0] );
                echo '<p><strong>tutor_cb_content_usage — schema + 3 sample rows:</strong></p>';
                echo '<table class="widefat striped" style="max-width:900px;"><thead><tr>';
                foreach ( $cols as $col ) { printf( '<th>%s</th>', esc_html( $col ) ); }
                echo '</tr></thead><tbody>';
                foreach ( $diag['cb_sample'] as $row ) {
                    echo '<tr>';
                    foreach ( (array) $row as $val ) { printf( '<td><code>%s</code></td>', esc_html( substr( (string) $val, 0, 60 ) ) ); }
                    echo '</tr>';
                }
                echo '</tbody></table>';
            } else {
                echo '<p><em>tutor_cb_content_usage: no rows (table empty or does not exist).</em></p>';
            }

            if ( ! empty( $diag['cb_assign_rows'] ) ) {
                $cols = array_keys( (array) $diag['cb_assign_rows'][0] );
                echo '<p><strong>tutor_cb_content_usage rows for assignment 9546:</strong></p>';
                echo '<table class="widefat striped" style="max-width:900px;"><thead><tr>';
                foreach ( $cols as $col ) { printf( '<th>%s</th>', esc_html( $col ) ); }
                echo '</tr></thead><tbody>';
                foreach ( $diag['cb_assign_rows'] as $row ) {
                    echo '<tr>';
                    foreach ( (array) $row as $val ) { printf( '<td><code>%s</code></td>', esc_html( (string) $val ) ); }
                    echo '</tr>';
                }
                echo '</tbody></table>';
            } else {
                echo '<p><em>No rows in tutor_cb_content_usage for this assignment.</em></p>';
            }

            if ( ! empty( $diag['assign_child_rows'] ) ) {
                echo '<p><strong>wp_posts children of assignment definition(s) (any post_type):</strong></p>';
                echo '<table class="widefat striped" style="max-width:700px;">';
                echo '<thead><tr><th>ID</th><th>post_type</th><th>post_status</th><th>post_author</th><th>post_parent</th></tr></thead><tbody>';
                foreach ( $diag['assign_child_rows'] as $row ) {
                    printf(
                        '<tr><td>%d</td><td><code>%s</code></td><td><code>%s</code></td><td>%d</td><td>%d</td></tr>',
                        (int) $row->ID,
                        esc_html( $row->post_type ),
                        esc_html( $row->post_status ),
                        (int) $row->post_author,
                        (int) $row->post_parent
                    );
                }
                echo '</tbody></table>';
            } elseif ( ! empty( $diag['content_assigns'] ) ) {
                echo '<p><em>No child posts found for assignment definition(s) — submissions may be in a custom table.</em></p>';
            }

            if ( ! empty( $diag['lesson_post_rows'] ) ) {
                echo '<p><strong>wp_posts rows for completed lesson IDs:</strong></p>';
                echo '<table class="widefat striped" style="max-width:600px;">';
                echo '<thead><tr><th>ID</th><th>post_type</th><th>post_status</th><th>post_parent</th></tr></thead><tbody>';
                foreach ( $diag['lesson_post_rows'] as $row ) {
                    printf(
                        '<tr><td>%d</td><td><code>%s</code></td><td><code>%s</code></td><td>%d</td></tr>',
                        (int) $row->ID,
                        esc_html( $row->post_type ),
                        esc_html( $row->post_status ),
                        (int) $row->post_parent
                    );
                }
                echo '</tbody></table>';
            }

            echo '<hr>';
            if ( empty( $diag['meta_rows'] ) ) {
                echo '<p><em>No tutor-related usermeta rows found for this user.</em></p>';
            } else {
                echo '<p><strong>All tutor usermeta for this user:</strong></p>';
                echo '<table class="widefat striped" style="max-width:800px;">';
                echo '<thead><tr><th>meta_key</th><th>meta_value (truncated)</th></tr></thead><tbody>';
                foreach ( $diag['meta_rows'] as $row ) {
                    printf(
                        '<tr><td><code>%s</code></td><td><code>%s</code></td></tr>',
                        esc_html( $row->meta_key ),
                        esc_html( substr( $row->meta_value, 0, 120 ) )
                    );
                }
                echo '</tbody></table>';
            }
            echo '</details>';
        }

        echo '</div>';
    }

    private function page_styles(): string {
        return '
        <style>
        /* ── Layout ───────────────────────────────────────── */
        .ems-table-wrap { overflow-x: auto; width: 100%; }
        .ems-report-table { border-collapse: collapse; }
        .ems-report-table .ems-col-name  { min-width: 130px; white-space: nowrap; }
        .ems-report-table .ems-col-email { min-width: 170px; white-space: nowrap; }

        /* ── Rotated course headers — full text, no cutoff ── */
        .ems-report-table .ems-col-course {
            width: 34px; min-width: 34px; max-width: 34px;
            vertical-align: bottom; text-align: center;
            padding: 4px 2px 2px; overflow: visible;
        }
        .ems-col-text {
            display: inline-block;
            writing-mode: vertical-rl;
            transform: rotate(180deg);
            white-space: normal;
            word-break: break-word;
            font-size: 12px;
        }

        /* ── Status icons ─────────────────────────────────── */
        .ems-report-table .ems-col-status { text-align: center; padding: 3px 2px; }
        .ems-si {
            display: inline-flex; align-items: center; justify-content: center;
            width: 22px; height: 22px; border-radius: 50%;
            font-size: 13px; font-weight: 700; line-height: 1;
        }
        .ems-si-complete    { background: #00a32a; color: #fff; }
        .ems-si-in-progress { background: #2271b1; color: #fff; font-size: 10px; }
        .ems-si-none        { border: 2px solid #ddd; }

        /* ── Legend ───────────────────────────────────────── */
        .ems-legend {
            margin: 6px 0 8px; font-size: 13px;
            display: flex; align-items: center; gap: 18px; flex-wrap: wrap;
        }
        .ems-legend-item { display: flex; align-items: center; gap: 5px; }

        /* ── Sort controls ────────────────────────────────── */
        .ems-sortable { cursor: pointer; user-select: none; }
        .ems-sortable:hover { background: #f0f6fc; }
        .ems-sort-ind { font-size: 10px; color: #2271b1; }
        .ems-col-course .ems-sort-ind {
            display: block; margin-top: 3px;
            writing-mode: horizontal-tb; transform: none;
        }
        </style>
        ';
    }

    private function render_status_icon( string $status ): string {
        switch ( $status ) {
            case 'complete':
                return '<span class="ems-si ems-si-complete" title="Complete">&#10003;</span>';
            case 'in_progress':
                return '<span class="ems-si ems-si-in-progress" title="In Progress">&#9654;</span>';
            default:
                return '<span class="ems-si ems-si-none" title="Not Enrolled"></span>';
        }
    }

    private function render_legend(): string {
        return '
        <p class="ems-legend">
            <span class="ems-legend-item">
                <span class="ems-si ems-si-complete">&#10003;</span> Complete
            </span>
            <span class="ems-legend-item">
                <span class="ems-si ems-si-in-progress">&#9654;</span> In Progress
            </span>
            <span class="ems-legend-item">
                <span class="ems-si ems-si-none"></span> Not Enrolled
            </span>
        </p>';
    }

    private function sort_script(): string {
        return '<script>
        (function () {
            var table = document.getElementById("ems-report-table");
            if (!table) return;
            var tbody = table.tBodies[0];
            var ths   = Array.from(table.tHead.rows[0].cells);
            var state = { col: -1, asc: true };

            ths.forEach(function (th, i) {
                th.addEventListener("click", function () {
                    var asc = (state.col === i) ? !state.asc : (i >= 2 ? false : true);
                    state = { col: i, asc: asc };

                    Array.from(tbody.rows)
                        .sort(function (a, b) {
                            var ac = a.cells[i], bc = b.cells[i];
                            var av = ac.dataset.sort, bv = bc.dataset.sort;
                            if (av !== undefined && bv !== undefined) {
                                var d = parseInt(bv, 10) - parseInt(av, 10);
                                return asc ? -d : d;
                            }
                            var at = ac.textContent.trim(), bt = bc.textContent.trim();
                            return asc ? at.localeCompare(bt) : bt.localeCompare(at);
                        })
                        .forEach(function (r) { tbody.appendChild(r); });

                    ths.forEach(function (t) {
                        var ind = t.querySelector(".ems-sort-ind");
                        if (ind) ind.textContent = "";
                    });
                    var ind = th.querySelector(".ems-sort-ind");
                    if (ind) ind.textContent = asc ? " \u25b2" : " \u25bc";
                });
            });
        }());
        </script>';
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
