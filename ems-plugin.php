<?php
/**
 * Plugin Name: Expedition Management System (EMS)
 * Description: Manages DofE expeditions, teams, and route planning.
 * Version: 0.1.51
 * Author: SE Scotland DofE (David Strachan)
 * Text Domain: ems-plugin
 * Requires PHP: 8.2
 * Requires Plugins: oauth-login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EMS_PLUGIN_FILE', __FILE__ );
define( 'EMS_VERSION', '0.1.33' );
define( 'EMS_DEBUG', true );

// Autoload classes
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

/**
 * Initialize the plugin
 */
register_activation_hook( __FILE__, [ 'EMS\\Plugin', 'activate' ] );

add_action( 'plugins_loaded', function() {
	if ( ! class_exists( 'Circularlizard\\OAuthLogin\\Plugin' ) ) {
		add_action( 'admin_notices', function() {
			$class   = 'notice notice-error';
			$message = __( 'Expedition Management System (EMS) requires the <strong>Expedition Management System - OIDC Login</strong> plugin to be installed and active.', 'ems-plugin' );
			printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), wp_kses_post( $message ) );
		} );
	}

	if ( class_exists( 'EMS\\Plugin' ) ) {
		EMS\Plugin::maybe_upgrade();
		new EMS\Plugin();
	}
} );
