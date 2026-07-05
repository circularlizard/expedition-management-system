<?php
/**
 * WP-CLI seed script for Signups processing & reconciliation.
 * Run via: wp eval-file wp-content/plugins/ems-plugin/bin/seed-signups.php -- [count]
 *
 * Idempotent — cleans old mock data and is safe to run multiple times.
 *
 * Expedition preferences use the same short-code format as the expeditions CPT:
 *   Silver Practice:  H-SP1, H-SP2, H-SP3, H-SP4
 *   Silver Qualifier: H-SQ1, H-SQ2, H-SQ3
 *   Gold Practice:    H-GP1, H-GP2, H-GP3, H-GP4
 *   Gold Qualifier:   H-GQ1, H-GQ2, H-GQ3
 *
 * Every expedition signup MUST contain at least one practice and one qualifier date.
 * The form enforces this constraint; we mirror it here to produce realistic test data.
 */

global $wpdb;
$count = isset( $args[0] ) ? (int) $args[0] : 50;
if ( $count <= 0 ) {
    $count = 50;
}

$participant_table = $wpdb->prefix . 'ems_participant_signups';
$expedition_table  = $wpdb->prefix . 'ems_expedition_signups';
$explorers_table   = $wpdb->prefix . 'ems_osm_explorers';

// ── 1. Clean Data ─────────────────────────────────────────────────────────────
$wpdb->query( "DELETE FROM {$participant_table} WHERE form_submission_id >= 900000" );
$wpdb->query( "DELETE FROM {$expedition_table} WHERE form_submission_id >= 900000" );
WP_CLI::log( "Cleaned up old mock signup records." );

