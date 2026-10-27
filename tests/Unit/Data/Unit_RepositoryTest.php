<?php
namespace EMS\Tests\Unit\Data;

use EMS\Data\Unit_Repository;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;

class Unit_RepositoryTest extends EMSTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'is_email' )->alias( function( $email ) {
			return filter_var( $email, FILTER_VALIDATE_EMAIL ) !== false;
		} );
	}

	public function test_sync_patrol_creates_or_updates_and_matches(): void {
		$wpdb = new class {
			public $prefix = 'wp_';
			public $queries = [];
			public $last_args = [];
			public $insert_args = [];

			public function prepare( string $sql, ...$args ): string {
				if ( str_contains( $sql, 'INSERT INTO' ) ) {
					$this->insert_args = $args;
				}
				$this->last_args = $args;
				return vsprintf( str_replace( '%s', "'%s'", str_replace( '%d', '%d', $sql ) ), $args );
			}

			public function query( string $sql ) {
				$this->queries[] = $sql;
				return 1;
			}

			public function get_results( string $sql, string $output = 'OBJECT' ) {
				// Return master units for matching logic
				return [
					[ 'id' => 1, 'unit_id' => 10, 'short_code' => 'BO-Orion', 'name' => 'Orion ESU' ]
				];
			}

			public function get_var( string $sql ) {
				return 99;
			}
		};

		$repo = new Unit_Repository( $wpdb );
		$id = $repo->sync_patrol( [
			'patrol_id'  => 101,
			'section_id' => 99001,
			'name'       => 'Orion', // matches 'Orion ESU' by substring
			'active'     => 1,
		] );

		$this->assertEquals( 99, $id );
		$this->assertCount( 1, $wpdb->queries );
		$this->assertStringContainsString( 'INSERT INTO wp_ems_unit_patrols', $wpdb->queries[0] );
		// unit_id argument in INSERT should be 10 (the matched unit_id)
		$this->assertEquals( 10, $wpdb->insert_args[0] );
	}

	public function test_find_matching_unit(): void {
		$repo = new Unit_Repository();
		$master_units = [
			[ 'unit_id' => 10, 'short_code' => 'BO-Kelso', 'name' => 'Kelso ESU' ],
			[ 'unit_id' => 20, 'short_code' => 'BO-Orion', 'name' => 'Orion' ]
		];

		// 1. Exact match on short code
		$match = $repo->find_matching_unit( 'BO-Kelso', $master_units );
		$this->assertEquals( 10, $match['unit_id'] );

		// 2. Exact match on name
		$match = $repo->find_matching_unit( 'Orion', $master_units );
		$this->assertEquals( 20, $match['unit_id'] );

		// 3. Substring match
		$match = $repo->find_matching_unit( 'Kelso', $master_units );
		$this->assertEquals( 10, $match['unit_id'] );
	}

	public function test_update_custom_mappings_success(): void {
		$wpdb = new class {
			public $prefix = 'wp_';
			public $updated = [];

			public function update( string $table, array $data, array $where, array $format = [], array $where_format = [] ): int {
				$this->updated[] = [ 'table' => $table, 'data' => $data, 'where' => $where ];
				return 1;
			}
		};

		$repo = new Unit_Repository( $wpdb );
		$result = $repo->update_custom_mappings( 12, [
			'unit_id'      => 4200,
			'district'     => 'Braid',
			'short_code'   => 'ORION-ESU',
			'leader_email' => 'jane.smith@example.com',
		] );

		$this->assertTrue( $result );
		$this->assertCount( 1, $wpdb->updated );
		$this->assertEquals( 'wp_ems_units', $wpdb->updated[0]['table'] );
		$this->assertEquals( 4200, $wpdb->updated[0]['data']['unit_id'] );
		$this->assertEquals( 'Braid', $wpdb->updated[0]['data']['district'] );
		$this->assertEquals( 'ORION-ESU', $wpdb->updated[0]['data']['short_code'] );
		$this->assertEquals( 'jane.smith@example.com', $wpdb->updated[0]['data']['leader_email'] );
		$this->assertEquals( 12, $wpdb->updated[0]['where']['id'] );
	}

	public function test_update_custom_mappings_throws_on_invalid_email(): void {
		$wpdb = new class {
			public $prefix = 'wp_';
		};

		$repo = new Unit_Repository( $wpdb );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Invalid leader email format' );

		$repo->update_custom_mappings( 12, [
			'leader_email' => 'bad-email',
		] );
	}

	public function test_find_by_short_code(): void {
		$wpdb = new class {
			public $prefix = 'wp_';
			public $last_query = '';

			public function prepare( string $sql, ...$args ): string {
				return vsprintf( str_replace( '%s', "'%s'", str_replace( '%d', '%d', $sql ) ), $args );
			}

			public function get_row( string $query, string $output = 'OBJECT' ) {
				$this->last_query = $query;
				return [
					'id'         => 12,
					'short_code' => 'ORION-ESU',
					'unit_id'    => 4200,
				];
			}
		};

		$repo = new Unit_Repository( $wpdb );
		$row = $repo->find_by_short_code( 'ORION-ESU' );

		$this->assertNotNull( $row );
		$this->assertEquals( 12, $row['id'] );
		$this->assertStringContainsString( "short_code = 'ORION-ESU'", $wpdb->last_query );
	}

	public function test_add_custom_unit_creates_unit(): void {
		$wpdb = new class {
			public $prefix = 'wp_';
			public $inserted = [];

			public function insert( string $table, array $data, array $format = [] ) {
				$this->inserted[] = [ 'table' => $table, 'data' => $data ];
				return 1;
			}

			public function prepare( string $sql, ...$args ): string {
				return vsprintf( str_replace( '%s', "'%s'", str_replace( '%d', '%d', $sql ) ), $args );
			}

			public function get_var( string $sql ) {
				return 99;
			}
		};

		$repo = new Unit_Repository( $wpdb );
		$new_id = $repo->add_custom_unit( [
			'name'         => 'Orion ESU',
			'district'     => 'Braid',
			'short_code'   => 'ORION',
			'unit_id'      => 12345,
			'leader_email' => 'leader@example.com',
		] );

		$this->assertEquals( 99, $new_id );
		$this->assertCount( 1, $wpdb->inserted );
		$this->assertEquals( 'wp_ems_units', $wpdb->inserted[0]['table'] );
		$this->assertEquals( 12345, $wpdb->inserted[0]['data']['unit_id'] );
		$this->assertEquals( 'Braid', $wpdb->inserted[0]['data']['district'] );
		$this->assertEquals( 'ORION', $wpdb->inserted[0]['data']['short_code'] );
	}

	public function test_delete_custom_unit_deletes_from_units_and_nulls_patrol_links(): void {
		$wpdb = new class {
			public $prefix = 'wp_';
			public $deleted = [];
			public $updated = [];

			public function get_row( string $query, string $output = 'OBJECT' ) {
				return [
					'id'      => 5,
					'unit_id' => 12345,
				];
			}

			public function prepare( string $sql, ...$args ): string {
				return vsprintf( str_replace( '%s', "'%s'", str_replace( '%d', '%d', $sql ) ), $args );
			}

			public function update( string $table, array $data, array $where, array $format = [], array $where_format = [] ): int {
				$this->updated[] = [ 'table' => $table, 'data' => $data, 'where' => $where ];
				return 1;
			}

			public function delete( string $table, array $where, array $where_format = [] ) {
				$this->deleted[] = [ 'table' => $table, 'where' => $where ];
				return 1;
			}
		};

		$repo = new Unit_Repository( $wpdb );
		$res = $repo->delete_custom_unit( 5 );

		$this->assertTrue( $res );
		$this->assertCount( 1, $wpdb->updated );
		$this->assertEquals( 'wp_ems_unit_patrols', $wpdb->updated[0]['table'] );
		$this->assertNull( $wpdb->updated[0]['data']['unit_id'] );
		$this->assertEquals( 12345, $wpdb->updated[0]['where']['unit_id'] );

		$this->assertCount( 1, $wpdb->deleted );
		$this->assertEquals( 'wp_ems_units', $wpdb->deleted[0]['table'] );
		$this->assertEquals( 5, $wpdb->deleted[0]['where']['id'] );
	}

	public function test_consolidate_duplicate_units(): void {
		$wpdb = new class {
			public $prefix = 'wp_';
			public $queries = [];

			public function prepare( string $sql, ...$args ): string {
				return vsprintf( str_replace( '%s', "'%s'", str_replace( '%d', '%d', $sql ) ), $args );
			}

			public function query( string $sql ) {
				$this->queries[] = $sql;
				return 1;
			}

			public function get_results( string $sql, string $output = 'OBJECT' ) {
				if ( str_contains( $sql, 'SELECT * FROM wp_ems_units' ) ) {
					return [
						[ 'id' => 10, 'unit_id' => 46461, 'district' => 'Braid', 'name' => 'BR-ALBATROSS', 'short_code' => 'BR-Albatross', 'leader_email' => 'shaun@example.com' ],
						[ 'id' => 11, 'unit_id' => 46502, 'district' => '',      'name' => 'BR-ALBATROSS', 'short_code' => 'BR-Albatross', 'leader_email' => 'shaun@example.com' ],
						[ 'id' => 20, 'unit_id' => 99201, 'district' => 'Pentland', 'name' => 'PE-Castle', 'short_code' => 'PE-Castle', 'leader_email' => 'jon@example.com' ],
					];
				}
				if ( str_contains( $sql, 'SELECT * FROM wp_ems_unit_patrols' ) ) {
					return [];
				}
				return [];
			}
		};

		$repo = new Unit_Repository( $wpdb );
		$res  = $repo->consolidate_duplicate_units();

		$this->assertSame( 1, $res['merged_count'] );
		$this->assertCount( 1, $res['details'] );
		$this->assertStringContainsString( 'BR-ALBATROSS: Merged 1 duplicate(s) into Unit ID 46461', $res['details'][0] );

		// Check that UPDATE and DELETE queries were executed
		$this->assertCount( 2, $wpdb->queries );
		$this->assertStringContainsString( 'UPDATE wp_ems_unit_patrols SET unit_id = 46461 WHERE unit_id IN (46502)', $wpdb->queries[0] );
		$this->assertStringContainsString( 'DELETE FROM wp_ems_units WHERE id IN (11)', $wpdb->queries[1] );
	}

	public function test_invite_unit_leaders_creates_users_sends_notifications_and_skips_invited(): void {
		$wpdb = new class {
			public $prefix = 'wp_';

			public function prepare( string $sql, ...$args ): string {
				return vsprintf( str_replace( '%s', "'%s'", str_replace( '%d', '%d', $sql ) ), $args );
			}

			public function get_results( string $sql, string $output = 'OBJECT' ) {
				if ( str_contains( $sql, 'SELECT * FROM wp_ems_units' ) ) {
					return [
						[ 'id' => 1, 'unit_id' => 10, 'district' => 'Borders', 'name' => 'Kelso ESU', 'short_code' => 'BO-Kelso', 'leader_email' => 'leader@kelso.scouts.org.uk' ],
						[ 'id' => 2, 'unit_id' => 20, 'district' => 'Tweed', 'name' => 'Peebles ESU', 'short_code' => 'TW-Peebles', 'leader_email' => 'leader@peebles.scouts.org' ],
						[ 'id' => 3, 'unit_id' => 30, 'district' => 'Borders', 'name' => 'Gala ESU', 'short_code' => 'BO-Gala', 'leader_email' => '' ],
					];
				}
				return [];
			}
		};

		$existing_user     = \Mockery::mock( 'WP_User' );
		$existing_user->ID = 202;
		$existing_user->shouldReceive( 'add_role' )->with( 'ems_leader' )->once();

		Functions\when( 'get_user_by' )->alias( function( $field, $value ) use ( $existing_user ) {
			if ( $field === 'email' && $value === 'leader@peebles.scouts.org' ) {
				return $existing_user;
			}
			return false;
		} );

		Functions\when( 'get_user_meta' )->alias( function( $user_id, $key, $single ) {
			if ( $user_id === 202 && $key === 'ems_leader_invited_at' ) {
				return '2026-10-01 10:00:00';
			}
			return '';
		} );

		Functions\when( 'sanitize_user' )->returnArg();
		Functions\when( 'username_exists' )->justReturn( false );
		Functions\when( 'wp_generate_password' )->justReturn( 'random-secret-pass' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$inserted_users = [];
		Functions\when( 'wp_insert_user' )->alias( function( $userdata ) use ( &$inserted_users ) {
			$inserted_users[] = $userdata;
			return 101;
		} );

		$notified_users = [];
		Functions\when( 'wp_new_user_notification' )->alias( function( $user_id, $deprecated = null, $notify = '' ) use ( &$notified_users ) {
			$notified_users[] = [ 'user_id' => $user_id, 'notify' => $notify ];
		} );

		$updated_meta = [];
		Functions\when( 'update_user_meta' )->alias( function( $user_id, $meta_key, $meta_value ) use ( &$updated_meta ) {
			$updated_meta[ $user_id ][ $meta_key ] = $meta_value;
			return true;
		} );

		$repo    = new Unit_Repository( $wpdb );
		$summary = $repo->invite_unit_leaders();

		$this->assertSame( 1, $summary['invited'] );
		$this->assertSame( 1, $summary['already_invited'] );
		$this->assertSame( 1, $summary['skipped_no_email'] );
		$this->assertEmpty( $summary['errors'] );

		$this->assertCount( 1, $inserted_users );
		$this->assertSame( 'leader@kelso.scouts.org.uk', $inserted_users[0]['user_email'] );
		$this->assertSame( 'ems_leader', $inserted_users[0]['role'] );

		$this->assertCount( 1, $notified_users );
		$this->assertSame( 101, $notified_users[0]['user_id'] );
		$this->assertSame( 'user', $notified_users[0]['notify'] );
		$this->assertNotEmpty( $updated_meta[101]['ems_leader_invited_at'] );
	}

	public function test_get_leader_access_status_returns_invited_and_login_flags(): void {
		$user_kelso     = \Mockery::mock( 'WP_User' );
		$user_kelso->ID = 101;

		$user_peebles     = \Mockery::mock( 'WP_User' );
		$user_peebles->ID = 202;

		Functions\when( 'get_user_by' )->alias( function( $field, $value ) use ( $user_kelso, $user_peebles ) {
			if ( $field === 'email' && $value === 'leader@kelso.scouts.org.uk' ) {
				return $user_kelso;
			}
			if ( $field === 'email' && $value === 'leader@peebles.scouts.org' ) {
				return $user_peebles;
			}
			return false;
		} );

		Functions\when( 'get_user_meta' )->alias( function( $user_id, $key, $single ) {
			if ( $user_id === 101 && $key === 'ems_leader_invited_at' ) {
				return '2026-10-01 09:00:00';
			}
			if ( $user_id === 101 && $key === 'ems_last_login_at' ) {
				return '2026-10-02 14:30:00';
			}
			if ( $user_id === 202 && $key === 'ems_leader_invited_at' ) {
				return '2026-10-03 11:00:00';
			}
			return '';
		} );

		$repo = new Unit_Repository();

		$status_kelso = $repo->get_leader_access_status( 'leader@kelso.scouts.org.uk' );
		$this->assertTrue( $status_kelso['invited'] );
		$this->assertTrue( $status_kelso['logged_in'] );
		$this->assertSame( '2026-10-01 09:00:00', $status_kelso['invited_at'] );
		$this->assertSame( '2026-10-02 14:30:00', $status_kelso['last_login_at'] );

		$status_peebles = $repo->get_leader_access_status( 'leader@peebles.scouts.org' );
		$this->assertTrue( $status_peebles['invited'] );
		$this->assertFalse( $status_peebles['logged_in'] );

		$status_hawick = $repo->get_leader_access_status( 'leader@hawick.scouts.org' );
		$this->assertFalse( $status_hawick['invited'] );
		$this->assertFalse( $status_hawick['logged_in'] );
	}

	public function test_customize_leader_notification_email_replaces_placeholders(): void {
		Functions\when( 'get_option' )->alias( function( $key, $default = false ) {
			if ( $key === 'ems_leader_invite_email_subject' ) {
				return 'Welcome to {{site_name}} - {{unit_name}} Leader Access';
			}
			if ( $key === 'ems_leader_invite_email_body' ) {
				return "Hello {{user_email}},\nUnit: {{unit_name}}\nSet Password: {{set_password_url}}\nDashboard: {{dashboard_url}}";
			}
			if ( $key === 'ems_leader_dashboard_page_id' ) {
				return 42;
			}
			return $default;
		} );

		Functions\when( 'get_permalink' )->alias( function( $page_id ) {
			if ( (int) $page_id === 42 ) {
				return 'https://example.org/leader-dashboard/';
			}
			return '';
		} );

		$user             = \Mockery::mock( 'WP_User' );
		$user->ID         = 101;
		$user->user_login = 'leader';
		$user->user_email = 'leader@kelso.scouts.org.uk';

		$default_email = array(
			'to'      => 'leader@kelso.scouts.org.uk',
			'subject' => '[%s] Login Details',
			'message' => "Username: leader\r\n\r\nTo set your password, visit the following address:\r\n\r\nhttps://example.org/wp-login.php?action=rp&key=abc123key&login=leader\r\n\r\nhttps://example.org/wp-login.php\r\n",
			'headers' => '',
		);

		$repo   = new Unit_Repository();
		$result = $repo->customize_leader_notification_email(
			$default_email,
			$user,
			'SEE Expeditions',
			array(
				'name'       => 'Kelso ESU',
				'short_code' => 'BO-Kelso',
			)
		);

		$this->assertSame( 'Welcome to SEE Expeditions - Kelso ESU Leader Access', $result['subject'] );
		$this->assertStringContainsString( 'Hello leader@kelso.scouts.org.uk,', $result['message'] );
		$this->assertStringContainsString( 'Unit: Kelso ESU', $result['message'] );
		$this->assertStringContainsString( 'Set Password: https://example.org/wp-login.php?action=rp&key=abc123key&login=leader', $result['message'] );
		$this->assertStringContainsString( 'Dashboard: https://example.org/leader-dashboard/', $result['message'] );
	}

	public function test_leader_password_reset_and_login_redirect_to_leader_dashboard(): void {
		Functions\when( 'get_option' )->alias( function( $key, $default = false ) {
			if ( $key === 'ems_leader_dashboard_page_id' ) {
				return 42;
			}
			return $default;
		} );

		Functions\when( 'get_permalink' )->alias( function( $page_id ) {
			if ( (int) $page_id === 42 ) {
				return 'https://example.org/leader-dashboard/';
			}
			return '';
		} );

		$user        = \Mockery::mock( 'WP_User' );
		$user->ID    = 101;
		$user->roles = array( 'ems_leader' );

		$logged_in_user_id = 0;
		$auth_cookie_user  = 0;
		$updated_meta      = array();
		$redirected_to     = '';

		Functions\when( 'wp_set_current_user' )->alias( function( $id ) use ( &$logged_in_user_id ) {
			$logged_in_user_id = $id;
		} );
		Functions\when( 'wp_set_auth_cookie' )->alias( function( $id ) use ( &$auth_cookie_user ) {
			$auth_cookie_user = $id;
		} );
		Functions\when( 'update_user_meta' )->alias( function( $id, $key, $val ) use ( &$updated_meta ) {
			$updated_meta[ $id ][ $key ] = $val;
			return true;
		} );
		Functions\when( 'wp_safe_redirect' )->alias( function( $url ) use ( &$redirected_to ) {
			$redirected_to = $url;
			return true;
		} );
		Functions\when( 'admin_url' )->justReturn( 'https://example.org/wp-admin/' );

		$repo = new Unit_Repository();
		$repo->handle_leader_password_reset_redirect( $user );

		$this->assertSame( 101, $logged_in_user_id );
		$this->assertSame( 101, $auth_cookie_user );
		$this->assertNotEmpty( $updated_meta[101]['ems_last_login_at'] );
		$this->assertSame( 'https://example.org/leader-dashboard/', $redirected_to );

		// Also verify standard login_redirect filter for ems_leader
		$login_target = $repo->filter_leader_login_redirect( 'https://example.org/wp-admin/', '', $user );
		$this->assertSame( 'https://example.org/leader-dashboard/', $login_target );
	}

	public function test_filter_leader_wp_redirect_redirects_ems_leader_from_admin_url_to_dashboard(): void {
		$leader_user        = new \stdClass();
		$leader_user->ID    = 101;
		$leader_user->roles = [ 'ems_leader' ];

		$admin_user        = new \stdClass();
		$admin_user->ID    = 1;
		$admin_user->roles = [ 'administrator' ];

		$current_user = null;
		Functions\when( 'is_user_logged_in' )->alias( function() use ( &$current_user ) {
			return $current_user !== null;
		} );
		Functions\when( 'wp_get_current_user' )->alias( function() use ( &$current_user ) {
			return $current_user;
		} );
		Functions\when( 'admin_url' )->justReturn( 'https://example.org/wp-admin/' );
		Functions\when( 'get_option' )->alias( function( $key, $default = false ) {
			if ( $key === 'ems_leader_dashboard_page_id' ) {
				return 42;
			}
			return $default;
		} );
		Functions\when( 'get_permalink' )->alias( function( $id ) {
			if ( $id === 42 ) {
				return 'https://example.org/leader-dashboard/';
			}
			return false;
		} );

		$repo = new Unit_Repository();

		// 1. Not logged in: does not alter redirect
		$current_user = null;
		$this->assertSame( 'https://example.org/wp-admin/', $repo->filter_leader_wp_redirect( 'https://example.org/wp-admin/' ) );

		// 2. Logged in as administrator: does not alter redirect
		$current_user = $admin_user;
		$this->assertSame( 'https://example.org/wp-admin/', $repo->filter_leader_wp_redirect( 'https://example.org/wp-admin/' ) );

		// 3. Logged in as ems_leader redirecting to admin_url(): rewrites to leader dashboard
		$current_user = $leader_user;
		$this->assertSame( 'https://example.org/leader-dashboard/', $repo->filter_leader_wp_redirect( 'https://example.org/wp-admin/' ) );
		$this->assertSame( 'https://example.org/leader-dashboard/', $repo->filter_leader_wp_redirect( 'https://example.org/wp-admin' ) );

		// 4. Logged in as ems_leader redirecting to non-admin URL: leaves URL untouched
		$this->assertSame( 'https://example.org/custom-page/', $repo->filter_leader_wp_redirect( 'https://example.org/custom-page/' ) );
	}

	public function test_maybe_redirect_leader_from_admin_redirects_ems_leader(): void {
		$leader_user        = new \stdClass();
		$leader_user->ID    = 101;
		$leader_user->roles = [ 'ems_leader' ];

		$admin_user        = new \stdClass();
		$admin_user->ID    = 1;
		$admin_user->roles = [ 'administrator' ];

		$current_user   = null;
		$redirected_to  = null;
		$is_ajax        = false;

		Functions\when( 'is_user_logged_in' )->alias( function() use ( &$current_user ) {
			return $current_user !== null;
		} );
		Functions\when( 'wp_get_current_user' )->alias( function() use ( &$current_user ) {
			return $current_user;
		} );
		Functions\when( 'wp_doing_ajax' )->alias( function() use ( &$is_ajax ) {
			return $is_ajax;
		} );
		Functions\when( 'wp_safe_redirect' )->alias( function( $url ) use ( &$redirected_to ) {
			$redirected_to = $url;
			return true;
		} );
		Functions\when( 'get_option' )->alias( function( $key, $default = false ) {
			if ( $key === 'ems_leader_dashboard_page_id' ) {
				return 42;
			}
			return $default;
		} );
		Functions\when( 'get_permalink' )->alias( function( $id ) {
			if ( $id === 42 ) {
				return 'https://example.org/leader-dashboard/';
			}
			return false;
		} );

		$repo = new Unit_Repository();

		// 1. Logged in as administrator: no redirect
		$current_user  = $admin_user;
		$redirected_to = null;
		$repo->maybe_redirect_leader_from_admin();
		$this->assertNull( $redirected_to );

		// 2. Doing AJAX: no redirect
		$current_user  = $leader_user;
		$is_ajax       = true;
		$redirected_to = null;
		$repo->maybe_redirect_leader_from_admin();
		$this->assertNull( $redirected_to );

		// 3. Logged in as ems_leader: redirects to dashboard
		$is_ajax       = false;
		$redirected_to = null;
		$repo->maybe_redirect_leader_from_admin();
		$this->assertSame( 'https://example.org/leader-dashboard/', $redirected_to );
	}

	public function test_format_unit_names_list(): void {
		$repo = new Unit_Repository();

		$this->assertSame( 'your Explorer Scout Unit', $repo->format_unit_names_list( [] ) );
		$this->assertSame( 'Kelso ESU', $repo->format_unit_names_list( [ 'Kelso ESU' ] ) );
		$this->assertSame( 'Hawick ESU and Jedburgh ESU', $repo->format_unit_names_list( [ 'Hawick ESU', 'Jedburgh ESU' ] ) );
		$this->assertSame( 'Kelso ESU, Peebles ESU and Gala ESU', $repo->format_unit_names_list( [ 'Kelso ESU', 'Peebles ESU', 'Gala ESU' ] ) );
		$this->assertSame( 'Unit 1, Unit 2, Unit 3 and Unit 4', $repo->format_unit_names_list( [ 'Unit 1', 'Unit 2', 'Unit 3', 'Unit 4' ] ) );
	}

	public function test_customize_leader_notification_email_aggregates_multiple_units_for_same_leader(): void {
		$wpdb = new class {
			public $prefix = 'wp_';

			public function prepare( string $sql, ...$args ): string {
				return vsprintf( str_replace( '%s', "'%s'", $sql ), $args );
			}

			public function get_results( string $sql, string $output = 'OBJECT' ) {
				if ( str_contains( $sql, 'leader@example.com' ) ) {
					return [
						[ 'id' => 1, 'name' => 'Kelso ESU', 'short_code' => 'BO-Kelso' ],
						[ 'id' => 2, 'name' => 'Peebles ESU', 'short_code' => 'TW-Peebles' ],
						[ 'id' => 3, 'name' => 'Gala ESU', 'short_code' => 'BO-Gala' ],
					];
				}
				return [];
			}
		};

		Functions\when( 'get_option' )->alias( function( $key, $default = false ) {
			if ( $key === 'ems_leader_invite_email_subject' ) {
				return 'Welcome - {{unit_name}} Access';
			}
			if ( $key === 'ems_leader_invite_email_body' ) {
				return 'Units: {{units}}';
			}
			return $default;
		} );

		$user             = \Mockery::mock( 'WP_User' );
		$user->ID         = 501;
		$user->user_login = 'john';
		$user->user_email = 'leader@example.com';

		$default_email = array(
			'to'      => 'leader@example.com',
			'subject' => '[Site] Login Details',
			'message' => 'https://example.org/wp-login.php?action=rp&key=xyz123',
		);

		$repo   = new Unit_Repository( $wpdb );
		$result = $repo->customize_leader_notification_email( $default_email, $user, 'SEE Expeditions' );

		$this->assertSame( 'Welcome - Kelso ESU, Peebles ESU and Gala ESU Access', $result['subject'] );
		$this->assertSame( 'Units: Kelso ESU, Peebles ESU and Gala ESU', $result['message'] );
	}

	public function test_list_leaders_with_units_groups_by_email(): void {
		$wpdb = new class {
			public $prefix = 'wp_';

			public function prepare( string $sql, ...$args ): string {
				return vsprintf( str_replace( '%s', "'%s'", $sql ), $args );
			}

			public function get_results( string $sql, string $output = 'OBJECT' ) {
				if ( str_contains( $sql, 'SELECT * FROM wp_ems_units' ) ) {
					return [
						[ 'id' => 1, 'unit_id' => 10, 'district' => 'Borders', 'name' => 'Kelso ESU', 'short_code' => 'BO-Kelso', 'leader_email' => 'john@example.com' ],
						[ 'id' => 2, 'unit_id' => 20, 'district' => 'Tweed',   'name' => 'Peebles ESU', 'short_code' => 'TW-Peebles', 'leader_email' => 'john@example.com' ],
						[ 'id' => 3, 'unit_id' => 30, 'district' => 'Borders', 'name' => 'Hawick ESU', 'short_code' => 'BO-Hawick', 'leader_email' => 'mary@example.com' ],
						[ 'id' => 4, 'unit_id' => 40, 'district' => 'Borders', 'name' => 'Gala ESU',   'short_code' => 'BO-Gala',   'leader_email' => '' ],
					];
				}
				return [];
			}
		};

		$user_john     = \Mockery::mock( 'WP_User' );
		$user_john->ID = 501;

		Functions\when( 'get_user_by' )->alias( function( $field, $value ) use ( $user_john ) {
			if ( $field === 'email' && $value === 'john@example.com' ) {
				return $user_john;
			}
			return false;
		} );

		Functions\when( 'get_user_meta' )->alias( function( $user_id, $key, $single ) {
			if ( $user_id === 501 && $key === 'ems_leader_invited_at' ) {
				return '2026-10-01 10:00:00';
			}
			if ( $user_id === 501 && $key === 'ems_last_login_at' ) {
				return '2026-10-02 12:00:00';
			}
			return '';
		} );

		$repo    = new Unit_Repository( $wpdb );
		$leaders = $repo->list_leaders_with_units();

		$this->assertCount( 2, $leaders );
		$this->assertArrayHasKey( 'john@example.com', $leaders );
		$this->assertArrayHasKey( 'mary@example.com', $leaders );

		$john = $leaders['john@example.com'];
		$this->assertSame( 'john@example.com', $john['email'] );
		$this->assertCount( 2, $john['units'] );
		$this->assertSame( [ 'Kelso ESU', 'Peebles ESU' ], $john['unit_names'] );
		$this->assertSame( [ 'Borders', 'Tweed' ], $john['districts'] );
		$this->assertTrue( $john['access_status']['invited'] );
		$this->assertTrue( $john['access_status']['logged_in'] );

		$mary = $leaders['mary@example.com'];
		$this->assertSame( 'mary@example.com', $mary['email'] );
		$this->assertCount( 1, $mary['units'] );
		$this->assertSame( [ 'Hawick ESU' ], $mary['unit_names'] );
		$this->assertFalse( $mary['access_status']['invited'] );
		$this->assertFalse( $mary['access_status']['logged_in'] );
	}

	public function test_invite_leader_by_email_sends_notification(): void {
		$wpdb = new class {
			public $prefix = 'wp_';

			public function prepare( string $sql, ...$args ): string {
				return vsprintf( str_replace( '%s', "'%s'", $sql ), $args );
			}

			public function get_results( string $sql, string $output = 'OBJECT' ) {
				if ( str_contains( $sql, 'leader@example.com' ) ) {
					return [
						[ 'id' => 1, 'name' => 'Kelso ESU', 'short_code' => 'BO-Kelso' ],
					];
				}
				return [];
			}
		};

		$inserted_users = [];
		Functions\when( 'wp_insert_user' )->alias( function( $userdata ) use ( &$inserted_users ) {
			$inserted_users[] = $userdata;
			return 777;
		} );

		$notified_users = [];
		Functions\when( 'wp_new_user_notification' )->alias( function( $user_id, $dep = null, $notify = '' ) use ( &$notified_users ) {
			$notified_users[] = $user_id;
		} );

		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( 'sanitize_user' )->returnArg();
		Functions\when( 'username_exists' )->justReturn( false );
		Functions\when( 'wp_generate_password' )->justReturn( 'password123' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'update_user_meta' )->justReturn( true );

		$repo    = new Unit_Repository( $wpdb );
		$success = $repo->invite_leader_by_email( 'leader@example.com' );

		$this->assertTrue( $success );
		$this->assertCount( 1, $inserted_users );
		$this->assertSame( 'leader@example.com', $inserted_users[0]['user_email'] );
		$this->assertCount( 1, $notified_users );
		$this->assertSame( 777, $notified_users[0] );
	}
}
