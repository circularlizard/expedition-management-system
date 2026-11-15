<?php
namespace EMS\Modules\Expeditions;

use EMS\Contracts\ModuleInterface;

class ExpeditionsModule implements ModuleInterface {

	public function getSlug(): string {
		return 'expeditions';
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
		$controller = new Expedition_Admin_Controller(
			new \EMS\Data\Expedition_Repository(),
			new \EMS\Data\Team_Repository(),
			new \EMS\Data\Team_Member_Repository(),
			null,
			null,
			new \EMS\Data\Signup_Repository()
		);
		$controller->register_routes();
	}
}
