<?php
/**
 * Scratch script to simulate and debug a Fluent Forms submission.
 * Run this script via WP-CLI inside the container:
 * wp eval-file scratch/mock_submission.php
 */

// Ensure we are running under a logged-in parent user context
$parent_user = get_user_by( 'email', 'parent@example.com' );
if ( ! $parent_user ) {
    // If parent doesn't exist, retrieve the first OIDC parent or WP administrator
    $users = get_users( [ 'role' => 'ems_parent' ] );
    if ( ! empty( $users ) ) {
        $parent_user = $users[0];
    } else {
        $parent_user = get_user_by( 'id', 1 ); // Fallback to admin
    }
}

if ( $parent_user ) {
    wp_set_current_user( $parent_user->ID );
    echo "Using User Context: " . $parent_user->user_email . " (ID: " . $parent_user->ID . ")\n";
    
    // Set up mock parent-child relationship
    update_user_meta( $parent_user->ID, 'ems_children', [
        [ 'scout_id' => 30001, 'first_name' => 'Mary', 'last_name' => 'Smith', 'section_ids' => [ 99001 ] ]
    ] );
} else {
    echo "Error: No user context found.\n";
    exit(1);
}

// 1. Prepare Form Mappings Options
$form_id = 4;
update_option( 'ems_fluent_form_id', $form_id );
update_option( 'ems_form_mappings', [
    $form_id => [
        'scout_id_field'   => 'signup_child',
        'dofe_level_field' => 'signup_level',
        'esu_patrol_field' => 'signup_unit',
        'first_aid_field'  => 'input_radio',
        'pref_fields'      => [
            'exped_practice_dates',
            'exped_qualifier_dates',
            'exped_type',
            'exped_team_names',
            'exped_asn'
        ]
    ]
] );

// 1. Prepare Mock Submission Data
$form_id = 4;
$form = (object) [ 'id' => $form_id ];
$entryId = 9999;

// Mock payload reflecting your Fluent Forms fields structure
$formData = [
    'signup_child'          => '30001|Mary|Smith',
    'signup_unit'           => 'BO-Kelso',
    'signup_unitid'         => 10,
    'signup_level'          => 'Bronze',
    'input_radio'           => 'first-response',
    'exped_type'            => 'Hillwalking',
    'exped_practice_dates'  => [ 'P-28-6' ],
    'exped_qualifier_dates' => [ 'Q-13-8' ],
    'exped_team_names'      => 'John Doe',
    'exped_asn'             => 'None',
];

// Re-populate $_POST superglobal to mimic actual browser request for validation hooks
$_POST = $formData;

// 2. Trigger Validation Hook
echo "\n--- Simulating Validation ---\n";
$errors = apply_filters( 'fluentform/validation_errors', [], $formData, $form, [] );
if ( ! empty( $errors ) ) {
    echo "Validation FAILED with errors:\n";
    print_r( $errors );
} else {
    echo "Validation PASSED successfully.\n";
}

// 3. Trigger Submission Hook
echo "\n--- Simulating Form Submission Ingestion ---\n";
try {
    do_action( 'fluentform/submission_inserted', $entryId, $formData, $form );
    echo "Submission action hook completed successfully.\n";
    
    // Check if the signup row was written
    global $wpdb;
    $row = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}ems_signups ORDER BY id DESC LIMIT 1", ARRAY_A );
    if ( $row ) {
        echo "\nDatabase Entry Written:\n";
        print_r( $row );
    } else {
        echo "\nError: No signup row found in the database.\n";
    }
} catch ( \Exception $e ) {
    echo "Error during execution: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
