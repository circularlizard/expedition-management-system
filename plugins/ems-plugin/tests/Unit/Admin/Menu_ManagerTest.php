<?php
namespace EMS\Tests\Unit\Admin;

use EMS\Admin\Admin_Page;
use EMS\Admin\Diagnostic_Panel;
use EMS\Admin\Menu_Manager;
use EMS\Admin\Settings_Page;
use EMS\Admin\Training_Report_Page;
use EMS\Integrations\TutorLMS_Client;
use EMS\Modules\LeaderDashboard\Unit_Leader_Page;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;
use Mockery;

class Menu_ManagerTest extends EMSTestCase {

	public function test_init_registers_admin_menu_action(): void {
		$admin_page        = Mockery::mock( Admin_Page::class );
		$unit_leader_page  = Mockery::mock( Unit_Leader_Page::class );
		$settings_page     = Mockery::mock( Settings_Page::class );
		$report_page       = Mockery::mock( Training_Report_Page::class );

		$manager = new Menu_Manager(
			$admin_page,
			$unit_leader_page,
			$settings_page,
			$report_page
		);

		$manager->init();

		$this->assertTrue( has_action( 'admin_menu' ) );
	}

	public function test_register_all_menus_calls_each_registration_in_order(): void {
		$admin_page        = Mockery::mock( Admin_Page::class );
		$unit_leader_page  = Mockery::mock( Unit_Leader_Page::class );
		$settings_page     = Mockery::mock( Settings_Page::class );
		$report_page       = Mockery::mock( Training_Report_Page::class );

		// Strict sequence expectation
		$admin_page->shouldReceive( 'register' )->once()->ordered();
		$admin_page->shouldReceive( 'register_explorers_menu' )->once()->ordered();
		$unit_leader_page->shouldReceive( 'register' )->once()->ordered();
		$admin_page->shouldReceive( 'register_volunteers_menu' )->once()->ordered();
		$admin_page->shouldReceive( 'register_reference_menu' )->once()->ordered();
		$settings_page->shouldReceive( 'register' )->once()->ordered();
		$report_page->shouldReceive( 'register' )->once()->ordered();
		$admin_page->shouldReceive( 'register_mapper_menu' )->once()->ordered();

		Functions\when( 'add_submenu_page' )->justReturn( 'hook' );

		$manager = new Menu_Manager(
			$admin_page,
			$unit_leader_page,
			$settings_page,
			$report_page
		);

		$manager->register_all_menus();

		$this->assertTrue( true );
	}

	public function test_register_all_menus_produces_exact_submenu_hierarchy(): void {
		$registered_submenus = array();

		Functions\when( 'add_menu_page' )->justReturn( 'ems_hook' );
		Functions\when( 'add_submenu_page' )->alias(
			function( $parent, $page_title, $menu_title, $cap, $slug, $cb = '' ) use ( &$registered_submenus ) {
				$registered_submenus[] = array(
					'parent' => $parent,
					'title'  => $menu_title,
					'slug'   => $slug,
				);
				return 'hook_' . $slug;
			}
		);

		$diagnostic        = Mockery::mock( Diagnostic_Panel::class );
		$tutor_client      = Mockery::mock( TutorLMS_Client::class );
		$admin_page        = new Admin_Page( $diagnostic );
		$unit_leader_page  = new Unit_Leader_Page();
		$settings_page     = new Settings_Page();
		$report_page       = new Training_Report_Page( $tutor_client );

		$manager = new Menu_Manager(
			$admin_page,
			$unit_leader_page,
			$settings_page,
			$report_page
		);

		$manager->register_all_menus();

		// Filter visible items under 'ems' parent
		$visible_submenus = array_values(
			array_filter(
				$registered_submenus,
				fn( $item ) => $item['parent'] === 'ems'
			)
		);

		$visible_slugs = array_column( $visible_submenus, 'slug' );

		$expected_order = array(
			'ems',                  // 1. Signups
			'ems-expeditions',      // 2. Expeditions
			'ems-explorers',        // 3. Explorers
			'ems-units',            // 4. Units & Leaders
			'ems-volunteers',       // 5. Volunteers
			'ems-route-studio',     // 6. Route Studio
			'ems-reference',        // 7. OSM Sync
			'ems-settings',         // 8. Settings
			'ems-training-report',  // 9. Training Report (at the end)
		);

		$this->assertSame( $expected_order, $visible_slugs );

		// Verify Column Mapper is registered with null parent (hidden utility page)
		$hidden_submenus = array_filter(
			$registered_submenus,
			fn( $item ) => $item['parent'] === null
		);
		$hidden_slugs = array_column( $hidden_submenus, 'slug' );
		$this->assertContains( 'ems-column-mapper', $hidden_slugs );
	}
}
