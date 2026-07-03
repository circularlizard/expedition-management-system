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

$signups_table = $wpdb->prefix . 'ems_signups';
$explorers_table = $wpdb->prefix . 'ems_osm_explorers';

// 1. Clean Data
$wpdb->query( "DELETE FROM {$signups_table} WHERE form_submission_id >= 900000" );
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
$statuses = ['pending', 'processed', 'archived'];

$admin_user_id = get_current_user_id() ?: 1;

for ( $i = 0; $i < $count; $i++ ) {
    $submission_id = 900000 + $i;

    // Bucket distribution:
    // 40% Directly Linked, 30% Fuzzy Matchable, 30% New Recruits
    $bucket_rand = rand( 1, 100 );
    $scout_id = null;
    $first_name = '';
    $last_name = '';
    $parent_uid = $parent_ids[ array_rand( $parent_ids ) ];

    if ( $bucket_rand <= 40 && ! empty( $explorers ) ) {
        // Directly Linked
        $exp = $explorers[ array_rand( $explorers ) ];
        $scout_id = (int) $exp['scout_id'];
        $first_name = $exp['first_name'];
        $last_name = $exp['last_name'];
    } elseif ( $bucket_rand <= 70 && ! empty( $explorers ) ) {
        // Fuzzy Matchable (name matching, scout_id is null)
        $exp = $explorers[ array_rand( $explorers ) ];
        $scout_id = null;
        $first_name = $exp['first_name'];
        $last_name = $exp['last_name'];
    } else {
        // New Recruit
        $first_name = "Recruit" . rand( 100, 999 );
        $last_name = "New";
        $scout_id = null;
    }

    // State Distributions:
    // Levels: 50% Bronze, 35% Silver, 15% Gold
    $level_rand = rand( 1, 100 );
    if ( $level_rand <= 50 ) {
        $level = 'bronze';
    } elseif ( $level_rand <= 85 ) {
        $level = 'silver';
    } else {
        $level = 'gold';
    }

    // Payments: 70% paid, 25% pending, 5% failed
    $payment_rand = rand( 1, 100 );
    if ( $payment_rand <= 70 ) {
        $payment = 'paid';
    } elseif ( $payment_rand <= 95 ) {
        $payment = 'pending';
    } else {
        $payment = 'failed';
    }

    // Status: 60% pending, 30% processed, 10% archived
    $status_rand = rand( 1, 100 );
    if ( $status_rand <= 60 ) {
        $status = 'pending';
    } elseif ( $status_rand <= 90 ) {
        $status = 'processed';
    } else {
        $status = 'archived';
    }

    // Processed audit simulation
    $processed_by = null;
    $processed_at = null;
    if ( $status === 'processed' ) {
        $processed_by = $admin_user_id;
        $days_ago = rand( 1, 30 );
        $processed_at = date( 'Y-m-d H:i:s', time() - ( $days_ago * DAY_IN_SECONDS ) );
    }

    $dofe_number = "D-" . rand( 100000, 999999 );

    // Preferences payload
    $prefs = [
        'preferred_dates' => [ date( 'Y-m-d', time() + rand( 1, 30 ) * DAY_IN_SECONDS ), date( 'Y-m-d', time() + rand( 31, 60 ) * DAY_IN_SECONDS ) ],
        'teammate_preferences' => [ "FriendA", "FriendB" ],
        'dietary_requirements' => array_rand( [ 'None' => 0.8, 'Vegetarian' => 0.1, 'Vegan' => 0.05, 'Gluten Free' => 0.05 ] ),
        'notes' => "Mock seeded signup preferences.",
    ];

    $wpdb->insert(
        $signups_table,
        [
            'scout_id'               => $scout_id,
            'parent_user_id'         => $parent_uid,
            'unit_id'                => 99001,
            'explorer_first_name'    => $first_name,
            'explorer_last_name'     => $last_name,
            'dofe_level'             => $level,
            'dofe_number'            => $dofe_number,
            'expedition_preferences' => json_encode( $prefs ),
            'first_aid_status'       => 'none',
            'signup_status'          => $status,
            'payment_status'         => $payment,
            'processed_by'           => $processed_by,
            'processed_at'           => $processed_at,
            'form_submission_id'     => $submission_id,
            'created_at'             => current_time( 'mysql' ),
            'updated_at'             => current_time( 'mysql' ),
        ],
        [ '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' ]
    );
}

WP_CLI::success( "Successfully seeded {$count} mock signup records." );
