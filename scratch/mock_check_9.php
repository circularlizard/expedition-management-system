<?php
/**
 * Check Submission 9 details
 * Run: docker compose run --rm wpcli eval-file mock_check_9.php
 */

global $wpdb;

echo "--- Fluent Forms Entry 9 ---\n";
$ff_entry = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}fluentform_submissions WHERE id = 9", ARRAY_A );
print_r( $ff_entry );

echo "\n--- EMS Signups Entry with form_submission_id = 9 ---\n";
$ems_signup = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}ems_signups WHERE form_submission_id = 9", ARRAY_A );
print_r( $ems_signup );

echo "\n--- All EMS Signups ---\n";
$all_signups = $wpdb->get_results( "SELECT id, scout_id, payment_status, signup_status, form_submission_id FROM {$wpdb->prefix}ems_signups", ARRAY_A );
print_r( $all_signups );
