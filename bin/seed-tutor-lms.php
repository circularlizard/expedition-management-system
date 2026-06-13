<?php
/**
 * WP-CLI seed script for Phase 1 Tutor LMS test data.
 * Run via: docker compose run --rm wpcli eval-file wp-content/plugins/ems-plugin/bin/seed-tutor-lms.php
 *
 * Idempotent — safe to run multiple times.
 */

// --- Helpers ----------------------------------------------------------------

function ems_get_or_create_course( $title ) {
    $existing = get_posts( [
        'post_type'   => 'courses',
        'post_status' => 'publish',
        'title'       => $title,
    ] );

    if ( $existing ) {
        return $existing[0]->ID;
    }

    $id = wp_insert_post( [
        'post_type'   => 'courses',
        'post_title'  => $title,
        'post_status' => 'publish',
    ] );

    if ( is_wp_error( $id ) ) {
        WP_CLI::error( "Failed to create course '{$title}': " . $id->get_error_message() );
    }

    WP_CLI::success( "Created course: {$title} (ID: {$id})" );
    return $id;
}

function ems_get_or_create_user( $login, $first, $last ) {
    $email = $login . '@example.com';
    $existing = get_user_by( 'login', $login );

    if ( $existing ) {
        return $existing->ID;
    }

    $id = wp_create_user( $login, 'password', $email );

    if ( is_wp_error( $id ) ) {
        WP_CLI::error( "Failed to create user '{$login}': " . $id->get_error_message() );
    }

    wp_update_user( [
        'ID'           => $id,
        'first_name'   => $first,
        'last_name'    => $last,
        'display_name' => "{$first} {$last}",
    ] );

    WP_CLI::success( "Created user: {$login} (ID: {$id})" );
    return $id;
}

function ems_enroll_user( $uid, $cid ) {
    $existing = get_posts( [
        'post_type'    => 'tutor_enrolled',
        'author'       => $uid,
        'post_parent'  => $cid,
        'post_status'  => 'any',
    ] );

    if ( $existing ) {
        return $existing[0]->ID;
    }

    $id = wp_insert_post( [
        'post_type'   => 'tutor_enrolled',
        'post_title'  => 'Course Enrolled',
        'post_status' => 'completed',
        'post_author' => $uid,
        'post_parent' => $cid,
    ] );

    WP_CLI::log( "Enrolled user {$uid} in course {$cid}" );
    return $id;
}

function ems_create_lesson( $cid, $title ) {
    $existing = get_posts( [
        'post_type'   => 'tutor_lesson',
        'post_parent' => $cid,
        'title'       => $title,
    ] );

    if ( $existing ) {
        return $existing[0]->ID;
    }

    $id = wp_insert_post( [
        'post_type'   => 'tutor_lesson',
        'post_title'  => $title,
        'post_status' => 'publish',
        'post_parent' => $cid,
    ] );

    WP_CLI::log( "Created lesson: {$title} (ID: {$id}) in course {$cid}" );
    return $id;
}

function ems_create_assignment( $cid, $title ) {
    $existing = get_posts( [
        'post_type'   => 'tutor_assignments',
        'post_parent' => $cid,
        'title'       => $title,
    ] );

    if ( $existing ) {
        return $existing[0]->ID;
    }

    $id = wp_insert_post( [
        'post_type'   => 'tutor_assignments',
        'post_title'  => $title,
        'post_status' => 'publish',
        'post_parent' => $cid,
    ] );

    WP_CLI::log( "Created assignment: {$title} (ID: {$id}) in course {$cid}" );
    return $id;
}

// --- Main -------------------------------------------------------------------

WP_CLI::log( "==> Seeding Tutor LMS Mock Data..." );

// 1. Basic Course with explicit completion
$c1_id = ems_get_or_create_course( 'Basic Navigation' );
$u1_id = ems_get_or_create_user( 'tutor_alice', 'Alice', 'Tutor' );
ems_enroll_user( $u1_id, $c1_id );
update_user_meta( $u1_id, '_tutor_completed_course_' . $c1_id, time() );
WP_CLI::log( "Alice marked explicitly complete for Basic Navigation" );

// 2. Course with lessons (100% detection)
$c2_id = ems_get_or_create_course( 'Advanced Expedition Planning' );
$l1_id = ems_create_lesson( $c2_id, 'Route Mapping' );
$l2_id = ems_create_lesson( $c2_id, 'Risk Assessment' );

// User 2: Bob - In Progress (1/2 lessons)
$u2_id = ems_get_or_create_user( 'tutor_bob', 'Bob', 'Tutor' );
ems_enroll_user( $u2_id, $c2_id );
update_user_meta( $u2_id, '_tutor_completed_lesson_id_' . $l1_id, time() );
WP_CLI::log( "Bob completed 1/2 lessons for Advanced Planning" );

// User 3: Charlie - Complete via content (2/2 lessons)
$u3_id = ems_get_or_create_user( 'tutor_charlie', 'Charlie', 'Tutor' );
ems_enroll_user( $u3_id, $c2_id );
update_user_meta( $u3_id, '_tutor_completed_lesson_id_' . $l1_id, time() );
update_user_meta( $u3_id, '_tutor_completed_lesson_id_' . $l2_id, time() );
WP_CLI::log( "Charlie completed 2/2 lessons for Advanced Planning" );

// 3. Course with assignments
$c3_id = ems_get_or_create_course( 'First Aid for Expeditions' );
$a1_id = ems_create_assignment( $c3_id, 'Primary Survey Case Study' );

// User 4: Diana - Complete via assignment submission
$u4_id = ems_get_or_create_user( 'tutor_diana', 'Diana', 'Tutor' );
ems_enroll_user( $u4_id, $c3_id );

// Simulate assignment submission (post type 'tutor_assignments' submission)
$existing_sub = get_posts( [
    'post_type'   => 'tutor_assignments', // In standard TutorLMS, submissions are often just posts with parent = assignment_id
    'post_author' => $u4_id,
    'post_parent' => $a1_id,
] );

if ( ! $existing_sub ) {
    wp_insert_post( [
        'post_type'   => 'tutor_assignments',
        'post_title'  => 'Diana\'s Submission',
        'post_status' => 'publish',
        'post_author' => $u4_id,
        'post_parent' => $a1_id,
    ] );
    WP_CLI::log( "Diana submitted assignment for First Aid" );
}

// Also add a submission via comment (fallback path)
$u5_id = ems_get_or_create_user( 'tutor_edward', 'Edward', 'Tutor' );
ems_enroll_user( $u5_id, $c3_id );
$comment_data = [
    'comment_post_ID' => $a1_id,
    'user_id'         => $u5_id,
    'comment_content' => 'Edward\'s assignment submission',
    'comment_type'    => 'tutor_assignment',
    'comment_approved'=> 1,
];
wp_insert_comment( $comment_data );
WP_CLI::log( "Edward submitted assignment via comment for First Aid" );

WP_CLI::success( "Tutor LMS seeding complete." );
