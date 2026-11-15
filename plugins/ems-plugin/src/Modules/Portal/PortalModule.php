<?php
namespace EMS\Modules\Portal;

use EMS\Contracts\ModuleInterface;

class PortalModule implements ModuleInterface {

	public function getSlug(): string {
		return 'portal';
	}

	public function isAlwaysOn(): bool {
		return false;
	}

	public function register(): void {
		// Hook registrations if needed
	}

	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		$controller = new Portal_Controller();
		$controller->register_routes();
	}
}
