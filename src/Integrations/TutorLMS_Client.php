<?php
namespace EMS\Integrations;

class TutorLMS_Client {

    public function get_all_courses(): array {
        return get_posts( [
            'post_type'      => 'courses',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ] );
    }

    public function get_enrolled_students( int $course_id ): array {
        $enrollments = get_posts( [
            'post_type'      => 'tutor_enrolled',
            'post_parent'    => $course_id,
            'post_status'    => 'completed',
            'posts_per_page' => -1,
        ] );

        $students = [];
        foreach ( $enrollments as $enrollment ) {
            $user = get_userdata( $enrollment->post_author );
            if ( $user ) {
                $students[] = $user;
            }
        }

        return $students;
    }

    public function get_enrollment_status( int $course_id, int $user_id ): string {
        $enrollments = get_posts( [
            'post_type'      => 'tutor_enrolled',
            'author'         => $user_id,
            'post_parent'    => $course_id,
            'post_status'    => 'completed',
            'posts_per_page' => 1,
        ] );

        if ( empty( $enrollments ) ) {
            return 'not_enrolled';
        }

        $completed = get_user_meta( $user_id, '_tutor_completed_course_' . $course_id, true );

        return $completed ? 'complete' : 'in_progress';
    }

    /**
     * Returns distinct user IDs enrolled in any of the given course IDs.
     * Uses a single DB query — suitable for large data sets.
     *
     * @param int[] $course_ids
     * @return int[]
     */
    public function get_all_enrolled_user_ids( array $course_ids ): array {
        if ( empty( $course_ids ) ) {
            return [];
        }

        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $course_ids ), '%d' ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $results = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT post_author
             FROM {$wpdb->posts}
             WHERE post_type   = 'tutor_enrolled'
             AND   post_status = 'completed'
             AND   post_parent IN ({$placeholders})
             ORDER BY post_author ASC",
            ...$course_ids
        ) );

        return array_map( 'intval', $results );
    }

    /**
     * Returns a matrix of enrollment statuses for the given users and courses.
     * Uses 2 DB queries regardless of how many users/courses are given.
     *
     * @param int[] $user_ids
     * @param int[] $course_ids
     * @return array<int, array<int, string>>  $matrix[$user_id][$course_id] = 'complete'|'in_progress'|'not_enrolled'
     */
    public function get_enrollment_matrix( array $user_ids, array $course_ids ): array {
        if ( empty( $user_ids ) || empty( $course_ids ) ) {
            return [];
        }

        delete_transient( 'ems_completion_diag' );

        global $wpdb;
        $u_ph = implode( ',', array_fill( 0, count( $user_ids ),  '%d' ) );
        $c_ph = implode( ',', array_fill( 0, count( $course_ids ), '%d' ) );

        // 1. Active enrollments
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $enrolled_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT post_author, post_parent
             FROM {$wpdb->posts}
             WHERE post_type   = 'tutor_enrolled'
             AND   post_status = 'completed'
             AND   post_author IN ({$u_ph})
             AND   post_parent IN ({$c_ph})",
            ...array_merge( $user_ids, $course_ids )
        ) );

        $enrolled = [];
        foreach ( $enrolled_rows as $row ) {
            $enrolled[ (int) $row->post_author ][ (int) $row->post_parent ] = true;
        }

        // 2. Explicit completion meta (_tutor_completed_course_{course_id})
        $explicit_meta_keys = array_map( fn( $cid ) => '_tutor_completed_course_' . $cid, $course_ids );
        $em_ph              = implode( ',', array_fill( 0, count( $explicit_meta_keys ), '%s' ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $explicit_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT user_id, meta_key
             FROM {$wpdb->usermeta}
             WHERE user_id  IN ({$u_ph})
             AND   meta_key IN ({$em_ph})
             AND   meta_value != ''",
            ...array_merge( $user_ids, $explicit_meta_keys )
        ) );

        $explicitly_complete = [];
        foreach ( $explicit_rows as $row ) {
            $cid = (int) str_replace( '_tutor_completed_course_', '', $row->meta_key );
            $explicitly_complete[ (int) $row->user_id ][ $cid ] = true;
        }

        // 3. All lesson + quiz IDs per course (via topics hierarchy OR directly on course)
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $content_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT c.ID as content_id, c.post_type as content_type, t.post_parent as course_id
             FROM {$wpdb->posts} c
             JOIN {$wpdb->posts} t ON c.post_parent = t.ID
             WHERE t.post_type   = 'topics'
             AND   c.post_status IN ('publish', 'private')
             AND   c.post_type   IN ('tutor_lesson', 'lesson', 'tutor_quiz', 'tutor_assignments')
             AND   t.post_parent IN ({$c_ph})
             UNION
             SELECT c.ID as content_id, c.post_type as content_type, c.post_parent as course_id
             FROM {$wpdb->posts} c
             WHERE c.post_type   IN ('tutor_lesson', 'lesson', 'tutor_quiz', 'tutor_assignments')
             AND   c.post_status IN ('publish', 'private')
             AND   c.post_parent IN ({$c_ph})",
            ...$course_ids,
            ...$course_ids
        ) );

        $course_lessons     = [];
        $course_quizzes     = [];
        $course_assignments = [];
        $all_lesson_ids     = [];
        $all_quiz_ids       = [];
        $all_assignment_ids = [];
        foreach ( $content_rows as $row ) {
            $cid = (int) $row->course_id;
            $lid = (int) $row->content_id;
            if ( 'tutor_lesson' === $row->content_type || 'lesson' === $row->content_type ) {
                $course_lessons[ $cid ][] = $lid;
                $all_lesson_ids[]          = $lid;
            } elseif ( 'tutor_quiz' === $row->content_type ) {
                $course_quizzes[ $cid ][] = $lid;
                $all_quiz_ids[]            = $lid;
            } else {
                $course_assignments[ $cid ][] = $lid;
                $all_assignment_ids[]          = $lid;
            }
        }

        // 4. Completed content: TutorLMS Pro uses _tutor_completed_lesson_id_* for ALL content
        //    types — lessons AND assignments (and quizzes). Run whenever any content exists.
        $lesson_done    = [];
        $assignment_done = [];
        $all_lesson_set = ! empty( $all_lesson_ids )     ? array_flip( $all_lesson_ids )     : [];
        $all_assign_set = ! empty( $all_assignment_ids ) ? array_flip( $all_assignment_ids ) : [];
        if ( ! empty( $all_lesson_ids ) || ! empty( $all_assignment_ids ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $lesson_meta_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT user_id, meta_key
                 FROM {$wpdb->usermeta}
                 WHERE user_id IN ({$u_ph})
                 AND   meta_key LIKE '_tutor_completed_lesson_id_%%'",
                ...$user_ids
            ) );
            foreach ( $lesson_meta_rows as $row ) {
                $lid = (int) str_replace( '_tutor_completed_lesson_id_', '', $row->meta_key );
                if ( isset( $all_lesson_set[ $lid ] ) ) {
                    $lesson_done[ (int) $row->user_id ][ $lid ] = true;
                }
                // TutorLMS Pro also fires this meta for assignment completions.
                if ( isset( $all_assign_set[ $lid ] ) ) {
                    $assignment_done[ (int) $row->user_id ][ $lid ] = true;
                }
            }
        }

        // 4b. Completed lessons via TutorLMS Pro reading-info meta:
        //     _tutor_reading_info_{course_id} stores a serialised array of completed lesson IDs.
        if ( ! empty( $course_ids ) && ! empty( $all_lesson_ids ) ) {
            $reading_keys = array_map( fn( $cid ) => '_tutor_reading_info_' . $cid, $course_ids );
            $ri_ph        = implode( ',', array_fill( 0, count( $reading_keys ), '%s' ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $reading_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT user_id, meta_key, meta_value
                 FROM {$wpdb->usermeta}
                 WHERE user_id  IN ({$u_ph})
                 AND   meta_key IN ({$ri_ph})",
                ...array_merge( $user_ids, $reading_keys )
            ) );
            foreach ( $reading_rows as $row ) {
                $read_ids = maybe_unserialize( $row->meta_value );
                if ( ! is_array( $read_ids ) ) {
                    continue;
                }
                foreach ( array_keys( $read_ids ) as $lid ) {
                    $lid = (int) $lid;
                    if ( isset( $all_lesson_set[ $lid ] ) ) {
                        $lesson_done[ (int) $row->user_id ][ $lid ] = true;
                    }
                }
            }
        }

        // 5. Completed quizzes: tutor_quiz_attempts (attempt_status != 'attempt_started')
        $quiz_done = [];
        if ( ! empty( $all_quiz_ids ) ) {
            $q_ph           = implode( ',', array_fill( 0, count( $all_quiz_ids ), '%d' ) );
            $attempts_table = $wpdb->prefix . 'tutor_quiz_attempts';
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $attempt_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT DISTINCT user_id, quiz_id
                 FROM {$attempts_table}
                 WHERE user_id IN ({$u_ph})
                 AND   quiz_id IN ({$q_ph})
                 AND   attempt_status != %s",
                ...array_merge( $user_ids, $all_quiz_ids, [ 'attempt_started' ] )
            ) );
            foreach ( $attempt_rows as $row ) {
                $quiz_done[ (int) $row->user_id ][ (int) $row->quiz_id ] = true;
            }
        }

        // 5.5 Submitted assignments: wp_posts fallback for standard TutorLMS Free installs.
        //     TutorLMS Pro uses _tutor_completed_lesson_id_* (step 4) instead.
        if ( ! empty( $all_assignment_ids ) ) {
            $a_ph            = implode( ',', array_fill( 0, count( $all_assignment_ids ), '%d' ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $submission_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT DISTINCT post_author, post_parent
                 FROM {$wpdb->posts}
                 WHERE post_type   = 'tutor_assignments'
                 AND   post_author IN ({$u_ph})
                 AND   post_parent IN ({$a_ph})
                 AND   post_status NOT IN ('auto-draft', 'trash')",
                ...array_merge( $user_ids, $all_assignment_ids )
            ) );
            foreach ( $submission_rows as $row ) {
                $assignment_done[ (int) $row->post_author ][ (int) $row->post_parent ] = true;
            }

        }

        // Build matrix
        $matrix = [];
        foreach ( $user_ids as $uid ) {
            foreach ( $course_ids as $cid ) {
                if ( ! isset( $enrolled[ $uid ][ $cid ] ) ) {
                    $matrix[ $uid ][ $cid ] = 'not_enrolled';
                    continue;
                }

                if ( isset( $explicitly_complete[ $uid ][ $cid ] ) ) {
                    $matrix[ $uid ][ $cid ] = 'complete';
                    continue;
                }

                // Fall back to content-level 100% detection
                $lessons     = $course_lessons[ $cid ] ?? [];
                $quizzes     = $course_quizzes[ $cid ] ?? [];
                $assignments = $course_assignments[ $cid ] ?? [];
                $total       = count( $lessons ) + count( $quizzes ) + count( $assignments );

                if ( $total > 0 ) {
                    $done = 0;
                    foreach ( $lessons as $lid ) {
                        if ( isset( $lesson_done[ $uid ][ $lid ] ) ) {
                            ++$done;
                        }
                    }
                    foreach ( $quizzes as $qid ) {
                        if ( isset( $quiz_done[ $uid ][ $qid ] ) ) {
                            ++$done;
                        }
                    }
                    foreach ( $assignments as $aid ) {
                        if ( isset( $assignment_done[ $uid ][ $aid ] ) ) {
                            ++$done;
                        }
                    }
                    if ( $done >= $total ) {
                        $matrix[ $uid ][ $cid ] = 'complete';
                        continue;
                    }
                }

                $matrix[ $uid ][ $cid ] = 'in_progress';

                // Diagnostic: store rich context for the first unresolved enrolled user
                // so the admin UI can show exactly what the content query found vs what
                // lesson-completion meta exists — revealing any ID mismatch or $total=0.
                static $diag_saved = false;
                $has_assignments = ! empty( $course_assignments[ $cid ] );
                if ( ! $diag_saved && $has_assignments ) {
                    $diag_saved = true;
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $meta_rows = $wpdb->get_results( $wpdb->prepare(
                        "SELECT meta_key, meta_value
                         FROM {$wpdb->usermeta}
                         WHERE user_id = %d
                         AND   meta_key LIKE '%%tutor%%'",
                        $uid
                    ) );
                    // Look up the actual wp_posts rows for any completed-lesson IDs we
                    // found in meta, so we can see their real post_type / post_status /
                    // post_parent and understand why the content query missed them.
                    $completed_lesson_ids = [];
                    foreach ( $meta_rows as $mr ) {
                        if ( 0 === strpos( $mr->meta_key, '_tutor_completed_lesson_id_' ) ) {
                            $completed_lesson_ids[] = (int) str_replace( '_tutor_completed_lesson_id_', '', $mr->meta_key );
                        }
                    }
                    $lesson_post_rows = [];
                    if ( ! empty( $completed_lesson_ids ) ) {
                        $lp_ph = implode( ',', array_fill( 0, count( $completed_lesson_ids ), '%d' ) );
                        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                        $lesson_post_rows = $wpdb->get_results( $wpdb->prepare(
                            "SELECT ID, post_type, post_status, post_parent
                             FROM {$wpdb->posts}
                             WHERE ID IN ({$lp_ph})",
                            ...$completed_lesson_ids
                        ) );
                    }

                    // Sample the tutor_cb_content_usage table (TutorLMS Pro) to understand
                    // its schema and check if assignment submissions are stored there.
                    $cb_table      = $wpdb->prefix . 'tutor_cb_content_usage';
                    $cb_sample     = $wpdb->get_results( "SELECT * FROM {$cb_table} LIMIT 3" ); // phpcs:ignore
                    $assign_ids_for_course = $course_assignments[ $cid ] ?? [];
                    $cb_assign_rows = [];
                    if ( ! empty( $assign_ids_for_course ) ) {
                        $ca_ph = implode( ',', array_fill( 0, count( $assign_ids_for_course ), '%d' ) );
                        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                        $cb_assign_rows = $wpdb->get_results( $wpdb->prepare(
                            "SELECT * FROM {$cb_table} WHERE content_id IN ({$ca_ph}) LIMIT 10",
                            ...$assign_ids_for_course
                        ) );
                    }

                    // Keep the child-post lookup as a fallback check.
                    $assign_child_rows = [];
                    $assign_def_ids    = $course_assignments[ $cid ] ?? [];
                    if ( ! empty( $assign_def_ids ) ) {
                        $ad_ph = implode( ',', array_fill( 0, count( $assign_def_ids ), '%d' ) );
                        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                        $assign_child_rows = $wpdb->get_results( $wpdb->prepare(
                            "SELECT ID, post_type, post_status, post_author, post_parent
                             FROM {$wpdb->posts}
                             WHERE post_parent IN ({$ad_ph})
                             LIMIT 10",
                            ...$assign_def_ids
                        ) );
                    }

                    set_transient( 'ems_completion_diag', [
                        'user_id'            => $uid,
                        'course_id'          => $cid,
                        'content_lessons'    => $course_lessons[ $cid ]     ?? [],
                        'content_quizzes'    => $course_quizzes[ $cid ]     ?? [],
                        'content_assigns'    => $course_assignments[ $cid ] ?? [],
                        'total'              => $total,
                        'done'               => $done,
                        'lesson_done_ids'    => array_keys( $lesson_done[ $uid ]     ?? [] ),
                        'assignment_done_ids'=> array_keys( $assignment_done[ $uid ] ?? [] ),
                        'cb_sample'          => $cb_sample,
                        'cb_assign_rows'     => $cb_assign_rows,
                        'assign_child_rows'  => $assign_child_rows,
                        'lesson_post_rows'   => $lesson_post_rows,
                        'meta_rows'          => $meta_rows,
                    ], HOUR_IN_SECONDS );
                }
            }
        }

        return $matrix;
    }
}
