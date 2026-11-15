<?php
namespace EMS\Modules\LeaderDashboard;

use EMS\Contracts\ModuleInterface;

class LeaderDashboardModule implements ModuleInterface {

	public function getSlug(): string {
		return 'leader_dashboard';
	}

	public function isAlwaysOn(): bool {
		return false;
	}

	public function register(): void {
		$unit_leader_page = new Unit_Leader_Page();
		add_action( 'admin_init', array( $unit_leader_page, 'maybe_handle_export' ) );
	}

	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		$controller = new Unit_Leader_Controller();
		$controller->register_routes();
	}
}
