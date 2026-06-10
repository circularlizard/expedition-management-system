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

        // 3. All lesson + quiz IDs per course (through topics hierarchy)
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $content_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT c.ID as content_id, c.post_type as content_type, t.post_parent as course_id
             FROM {$wpdb->posts} c
             JOIN {$wpdb->posts} t ON c.post_parent = t.ID
             WHERE t.post_type   = 'topics'
             AND   t.post_status = 'publish'
             AND   c.post_status = 'publish'
             AND   c.post_type   IN ('tutor_lesson', 'tutor_quiz')
             AND   t.post_parent IN ({$c_ph})",
            ...$course_ids
        ) );

        $course_lessons = [];
        $course_quizzes = [];
        $all_lesson_ids = [];
        $all_quiz_ids   = [];
        foreach ( $content_rows as $row ) {
            $cid = (int) $row->course_id;
            $lid = (int) $row->content_id;
            if ( 'tutor_lesson' === $row->content_type ) {
                $course_lessons[ $cid ][] = $lid;
                $all_lesson_ids[]          = $lid;
            } else {
                $course_quizzes[ $cid ][] = $lid;
                $all_quiz_ids[]            = $lid;
            }
        }

        // 4. Completed lessons: _tutor_completed_lesson_id_* user meta (LIKE avoids huge IN clause)
        $lesson_done = [];
        if ( ! empty( $all_lesson_ids ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $lesson_meta_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT user_id, meta_key
                 FROM {$wpdb->usermeta}
                 WHERE user_id IN ({$u_ph})
                 AND   meta_key LIKE '_tutor_completed_lesson_id_%%'",
                ...$user_ids
            ) );
            $all_lesson_set = array_flip( $all_lesson_ids );
            foreach ( $lesson_meta_rows as $row ) {
                $lid = (int) str_replace( '_tutor_completed_lesson_id_', '', $row->meta_key );
                if ( isset( $all_lesson_set[ $lid ] ) ) {
                    $lesson_done[ (int) $row->user_id ][ $lid ] = true;
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
                $lessons = $course_lessons[ $cid ] ?? [];
                $quizzes = $course_quizzes[ $cid ] ?? [];
                $total   = count( $lessons ) + count( $quizzes );

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
                    if ( $done >= $total ) {
                        $matrix[ $uid ][ $cid ] = 'complete';
                        continue;
                    }
                }

                $matrix[ $uid ][ $cid ] = 'in_progress';
            }
        }

        return $matrix;
    }
}
