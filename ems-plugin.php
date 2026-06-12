<?php
/**
 * Plugin Name: Expedition Management System (EMS)
 * Description: Manages DofE expeditions, teams, and route planning.
 * Version: 0.1.26
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
register_activation_hook( __FILE__, [ 'EMS\\Plugin', 'activate' ] );

add_action( 'plugins_loaded', function() {
    if ( class_exists( 'EMS\\Plugin' ) ) {
        new EMS\Plugin();
    }
} );
