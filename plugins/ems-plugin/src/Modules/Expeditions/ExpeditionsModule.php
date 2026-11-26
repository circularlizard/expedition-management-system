<?php
namespace EMS\Modules\Expeditions;

use EMS\Contracts\BackupContributorInterface;
use EMS\Contracts\ModuleInterface;

class ExpeditionsModule implements ModuleInterface, BackupContributorInterface {

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

		$team_controller = new Rest\TeamController();
		$team_controller->register_routes();
	}

	/**
	 * @return array<string>
	 */
	public function getBackupTables(): array {
		return array(
			'ems_cohorts',
			'ems_cohort_members',
			'ems_teammate_preferences',
		);
	}

	/**
	 * @return array<string>
	 */
	public function getBackupPostTypes(): array {
		return array();
	}

	/**
	 * @return array<string>
	 */
	public function getBackupOptionNames(): array {
		return array();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function exportData(): array {
		return array();
	}

	/**
	 * @param array<string, mixed> $data
	 * @return bool
	 */
	public function importData( array $data ): bool {
		return true;
	}
}
