<?php
namespace EMS\Tests\Unit\Integrations;

use EMS\Integrations\Fluent_Forms_Sync;
use EMS\Data\Signup_Repository;
use EMS\Data\Unit_Repository;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;
use Brain\Monkey\Filters;
use Brain\Monkey\Actions;
use Mockery;

class Fluent_Forms_SyncTest extends EMSTestCase {
    private $signup_repo;
    private $unit_repo;
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        $this->signup_repo = Mockery::mock( Signup_Repository::class );
        $this->unit_repo   = Mockery::mock( Unit_Repository::class );
        
        $this->wpdb = new class {
            public $prefix = 'wp_';
            public $rows = [];
            public $prepared = '';

            public function prepare( string $sql, ...$args ): string {
                return vsprintf( str_replace( '%s', "'%s'", str_replace( '%d', '%d', $sql ) ), $args );
            }

            public function get_row( string $sql, string $output = ARRAY_A ) {
                return $this->rows[ $sql ] ?? null;
            }
        };

        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'is_user_logged_in' )->justReturn( false );
        Functions\when( 'sanitize_key' )->alias( fn( $k ) => $k );
        Functions\when( 'get_option' )->alias( function( $key, $default = null ) {
            if ( $key === 'ems_fluent_participant_form_id' ) return 6;
            if ( $key === 'ems_fluent_expedition_form_id' ) return 7;
            return $default ?? [];
        } );

        $this->wpdb->rows["SELECT first_name, last_name FROM wp_ems_osm_explorers WHERE scout_id = 30001"] = [
            'first_name' => 'Mary',
            'last_name'  => 'Smith',
        ];
        $this->wpdb->rows["SELECT first_name, last_name FROM wp_ems_osm_explorers WHERE scout_id = 30002"] = [
            'first_name' => 'Jane',
            'last_name'  => 'Doe',
        ];
    }

    public function test_init_hooks_adds_filters_and_actions(): void {
        $sync = new Fluent_Forms_Sync( $this->signup_repo, $this->wpdb );
        $sync->init_hooks();

        $this->assertTrue( Filters\has( 'fluentform/rendering_field_data_select' ) );
        $this->assertTrue( Filters\has( 'fluentform/validate_input_item_select' ) );
        $this->assertTrue( Filters\has( 'fluentform/validation_errors' ) );
        $this->assertTrue( Actions\has( 'fluentform/submission_inserted' ) );
        $this->assertTrue( Actions\has( 'fluentform/after_payment_status_change' ) );
    }

    public function test_populate_child_dropdown_injects_choices(): void {
        $children = [
            [ 'scout_id' => 30001, 'first_name' => 'Mary', 'last_name' => 'Smith', 'section_ids' => [ 99001 ] ]
        ];
        Functions\when( 'get_user_meta' )->alias( function( $uid, $key, $single = false ) use ( $children ) {
            if ( $key === 'ems_access_type' ) return 'parent';
            if ( $key === 'ems_children' ) return $children;
            return '';
        } );

        $this->wpdb->rows["SELECT first_name, last_name FROM wp_ems_osm_explorers WHERE scout_id = 30001"] = [
            'first_name' => 'Mary',
            'last_name'  => 'Smith',
        ];

        $sync = new Fluent_Forms_Sync( $this->signup_repo, $this->wpdb );
        
        $field_data = [
            'attributes' => [ 'name' => 'signup_child' ],
            'settings'   => [ 'advanced_options' => [] ],
        ];
        $form = (object) [ 'id' => 6 ];

        $result = $sync->populate_child_dropdown( $field_data, $form );

        $options = $result['settings']['advanced_options'];
        $this->assertCount( 1, $options );
        $this->assertEquals( 'Mary Smith', $options[0]['label'] );
        $this->assertEquals( '30001', $options[0]['value'] );
    }

    public function test_handle_submission_creates_participant_signup_record(): void {
        $captured = null;
        $this->signup_repo->shouldReceive( 'create_participant_signup' )
            ->once()
            ->with( Mockery::on( function( $data ) use ( &$captured ) {
                $captured = $data;
                return $data['scout_id'] === 30001 &&
                       $data['explorer_first_name'] === 'Mary' &&
                       $data['explorer_last_name'] === 'Smith' &&
                       $data['dofe_level'] === 'bronze' &&
                       $data['dob'] === '2010-05-15';
            } ) )
            ->andReturn( 123 );

        $sync = new Fluent_Forms_Sync( $this->signup_repo, $this->wpdb );
        $sync->handle_submission( 999, [
            'signup_child'          => '30001',
            'signup_level'          => 'Bronze',
            'signup_dob'            => '2010-05-15',
            'signup_dofe_registered'=> 'n',
        ], (object) [ 'id' => 6 ] );

        $this->assertNotNull( $captured );
        $this->assertSame( 30001, $captured['scout_id'] );
        $this->assertSame( 'bronze', $captured['dofe_level'] );
    }

    public function test_handle_submission_creates_expedition_signup_record(): void {
        $captured = null;
        $this->signup_repo->shouldReceive( 'create_expedition_signup' )
            ->once()
            ->with( Mockery::on( function( $data ) use ( &$captured ) {
                $captured = $data;
                return $data['scout_id'] === 30001 &&
                       $data['explorer_first_name'] === 'Mary' &&
                       $data['explorer_last_name'] === 'Smith' &&
                       $data['dofe_level'] === 'silver' &&
                       $data['first_aid_status'] === 'first-response';
            } ) )
            ->andReturn( 456 );

        $sync = new Fluent_Forms_Sync( $this->signup_repo, $this->wpdb );
        $sync->handle_submission( 999, [
            'signup_child' => '30001',
            'signup_level' => 'Silver',
            'exped_type'   => 'Hillwalking',
            'input_radio'  => 'first-response',
        ], (object) [ 'id' => 7 ] );

        $this->assertNotNull( $captured );
        $this->assertSame( 30001, $captured['scout_id'] );
        $this->assertSame( 'silver', $captured['dofe_level'] );
    }

    public function test_handle_submission_resolves_level_specific_date_fields(): void {
        $exp_mappings = [
            'silver_practice_dates_field'  => 'exped-silver-practice-dates',
            'silver_qualifier_dates_field' => 'exped-silver-qualifier-dates',
            'gold_practice_dates_field'    => 'exped-gold-practice-dates',
            'gold_qualifier_dates_field'   => 'exped-gold-qualifier-dates',
        ];

        Functions\when( 'get_option' )->alias( function( $key, $default = null ) use ( $exp_mappings ) {
            if ( $key === 'ems_fluent_participant_form_id' ) return 6;
            if ( $key === 'ems_fluent_expedition_form_id' ) return 7;
            if ( $key === 'ems_expedition_form_mappings' ) return $exp_mappings;
            return $default ?? [];
        } );

        $captured = null;
        $this->signup_repo->shouldReceive( 'create_expedition_signup' )
            ->once()
            ->with( Mockery::on( function( $data ) use ( &$captured ) {
                $captured = $data;
                $prefs = $data['expedition_preferences'];
                return $data['scout_id'] === 30001 &&
                       $data['dofe_level'] === 'silver' &&
                       $prefs['exped_practice_dates'] === [ 'P-SILVER-1' ] &&
                       $prefs['exped_qualifier_dates'] === [ 'Q-SILVER-1' ];
            } ) )
            ->andReturn( 789 );

        $sync = new Fluent_Forms_Sync( $this->signup_repo, $this->wpdb );
        $sync->handle_submission( 999, [
            'signup_child' => '30001',
            'signup_level' => 'Silver',
            'exped_type'   => 'Hillwalking',
            'exped-silver-practice-dates' => [ 'P-SILVER-1' ],
            'exped-silver-qualifier-dates' => [ 'Q-SILVER-1' ],
            'exped-gold-practice-dates' => [ 'P-GOLD-1' ],
            'exped-gold-qualifier-dates' => [ 'Q-GOLD-1' ],
        ], (object) [ 'id' => 7 ] );

        $this->assertNotNull( $captured );
        $this->assertSame( [ 'P-SILVER-1' ], $captured['expedition_preferences']['exped_practice_dates'] );
        $this->assertSame( [ 'Q-SILVER-1' ], $captured['expedition_preferences']['exped_qualifier_dates'] );
    }

    public function test_populate_child_dropdown_synthesizes_self_for_member_access(): void {
        Functions\when( 'get_user_meta' )->alias( function( $uid, $key, $single = false ) {
            if ( $key === 'ems_access_type' ) return 'member';
            if ( $key === 'ems_scout_ids' ) return [ 30001 ];
            if ( $key === 'first_name' ) return 'Tom';
            if ( $key === 'last_name' ) return 'Strachan';
            if ( $key === 'ems_section_ids' ) return [ 99001 ];
            if ( $key === 'ems_unit' ) return 'Kelso';
            return '';
        } );

        $user = (object) [ 'first_name' => 'Tom', 'last_name' => 'Strachan' ];
        Functions\when( 'get_userdata' )->justReturn( $user );

        $this->wpdb->rows["SELECT first_name, last_name FROM wp_ems_osm_explorers WHERE scout_id = 30001"] = [
            'first_name' => 'Tom',
            'last_name'  => 'Strachan',
        ];

        $sync = new Fluent_Forms_Sync( $this->signup_repo, $this->wpdb );
        
        $field_data = [
            'attributes' => [ 'name' => 'signup_child' ],
            'settings'   => [ 'advanced_options' => [] ],
        ];
        $form = (object) [ 'id' => 6 ];

        $result = $sync->populate_child_dropdown( $field_data, $form );

        $options = $result['settings']['advanced_options'];
        $this->assertCount( 1, $options );
        $this->assertEquals( 'Tom Strachan', $options[0]['label'] );
        $this->assertEquals( '30001', $options[0]['value'] );
    }

    public function test_validate_submission_allows_self_scout_id_for_member_access(): void {
        Functions\when( 'get_user_meta' )->alias( function( $uid, $key, $single = false ) {
            if ( $key === 'ems_access_type' ) return 'member';
            if ( $key === 'ems_scout_ids' ) return [ 30001 ];
            if ( $key === 'first_name' ) return 'Tom';
            if ( $key === 'last_name' ) return 'Strachan';
            return '';
        } );

        $user = (object) [ 'first_name' => 'Tom', 'last_name' => 'Strachan' ];
        Functions\when( 'get_userdata' )->justReturn( $user );

        $sync = new Fluent_Forms_Sync( $this->signup_repo, $this->wpdb );

        $_POST['signup_child'] = '30001';
        $errors = $sync->validate_submission( [], (object) [ 'id' => 6 ] );
        $this->assertEmpty( $errors );

        // Should fail for unowned ID
        $_POST['signup_child'] = '99999';
        $errors = $sync->validate_submission( [], (object) [ 'id' => 6 ] );
        $this->assertNotEmpty( $errors );

        unset( $_POST['signup_child'] );
    }

    public function test_get_allowed_children_returns_children_for_leader_who_is_also_parent(): void {
        $children = [
            [ 'scout_id' => 30002, 'first_name' => 'Jane', 'last_name' => 'Doe', 'section_ids' => [ 99001 ] ]
        ];
        Functions\when( 'get_user_meta' )->alias( function( $uid, $key, $single = false ) use ( $children ) {
            if ( $key === 'ems_access_type' ) return 'leader';
            if ( $key === 'ems_children' ) return $children;
            return '';
        } );

        $sync = new Fluent_Forms_Sync( $this->signup_repo, $this->wpdb );
        
        $field_data = [
            'attributes' => [ 'name' => 'signup_child' ],
            'settings' => [ 'advanced_options' => [] ],
        ];
        $form = (object) [ 'id' => 6 ];
        $result = $sync->populate_child_dropdown( $field_data, $form );
        
        $options = $result['settings']['advanced_options'];
        $this->assertCount( 1, $options );
        $this->assertEquals( 'Jane Doe', $options[0]['label'] );
        $this->assertEquals( '30002', $options[0]['value'] );
    }

    public function test_populate_parent_email_prepopulates_and_sets_readonly_when_logged_in(): void {
        Functions\when( 'get_option' )->alias( function( $key, $default = null ) {
            if ( $key === 'ems_fluent_participant_form_id' ) return 6;
            if ( $key === 'ems_participant_form_mappings' ) return [ 'parent_email_field' => 'custom_parent_email' ];
            return $default;
        } );

        Functions\when( 'get_current_user_id' )->justReturn( 42 );

        $mock_user = (object) [ 'user_email' => 'parent@example.com' ];
        Functions\when( 'get_userdata' )->justReturn( $mock_user );

        $sync = new Fluent_Forms_Sync( $this->signup_repo, $this->wpdb );

        $field_data = [
            'attributes' => [ 'name' => 'custom_parent_email', 'class' => 'existing-class' ],
            'settings' => [ 'value' => '' ],
        ];
        $form = (object) [ 'id' => 6 ];

        $result = $sync->populate_parent_email( $field_data, $form );

        $this->assertSame( 'parent@example.com', $result['attributes']['value'] );
        $this->assertSame( 'parent@example.com', $result['settings']['value'] );
        $this->assertSame( 'readonly', $result['attributes']['readonly'] );
        $this->assertSame( 'existing-class ff-read-only', $result['attributes']['class'] );
    }

    public function test_populate_parent_email_does_not_set_readonly_when_logged_out(): void {
        Functions\when( 'get_option' )->alias( function( $key, $default = null ) {
            if ( $key === 'ems_fluent_participant_form_id' ) return 6;
            if ( $key === 'ems_participant_form_mappings' ) return [ 'parent_email_field' => 'custom_parent_email' ];
            return $default;
        } );

        Functions\when( 'get_current_user_id' )->justReturn( 0 );
        Functions\when( 'get_userdata' )->justReturn( null );

        $sync = new Fluent_Forms_Sync( $this->signup_repo, $this->wpdb );

        $field_data = [
            'attributes' => [ 'name' => 'custom_parent_email', 'class' => 'existing-class' ],
            'settings' => [ 'value' => '' ],
        ];
        $form = (object) [ 'id' => 6 ];

        $result = $sync->populate_parent_email( $field_data, $form );

        $this->assertArrayNotHasKey( 'value', $result['attributes'] );
        $this->assertArrayNotHasKey( 'readonly', $result['attributes'] );
        $this->assertSame( 'existing-class', $result['attributes']['class'] );
    }

    public function test_validate_email_otp_success_for_guest_with_correct_otp(): void {
        Functions\when( 'get_option' )->alias( function( $key, $default = null ) {
            if ( $key === 'ems_fluent_participant_form_id' ) return 6;
            if ( $key === 'ems_participant_form_mappings' ) {
                return [
                    'parent_email_field' => 'signup_parent_email',
                    'parent_otp_field'   => 'signup_parent_otp_code',
                ];
            }
            return $default;
        } );

        Functions\when( 'get_current_user_id' )->justReturn( 0 );
        Functions\when( 'is_user_logged_in' )->justReturn( false );

        $otp = '123456';
        $email = 'parent@example.com';
        $transient_key = 'fluent_otp_' . md5($email . '_signup_parent_email');
        
        $transients = [ $transient_key => hash('sha256', $otp) ];
        Functions\when( 'get_transient' )->alias( fn($key) => $transients[$key] ?? null );
        Functions\when( 'delete_transient' )->alias( function($key) use (&$transients) {
            unset($transients[$key]);
            return true;
        } );

        $sync = new Fluent_Forms_Sync( $this->signup_repo, $this->wpdb );

        $field = [ 'name' => 'signup_parent_email' ];
        $formData = [
            'signup_parent_email'    => 'parent@example.com',
            'signup_parent_otp_code' => '123456',
        ];
        $form = (object) [ 'id' => 6 ];

        $error = $sync->validate_email_otp( '', $field, $formData, [], $form );

        $this->assertEmpty( $error );
        $this->assertArrayNotHasKey( $transient_key, $transients );
    }

    public function test_validate_email_otp_failure_for_guest_with_incorrect_otp(): void {
        Functions\when( 'get_option' )->alias( function( $key, $default = null ) {
            if ( $key === 'ems_fluent_participant_form_id' ) return 6;
            if ( $key === 'ems_participant_form_mappings' ) {
                return [
                    'parent_email_field' => 'signup_parent_email',
                    'parent_otp_field'   => 'signup_parent_otp_code',
                ];
            }
            return $default;
        } );

        Functions\when( 'get_current_user_id' )->justReturn( 0 );
        Functions\when( 'is_user_logged_in' )->justReturn( false );

        $otp = '123456';
        $email = 'parent@example.com';
        $transient_key = 'fluent_otp_' . md5($email . '_signup_parent_email');
        
        $transients = [ $transient_key => hash('sha256', $otp) ];
        Functions\when( 'get_transient' )->alias( fn($key) => $transients[$key] ?? null );

        $sync = new Fluent_Forms_Sync( $this->signup_repo, $this->wpdb );

        $field = [ 'name' => 'signup_parent_email' ];
        $formData = [
            'signup_parent_email'    => 'parent@example.com',
            'signup_parent_otp_code' => '999999',
        ];
        $form = (object) [ 'id' => 6 ];

        $error = $sync->validate_email_otp( '', $field, $formData, [], $form );

        $this->assertSame( 'The verification code is incorrect.', $error );
        $this->assertNotEmpty( $transients[$transient_key] );
    }

    public function test_validate_email_otp_bypass_for_logged_in_parent(): void {
        Functions\when( 'get_option' )->alias( function( $key, $default = null ) {
            if ( $key === 'ems_fluent_participant_form_id' ) return 6;
            if ( $key === 'ems_participant_form_mappings' ) {
                return [
                    'parent_email_field' => 'signup_parent_email',
                    'parent_otp_field'   => 'signup_parent_otp_code',
                ];
            }
            return $default;
        } );

        Functions\when( 'get_current_user_id' )->justReturn( 42 );
        Functions\when( 'is_user_logged_in' )->justReturn( true );

        $sync = new Fluent_Forms_Sync( $this->signup_repo, $this->wpdb );

        $field = [ 'name' => 'signup_parent_email' ];
        $formData = [
            'signup_parent_email'    => 'parent@example.com',
            'signup_parent_otp_code' => '',
        ];
        $form = (object) [ 'id' => 6 ];

        $error = $sync->validate_email_otp( '', $field, $formData, [], $form );

        $this->assertEmpty( $error );
    }

    public function test_validate_email_otp_bypass_for_matching_email_deduplication(): void {
        Functions\when( 'get_option' )->alias( function( $key, $default = null ) {
            if ( $key === 'ems_fluent_participant_form_id' ) return 6;
            if ( $key === 'ems_participant_form_mappings' ) {
                return [
                    'parent_email_field'   => 'signup_parent_email',
                    'parent_otp_field'     => 'signup_parent_otp_code',
                    'explorer_email_field' => 'signup_explorer_email',
                    'explorer_otp_field'   => 'signup_explorer_otp_code',
                ];
            }
            return $default;
        } );

        Functions\when( 'get_current_user_id' )->justReturn( 0 );
        Functions\when( 'is_user_logged_in' )->justReturn( false );

        $otp = '123456';
        $email = 'family@example.com';
        $parent_transient_key = 'fluent_otp_' . md5($email . '_signup_parent_email');
        
        $transients = [ $parent_transient_key => hash('sha256', $otp) ];
        Functions\when( 'get_transient' )->alias( fn($key) => $transients[$key] ?? null );

        $sync = new Fluent_Forms_Sync( $this->signup_repo, $this->wpdb );

        $field = [ 'name' => 'signup_explorer_email' ];
        $formData = [
            'signup_parent_email'      => 'family@example.com',
            'signup_parent_otp_code'   => '123456',
            'signup_explorer_email'    => 'family@example.com',
            'signup_explorer_otp_code' => '',
        ];
        $form = (object) [ 'id' => 6 ];

        $error = $sync->validate_email_otp( '', $field, $formData, [], $form );

        $this->assertEmpty( $error );
    }

    public function test_validate_email_otp_bypass_for_matching_email_deduplication_bidirectional(): void {
        Functions\when( 'get_option' )->alias( function( $key, $default = null ) {
            if ( $key === 'ems_fluent_participant_form_id' ) return 6;
            if ( $key === 'ems_participant_form_mappings' ) {
                return [
                    'parent_email_field'   => 'signup_parent_email',
                    'parent_otp_field'     => 'signup_parent_otp_code',
                    'explorer_email_field' => 'signup_explorer_email',
                    'explorer_otp_field'   => 'signup_explorer_otp_code',
                ];
            }
            return $default;
        } );

        Functions\when( 'get_current_user_id' )->justReturn( 0 );
        Functions\when( 'is_user_logged_in' )->justReturn( false );

        $otp = '654321';
        $email = 'family@example.com';
        $explorer_transient_key = 'fluent_otp_' . md5($email . '_signup_explorer_email');
        
        $transients = [ $explorer_transient_key => hash('sha256', $otp) ];
        Functions\when( 'get_transient' )->alias( fn($key) => $transients[$key] ?? null );

        $sync = new Fluent_Forms_Sync( $this->signup_repo, $this->wpdb );

        $field = [ 'name' => 'signup_parent_email' ];
        $formData = [
            'signup_parent_email'      => 'family@example.com',
            'signup_parent_otp_code'   => '',
            'signup_explorer_email'    => 'family@example.com',
            'signup_explorer_otp_code' => '654321',
        ];
        $form = (object) [ 'id' => 6 ];

        $error = $sync->validate_email_otp( '', $field, $formData, [], $form );

        $this->assertEmpty( $error );
    }

	public function test_resolve_unit_for_child(): void {
		$wpdb = new class {
			public $prefix = 'wp_';
			public $prepare_args = [];

			public function prepare( string $sql, ...$args ): string {
				$this->prepare_args = $args;
				return vsprintf( str_replace( '%s', "'%s'", str_replace( '%d', '%d', $sql ) ), $args );
			}

			public function get_row( string $sql, string $output = ARRAY_A ) {
				if ( str_contains( $sql, 'wp_ems_osm_explorers' ) ) {
					return [ 'section_id' => 201 ];
				}
				if ( str_contains( $sql, 'wp_ems_units' ) ) {
					return [ 'short_code' => 'BO-Direct', 'unit_id' => 201, 'leader_email' => 'direct@example.com' ];
				}
				return null;
			}
		};

		$sync = new Fluent_Forms_Sync( $this->signup_repo, $wpdb );

		$reflected = new \ReflectionClass( Fluent_Forms_Sync::class );
		$method = $reflected->getMethod( 'resolve_unit_for_child' );
		$method->setAccessible( true );

		$res = $method->invoke( $sync, [
			'scout_id' => 30001,
			'section_ids' => [ 201 ]
		] );

		$this->assertEquals( 201, $res['unit_id'] );
		$this->assertEquals( 'BO-Direct', $res['short_code'] );
		$this->assertEquals( 'direct@example.com', $res['leader_email'] );
	}

	public function test_enqueue_form_script_generates_unit_mappings_without_active_clause(): void {
		$wpdb = new class {
			public $prefix = 'wp_';
			public string $last_query = '';

			public function get_results( string $sql, string $output = ARRAY_A ) {
				$this->last_query = $sql;
				if ( str_contains( $sql, 'active =' ) || str_contains( $sql, 'active=' ) ) {
					throw new \RuntimeException( "Unknown column 'active' in 'where clause'" );
				}
				if ( str_contains( $sql, 'wp_ems_units' ) ) {
					return [
						[
							'unit_id'      => 10,
							'name'         => 'Kelso ESU',
							'short_code'   => 'BO-Kelso',
							'leader_email' => 'leader@kelso.org',
						],
					];
				}
				return [];
			}
		};

		Functions\when( 'get_option' )->alias( function( $key, $default = false ) {
			if ( $key === 'ems_fluent_participant_form_id' ) {
				return 6;
			}
			return $default;
		} );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'admin_url' )->justReturn( 'http://example.com/wp-admin/admin-ajax.php' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce123' );

		$sync = new Fluent_Forms_Sync( $this->signup_repo, $wpdb );

		ob_start();
		$sync->enqueue_form_script( (object) [ 'id' => 6 ] );
		$output = ob_get_clean();

		$this->assertNotEmpty( $output );
		// Query must not contain 'WHERE active = 1'
		$this->assertStringNotContainsString( 'active', $wpdb->last_query );
		// Script output must include short code, name, and unit ID mappings
		$this->assertStringContainsString( 'BO-Kelso', $output );
		$this->assertStringContainsString( 'Kelso ESU', $output );
		$this->assertStringContainsString( '10', $output );
		$this->assertStringContainsString( 'leader@kelso.org', $output );
	}

	public function test_handle_verify_fluent_otp_success_transitions_to_verified_state(): void {
		$_POST['email']      = 'parent@test.com';
		$_POST['field_name'] = 'signup_parent_email';
		$_POST['code']       = '123456';

		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'is_email' )->justReturn( true );

		$transient_key = 'fluent_otp_' . md5( 'parent@test.com_signup_parent_email' );
		$stored_hash   = hash( 'sha256', '123456' );

		Functions\when( 'get_transient' )->alias( function( $k ) use ( $transient_key, $stored_hash ) {
			if ( $k === $transient_key ) return $stored_hash;
			return false;
		} );

		$saved_transients = array();
		Functions\when( 'set_transient' )->alias( function( $k, $v, $ttl ) use ( &$saved_transients ) {
			$saved_transients[ $k ] = $v;
			return true;
		} );

		Functions\expect( 'wp_send_json_success' )
			->once()
			->with( [ 'message' => 'Email verified!' ] );

		$sync = new Fluent_Forms_Sync( $this->signup_repo, $this->wpdb );
		$sync->handle_verify_fluent_otp();

		$this->assertSame( 'VERIFIED_' . $stored_hash, $saved_transients[ $transient_key ] );
	}

	public function test_handle_verify_fluent_otp_locks_out_after_5_attempts(): void {
		$_POST['email']      = 'parent@test.com';
		$_POST['field_name'] = 'signup_parent_email';
		$_POST['code']       = 'wrongcode';

		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'is_email' )->justReturn( true );

		$transient_key = 'fluent_otp_' . md5( 'parent@test.com_signup_parent_email' );
		$attempts_key  = 'fluent_otp_att_' . md5( 'parent@test.com_signup_parent_email' );

		Functions\when( 'get_transient' )->alias( function( $k ) use ( $transient_key, $attempts_key ) {
			if ( $k === $transient_key ) return hash( 'sha256', '123456' );
			if ( $k === $attempts_key ) return 5;
			return false;
		} );

		$deleted_transients = array();
		Functions\when( 'delete_transient' )->alias( function( $k ) use ( &$deleted_transients ) {
			$deleted_transients[] = $k;
			return true;
		} );

		Functions\expect( 'wp_send_json_error' )
			->once()
			->with( \Mockery::on( fn( $arg ) => strpos( $arg['message'], 'Too many' ) !== false ) );

		$sync = new Fluent_Forms_Sync( $this->signup_repo, $this->wpdb );
		$sync->handle_verify_fluent_otp();

		$this->assertContains( $transient_key, $deleted_transients );
		$this->assertContains( $attempts_key, $deleted_transients );
	}

	public function test_validate_email_otp_accepts_verified_token_and_deletes_it(): void {
		$transient_key = 'fluent_otp_' . md5( 'parent@test.com_signup_parent_email' );
		$expected_hash = hash( 'sha256', '123456' );

		Functions\when( 'get_transient' )->alias( function( $k ) use ( $transient_key, $expected_hash ) {
			if ( $k === $transient_key ) return 'VERIFIED_' . $expected_hash;
			return false;
		} );

		$deleted = array();
		Functions\when( 'delete_transient' )->alias( function( $k ) use ( &$deleted ) {
			$deleted[] = $k;
			return true;
		} );

		$sync = new Fluent_Forms_Sync( $this->signup_repo, $this->wpdb );
		$result = $sync->validate_email_otp(
			'',
			[ 'name' => 'signup_parent_email' ],
			[ 'signup_parent_email' => 'parent@test.com', 'signup_parent_otp_code' => '123456' ],
			[],
			[ 'id' => 6 ]
		);

		$this->assertSame( '', $result );
		$this->assertContains( $transient_key, $deleted );
	}

	public function test_save_expedition_submission_resolves_exped_type_bronze_and_updated_fields(): void {
		Functions\when( 'get_option' )->alias( function( $key, $default = false ) {
			if ( $key === 'ems_fluent_expedition_form_id' ) {
				return 3;
			}
			return $default;
		} );

		Functions\when( 'get_current_user_id' )->justReturn( 42 );
		Functions\when( 'get_user_meta' )->alias( function( $user_id, $key, $single = false ) {
			if ( $key === 'ems_access_type' ) return 'parent';
			if ( $key === 'ems_children' ) {
				return [
					[
						'scout_id'   => 30001,
						'first_name' => 'Tom',
						'last_name'  => 'Strachan',
					]
				];
			}
			return '';
		} );
		Functions\when( 'is_email' )->alias( fn( $val ) => str_contains( (string) $val, '@' ) );
		Functions\when( 'sanitize_email' )->alias( fn( $val ) => trim( (string) $val ) );
		Functions\when( 'wp_json_encode' )->alias( fn( $val ) => json_encode( $val ) );

		$wpdb = new class {
			public $prefix = 'wp_';
			public function prepare( string $sql, ...$args ): string {
				return $sql;
			}
			public function get_row( string $sql, string $output = ARRAY_A ) {
				if ( str_contains( $sql, 'wp_ems_units' ) ) {
					return [
						'unit_id'      => 10,
						'short_code'   => 'BO-Kelso',
						'name'         => 'Kelso ESU',
						'leader_email' => 'leader@kelso.scouts.org.uk',
					];
				}
				return null;
			}
		};

		$captured_data = null;
		$this->signup_repo->shouldReceive( 'create_expedition_signup' )
			->once()
			->andReturnUsing( function( array $data ) use ( &$captured_data ) {
				$captured_data = $data;
				return 99;
			} );

		$sync = new Fluent_Forms_Sync( $this->signup_repo, $wpdb );

		$formData = [
			'signup_child'            => '30001',
			'signup_child_name'       => [ 'first_name' => 'Tom', 'last_name' => 'Strachan' ],
			'signup_dob'              => '15/05/2011',
			'signup_email'            => 'tom@example.com',
			'signup_parentname'       => [ 'first_name' => 'Jane', 'last_name' => 'Strachan' ],
			'signup_parent_email'     => 'jane@example.com',
			'signup_unit'             => 'BO-Kelso',
			'signup_leader_email'     => 'leader@kelso.scouts.org.uk',
			'signup_level'            => 'Bronze',
			'signup_edofe_registered' => 'yes',
			'signup_dofe_number'      => '1234567',
			'exped_type'              => '',
			'exped_type_bronze'       => 'Paddling',
			'exped_bronze_dates'      => [ 'B-PQ1', 'B-PQ2' ],
			'exped_team_names'        => 'Sam Taylor',
			'input_radio'             => 'first-aid-yes',
			'input_radio_1'           => 'Yes',
			'description'             => 'Dyslexia',
		];

		$sync->handle_submission( 555, $formData, (object) [ 'id' => 3 ] );

		$this->assertNotNull( $captured_data );
		$this->assertSame( 30001, $captured_data['scout_id'] );
		$this->assertSame( 'bronze', $captured_data['dofe_level'] );
		$this->assertSame( '2011-05-15', $captured_data['dob'] );
		$this->assertSame( 'yes', $captured_data['dofe_registered'] );
		$this->assertSame( '1234567', $captured_data['dofe_number'] );
		$this->assertSame( 'first-aid-yes', $captured_data['first_aid_status'] );
		$this->assertNull( $captured_data['first_aid_expiry'] );
		$this->assertSame( 'Dyslexia', $captured_data['additional_support_needs'] );

		$prefs = $captured_data['expedition_preferences'];
		$this->assertSame( 'Paddling', $prefs['exped_type'] );
		$this->assertSame( [ 'B-PQ1', 'B-PQ2' ], $prefs['exped_practice_dates'] );
		$this->assertSame( [ 'B-PQ1', 'B-PQ2' ], $prefs['exped_qualifier_dates'] );
		$this->assertSame( 'Sam Taylor', $prefs['exped_team_names'] );
	}

	public function test_save_expedition_submission_captures_underscored_silver_and_gold_date_fields(): void {
		Functions\when( 'get_option' )->alias( function( $key, $default = false ) {
			if ( $key === 'ems_fluent_expedition_form_id' ) {
				return 3;
			}
			return $default;
		} );

		Functions\when( 'get_current_user_id' )->justReturn( 42 );
		Functions\when( 'get_user_meta' )->alias( function( $user_id, $key, $single = false ) {
			if ( $key === 'ems_access_type' ) return 'parent';
			if ( $key === 'ems_children' ) {
				return [
					[
						'scout_id'   => 30002,
						'first_name' => 'Lucy',
						'last_name'  => 'Strachan',
					]
				];
			}
			return '';
		} );
		Functions\when( 'is_email' )->alias( fn( $val ) => str_contains( (string) $val, '@' ) );
		Functions\when( 'sanitize_email' )->alias( fn( $val ) => trim( (string) $val ) );
		Functions\when( 'wp_json_encode' )->alias( fn( $val ) => json_encode( $val ) );

		$wpdb = new class {
			public $prefix = 'wp_';
			public function prepare( string $sql, ...$args ): string {
				return $sql;
			}
			public function get_row( string $sql, string $output = ARRAY_A ) {
				return null;
			}
		};

		$captured_data = null;
		$this->signup_repo->shouldReceive( 'create_expedition_signup' )
			->once()
			->andReturnUsing( function( array $data ) use ( &$captured_data ) {
				$captured_data = $data;
				return 100;
			} );

		$sync = new Fluent_Forms_Sync( $this->signup_repo, $wpdb );

		$formData = [
			'signup_child'                 => '30002',
			'signup_child_name'            => [ 'first_name' => 'Lucy', 'last_name' => 'Strachan' ],
			'signup_unit'                  => 'BO-Kelso',
			'signup_level'                 => 'Silver',
			'signup_edofe_registered'      => 'Registered but not received yet',
			'exped_type'                   => 'Hillwalking',
			'exped_silver_practice_dates'  => [ 'H-SP1', 'H-SP2' ],
			'exped_silver_qualifier_dates' => [ 'H-SQ1' ],
			'input_radio'                  => 'first-aid-no',
			'input_radio_1'                => 'No',
		];

		$sync->handle_submission( 556, $formData, (object) [ 'id' => 3 ] );

		$this->assertNotNull( $captured_data );
		$this->assertSame( 30002, $captured_data['scout_id'] );
		$this->assertSame( 'silver', $captured_data['dofe_level'] );
		$this->assertSame( 'Registered but not received yet', $captured_data['dofe_registered'] );
		$this->assertSame( 'first-aid-no', $captured_data['first_aid_status'] );

		$prefs = $captured_data['expedition_preferences'];
		$this->assertSame( 'Hillwalking', $prefs['exped_type'] );
		$this->assertSame( [ 'H-SP1', 'H-SP2' ], $prefs['exped_practice_dates'] );
		$this->assertSame( [ 'H-SQ1' ], $prefs['exped_qualifier_dates'] );
	}

	public function test_resync_existing_signups_updates_valid_submissions_and_archives_deleted_ones(): void {
		Functions\when( 'get_option' )->alias( function( $key, $default = false ) {
			if ( $key === 'ems_fluent_participant_form_id' ) return 6;
			if ( $key === 'ems_fluent_expedition_form_id' ) return 3;
			return $default;
		} );
		Functions\when( 'is_email' )->alias( fn( $val ) => str_contains( (string) $val, '@' ) );
		Functions\when( 'sanitize_email' )->alias( fn( $val ) => trim( (string) $val ) );
		Functions\when( 'wp_json_encode' )->alias( fn( $val ) => json_encode( $val ) );

		$wpdb = new class {
			public string $prefix = 'wp_';
			public function prepare( string $sql, ...$args ): string {
				$vsprintf_args = [];
				foreach ( $args as $arg ) {
					if ( is_array( $arg ) ) {
						$vsprintf_args = array_merge( $vsprintf_args, $arg );
					} else {
						$vsprintf_args[] = $arg;
					}
				}
				$formatted = str_replace( [ '%d', '%s' ], [ '%u', "'%s'" ], $sql );
				return vsprintf( $formatted, $vsprintf_args );
			}
			public function get_var( string $sql ) {
				if ( str_contains( $sql, 'SHOW TABLES LIKE' ) && str_contains( $sql, 'fluentform_forms' ) ) {
					return 'wp_fluentform_forms';
				}
				if ( str_contains( $sql, 'FROM wp_fluentform_forms WHERE id = 3' ) ) {
					return '3';
				}
				if ( str_contains( $sql, 'FROM wp_fluentform_forms WHERE id = 999' ) ) {
					return null; // Deleted form
				}
				return null;
			}
			public function get_row( string $sql, string $output = ARRAY_A ) {
				if ( str_contains( $sql, 'FROM wp_fluentform_submissions WHERE id = 501' ) ) {
					return [
						'id'       => 501,
						'form_id'  => 3,
						'status'   => 'unread',
						'response' => json_encode( [
							'signup_child'            => '30001',
							'signup_child_name'       => [ 'first_name' => 'Tom', 'last_name' => 'Strachan' ],
							'signup_dob'              => '15/05/2011',
							'signup_email'            => 'tom@example.com',
							'signup_parent_email'     => 'jane@example.com',
							'signup_unit'             => 'BO-Kelso',
							'signup_level'            => 'Bronze',
							'signup_edofe_registered' => 'yes',
							'signup_dofe_number'      => '1234567',
							'exped_type_bronze'       => 'Paddling',
							'exped_bronze_dates'      => [ 'B-PQ1', 'B-PQ2' ],
							'description'             => 'Dyslexia',
							'input_radio'             => 'first-aid-yes',
						] ),
					];
				}
				if ( str_contains( $sql, 'FROM wp_fluentform_submissions WHERE id = 502' ) ) {
					return null; // Submission deleted
				}
				if ( str_contains( $sql, 'FROM wp_fluentform_submissions WHERE id = 503' ) ) {
					return [
						'id'       => 503,
						'form_id'  => 999, // Form 999 was deleted
						'status'   => 'unread',
						'response' => json_encode( [ 'signup_child' => '30002' ] ),
					];
				}
				return null;
			}
		};

		$this->signup_repo->shouldReceive( 'get_participant_signups' )
			->once()
			->with( 'submitted' )
			->andReturn( [
				[
					'id'                 => 21,
					'scout_id'           => 30002,
					'form_submission_id' => 503,
					'signup_status'      => 'submitted',
				],
			] );

		$this->signup_repo->shouldReceive( 'get_expedition_signups' )
			->once()
			->with( 'submitted' )
			->andReturn( [
				[
					'id'                 => 11,
					'scout_id'           => 30001,
					'unit_id'            => 10,
					'unit_name'          => 'BO-Kelso',
					'leader_email'       => 'leader@kelso.scouts.org.uk',
					'form_submission_id' => 501,
					'signup_status'      => 'submitted',
				],
				[
					'id'                 => 12,
					'scout_id'           => 30003,
					'form_submission_id' => 502,
					'signup_status'      => 'submitted',
				],
			] );

		$captured_update = null;
		$this->signup_repo->shouldReceive( 'update_expedition_signup_from_sync' )
			->once()
			->with( 11, Mockery::on( function( array $data ) use ( &$captured_update ) {
				$captured_update = $data;
				return true;
			} ) )
			->andReturn( true );

		$this->signup_repo->shouldReceive( 'archive_expedition_signup' )
			->once()
			->with( 12 )
			->andReturn( true );

		$this->signup_repo->shouldReceive( 'archive_participant_signup' )
			->once()
			->with( 21 )
			->andReturn( true );

		$sync   = new Fluent_Forms_Sync( $this->signup_repo, $wpdb );
		$result = $sync->resync_existing_signups();

		$this->assertSame( 1, $result['updated'] );
		$this->assertSame( 2, $result['archived'] );
		$this->assertNotNull( $captured_update );
		$this->assertSame( 'tom@example.com', $captured_update['explorer_email'] );
		$this->assertSame( '2011-05-15', $captured_update['dob'] );
		$this->assertSame( 'yes', $captured_update['dofe_registered'] );
		$this->assertSame( 'Dyslexia', $captured_update['additional_support_needs'] );
		$this->assertSame( 'Paddling', $captured_update['expedition_preferences']['exped_type'] );
		$this->assertSame( [ 'B-PQ1', 'B-PQ2' ], $captured_update['expedition_preferences']['exped_practice_dates'] );
	}

	public function test_save_expedition_submission_inherits_scout_id_from_existing_participant_signup_when_guest(): void {
		$this->wpdb->rows["SELECT scout_id FROM wp_ems_participant_signups WHERE scout_id > 0 AND signup_status = 'submitted' AND ((explorer_email = 'mary@example.com' AND explorer_email != '') OR (LOWER(explorer_first_name) = 'mary' AND LOWER(explorer_last_name) = 'smith' AND parent_email = 'parent@example.com')) LIMIT 1"] = [
			'scout_id' => 30001,
		];

		$captured = null;
		$this->signup_repo->shouldReceive( 'create_expedition_signup' )
			->once()
			->with( Mockery::on( function( $data ) use ( &$captured ) {
				$captured = $data;
				return true;
			} ) )
			->andReturn( 456 );

		$sync = new Fluent_Forms_Sync( $this->signup_repo, $this->wpdb );
		$sync->handle_submission( 999, [
			'signup_child_name'   => [ 'first_name' => 'Mary', 'last_name' => 'Smith' ],
			'signup_email'        => 'mary@example.com',
			'signup_parent_email' => 'parent@example.com',
			'signup_level'        => 'Bronze',
		], (object) [ 'id' => 7 ] );

		$this->assertNotNull( $captured );
		$this->assertSame( 30001, $captured['scout_id'] );
	}
}


