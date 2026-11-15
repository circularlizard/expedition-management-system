<?php
/**
 * WP-CLI seed script for Phase 1 Settings and Sections.
 * Run via: docker compose run --rm wpcli eval-file wp-content/plugins/ems-plugin/bin/seed-settings.php
 */

WP_CLI::log( "==> Seeding EMS Settings..." );

update_option( 'ems_api_mode', 'mock' );
update_option( 'ems_osm_api_base_url', 'https://www.onlinescoutmanager.co.uk/api.php' );
update_option( 'ems_osm_auth_url', 'https://www.onlinescoutmanager.co.uk/oauth/authorize' );
update_option( 'ems_osm_token_url', 'https://www.onlinescoutmanager.co.uk/oauth/token' );
update_option( 'ems_osm_resource_url', 'https://www.onlinescoutmanager.co.uk/oauth/resource' );

$sections = [
    43105 => [
        'name'    => 'Silver ESU',
        'extraid' => '73848',
    ],
    43106 => [
        'name'    => 'Bronze ESU',
        'extraid' => '73849',
    ]
];
update_option( 'ems_managed_sections', $sections );
update_option( 'ems_managed_sections_default', 43105 );
update_option( 'ems_gravity_form_id', 1 );

WP_CLI::log( "==> Flushing rewrite rules..." );
global $wp_rewrite;
$wp_rewrite->flush_rules( true );
WP_CLI::success( "Settings seeded. Sections: Silver ESU (43105), Bronze ESU (43106)" );
