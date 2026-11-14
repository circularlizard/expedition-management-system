<?php
declare(strict_types=1);

namespace EMS\Modules\RouteEngine;

use EMS\Contracts\ModuleInterface;
use EMS\Modules\RouteEngine\Admin\Admin_Studio_Controller;
use EMSRoute\Core\REST\DEM_Management_Controller;
use EMSRoute\Core\REST\Route_Card_Controller;
use EMSRoute\Core\REST\Settings_Controller;

class RouteEngineModule implements ModuleInterface {

	public function getSlug(): string {
		return 'route_engine';
	}

	public function isAlwaysOn(): bool {
		return false;
	}

	public function register(): void {
		if ( ! defined( 'DOFE_DEM_DATA_DIR' ) ) {
			$content_dir = defined( 'WP_CONTENT_DIR' ) ? constant( 'WP_CONTENT_DIR' ) : ( sys_get_temp_dir() . '/wp-content' );
			define( 'DOFE_DEM_DATA_DIR', $content_dir . '/dofe-dem-data' );
		}
		if ( ! defined( 'EMS_ROUTE_CARD_VERSION' ) ) {
			define( 'EMS_ROUTE_CARD_VERSION', defined( 'EMS_VERSION' ) ? EMS_VERSION : '1.0.21' );
		}
		if ( ! defined( 'EMS_ROUTE_CARD_FILE' ) ) {
			define( 'EMS_ROUTE_CARD_FILE', defined( 'EMS_PLUGIN_FILE' ) ? EMS_PLUGIN_FILE : __FILE__ );
		}
		if ( ! defined( 'EMS_ROUTE_CARD_PATH' ) ) {
			define( 'EMS_ROUTE_CARD_PATH', defined( 'EMS_PLUGIN_PATH' ) ? EMS_PLUGIN_PATH : plugin_dir_path( __FILE__ ) );
		}
		if ( ! defined( 'EMS_ROUTE_CARD_URL' ) ) {
			define( 'EMS_ROUTE_CARD_URL', defined( 'EMS_PLUGIN_URL' ) ? EMS_PLUGIN_URL : plugin_dir_url( __FILE__ ) );
		}
	}

	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		Admin_Studio_Controller::init();
		add_shortcode( 'ems_route_card_studio', array( \EMSRoute\Core\Plugin::class, 'render_studio_shortcode' ) );
	}

	public function register_routes(): void {
		( new Route_Card_Controller() )->register_routes();
		( new DEM_Management_Controller() )->register_routes();
		( new Settings_Controller() )->register_routes();
	}
}
