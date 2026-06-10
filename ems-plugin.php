<?php
/**
 * Plugin Name: Expedition Management System (EMS)
 * Description: Manages DofE expeditions, teams, and route planning.
 * Version: 0.1.3
 * Author: SE Scotland DofE
 * Text Domain: ems-plugin
 * Requires PHP: 8.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Autoload classes
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

/**
 * Initialize the plugin
 */
add_action( 'plugins_loaded', function() {
    // Initial hook for booting the system
    if ( class_exists( 'EMS\\Plugin' ) ) {
        new EMS\Plugin();
    }
});
