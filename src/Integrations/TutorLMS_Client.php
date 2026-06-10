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
            'post_author'    => $user_id,
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
}
