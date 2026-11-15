<?php
namespace EMS\Admin;

use EMS\Modules\LeaderDashboard\Unit_Leader_Page;
use EMS\Modules\RouteEngine\Admin\Admin_Studio_Controller;

/**
 * Coordinates all EMS admin menu pages and submenus in a single centralized hierarchy.
 */
class Menu_Manager {

	private Admin_Page $admin_page;
	private Unit_Leader_Page $unit_leader_page;
	private Settings_Page $settings_page;
	private Training_Report_Page $report_page;

	public function __construct(
		Admin_Page $admin_page,
		Unit_Leader_Page $unit_leader_page,
		Settings_Page $settings_page,
		Training_Report_Page $report_page
	) {
		$this->admin_page       = $admin_page;
		$this->unit_leader_page = $unit_leader_page;
		$this->settings_page    = $settings_page;
		$this->report_page      = $report_page;
	}

	/**
	 * Registers the single admin_menu hook.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_all_menus' ), 10 );
	}

	/**
	 * Registers all EMS top-level and submenu pages in strict visual sequence.
	 */
	public function register_all_menus(): void {
		// 1. Top-level parent EMS menu + Signups & Expeditions
		$this->admin_page->register();

		// 2. Explorers
		$this->admin_page->register_explorers_menu();

		// 3. Units & Leaders
		$this->unit_leader_page->register();

		// 4. Volunteers
		$this->admin_page->register_volunteers_menu();

		// 5. Route Studio
		Admin_Studio_Controller::register_admin_menu();

		// 6. OSM Sync
		$this->admin_page->register_reference_menu();

		// 7. Settings
		$this->settings_page->register();

		// 8. Training Report (placed at end for visibility)
		$this->report_page->register();

		// 9. Hidden Utility Pages (registered with null parent slug)
		$this->admin_page->register_mapper_menu();
	}
}
