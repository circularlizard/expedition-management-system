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
        $this->assertContains( 'ems-expeditions', $submenu_slugs );
        $this->assertContains( 'ems-volunteers', $submenu_slugs );
        $this->assertContains( 'ems-reference', $submenu_slugs );

        $this->assertContains( 'Expeditions', $submenu_titles );
        $this->assertContains( 'Explorers', $submenu_titles );
        $this->assertContains( 'Signups', $submenu_titles );
        $this->assertContains( 'Volunteers', $submenu_titles );
        $this->assertContains( 'OSM Sync', $submenu_titles );
    }

	public function test_save_sections_stores_checked_ids_from_available_transient(): void {
		$stored = [];
		Functions\when( 'update_option' )->alias( static function ( $k, $v ) use ( &$stored ) { $stored[$k] = $v; return true; } );
		Functions\when( 'get_transient' )->alias( static function ( $key ) {
			if ( $key === 'ems_available_sections' ) {
				return [ 10001 => [ 'name' => 'Silver ESU' ], 10002 => [ 'name' => 'Gold ESU' ] ];
			}
			return false;
		} );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => $v );

		$diagnostic = Mockery::mock( Diagnostic_Panel::class );
		$page = new Admin_Page( $diagnostic );
		ob_start();
		$page->save_sections( [ 'ems_managed_section_ids' => [ '10001' ] ] );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Managed sections updated.', (string) $output );
		$this->assertArrayHasKey( 10001, $stored['ems_managed_sections'] );
		$this->assertArrayNotHasKey( 10002, $stored['ems_managed_sections'] );
		$this->assertSame( 'Silver ESU', $stored['ems_managed_sections'][10001]['name'] );
	}

	public function test_save_sections_ignores_ids_not_in_available_transient(): void {
		$stored = [];
		Functions\when( 'update_option' )->alias( static function ( $k, $v ) use ( &$stored ) { $stored[$k] = $v; return true; } );
		Functions\when( 'get_transient' )->alias( static function ( $key ) {
			if ( $key === 'ems_available_sections' ) {
				return [ 10001 => [ 'name' => 'Silver ESU' ] ];
			}
			return false;
		} );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => $v );

		$diagnostic = Mockery::mock( Diagnostic_Panel::class );
		$page = new Admin_Page( $diagnostic );
		ob_start();
		$page->save_sections( [ 'ems_managed_section_ids' => [ '99999' ] ] );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Managed sections updated.', (string) $output );
		$this->assertEmpty( $stored['ems_managed_sections'] );
	}

	public function test_save_sections_stores_empty_when_none_checked(): void {
		$stored = [];
		Functions\when( 'update_option' )->alias( static function ( $k, $v ) use ( &$stored ) { $stored[$k] = $v; return true; } );
		Functions\when( 'get_transient' )->alias( static function ( $key ) {
			if ( $key === 'ems_available_sections' ) {
				return [ 10001 => [ 'name' => 'Silver ESU' ] ];
			}
			return false;
		} );

		$diagnostic = Mockery::mock( Diagnostic_Panel::class );
		$page = new Admin_Page( $diagnostic );
		ob_start();
		$page->save_sections( [] );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Managed sections updated.', (string) $output );
		$this->assertEmpty( $stored['ems_managed_sections'] );
	}

	public function test_save_sections_does_not_store_extraid(): void {
		$stored = [];
		Functions\when( 'update_option' )->alias( static function ( $k, $v ) use ( &$stored ) { $stored[$k] = $v; return true; } );
		Functions\when( 'get_transient' )->alias( static function ( $key ) {
			if ( $key === 'ems_available_sections' ) {
				return [ 10001 => [ 'name' => 'Silver ESU', 'extraid' => '73848' ] ];
			}
			return false;
		} );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => $v );

		$diagnostic = Mockery::mock( Diagnostic_Panel::class );
		$page = new Admin_Page( $diagnostic );
		ob_start();
		$page->save_sections( [ 'ems_managed_section_ids' => [ '10001' ] ] );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Managed sections updated.', (string) $output );
		$this->assertArrayNotHasKey( 'extraid', $stored['ems_managed_sections'][10001] );
	}

	public function test_save_sections_stores_writeback_section_id(): void {
		$stored = [];
		Functions\when( 'update_option' )->alias( static function ( $k, $v ) use ( &$stored ) { $stored[$k] = $v; return true; } );
		Functions\when( 'get_transient' )->alias( static function ( $key ) {
			if ( $key === 'ems_available_sections' ) {
				return [ 10001 => [ 'name' => 'Silver ESU' ] ];
			}
			return false;
		} );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => $v );

		$diagnostic = Mockery::mock( Diagnostic_Panel::class );
		$page = new Admin_Page( $diagnostic );
		ob_start();
		$page->save_sections( [
			'ems_managed_section_ids' => [ '10001' ],
			'ems_writeback_section_id' => '10001',
		] );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Managed sections updated.', (string) $output );
		$this->assertSame( 10001, $stored['ems_writeback_section_id'] );
	}

	public function test_render_reference_page_excludes_unit_mapping_tab(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'admin_url' )->alias( fn( $p = '' ) => 'http://example.com/wp-admin/' . $p );
		Functions\when( 'get_option' )->justReturn( [] );

		$wpdb = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'get_results' )->andReturn( [] );
		$GLOBALS['wpdb'] = $wpdb;

		$diagnostic = Mockery::mock( Diagnostic_Panel::class );
		$diagnostic->shouldReceive( 'run_all' )->andReturn( [] );
		$page = new Admin_Page( $diagnostic );

		ob_start();
		$page->render_reference_page();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'tab=unit_mapping', (string) $output );
		$this->assertStringContainsString( 'OSM Reference Data', (string) $output );
	}

	public function test_render_reference_page_redirects_unit_mapping_tab(): void {
		$_GET['tab'] = 'unit_mapping';

		$redirected = false;
		Functions\when( 'admin_url' )->alias( fn( $p = '' ) => 'http://example.com/wp-admin/' . $p );
		Functions\when( 'wp_safe_redirect' )->alias( function( $url ) use ( &$redirected ) {
			$redirected = $url;
			return true;
		} );

		$diagnostic = Mockery::mock( Diagnostic_Panel::class );
		$page = new Admin_Page( $diagnostic );

		$page->render_reference_page();

		$this->assertSame( 'http://example.com/wp-admin/admin.php?page=ems-units&tab=units', $redirected );
		unset( $_GET['tab'] );
	}

	public function test_render_reference_page_redirects_unit_mapping_view_leaders_tab(): void {
		$_GET['tab']  = 'unit_mapping';
		$_GET['view'] = 'leaders';

		$redirected = false;
		Functions\when( 'admin_url' )->alias( fn( $p = '' ) => 'http://example.com/wp-admin/' . $p );
		Functions\when( 'wp_safe_redirect' )->alias( function( $url ) use ( &$redirected ) {
			$redirected = $url;
			return true;
		} );

		$diagnostic = Mockery::mock( Diagnostic_Panel::class );
		$page = new Admin_Page( $diagnostic );

		$page->render_reference_page();

		$this->assertSame( 'http://example.com/wp-admin/admin.php?page=ems-units&tab=leaders', $redirected );
		unset( $_GET['tab'], $_GET['view'] );
	}
}
