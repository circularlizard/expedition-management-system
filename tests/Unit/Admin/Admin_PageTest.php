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
		$page->save_sections( [ 'ems_managed_section_ids' => [ '10001' ] ] );

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
		$page->save_sections( [ 'ems_managed_section_ids' => [ '99999' ] ] );

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
		$page->save_sections( [] );

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
		$page->save_sections( [ 'ems_managed_section_ids' => [ '10001' ] ] );

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
		$page->save_sections( [
			'ems_managed_section_ids' => [ '10001' ],
			'ems_writeback_section_id' => '10001',
		] );

		$this->assertSame( 10001, $stored['ems_writeback_section_id'] );
	}

	public function test_save_unit_leaders_saves_mappings(): void {
		Functions\when( 'get_option' )->justReturn( [] );
		$repo = Mockery::mock( \EMS\Data\Unit_Repository::class );
		$repo->shouldReceive( 'update_custom_mappings' )->with( 12, [
			'unit_id'      => 4200,
			'district'     => 'Braid',
			'short_code'   => 'ORION-ESU',
			'leader_email' => 'john.doe@example.com',
		] )->once()->andReturn( true );

		$diagnostic = Mockery::mock( Diagnostic_Panel::class );
		$page = Mockery::mock( Admin_Page::class . '[get_unit_repository]', [ $diagnostic ] );
		$page->shouldAllowMockingProtectedMethods();
		$page->shouldReceive( 'get_unit_repository' )->andReturn( $repo );

		$page->save_unit_leaders( [
			'unit_leaders' => [
				12 => [
					'unit_id'    => 4200,
					'district'   => 'Braid',
					'short_code' => 'ORION-ESU',
					'email'      => 'john.doe@example.com',
				]
			]
		] );

		$this->addToAssertionCount( 1 );
	}

	public function test_handle_import_units_unauthorized(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$diagnostic = Mockery::mock( Diagnostic_Panel::class );
		$page = new Admin_Page( $diagnostic );
		
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Unauthorized.' );

		$reflected = new \ReflectionClass(Admin_Page::class);
		$method = $reflected->getMethod('handle_import_units');
		$method->setAccessible(true);
		$method->invoke( $page );
	}

	public function test_handle_import_units_empty_file(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$diagnostic = Mockery::mock( Diagnostic_Panel::class );
		$page = new Admin_Page( $diagnostic );

		unset($_FILES['ems_units_backup_file']);

		$reflected = new \ReflectionClass(Admin_Page::class);
		$method = $reflected->getMethod('handle_import_units');
		$method->setAccessible(true);

		ob_start();
		$method->invoke( $page );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Please upload a unit lookup backup file.', $output );
	}

	public function test_handle_import_units_success(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		
		// Create a temporary file to use as the backup file
		$temp_file = tempnam( sys_get_temp_dir(), 'ems_test' );
		$backup_data = array(
			'type'    => 'ems_units_export',
			'version' => '0.1.x',
			'units'   => array(
				array(
					'id'        => 5,
					'patrol_id' => 500,
					'section_id'=> 600,
					'name'      => 'Falcons',
					'active'    => 1,
				)
			)
		);
		file_put_contents( $temp_file, json_encode( $backup_data ) );

		$_FILES['ems_units_backup_file'] = array(
			'tmp_name' => $temp_file,
		);

		// Mock wpdb functions called during Portability_Engine->import_units
		$wpdb = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'query' )->with( 'TRUNCATE TABLE wp_ems_units' )->once()->andReturn( true );
		$wpdb->shouldReceive( 'query' )->with( 'TRUNCATE TABLE wp_ems_unit_patrols' )->once()->andReturn( true );
		$wpdb->shouldReceive( 'insert' )->with( 'wp_ems_units', Mockery::any() )->andReturn( true );
		$wpdb->shouldReceive( 'insert' )->with( 'wp_ems_unit_patrols', Mockery::any() )->andReturn( true );
		$GLOBALS['wpdb'] = $wpdb;

		$diagnostic = Mockery::mock( Diagnostic_Panel::class );
		$page = new Admin_Page( $diagnostic );

		$reflected = new \ReflectionClass(Admin_Page::class);
		$method = $reflected->getMethod('handle_import_units');
		$method->setAccessible(true);

		ob_start();
		$method->invoke( $page );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Unit lookup data imported successfully.', $output );

		unlink( $temp_file );
		unset( $_FILES['ems_units_backup_file'] );
	}

	public function test_register_hooks_admin_init_for_export(): void {
		Functions\stubs( [ '__' ] );
		Functions\when( 'add_menu_page' )->justReturn( 'hook' );
		Functions\when( 'add_submenu_page' )->justReturn( 'hook' );

		$diagnostic = Mockery::mock( Diagnostic_Panel::class );
		$page = new Admin_Page( $diagnostic );
		$page->register();

		$this->assertTrue( \Brain\Monkey\Actions\has( 'admin_init' ) );
	}

	public function test_handle_add_custom_unit_success(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => $v );

		$_POST['custom_unit_name'] = 'Orion ESU';
		$_POST['custom_unit_district'] = 'Braid';
		$_POST['custom_unit_short_code'] = 'ORION';
		$_POST['custom_unit_id'] = '12345';
		$_POST['custom_unit_email'] = 'john.doe@example.com';

		$repo = Mockery::mock( \EMS\Data\Unit_Repository::class );
		$repo->shouldReceive( 'add_custom_unit' )
			->once()
			->with( [
				'name'         => 'Orion ESU',
				'district'     => 'Braid',
				'short_code'   => 'ORION',
				'unit_id'      => 12345,
				'leader_email' => 'john.doe@example.com',
			] )
			->andReturn( 99 );

		$diagnostic = Mockery::mock( Diagnostic_Panel::class );
		$page = Mockery::mock( Admin_Page::class . '[get_unit_repository]', [ $diagnostic ] );
		$page->shouldAllowMockingProtectedMethods();
		$page->shouldReceive( 'get_unit_repository' )->andReturn( $repo );

		$reflected = new \ReflectionClass(Admin_Page::class);
		$method = $reflected->getMethod('handle_add_custom_unit');
		$method->setAccessible(true);

		ob_start();
		$method->invoke( $page );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Custom unit created successfully.', $output );

		unset( $_POST['custom_unit_name'] );
		unset( $_POST['custom_unit_district'] );
		unset( $_POST['custom_unit_short_code'] );
		unset( $_POST['custom_unit_id'] );
		unset( $_POST['custom_unit_email'] );
	}

	public function test_handle_delete_custom_unit_success(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$repo = Mockery::mock( \EMS\Data\Unit_Repository::class );
		$repo->shouldReceive( 'delete_custom_unit' )
			->once()
			->with( 99 )
			->andReturn( true );

		$diagnostic = Mockery::mock( Diagnostic_Panel::class );
		$page = Mockery::mock( Admin_Page::class . '[get_unit_repository]', [ $diagnostic ] );
		$page->shouldAllowMockingProtectedMethods();
		$page->shouldReceive( 'get_unit_repository' )->andReturn( $repo );

		$reflected = new \ReflectionClass(Admin_Page::class);
		$method = $reflected->getMethod('handle_delete_custom_unit');
		$method->setAccessible(true);

		ob_start();
		$method->invoke( $page, 99 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Custom unit deleted.', $output );
	}

	public function test_handle_link_patrol_to_unit_success(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$_POST['patrol_id'] = '12345';
		$_POST['section_id'] = '99001';
		$_POST['link_unit_id'] = '800';

		$wpdb = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'update' )
			->once()
			->with(
				'wp_ems_unit_patrols',
				[ 'unit_id' => 800 ],
				[ 'patrol_id' => 12345, 'section_id' => 99001 ],
				[ '%d' ],
				[ '%d', '%d' ]
			)
			->andReturn( 1 );

		$GLOBALS['wpdb'] = $wpdb;

		$diagnostic = Mockery::mock( Diagnostic_Panel::class );
		$page = new Admin_Page( $diagnostic );

		$reflected = new \ReflectionClass( Admin_Page::class );
		$method = $reflected->getMethod( 'handle_link_patrol_to_unit' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $page );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Patrol linked to unit successfully.', $output );

		unset( $_POST['patrol_id'] );
		unset( $_POST['section_id'] );
		unset( $_POST['link_unit_id'] );
	}

	public function test_handle_create_unit_from_patrol_success(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$_POST['patrol_id'] = '12345';
		$_POST['section_id'] = '99001';
		$_POST['patrol_name'] = 'CR-Pink Panthers';

		$wpdb = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'get_var' )
			->once()
			->andReturn( '900005' );
		$wpdb->shouldReceive( 'update' )
			->once()
			->with(
				'wp_ems_unit_patrols',
				[ 'unit_id' => 900006 ],
				[ 'patrol_id' => 12345, 'section_id' => 99001 ],
				[ '%d' ],
				[ '%d', '%d' ]
			)
			->andReturn( 1 );

		$GLOBALS['wpdb'] = $wpdb;

		$repo = Mockery::mock( \EMS\Data\Unit_Repository::class );
		$repo->shouldReceive( 'add_custom_unit' )
			->once()
			->with( [
				'unit_id' => 900006,
				'district' => 'CR',
				'name' => 'CR-Pink Panthers',
				'short_code' => 'CR-Pink Panthers',
				'leader_email' => '',
			] )
			->andReturn( true );

		$diagnostic = Mockery::mock( Diagnostic_Panel::class );
		$page = Mockery::mock( Admin_Page::class . '[get_unit_repository]', [ $diagnostic ] );
		$page->shouldAllowMockingProtectedMethods();
		$page->shouldReceive( 'get_unit_repository' )->andReturn( $repo );

		$reflected = new \ReflectionClass( Admin_Page::class );
		$method = $reflected->getMethod( 'handle_create_unit_from_patrol' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $page );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Master unit "CR-Pink Panthers" created and linked successfully.', $output );

		unset( $_POST['patrol_id'] );
		unset( $_POST['section_id'] );
		unset( $_POST['patrol_name'] );
	}

	public function test_handle_add_district_success(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$_POST['ems_new_district_name'] = 'Craigalmond';

		$stored_options = [];
		Functions\when( 'get_option' )->alias( function ( $k, $default = false ) use ( &$stored_options ) {
			return $stored_options[ $k ] ?? $default;
		} );
		Functions\when( 'update_option' )->alias( function ( $k, $v ) use ( &$stored_options ) {
			$stored_options[ $k ] = $v;
			return true;
		} );

		$diagnostic = Mockery::mock( Diagnostic_Panel::class );
		$page       = new Admin_Page( $diagnostic );

		$reflected = new \ReflectionClass( Admin_Page::class );
		$method    = $reflected->getMethod( 'handle_add_district' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $page );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'District "Craigalmond" created successfully.', $output );
		$this->assertSame( [ 'Craigalmond' ], $stored_options['ems_districts'] );

		unset( $_POST['ems_new_district_name'] );
	}

	public function test_handle_delete_district_success(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$stored_options = [
			'ems_districts' => [ 'Braid', 'Pentland' ],
		];
		Functions\when( 'get_option' )->alias( function ( $k, $default = false ) use ( &$stored_options ) {
			return $stored_options[ $k ] ?? $default;
		} );
		Functions\when( 'update_option' )->alias( function ( $k, $v ) use ( &$stored_options ) {
			$stored_options[ $k ] = $v;
			return true;
		} );

		$diagnostic = Mockery::mock( Diagnostic_Panel::class );
		$page       = new Admin_Page( $diagnostic );

		$reflected = new \ReflectionClass( Admin_Page::class );
		$method    = $reflected->getMethod( 'handle_delete_district' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $page, 'Braid' );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'District "Braid" removed.', $output );
		$this->assertSame( [ 'Pentland' ], $stored_options['ems_districts'] );
	}
}

