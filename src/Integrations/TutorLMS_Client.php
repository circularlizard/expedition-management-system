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

        // --- enrollments ---
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

        // --- completion meta ---
        $meta_keys = array_map( fn( $cid ) => '_tutor_completed_course_' . $cid, $course_ids );
        $m_ph      = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $completion_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT user_id, meta_key
             FROM {$wpdb->usermeta}
             WHERE user_id  IN ({$u_ph})
             AND   meta_key IN ({$m_ph})
             AND   meta_value != ''",
            ...array_merge( $user_ids, $meta_keys )
        ) );

        $completed = [];
        foreach ( $completion_rows as $row ) {
            $cid = (int) str_replace( '_tutor_completed_course_', '', $row->meta_key );
            $completed[ (int) $row->user_id ][ $cid ] = true;
        }

        // --- build matrix ---
        $matrix = [];
        foreach ( $user_ids as $uid ) {
            foreach ( $course_ids as $cid ) {
                if ( ! isset( $enrolled[ $uid ][ $cid ] ) ) {
                    $matrix[ $uid ][ $cid ] = 'not_enrolled';
                } elseif ( isset( $completed[ $uid ][ $cid ] ) ) {
                    $matrix[ $uid ][ $cid ] = 'complete';
                } else {
                    $matrix[ $uid ][ $cid ] = 'in_progress';
                }
            }
        }

        return $matrix;
    }
}
