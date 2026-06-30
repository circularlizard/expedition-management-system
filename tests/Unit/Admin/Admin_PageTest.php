<?php
namespace EMS\Tests\Unit\Admin;

use EMS\Admin\Admin_Page;
use EMS\Admin\Diagnostic_Panel;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;
use Mockery;

class Admin_PageTest extends EMSTestCase {

    public function test_register_menus_registers_correct_hierarchy(): void {
        $menu_pages = [];
        $submenu_pages = [];

        Functions\when( 'add_menu_page' )->alias(
            function ( $page_title, $menu_title, $capability, $menu_slug, $callback = '', $icon_url = '', $position = null ) use ( &$menu_pages ) {
                $menu_pages[] = [
                    'page_title' => $page_title,
                    'menu_title' => $menu_title,
                    'capability' => $capability,
                    'menu_slug'  => $menu_slug,
                ];
                return 'hook-' . $menu_slug;
            }
        );

        Functions\when( 'add_submenu_page' )->alias(
            function ( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '' ) use ( &$submenu_pages ) {
                $submenu_pages[] = [
                    'parent_slug' => $parent_slug,
                    'page_title'  => $page_title,
                    'menu_title'  => $menu_title,
                    'capability'  => $capability,
                    'menu_slug'   => $menu_slug,
                ];
                return 'hook-' . $menu_slug;
            }
        );

        $diagnostic = Mockery::mock( Diagnostic_Panel::class );
        $admin_page = new Admin_Page( $diagnostic );
        
        $admin_page->register();
        $admin_page->register_explorers_menu();
        $admin_page->register_volunteers_menu();
        $admin_page->register_reference_menu();
        $admin_page->register_mapper_menu();

        // Verify ESM parent menu is registered
        $this->assertCount( 1, $menu_pages );
        $this->assertEquals( 'EMS', $menu_pages[0]['menu_title'] );
        $this->assertEquals( 'ems', $menu_pages[0]['menu_slug'] );

        // Verify submenus under parent 'ems'
        $ems_submenus = array_filter( $submenu_pages, fn( $s ) => $s['parent_slug'] === 'ems' );
        $submenu_slugs = array_map( fn( $s ) => $s['menu_slug'], $ems_submenus );
        $submenu_titles = array_map( fn( $s ) => $s['menu_title'], $ems_submenus );

        $this->assertContains( 'ems', $submenu_slugs );
        $this->assertContains( 'ems-explorers', $submenu_slugs );
        $this->assertContains( 'ems-volunteers', $submenu_slugs );
        $this->assertContains( 'ems-reference', $submenu_slugs );

        $this->assertContains( 'Expeditions', $submenu_titles );
        $this->assertContains( 'Explorers', $submenu_titles );
        $this->assertContains( 'Volunteers', $submenu_titles );
        $this->assertContains( 'OSM Sync', $submenu_titles );
    }
}
