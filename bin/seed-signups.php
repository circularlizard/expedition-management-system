<?php
/**
 * WP-CLI seed script for Signups processing & reconciliation.
 * Run via: wp eval-file wp-content/plugins/ems-plugin/bin/seed-signups.php -- [count]
 *
 * Idempotent — cleans old mock data and is safe to run multiple times.
 */

global $wpdb;
$count = isset( $args[0] ) ? (int) $args[0] : 50;
if ( $count <= 0 ) {
    $count = 50;
}

$participant_table = $wpdb->prefix . 'ems_participant_signups';
$expedition_table = $wpdb->prefix . 'ems_expedition_signups';
$explorers_table = $wpdb->prefix . 'ems_osm_explorers';

// 1. Clean Data
$wpdb->query( "DELETE FROM {$participant_table} WHERE form_submission_id >= 900000" );
$wpdb->query( "DELETE FROM {$expedition_table} WHERE form_submission_id >= 900000" );
WP_CLI::log( "Cleaned up old mock signup records." );

// 2. Parent User Accounts
$parent_ids = [];
for ( $i = 1; $i <= 5; $i++ ) {
    $email = "mock.parent.{$i}@example-ems.test";
    $user = get_user_by( 'email', $email );
    if ( $user ) {
        $parent_ids[] = $user->ID;
    } else {
        $user_id = wp_create_user( "mock_parent_{$i}", "password", $email );
        if ( ! is_wp_error( $user_id ) ) {
            $u = new WP_User( $user_id );
            $u->set_role( 'ems_parent' );
            $parent_ids[] = $user_id;
            WP_CLI::log( "Created mock parent user: {$email}" );
        }
    }
}

if ( empty( $parent_ids ) ) {
    $parent_ids = [ 1 ];
}