// ── 2. Parent User Accounts ───────────────────────────────────────────────────
$parent_ids = [];
for ( $i = 1; $i <= 5; $i++ ) {
    $email = "mock.parent.{$i}@example-ems.test";
    $user  = get_user_by( 'email', $email );
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

// ── 3. Ensure mock explorers exist ───────────────────────────────────────────
$explorers = $wpdb->get_results( "SELECT * FROM {$explorers_table}", ARRAY_A ) ?: [];
if ( count( $explorers ) < 5 ) {
    for ( $i = 1; $i <= 10; $i++ ) {
        $scout_id = 900000 + $i;
        $wpdb->insert(
            $explorers_table,
            [
                'scout_id'     => $scout_id,
                'first_name'   => "Explorer{$i}",
                'last_name'    => "Mock",
                'email'        => "explorer.{$i}@example-ems.test",
                'parent_email' => "mock.parent.1@example-ems.test",
                'patrol'       => "Mock Patrol",
                'section_id'   => 99001,
                'synced_at'    => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
        );
    }
    $explorers = $wpdb->get_results( "SELECT * FROM {$explorers_table}", ARRAY_A ) ?: [];
    WP_CLI::log( "Seeded 10 base mock explorers to allow linkages." );
}

// ── 4. Shared helpers ─────────────────────────────────────────────────────────
$units         = [ 'SMESU', 'Kelso', 'Jedburgh', 'Hawick', 'Galashiels' ];
$admin_user_id = get_current_user_id() ?: 1;

// Expedition event codes, grouped by level × type
$silver_practices  = [ 'H-SP1', 'H-SP2', 'H-SP3', 'H-SP4' ];
$silver_qualifiers = [ 'H-SQ1', 'H-SQ2', 'H-SQ3' ];
$gold_practices    = [ 'H-GP1', 'H-GP2', 'H-GP3', 'H-GP4' ];
$gold_qualifiers   = [ 'H-GQ1', 'H-GQ2', 'H-GQ3' ];

/**
 * Pick a random subset of $min..$max items from $pool (shuffled).
 * Guarantees at least $min items are returned.
 */
function pick_dates( array $pool, int $min = 1, int $max = 0 ): array {
    if ( $max <= 0 ) {
        $max = count( $pool );
    }
    $n = rand( $min, min( $max, count( $pool ) ) );
    shuffle( $pool );
    return array_slice( $pool, 0, $n );
}

// ── 5. Seed Participant Signups ───────────────────────────────────────────────
for ( $i = 0; $i < $count; $i++ ) {
    $submission_id = 900000 + $i;
    $parent_uid    = $parent_ids[ array_rand( $parent_ids ) ];

    // 70 % chance to link to a real OSM explorer
    $exp        = ( ! empty( $explorers ) && rand( 1, 10 ) <= 7 ) ? $explorers[ array_rand( $explorers ) ] : null;
    $scout_id   = $exp ? (int) $exp['scout_id'] : ( 999000 + rand( 100, 999 ) );
    $first_name = $exp ? $exp['first_name']      : 'Explorer' . rand( 100, 999 );
    $last_name  = $exp ? $exp['last_name']       : 'Mock Unlinked';

    // 50 % Bronze, 35 % Silver, 15 % Gold
    $level_rand = rand( 1, 100 );
    $level      = $level_rand <= 50 ? 'bronze' : ( $level_rand <= 85 ? 'silver' : 'gold' );

    // 70 % paid, 25 % pending, 5 % failed
    $payment_rand = rand( 1, 100 );
    $payment      = $payment_rand <= 70 ? 'paid' : ( $payment_rand <= 95 ? 'pending' : 'failed' );

    // 60 % received, 30 % allocated, 10 % archived
    $status_rand = rand( 1, 100 );
    $status      = $status_rand <= 60 ? 'received' : ( $status_rand <= 90 ? 'allocated' : 'archived' );

    $processed_by = ( $status === 'allocated' ) ? $admin_user_id : null;
    $processed_at = ( $status === 'allocated' ) ? current_time( 'mysql' ) : null;
    $dofe_number  = ( $status === 'allocated' || rand( 0, 1 ) === 1 ) ? 'D-' . rand( 100000, 999999 ) : null;

    $dofe_reg        = 'y';
    $dofe_org_seeded = null;
    if ( empty( $dofe_number ) ) {
        $dofe_reg = 'n';
    } elseif ( rand( 1, 10 ) <= 2 ) {
        $dofe_reg        = 'y-other';
        $dofe_org_seeded = 'Borders Scout Region';
    }

    $bronze_comp = null;
    $silver_comp = null;
    if ( $level === 'silver' ) {
        $bronze_comp = [];
        if ( rand( 0, 1 ) ) { $bronze_comp[] = 'Volunteering'; }
        if ( rand( 0, 1 ) ) { $bronze_comp[] = 'Skills'; }
        if ( rand( 0, 1 ) ) { $bronze_comp[] = 'Physical'; }
        if ( rand( 0, 1 ) ) { $bronze_comp[] = 'Expedition'; }
        if ( empty( $bronze_comp ) ) { $bronze_comp[] = 'None'; }
    } elseif ( $level === 'gold' ) {
        $bronze_comp = [ 'Volunteering', 'Skills', 'Physical', 'Expedition' ];
        $silver_comp = [];
        if ( rand( 0, 1 ) ) { $silver_comp[] = 'Volunteering'; }
        if ( rand( 0, 1 ) ) { $silver_comp[] = 'Skills'; }
        if ( rand( 0, 1 ) ) { $silver_comp[] = 'Physical'; }
        if ( rand( 0, 1 ) ) { $silver_comp[] = 'Expedition'; }
        if ( empty( $silver_comp ) ) { $silver_comp[] = 'None'; }
    }

    $wpdb->insert(
        $participant_table,
        [
            'scout_id'            => $scout_id,
            'parent_user_id'      => $parent_uid,
            'unit_name'           => $units[ array_rand( $units ) ],
            'explorer_first_name' => $first_name,
            'explorer_last_name'  => $last_name,
            'explorer_email'      => strtolower( "{$first_name}.{$last_name}@example-ems.test" ),
            'parent_email'        => 'mock.parent.1@example-ems.test',
            'leader_email'        => 'kelso.leader@example-ems.test',
            'dofe_level'          => $level,
            'dob'                 => '2010-05-15',
            'dofe_registered'     => $dofe_reg,
            'dofe_number'         => $dofe_number,
            'dofe_org'            => $dofe_org_seeded,
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
        [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' ]
    );
}

// ── 6. Seed Expedition Signups ────────────────────────────────────────────────
// Notes:
//  • Bronze is out of scope for the planning board — expedition signups are Silver or Gold only.
//  • expedition_preferences stores arrays of event shortcodes, NOT freeform text.
//  • Every signup MUST have ≥ 1 practice date AND ≥ 1 qualifier date (form enforces this).
//  • Explorers may select up to 3 practices and up to 2 qualifiers.
//  • Dates are restricted to the explorer's own level; no cross-level mixing.
for ( $i = 0; $i < $count; $i++ ) {
    $submission_id = 900000 + $count + $i;
    $parent_uid    = $parent_ids[ array_rand( $parent_ids ) ];

    // 70 % chance to link to a real OSM explorer
    $exp        = ( ! empty( $explorers ) && rand( 1, 10 ) <= 7 ) ? $explorers[ array_rand( $explorers ) ] : null;
    $scout_id   = $exp ? (int) $exp['scout_id'] : ( 999000 + rand( 100, 999 ) );
    $first_name = $exp ? $exp['first_name']      : 'Explorer' . rand( 100, 999 );
    $last_name  = $exp ? $exp['last_name']       : 'Mock Unlinked';

    // 60 % Silver, 40 % Gold
    $level = rand( 1, 10 ) <= 6 ? 'silver' : 'gold';

    // 90 % pending, 10 % archived
    $status = rand( 0, 10 ) <= 8 ? 'pending' : 'archived';

    // Select pools based on level
    if ( $level === 'silver' ) {
        $practice_pool  = $silver_practices;   // H-SP1 … H-SP4
        $qualifier_pool = $silver_qualifiers;  // H-SQ1 … H-SQ3
    } else {
        $practice_pool  = $gold_practices;     // H-GP1 … H-GP4
        $qualifier_pool = $gold_qualifiers;    // H-GQ1 … H-GQ3
    }

    // 1–3 practice dates, 1–2 qualifier dates — at least one of each (mandatory)
    $practice_dates  = pick_dates( $practice_pool,  1, 3 );
    $qualifier_dates = pick_dates( $qualifier_pool, 1, 2 );

    $prefs = [
        'exped_type'            => 'Hillwalking',
        'exped_practice_dates'  => $practice_dates,
        'exped_qualifier_dates' => $qualifier_dates,
        'exped_team_names'      => '',
    ];

    $wpdb->insert(
        $expedition_table,
        [
            'scout_id'                 => $scout_id,
            'parent_user_id'           => $parent_uid,
            'unit_name'                => $units[ array_rand( $units ) ],
            'explorer_first_name'      => $first_name,
            'explorer_last_name'       => $last_name,
            'explorer_email'           => strtolower( "{$first_name}.{$last_name}@example-ems.test" ),
            'parent_email'             => 'mock.parent.1@example-ems.test',
            'leader_email'             => 'smesu.leader@example-ems.test',
            'dofe_level'               => $level,
            'expedition_preferences'   => json_encode( $prefs ),
            'additional_support_needs' => rand( 0, 10 ) > 8 ? 'Asthma inhaler required.' : '',
            'first_aid_status'         => 'first-response',
            'first_aid_expiry'         => '2028-06-13',
            'signup_status'            => $status,
            'form_submission_id'       => $submission_id,
            'created_at'               => current_time( 'mysql' ),
            'updated_at'               => current_time( 'mysql' ),
        ],
        [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
    );
}

WP_CLI::success( "Seeded {$count} participant signups and {$count} expedition signups." );
