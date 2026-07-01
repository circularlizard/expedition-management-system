<?php
/**
 * Test Hook Output
 * Run: docker compose run --rm wpcli eval-file mock_html.php
 */

$parent_user = get_user_by( 'email', 'parent@example.com' );
if ( ! $parent_user ) {
    $users = get_users( [ 'role' => 'ems_parent' ] );
    $parent_user = ! empty( $users ) ? $users[0] : get_user_by( 'id', 1 );
}

if ( $parent_user ) {
    wp_set_current_user( $parent_user->ID );
    update_user_meta( $parent_user->ID, 'ems_children', [
        [ 'scout_id' => 30001, 'first_name' => 'Mary', 'last_name' => 'Smith', 'section_ids' => [ 99001 ] ]
    ] );
}

$form = (object) [ 'id' => 4 ];
do_action( 'fluentform/before_form_render', $form, [] );