// Ensure we have some mock explorers in the DB to test linkages
$explorers = $wpdb->get_results( "SELECT * FROM {$explorers_table}", ARRAY_A ) ?: [];
if ( count( $explorers ) < 5 ) {
    for ( $i = 1; $i <= 10; $i++ ) {
        $scout_id = 900000 + $i;
        $wpdb->insert(
            $explorers_table,
            [
                'scout_id' => $scout_id,
                'first_name' => "Explorer{$i}",
                'last_name' => "Mock",
                'email' => "explorer.{$i}@example-ems.test",
                'parent_email' => "mock.parent.1@example-ems.test",
                'patrol' => "Mock Patrol",
                'section_id' => 99001,
                'synced_at' => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
        );
    }
    $explorers = $wpdb->get_results( "SELECT * FROM {$explorers_table}", ARRAY_A ) ?: [];
    WP_CLI::log( "Seeded 10 base mock explorers to allow linkages." );
}

// 3. Helper arrays for generation
$levels = ['bronze', 'silver', 'gold'];
$payments = ['paid', 'pending', 'failed'];

$admin_user_id = get_current_user_id() ?: 1;

// Seed Participant Signups
for ( $i = 0; $i < $count; $i++ ) {
    $submission_id = 900000 + $i;
    $parent_uid = $parent_ids[ array_rand( $parent_ids ) ];
    
    // Choose an explorer
    $exp = !empty( $explorers ) ? $explorers[ array_rand( $explorers ) ] : null;
    $scout_id = $exp ? (int) $exp['scout_id'] : (900000 + rand(100, 999));
    $first_name = $exp ? $exp['first_name'] : "Explorer" . rand( 100, 999 );
    $last_name = $exp ? $exp['last_name'] : "Mock";

    // Levels: 50% Bronze, 35% Silver, 15% Gold
    $level_rand = rand( 1, 100 );
    $level = $level_rand <= 50 ? 'bronze' : ($level_rand <= 85 ? 'silver' : 'gold');

    // Payments: 70% paid, 25% pending, 5% failed
    $payment_rand = rand( 1, 100 );
    $payment = $payment_rand <= 70 ? 'paid' : ($payment_rand <= 95 ? 'pending' : 'failed');

    // Status: 60% received, 30% allocated, 10% archived
    $status_rand = rand( 1, 100 );
    $status = $status_rand <= 60 ? 'received' : ($status_rand <= 90 ? 'allocated' : 'archived');

    $processed_by = ($status === 'allocated') ? $admin_user_id : null;
    $processed_at = ($status === 'allocated') ? current_time( 'mysql' ) : null;
    $dofe_number = ($status === 'allocated' || rand(0, 1) === 1) ? "D-" . rand( 100000, 999999 ) : null;

    // Prior level completions
    $bronze_comp = null;
    $silver_comp = null;
    if ( $level === 'silver' ) {
        $bronze_comp = [
            'volunteering' => rand(0, 1) ? 'completed' : 'none',
            'skills'       => rand(0, 1) ? 'completed' : 'none',
            'physical'     => rand(0, 1) ? 'completed' : 'none',
            'expedition'   => rand(0, 1) ? 'completed' : 'none',
        ];
    } elseif ( $level === 'gold' ) {
        $bronze_comp = [ 'volunteering' => 'completed', 'skills' => 'completed', 'physical' => 'completed', 'expedition' => 'completed' ];
        $silver_comp = [
            'volunteering' => rand(0, 1) ? 'completed' : 'none',
            'skills'       => rand(0, 1) ? 'completed' : 'none',
            'physical'     => rand(0, 1) ? 'completed' : 'none',
            'expedition'   => rand(0, 1) ? 'completed' : 'none',
        ];
    }

    $wpdb->insert(
        $participant_table,
        [
            'scout_id'            => $scout_id,
            'parent_user_id'      => $parent_uid,
            'unit_name'           => 'Kelso',
            'explorer_first_name' => $first_name,
            'explorer_last_name'  => $last_name,
            'explorer_email'      => strtolower( "{$first_name}.{$last_name}@example-ems.test" ),
            'parent_email'        => "mock.parent.1@example-ems.test",
            'leader_email'        => "kelso.leader@example-ems.test",
            'dofe_level'          => $level,
            'dob'                 => '2010-05-15',
            'dofe_registered'     => empty( $dofe_number ) ? 'n' : 'y',
            'dofe_number'         => $dofe_number,
            'bronze_completion'   => $bronze_comp ? json_encode( $bronze_comp ) : null,
            'silver_completion'   => $silver_comp ? json_encode( $silver_comp ) : null,
            'signup_status'       => $status,
            'payment_status'      => $payment,
            'processed_by'        => $processed_by,
            'processed_at'        => $processed_at,
            'form_submission_id'  => $submission_id,
            'created_at'          => current_time( 'mysql' ),
            'updated_at'          => current_time( 'mysql' ),
        ],
        [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' ]
    );
}

// Seed Expedition Signups
for ( $i = 0; $i < $count; $i++ ) {
    $submission_id = 900000 + $count + $i;
    $parent_uid = $parent_ids[ array_rand( $parent_ids ) ];
    
    $exp = !empty( $explorers ) ? $explorers[ array_rand( $explorers ) ] : null;
    $scout_id = $exp ? (int) $exp['scout_id'] : (900000 + rand(100, 999));
    $first_name = $exp ? $exp['first_name'] : "Explorer" . rand( 100, 999 );
    $last_name = $exp ? $exp['last_name'] : "Mock";

    $level_rand = rand( 1, 100 );
    $level = $level_rand <= 50 ? 'bronze' : ($level_rand <= 85 ? 'silver' : 'gold');

    // Status: 60% pending, 30% processed, 10% archived
    $status_rand = rand( 1, 100 );
    $status = $status_rand <= 60 ? 'pending' : ($status_rand <= 90 ? 'processed' : 'archived');

    $processed_by = ($status === 'processed') ? $admin_user_id : null;
    $processed_at = ($status === 'processed') ? current_time( 'mysql' ) : null;

    $dofe_number = "D-" . rand( 100000, 999999 );

    $prefs = [
        'exped_type' => rand(0, 1) ? 'Hillwalking' : 'Paddling',
        'exped_practice_dates' => 'August 2026',
        'exped_qualifier_dates' => 'September 2026',
        'exped_team_names' => 'Team Alpha',
    ];

    $wpdb->insert(
        $expedition_table,
        [
            'scout_id'                 => $scout_id,
            'parent_user_id'           => $parent_uid,
            'unit_name'                => 'SMESU',
            'explorer_first_name'      => $first_name,
            'explorer_last_name'       => $last_name,
            'explorer_email'           => strtolower( "{$first_name}.{$last_name}@example-ems.test" ),
            'parent_email'             => "mock.parent.1@example-ems.test",
            'leader_email'             => "smesu.leader@example-ems.test",
            'dofe_level'               => $level,
            'dofe_number'              => $dofe_number,
            'expedition_preferences'   => json_encode( $prefs ),
            'additional_support_needs' => rand(0, 10) > 8 ? 'Asthma inhaler required.' : '',
            'first_aid_status'         => 'first-response',
            'first_aid_expiry'         => '2028-06-13',
            'signup_status'            => $status,
            'payment_status'           => 'pending',
            'processed_by'             => $processed_by,
            'processed_at'             => $processed_at,
            'form_submission_id'       => $submission_id,
            'created_at'               => current_time( 'mysql' ),
            'updated_at'               => current_time( 'mysql' ),
        ],
        [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' ]
    );
}

WP_CLI::success( "Successfully seeded {$count} mock participant and expedition signup records." );
