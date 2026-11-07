<?php
namespace EMS\Tests\Admin;

use EMS\Tests\EMSTestCase;
use EMS\Admin\Unit_Leader_Page;
use EMS\Data\Unit_Repository;
use EMS\Core\Portability_Engine;
use Brain\Monkey\Functions;
use Mockery;

class Unit_Leader_PageTest extends EMSTestCase {

	protected function setUp(): void {
		parent::setUp();
		unset( $_GET['tab'], $_GET['page'], $_POST['ems_action'], $_POST['ems_save_unit_leaders'], $_FILES['ems_units_backup_file'] );

		Functions\when( '_n' )->alias( fn( $single, $plural, $count ) => $count === 1 ? $single : $plural );
		Functions\when( 'esc_textarea' )->alias( fn( $v ) => $v );
		Functions\when( 'selected' )->alias( fn( $a, $b ) => ( (string) $a === (string) $b ) ? 'selected="selected"' : '' );
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'add_query_arg' )->alias( function( ...$args ) {
			if ( count( $args ) === 2 && is_array( $args[0] ) ) {
				$url   = $args[1] ?? '';
				$query = http_build_query( $args[0] );
			} elseif ( count( $args ) >= 3 ) {
				$url   = $args[2] ?? '';
				$query = http_build_query( [ $args[0] => $args[1] ] );
			} else {
				return $args[0] ?? '';
			}
			return str_contains( $url, '?' ) ? $url . '&' . $query : $url . '?' . $query;
		} );
	}

	protected function tearDown(): void {
		unset( $_GET['tab'], $_GET['page'], $_POST['ems_action'], $_POST['ems_save_unit_leaders'], $_FILES['ems_units_backup_file'] );
		parent::tearDown();
	}

	public function test_register_submenu_page_under_ems_parent(): void {
		$page = new Unit_Leader_Page();

		Functions\expect( 'add_submenu_page' )
			->once()
			->with(
				'ems',
				'Units & Leaders',
				'Units & Leaders',
				'manage_options',
				'ems-units',
				[ $page, 'render' ]
			)
			->andReturn( 'ems_page_ems-units' );

		Functions\expect( 'add_action' )
			->once()
			->with( 'admin_enqueue_scripts', Mockery::type( 'Closure' ) );

		$page->register();
		$this->assertTrue( true );
	}

	public function test_render_defaults_to_units_tab_for_invalid_tab_param(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'http://example.com/wp-admin/' . $path );

		$_GET['tab'] = 'invalid_tab_name';

		$repo = Mockery::mock( Unit_Repository::class );
		$repo->shouldReceive( 'list_active_units' )->andReturn( [] );

		$wpdb = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'get_results' )->andReturn( [] );
		$GLOBALS['wpdb'] = $wpdb;

		$page = Mockery::mock( Unit_Leader_Page::class . '[get_unit_repository]' );
		$page->shouldAllowMockingProtectedMethods();
		$page->shouldReceive( 'get_unit_repository' )->andReturn( $repo );

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nav-tab nav-tab-active', (string) $output );
		$this->assertStringContainsString( 'Units & Districts', (string) $output );
		$this->assertStringContainsString( 'Create New District', (string) $output );
	}

	// -------------------------------------------------------------
	// Subtab 1: Units & Districts ('units')
	// -------------------------------------------------------------

	public function test_render_units_tab_renders_district_cards_and_master_units(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'http://example.com/wp-admin/' . $path );
		Functions\when( 'get_option' )->alias( function( $k, $default = [] ) {
			if ( $k === 'ems_districts' ) {
				return [ 'Braid' ];
			}
			if ( $k === 'ems_managed_sections' ) {
				return [ 99001 => [ 'name' => 'Section A' ] ];
			}
			return $default;
		} );

		$_GET['tab'] = 'units';

		$repo = Mockery::mock( Unit_Repository::class );
		$repo->shouldReceive( 'list_active_units' )->andReturn( [
			[
				'id'              => 10,
				'unit_id'         => 101,
				'name'            => 'Braids ESU',
				'short_code'      => 'BRAIDS',
				'leader_email'    => 'braid@example.com',
				'district'        => 'Braid',
				'matched_patrols' => [
					[ 'patrol_id' => 55, 'name' => 'Falcons', 'section_id' => 99001 ],
				],
			],
		] );

		$wpdb = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'get_results' )->andReturn( [] );
		$GLOBALS['wpdb'] = $wpdb;

		$page = Mockery::mock( Unit_Leader_Page::class . '[get_unit_repository]' );
		$page->shouldAllowMockingProtectedMethods();
		$page->shouldReceive( 'get_unit_repository' )->andReturn( $repo );

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Braids ESU', (string) $output );
		$this->assertStringContainsString( 'BRAIDS', (string) $output );
		$this->assertStringContainsString( 'braid@example.com', (string) $output );
		$this->assertStringContainsString( 'District:', (string) $output );
		$this->assertStringContainsString( 'Falcons', (string) $output );
	}

	public function test_render_units_tab_empty_state_when_no_units_exist(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'http://example.com/wp-admin/' . $path );
		Functions\when( 'get_option' )->justReturn( [] );

		$_GET['tab'] = 'units';

		$repo = Mockery::mock( Unit_Repository::class );
		$repo->shouldReceive( 'list_active_units' )->andReturn( [] );

		$wpdb = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'get_results' )->andReturn( [] );
		$GLOBALS['wpdb'] = $wpdb;

		$page = Mockery::mock( Unit_Leader_Page::class . '[get_unit_repository]' );
		$page->shouldAllowMockingProtectedMethods();
		$page->shouldReceive( 'get_unit_repository' )->andReturn( $repo );

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'No Districts Created Yet', (string) $output );
	}

	public function test_handle_add_district_sanitizes_and_stores_district(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->alias( fn( $v ) => trim( (string) $v ) );

		$_POST['ems_new_district_name'] = 'Craigalmond';

		$stored_options = [];
		Functions\when( 'get_option' )->alias( fn( $k, $d = false ) => $stored_options[ $k ] ?? $d );
		Functions\when( 'update_option' )->alias( function( $k, $v ) use ( &$stored_options ) {
			$stored_options[ $k ] = $v;
			return true;
		} );

		$page = new Unit_Leader_Page();
		$reflected = new \ReflectionClass( Unit_Leader_Page::class );
		$method = $reflected->getMethod( 'handle_add_district' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $page );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'District "Craigalmond" created successfully.', (string) $output );
		$this->assertSame( [ 'Craigalmond' ], $stored_options['ems_districts'] );
		unset( $_POST['ems_new_district_name'] );
	}

	public function test_handle_add_district_rejects_empty_name(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->alias( fn( $v ) => trim( (string) $v ) );

		$_POST['ems_new_district_name'] = '   ';

		$page = new Unit_Leader_Page();
		$reflected = new \ReflectionClass( Unit_Leader_Page::class );
		$method = $reflected->getMethod( 'handle_add_district' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $page );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'District name is required.', (string) $output );
		unset( $_POST['ems_new_district_name'] );
	}

	public function test_handle_delete_district_removes_empty_district(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$stored_options = [ 'ems_districts' => [ 'Braid', 'Pentland' ] ];
		Functions\when( 'get_option' )->alias( fn( $k, $d = false ) => $stored_options[ $k ] ?? $d );
		Functions\when( 'update_option' )->alias( function( $k, $v ) use ( &$stored_options ) {
			$stored_options[ $k ] = $v;
			return true;
		} );

		$page = new Unit_Leader_Page();
		$reflected = new \ReflectionClass( Unit_Leader_Page::class );
		$method = $reflected->getMethod( 'handle_delete_district' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $page, 'Braid' );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'District "Braid" removed.', (string) $output );
		$this->assertSame( [ 'Pentland' ], $stored_options['ems_districts'] );
	}

	public function test_save_units_updates_district_short_code_and_email(): void {
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'sanitize_text_field' )->alias( fn( $v ) => $v );

		$repo = Mockery::mock( Unit_Repository::class );
		$repo->shouldReceive( 'update_custom_mappings' )->with( 12, [
			'unit_id'      => 4200,
			'district'     => 'Braid',
			'short_code'   => 'ORION-ESU',
			'leader_email' => 'john.doe@example.com',
		] )->once()->andReturn( true );

		$page = Mockery::mock( Unit_Leader_Page::class . '[get_unit_repository]' );
		$page->shouldAllowMockingProtectedMethods();
		$page->shouldReceive( 'get_unit_repository' )->andReturn( $repo );

		ob_start();
		$page->save_unit_leaders( [
			'unit_leaders' => [
				12 => [
					'unit_id'    => 4200,
					'district'   => 'Braid',
					'short_code' => 'ORION-ESU',
					'email'      => 'john.doe@example.com',
				],
			],
		] );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Unit lookup configurations saved.', (string) $output );
	}

	public function test_handle_add_custom_unit_validates_numeric_unit_id_and_name(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->alias( fn( $v ) => $v );
		Functions\when( 'sanitize_email' )->alias( fn( $v ) => $v );

		$_POST['custom_unit_name'] = 'Orion ESU';
		$_POST['custom_unit_district'] = 'Braid';
		$_POST['custom_unit_short_code'] = 'ORION';
		$_POST['custom_unit_id'] = '12345';
		$_POST['custom_unit_email'] = 'john.doe@example.com';

		$repo = Mockery::mock( Unit_Repository::class );
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

		$page = Mockery::mock( Unit_Leader_Page::class . '[get_unit_repository]' );
		$page->shouldAllowMockingProtectedMethods();
		$page->shouldReceive( 'get_unit_repository' )->andReturn( $repo );

		$reflected = new \ReflectionClass( Unit_Leader_Page::class );
		$method = $reflected->getMethod( 'handle_add_custom_unit' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $page );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Custom unit created successfully.', (string) $output );

		unset( $_POST['custom_unit_name'], $_POST['custom_unit_district'], $_POST['custom_unit_short_code'], $_POST['custom_unit_id'], $_POST['custom_unit_email'] );
	}

	public function test_handle_delete_custom_unit_removes_unit(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$repo = Mockery::mock( Unit_Repository::class );
		$repo->shouldReceive( 'delete_custom_unit' )
			->once()
			->with( 99 )
			->andReturn( true );

		$page = Mockery::mock( Unit_Leader_Page::class . '[get_unit_repository]' );
		$page->shouldAllowMockingProtectedMethods();
		$page->shouldReceive( 'get_unit_repository' )->andReturn( $repo );

		$reflected = new \ReflectionClass( Unit_Leader_Page::class );
		$method = $reflected->getMethod( 'handle_delete_custom_unit' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $page, 99 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Custom unit deleted.', (string) $output );
	}

	public function test_handle_consolidate_units_merges_duplicates_and_preserves_patrols(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$repo = Mockery::mock( Unit_Repository::class );
		$repo->shouldReceive( 'consolidate_duplicate_units' )
			->once()
			->andReturn( [
				'merged_count' => 2,
				'details'      => [ 'BR-ALBATROSS: Merged 1 duplicate(s) into Unit ID 46461' ],
			] );

		$page = Mockery::mock( Unit_Leader_Page::class . '[get_unit_repository]' );
		$page->shouldAllowMockingProtectedMethods();
		$page->shouldReceive( 'get_unit_repository' )->andReturn( $repo );

		$reflected = new \ReflectionClass( Unit_Leader_Page::class );
		$method = $reflected->getMethod( 'handle_consolidate_units' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $page );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Consolidation complete: Merged 2 duplicate unit record(s).', (string) $output );
		$this->assertStringContainsString( 'BR-ALBATROSS: Merged 1 duplicate(s) into Unit ID 46461', (string) $output );
	}

	public function test_handle_export_and_import_units_json(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$temp_file = tempnam( sys_get_temp_dir(), 'ems_test' );
		file_put_contents( $temp_file, '{"units":[]}' );

		$_FILES['ems_units_backup_file'] = [
			'tmp_name' => $temp_file,
		];

		$engine = Mockery::mock( Portability_Engine::class );
		$engine->shouldReceive( 'import_units' )
			->once()
			->with( '{"units":[]}' )
			->andReturn( true );

		$page = Mockery::mock( Unit_Leader_Page::class . '[get_portability_engine]' );
		$page->shouldAllowMockingProtectedMethods();
		$page->shouldReceive( 'get_portability_engine' )->andReturn( $engine );

		$reflected = new \ReflectionClass( Unit_Leader_Page::class );
		$method = $reflected->getMethod( 'handle_import_units' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $page );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Unit lookup data imported successfully.', (string) $output );

		if ( file_exists( $temp_file ) ) {
			unlink( $temp_file );
		}
	}

	// -------------------------------------------------------------
	// Subtab 2: Leaders & Access ('leaders')
	// -------------------------------------------------------------

	public function test_render_leaders_tab_displays_summary_counters_and_leader_table(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'http://example.com/wp-admin/' . $path );
		Functions\when( 'get_edit_user_link' )->alias( fn( $id ) => "http://example.com/wp-admin/user-edit.php?user_id={$id}" );

		$_GET['tab'] = 'leaders';

		$mock_user = Mockery::mock( \WP_User::class );
		$mock_user->ID = 99;
		$mock_user->display_name = 'Jane Leader';
		$mock_user->user_login = 'jleader';

		$leaders_data = [
			'jane@example.com' => [
				'email'         => 'jane@example.com',
				'units'         => [
					[ 'id' => 1, 'unit_id' => 101, 'name' => 'Kelso ESU', 'short_code' => 'KELSO', 'district' => 'Borders' ],
				],
				'unit_names'    => [ 'Kelso ESU' ],
				'districts'     => [ 'Borders' ],
				'access_status' => [
					'invited'       => true,
					'invited_at'    => '2026-06-13 10:00:00',
					'logged_in'     => true,
					'last_login_at' => '2026-06-13 12:00:00',
					'user_id'       => 99,
				],
				'user'          => $mock_user,
			],
		];

		$repo = Mockery::mock( Unit_Repository::class );
		$repo->shouldReceive( 'list_leaders_with_units' )->once()->andReturn( $leaders_data );

		$page = Mockery::mock( Unit_Leader_Page::class . '[get_unit_repository]' );
		$page->shouldAllowMockingProtectedMethods();
		$page->shouldReceive( 'get_unit_repository' )->andReturn( $repo );

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Unit Leader Invitations & Portal Access', (string) $output );
		$this->assertStringContainsString( 'Total Leaders: 1', (string) $output );
		$this->assertStringContainsString( 'jane@example.com', (string) $output );
		$this->assertStringContainsString( 'Kelso ESU', (string) $output );
		$this->assertStringContainsString( 'Logged In', (string) $output );
		$this->assertStringContainsString( 'Resend Invite', (string) $output );
	}

	public function test_render_leaders_tab_empty_state_when_no_leaders_configured(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'http://example.com/wp-admin/' . $path );

		$_GET['tab'] = 'leaders';

		$repo = Mockery::mock( Unit_Repository::class );
		$repo->shouldReceive( 'list_leaders_with_units' )->once()->andReturn( [] );

		$page = Mockery::mock( Unit_Leader_Page::class . '[get_unit_repository]' );
		$page->shouldAllowMockingProtectedMethods();
		$page->shouldReceive( 'get_unit_repository' )->andReturn( $repo );

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'No Leaders Found', (string) $output );
	}

	public function test_handle_single_leader_invite_sends_email_and_sets_user_meta(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$repo = Mockery::mock( Unit_Repository::class );
		$repo->shouldReceive( 'invite_leader_by_email' )
			->with( 'leader@example.com', true )
			->once()
			->andReturn( true );

		$page = Mockery::mock( Unit_Leader_Page::class . '[get_unit_repository]' );
		$page->shouldAllowMockingProtectedMethods();
		$page->shouldReceive( 'get_unit_repository' )->andReturn( $repo );

		$reflected = new \ReflectionClass( Unit_Leader_Page::class );
		$method = $reflected->getMethod( 'handle_invite_leader_by_email' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $page, 'leader@example.com' );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Leader invitation email sent to leader@example.com.', (string) $output );
	}

	public function test_handle_single_leader_invite_handles_mailer_failure_gracefully(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$repo = Mockery::mock( Unit_Repository::class );
		$repo->shouldReceive( 'invite_leader_by_email' )
			->with( 'bad-email@example.com', true )
			->once()
			->andReturn( false );

		$page = Mockery::mock( Unit_Leader_Page::class . '[get_unit_repository]' );
		$page->shouldAllowMockingProtectedMethods();
		$page->shouldReceive( 'get_unit_repository' )->andReturn( $repo );

		$reflected = new \ReflectionClass( Unit_Leader_Page::class );
		$method = $reflected->getMethod( 'handle_invite_leader_by_email' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $page, 'bad-email@example.com' );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Could not send invitation email to bad-email@example.com.', (string) $output );
	}

	public function test_handle_bulk_invite_leaders_invites_all_uninvited_and_reports_count(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$repo = Mockery::mock( Unit_Repository::class );
		$repo->shouldReceive( 'invite_unit_leaders' )
			->with( null, false )
			->once()
			->andReturn( [ 'invited' => 4, 'already_invited' => 1, 'skipped_no_email' => 0, 'errors' => [] ] );

		$page = Mockery::mock( Unit_Leader_Page::class . '[get_unit_repository]' );
		$page->shouldAllowMockingProtectedMethods();
		$page->shouldReceive( 'get_unit_repository' )->andReturn( $repo );

		$reflected = new \ReflectionClass( Unit_Leader_Page::class );
		$method = $reflected->getMethod( 'handle_bulk_invite_leaders' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $page, [] );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Leader invitations complete: 4 new leader(s) invited', (string) $output );
	}

	// -------------------------------------------------------------
	// Subtab 3: OSM Patrol Mapping ('patrols')
	// -------------------------------------------------------------

	public function test_render_patrols_tab_renders_unified_table_with_views_and_patrol_rows(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'http://example.com/wp-admin/' . $path );
		Functions\when( 'get_option' )->alias( function( $k, $d = [] ) {
			if ( $k === 'ems_managed_sections' ) {
				return [ 99001 => [ 'name' => 'Section A' ] ];
			}
			return $d;
		} );

		$_GET['tab'] = 'patrols';

		$repo = Mockery::mock( Unit_Repository::class );
		$repo->shouldReceive( 'list_active_units' )->andReturn( [
			[ 'unit_id' => 800, 'name' => 'Alpha Unit', 'short_code' => 'ALPHA' ],
		] );
		$repo->shouldReceive( 'count_patrols' )
			->with( null )
			->andReturn( [ 'all' => 2, 'unmatched' => 1, 'mapped' => 1 ] );
		$repo->shouldReceive( 'get_patrols' )
			->with( 'all', null, null )
			->andReturn( [
				[
					'patrol_id'  => 991,
					'section_id' => 99001,
					'name'       => 'Tiger Patrol',
					'unit_id'    => null,
					'unit_name'  => null,
				],
				[
					'patrol_id'       => 992,
					'section_id'      => 99001,
					'name'            => 'Eagle Patrol',
					'unit_id'         => 800,
					'unit_name'       => 'Alpha Unit',
					'unit_short_code' => 'ALPHA',
				],
			] );

		$page = Mockery::mock( Unit_Leader_Page::class . '[get_unit_repository]' );
		$page->shouldAllowMockingProtectedMethods();
		$page->shouldReceive( 'get_unit_repository' )->andReturn( $repo );

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'OSM Patrol Mapping', (string) $output );
		$this->assertStringContainsString( 'All <span class="count">(2)</span>', (string) $output );
		$this->assertStringContainsString( 'Unmatched <span class="count">(1)</span>', (string) $output );
		$this->assertStringContainsString( 'Mapped <span class="count">(1)</span>', (string) $output );
		$this->assertStringContainsString( 'Tiger Patrol', (string) $output );
		$this->assertStringContainsString( 'Eagle Patrol', (string) $output );
		$this->assertStringContainsString( 'Create Master Unit', (string) $output );
		$this->assertStringContainsString( 'Unlink', (string) $output );

		unset( $_GET['tab'] );
	}

	public function test_render_patrols_tab_filters_by_view_and_section(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'http://example.com/wp-admin/' . $path );
		Functions\when( 'get_option' )->alias( function( $k, $d = [] ) {
			if ( $k === 'ems_managed_sections' ) {
				return [ 99001 => [ 'name' => 'Section A' ] ];
			}
			return $d;
		} );

		$_GET['tab']        = 'patrols';
		$_GET['view']       = 'unmatched';
		$_GET['section_id'] = '99001';

		$repo = Mockery::mock( Unit_Repository::class );
		$repo->shouldReceive( 'list_active_units' )->andReturn( [] );
		$repo->shouldReceive( 'count_patrols' )
			->with( 99001 )
			->andReturn( [ 'all' => 1, 'unmatched' => 1, 'mapped' => 0 ] );
		$repo->shouldReceive( 'get_patrols' )
			->with( 'unmatched', 99001, null )
			->andReturn( [
				[
					'patrol_id'  => 991,
					'section_id' => 99001,
					'name'       => 'Tiger Patrol',
					'unit_id'    => null,
				],
			] );

		$page = Mockery::mock( Unit_Leader_Page::class . '[get_unit_repository]' );
		$page->shouldAllowMockingProtectedMethods();
		$page->shouldReceive( 'get_unit_repository' )->andReturn( $repo );

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Tiger Patrol', (string) $output );

		unset( $_GET['tab'], $_GET['view'], $_GET['section_id'] );
	}

	public function test_handle_link_patrol_to_unit_delegates_to_unit_repository(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$_POST['patrol_id']    = '12345';
		$_POST['section_id']   = '99001';
		$_POST['link_unit_id'] = '800';

		$repo = Mockery::mock( Unit_Repository::class );
		$repo->shouldReceive( 'link_patrol' )
			->once()
			->with( 12345, 99001, 800 )
			->andReturn( true );

		$page = Mockery::mock( Unit_Leader_Page::class . '[get_unit_repository]' );
		$page->shouldAllowMockingProtectedMethods();
		$page->shouldReceive( 'get_unit_repository' )->andReturn( $repo );

		$reflected = new \ReflectionClass( Unit_Leader_Page::class );
		$method    = $reflected->getMethod( 'handle_link_patrol_to_unit' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $page );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Patrol linked to unit successfully.', (string) $output );

		unset( $_POST['patrol_id'], $_POST['section_id'], $_POST['link_unit_id'] );
	}

	public function test_handle_unlink_patrol_delegates_to_unit_repository(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$_POST['patrol_id']  = '12345';
		$_POST['section_id'] = '99001';

		$repo = Mockery::mock( Unit_Repository::class );
		$repo->shouldReceive( 'unlink_patrol' )
			->once()
			->with( 12345, 99001 )
			->andReturn( true );

		$page = Mockery::mock( Unit_Leader_Page::class . '[get_unit_repository]' );
		$page->shouldAllowMockingProtectedMethods();
		$page->shouldReceive( 'get_unit_repository' )->andReturn( $repo );

		$reflected = new \ReflectionClass( Unit_Leader_Page::class );
		$method    = $reflected->getMethod( 'handle_unlink_patrol' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $page );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Patrol unlinked from master unit.', (string) $output );

		unset( $_POST['patrol_id'], $_POST['section_id'] );
	}

	public function test_handle_create_unit_from_patrol_creates_master_unit_and_links_patrol(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$_POST['patrol_id']   = '12345';
		$_POST['section_id']  = '99001';
		$_POST['patrol_name'] = 'CR-Pink Panthers';

		$wpdb = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'get_var' )->once()->andReturn( '900005' );
		$GLOBALS['wpdb'] = $wpdb;

		$repo = Mockery::mock( Unit_Repository::class );
		$repo->shouldReceive( 'add_custom_unit' )
			->once()
			->with( [
				'unit_id'      => 900006,
				'district'     => 'CR',
				'name'         => 'CR-Pink Panthers',
				'short_code'   => 'CR-Pink Panthers',
				'leader_email' => '',
			] )
			->andReturn( true );
		$repo->shouldReceive( 'link_patrol' )
			->once()
			->with( 12345, 99001, 900006 )
			->andReturn( true );

		$page = Mockery::mock( Unit_Leader_Page::class . '[get_unit_repository]' );
		$page->shouldAllowMockingProtectedMethods();
		$page->shouldReceive( 'get_unit_repository' )->andReturn( $repo );

		$reflected = new \ReflectionClass( Unit_Leader_Page::class );
		$method    = $reflected->getMethod( 'handle_create_unit_from_patrol' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $page );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Created master unit "CR-Pink Panthers"', (string) $output );

		unset( $_POST['patrol_id'], $_POST['section_id'], $_POST['patrol_name'] );
	}

	// -------------------------------------------------------------
	// Subtab 4: Invitation Email ('email')
	// -------------------------------------------------------------

	public function test_render_email_tab_populates_subject_reply_to_and_body_fields(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'http://example.com/wp-admin/' . $path );

		$options = [
			'ems_leader_invite_email_subject'  => 'Custom Invite Subject',
			'ems_leader_invite_email_reply_to' => 'dofe@example.org',
			'ems_leader_invite_email_body'     => 'Hello {leader_name}, welcome to {unit_name}!',
		];
		Functions\when( 'get_option' )->alias( fn( $k, $d = '' ) => $options[ $k ] ?? $d );

		$_GET['tab'] = 'email';

		$page = new Unit_Leader_Page();

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Custom Invite Subject', (string) $output );
		$this->assertStringContainsString( 'dofe@example.org', (string) $output );
		$this->assertStringContainsString( 'Hello {leader_name}, welcome to {unit_name}!', (string) $output );
		$this->assertStringContainsString( '{invite_url}', (string) $output );
	}

	public function test_handle_save_leader_invite_sanitizes_and_persists_email_options(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->alias( fn( $v ) => trim( (string) $v ) );
		Functions\when( 'sanitize_textarea_field' )->alias( fn( $v ) => trim( (string) $v ) );
		Functions\when( 'sanitize_email' )->alias( fn( $v ) => trim( (string) $v ) );

		$stored = [];
		Functions\when( 'update_option' )->alias( function( $k, $v ) use ( &$stored ) {
			$stored[ $k ] = $v;
			return true;
		} );

		$page = new Unit_Leader_Page();
		ob_start();
		$page->save_leader_invite( [
			'ems_leader_invite_email_subject'  => 'New Portal Access',
			'ems_leader_invite_email_reply_to' => 'support@example.org',
			'ems_leader_invite_email_body'     => 'Click here: {invite_url}',
		] );
		$output = ob_get_clean();

		$this->assertSame( 'New Portal Access', $stored['ems_leader_invite_email_subject'] );
		$this->assertSame( 'support@example.org', $stored['ems_leader_invite_email_reply_to'] );
		$this->assertSame( 'Click here: {invite_url}', $stored['ems_leader_invite_email_body'] );
		$this->assertStringContainsString( 'Leader invitation email settings saved.', (string) $output );
	}
}
