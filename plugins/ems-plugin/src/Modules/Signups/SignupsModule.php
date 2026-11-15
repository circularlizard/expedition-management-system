<?php
namespace EMS\Modules\Signups;

use EMS\Contracts\ModuleInterface;
use EMS\Core\Shortcode_Boundary;

class SignupsModule implements ModuleInterface {

	public function getSlug(): string {
		return 'signups';
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
		$controller = new Admin_View_Controller(
			new \EMS\Data\Team_Repository(),
			new \EMS\Data\Team_Member_Repository(),
			new \EMS\Integrations\TutorLMS_Client()
		);
		$controller->register_routes();

		$chaser_controller = new Signup_Chaser_Controller(
			new \EMS\Data\Signup_Repository(),
			new \EMS\Data\Unit_Repository(),
			new \EMS\Modules\Notifications\Email_Template_Service()
		);
		$chaser_controller->register_routes();
	}
}
