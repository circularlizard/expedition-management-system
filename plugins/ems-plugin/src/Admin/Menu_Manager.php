<?php
namespace EMS\Admin;

use EMS\Modules\LeaderDashboard\Unit_Leader_Page;
use EMS\Modules\RouteEngine\Admin\Admin_Studio_Controller;

/**
 * Coordinates all EMS admin menu pages and submenus in a single centralized hierarchy
 * (7 clean domain hubs + backward-compatible redirects for legacy slugs).
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
	 * Registers the admin_menu and legacy redirect hooks.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_all_menus' ), 10 );
		add_action( 'admin_init', array( $this, 'handle_legacy_redirects' ) );
	}

	/**
	 * Registers the 7 consolidated EMS domain hubs in strict visual sequence.
	 */
	public function register_all_menus(): void {
		// 1. Sign-ups (top-level EMS + Sign-ups) & 2. Expeditions & Teams
		$this->admin_page->register();

		// 3. Volunteers
		$this->admin_page->register_volunteers_menu();

		// 4. Units & OSM Sync (consolidates Units & Leaders, Explorers, OSM Reference & Column Mapper)
		$this->admin_page->register_units_sync_menu( $this->unit_leader_page );
		add_action( 'admin_init', array( $this->unit_leader_page, 'maybe_handle_export' ) );

		// 5. Route Studio
		Admin_Studio_Controller::register_admin_menu();

		// 6. Emails & Notifications
		$this->admin_page->register_email_templates_menu();

		// 7. Settings & System
		$this->settings_page->register();
	}

	/**
	 * Redirects retired/legacy admin page slugs to their new consolidated hub URLs
	 * so bookmarks and external links never break.
	 */
	public function handle_legacy_redirects(): void {
		if ( ! is_admin() || wp_doing_ajax() || ! isset( $_GET['page'] ) ) {
			return;
		}

		// Do not redirect POST form submissions; only GET navigation.
		if ( strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) === 'POST' ) {
			return;
		}

		$page = sanitize_key( wp_unslash( $_GET['page'] ) );
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		$target = null;

		switch ( $page ) {
			case 'ems-explorers':
				$target = admin_url( 'admin.php?page=ems-units-sync&tab=explorers' );
				break;
			case 'ems-column-mapper':
				$target = admin_url( 'admin.php?page=ems-units-sync&tab=mapper' );
				break;
			case 'ems-reference':
				if ( $tab === 'unit_mapping' ) {
					$view       = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
					$target_tab = ( $view === 'leaders' ) ? 'leaders' : 'units';
					$target     = admin_url( 'admin.php?page=ems-units-sync&tab=' . $target_tab );
				} elseif ( $tab === 'patrols' ) {
					$target = admin_url( 'admin.php?page=ems-units-sync&tab=osm_patrols' );
				} else {
					$valid  = array( 'explorers', 'events', 'sections', 'pushback', 'diagnostics' );
					$dest   = in_array( $tab, $valid, true ) ? $tab : 'explorers';
					$target = admin_url( 'admin.php?page=ems-units-sync&tab=' . $dest );
				}
				break;
			case 'ems-units':
				if ( $tab === 'email' ) {
					$target = admin_url( 'admin.php?page=ems-emails' );
				} else {
					$valid  = array( 'units', 'leaders', 'patrols' );
					$dest   = in_array( $tab, $valid, true ) ? $tab : 'units';
					$target = admin_url( 'admin.php?page=ems-units-sync&tab=' . $dest );
				}
				break;
			case 'ems-training-report':
				$target = admin_url( 'admin.php?page=ems-expeditions&tab=readiness' );
				break;
			case 'ems-email-templates':
				$target = admin_url( 'admin.php?page=ems-emails' );
				break;
		}

		if ( $target !== null ) {
			wp_safe_redirect( $target );
			exit;
		}
	}
}
