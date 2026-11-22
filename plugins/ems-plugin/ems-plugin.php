<?php
/**
 * Plugin Name: Expedition Management System (EMS)
 * Description: Manages DofE expeditions, teams, and route planning.
 * Version: 1.0.38
 * Author: SE Scotland DofE (David Strachan)
 * Text Domain: ems-plugin
 * Requires PHP: 8.2
 * Requires Plugins: oauth-login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EMS_PLUGIN_FILE', __FILE__ );
define( 'EMS_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'EMS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EMS_VERSION', '1.0.38' );
define( 'EMS_DEBUG', true );

// Route Card & Terrain Engine Constants
if ( ! defined( 'EMS_ROUTE_CARD_VERSION' ) ) {
	define( 'EMS_ROUTE_CARD_VERSION', EMS_VERSION );
}
if ( ! defined( 'EMS_ROUTE_CARD_FILE' ) ) {
	define( 'EMS_ROUTE_CARD_FILE', __FILE__ );
}
if ( ! defined( 'EMS_ROUTE_CARD_PATH' ) ) {
	define( 'EMS_ROUTE_CARD_PATH', EMS_PLUGIN_PATH );
}
if ( ! defined( 'EMS_ROUTE_CARD_URL' ) ) {
	define( 'EMS_ROUTE_CARD_URL', EMS_PLUGIN_URL );
}
if ( ! defined( 'DOFE_DEM_DATA_DIR' ) ) {
	define( 'DOFE_DEM_DATA_DIR', WP_CONTENT_DIR . '/dofe-dem-data' );
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
