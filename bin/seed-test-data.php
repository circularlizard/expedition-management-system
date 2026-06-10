<?php
/**
 * WP-CLI seed script for Phase 0 test data.
 * Run via: docker-compose run --rm wpcli eval-file wp-content/plugins/ems-plugin/bin/seed-test-data.php
 *
 * Idempotent — safe to run multiple times.
 */

// --- Courses ----------------------------------------------------------------

$course_titles = [
    'Bronze DofE: Expedition Planning',
    'Silver DofE: Navigation Skills',
    'Gold DofE: Leadership & Safety',
];

$course_ids = [];
foreach ( $course_titles as $title ) {
    $existing = get_posts( [
        'post_type'   => 'courses',
        'post_status' => 'publish',
        'title'       => $title,
    ] );

    if ( $existing ) {
        $course_ids[] = $existing[0]->ID;
        WP_CLI::log( "  Course already exists: {$title} (ID: {$existing[0]->ID})" );
        continue;
    }

    $id = wp_insert_post( [
        'post_type'   => 'courses',
        'post_title'  => $title,
        'post_status' => 'publish',
    ], true );

    if ( is_wp_error( $id ) ) {
        WP_CLI::error( "Failed to create course '{$title}': " . $id->get_error_message() );
    }

    $course_ids[] = $id;
    WP_CLI::success( "  Created course: {$title} (ID: {$id})" );
}

// --- Students ---------------------------------------------------------------

$students = [
    [ 'alice',  'alice@example.com',  'Alice',  'Smith'   ],
    [ 'bob',    'bob@example.com',    'Bob',    'Jones'   ],
    [ 'carol',  'carol@example.com',  'Carol',  'Brown'   ],
    [ 'dave',   'dave@example.com',   'Dave',   'Wilson'  ],
    [ 'eve',    'eve@example.com',    'Eve',    'Taylor'  ],
];

$user_ids = [];
foreach ( $students as [ $login, $email, $first, $last ] ) {
    $existing = get_user_by( 'email', $email );

    if ( $existing ) {
        $user_ids[] = $existing->ID;
        WP_CLI::log( "  User already exists: {$email} (ID: {$existing->ID})" );
        continue;
    }

    $id = wp_create_user( $login, 'password', $email );

    if ( is_wp_error( $id ) ) {
        WP_CLI::error( "Failed to create user '{$email}': " . $id->get_error_message() );
    }

    wp_update_user( [
        'ID'           => $id,
        'first_name'   => $first,
        'last_name'    => $last,
        'display_name' => "{$first} {$last}",
    ] );

    $user_ids[] = $id;
    WP_CLI::success( "  Created user: {$email} (ID: {$id})" );
}

// --- Enrollments ------------------------------------------------------------
// Enrol every student in every course.

foreach ( $user_ids as $uid ) {
    foreach ( $course_ids as $cid ) {
        $existing = get_posts( [
            'post_type'    => 'tutor_enrolled',
            'author__in'   => [ $uid ],
            'post_parent'  => $cid,
            'post_status'  => 'any',
        ] );

        if ( $existing ) {
            WP_CLI::log( "  Already enrolled: user {$uid} in course {$cid}" );
            continue;
        }

        wp_insert_post( [
            'post_type'   => 'tutor_enrolled',
            'post_title'  => 'Course Enrolled',
            'post_status' => 'completed',
            'post_author' => $uid,
            'post_parent' => $cid,
        ] );

        WP_CLI::log( "  Enrolled user {$uid} in course {$cid}" );
    }
}

// --- Completion matrix ------------------------------------------------------
// alice  [0]: all 3 courses complete
// bob    [1]: courses 0 & 1 complete, course 2 in progress
// carol  [2]: course 0 complete only
// dave   [3]: all enrolled, none complete
// eve    [4]: all enrolled, none complete

$completions = [
    0 => [ 0, 1, 2 ],
    1 => [ 0, 1 ],
    2 => [ 0 ],
    3 => [],
    4 => [],
];

foreach ( $completions as $u_idx => $c_indices ) {
    foreach ( $c_indices as $c_idx ) {
        $uid = $user_ids[ $u_idx ];
        $cid = $course_ids[ $c_idx ];
        update_user_meta( $uid, '_tutor_completed_course_' . $cid, time() );
        WP_CLI::log( "  Marked course {$cid} complete for user {$uid}" );
    }
}

WP_CLI::success( 'Seed complete.' );
