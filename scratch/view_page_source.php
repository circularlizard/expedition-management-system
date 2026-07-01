<?php
/**
 * Render form-test page source as logged-in user
 */

$parent_user = get_user_by( 'email', 'parent@example.com' );
if ( ! $parent_user ) {
    $users = get_users( [ 'role' => 'ems_parent' ] );
    $parent_user = ! empty( $users ) ? $users[0] : get_user_by( 'id', 1 );
}

if ( $parent_user ) {
    wp_set_current_user( $parent_user->ID );
}

// Fetch post ID 7 directly
$page = get_post( 7 );

if ( $page ) {
    $content = apply_filters( 'the_content', $page->post_content );
    file_put_contents( __DIR__ . '/form_test_rendered.html', $content );
    echo "Rendered HTML written to scratch/form_test_rendered.html\n";
} else {
    echo "Error: form-test page not found.\n";
}
