<?php
/**
 * WP-CLI reset script — wipes all EMS table data and options.
 * Run via: docker compose run --rm wpcli eval-file wp-content/plugins/ems-plugin/bin/reset-db.php
 *
 * Truncates every EMS custom table and deletes all ems_* options.
 * Table structure is left intact; run seed-settings.php afterwards to
 * re-seed options/sections.
 */

global $wpdb;

WP_CLI::log( "==> Resetting EMS database..." );

$tables = [
    $wpdb->prefix . 'ems_osm_event_attendance',
    $wpdb->prefix . 'ems_osm_events',
    $wpdb->prefix . 'ems_osm_explorers',
    $wpdb->prefix . 'ems_route_submissions',
    $wpdb->prefix . 'ems_volunteer_availability',
    $wpdb->prefix . 'ems_team_members',
];

$wpdb->query( 'SET FOREIGN_KEY_CHECKS=0' );

foreach ( $tables as $table ) {
    $wpdb->query( "TRUNCATE TABLE {$table}" );
    WP_CLI::log( "  Truncated: {$table}" );
}

$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' );

$options = $wpdb->get_col(
    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'ems_%'"
);

foreach ( $options as $option ) {
    delete_option( $option );
}

WP_CLI::log( "  Deleted " . count( $options ) . " ems_* option(s)" );
WP_CLI::success( "EMS database reset. Run seed-settings.php to restore defaults." );
