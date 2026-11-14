<?php
namespace EMS\Modules\Volunteers;

use EMS\Contracts\ModuleInterface;

class VolunteersModule implements ModuleInterface {

	public function getSlug(): string {
		return 'volunteers';
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
		$controller = new Volunteer_Controller();
		$controller->register_routes();
	}
}
