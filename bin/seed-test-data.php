<?php
/**
 * WP-CLI seed script to populate test data (expeditions and form submissions).
 * Run via: docker compose run --rm wpcli eval-file wp-content/plugins/ems-plugin/bin/seed-test-data.php
 *
 * Idempotent - cleans up old mock data and is safe to run multiple times.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

try {
    $seeder = new \EMS\Data\Database_Seeder();
    $results = $seeder->seed( function( $msg ) {
        WP_CLI::log( "==> " . $msg );
    } );

    WP_CLI::success( sprintf(
        "Successfully seeded %d participant place submissions and %d expedition preference submissions.",
        $results['participant_count'],
        $results['expedition_count']
    ) );
} catch ( \Exception $e ) {
    WP_CLI::error( $e->getMessage() );
}
