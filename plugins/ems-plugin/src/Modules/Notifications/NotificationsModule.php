<?php
namespace EMS\Modules\Notifications;

use EMS\Contracts\ModuleInterface;
use EMS\Core\Event_Bus;

class NotificationsModule implements ModuleInterface {

	public function getSlug(): string {
		return 'notifications';
	}

	public function isAlwaysOn(): bool {
		return false;
	}

	public function register(): void {
		add_action( 'ems_daily_log_cleanup', array( '\\EMS\\Core\\Log_Rotator', 'purge_old_logs' ) );
		add_action(
			'rest_api_init',
			static function (): void {
				( new Email_Templates_Controller() )->register_routes();
			}
		);
	}

	public function boot(): void {
		Event_Bus::listen( 'ems.signup.form_submitted', array( $this, 'on_signup_form_submitted' ) );
	}

	/**
	 * Transactional notification listener placeholder for Phase 3.
	 *
	 * @param object|mixed $payload
	 */
	public function on_signup_form_submitted( $payload ): void {
		// Log or dispatch transactional email chaser
	}
}
