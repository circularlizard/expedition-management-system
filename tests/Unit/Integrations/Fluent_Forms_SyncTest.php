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
        $sync = new Fluent_Forms_Sync( $this->signup_repo, $this->unit_repo, $this->wpdb );
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

        $sync = new Fluent_Forms_Sync( $this->signup_repo, $this->unit_repo, $this->wpdb );
        
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
        $this->signup_repo->shouldReceive( 'create_participant_signup' )
            ->once()
            ->with( Mockery::on( function( $data ) {
                return $data['scout_id'] === 30001 &&
                       $data['explorer_first_name'] === 'Mary' &&
                       $data['explorer_last_name'] === 'Smith' &&
                       $data['dofe_level'] === 'bronze' &&
                       $data['dob'] === '2010-05-15';
            } ) )
            ->andReturn( 123 );

        $sync = new Fluent_Forms_Sync( $this->signup_repo, $this->unit_repo, $this->wpdb );
        $sync->handle_submission( 999, [
            'signup_child'          => '30001',
            'signup_level'          => 'Bronze',
            'signup_dob'            => '2010-05-15',
            'signup_dofe_registered'=> 'n',
        ], (object) [ 'id' => 6 ] );

        $this->assertTrue( true );
    }

    public function test_handle_submission_creates_expedition_signup_record(): void {
        $this->signup_repo->shouldReceive( 'create_expedition_signup' )
            ->once()
            ->with( Mockery::on( function( $data ) {
                return $data['scout_id'] === 30001 &&
                       $data['explorer_first_name'] === 'Mary' &&
                       $data['explorer_last_name'] === 'Smith' &&
                       $data['dofe_level'] === 'silver' &&
                       $data['first_aid_status'] === 'first-response';
            } ) )
            ->andReturn( 456 );

        $sync = new Fluent_Forms_Sync( $this->signup_repo, $this->unit_repo, $this->wpdb );
        $sync->handle_submission( 999, [
            'signup_child' => '30001',
            'signup_level' => 'Silver',
            'exped_type'   => 'Hillwalking',
            'input_radio'  => 'first-response',
        ], (object) [ 'id' => 7 ] );

        $this->assertTrue( true );
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

        $this->signup_repo->shouldReceive( 'create_expedition_signup' )
            ->once()
            ->with( Mockery::on( function( $data ) {
                $prefs = $data['expedition_preferences'];
                return $data['scout_id'] === 30001 &&
                       $data['dofe_level'] === 'silver' &&
                       $prefs['exped_practice_dates'] === [ 'P-SILVER-1' ] &&
                       $prefs['exped_qualifier_dates'] === [ 'Q-SILVER-1' ];
            } ) )
            ->andReturn( 789 );

        $sync = new Fluent_Forms_Sync( $this->signup_repo, $this->unit_repo, $this->wpdb );
        $sync->handle_submission( 999, [
            'signup_child' => '30001',
            'signup_level' => 'Silver',
            'exped_type'   => 'Hillwalking',
            'exped-silver-practice-dates' => [ 'P-SILVER-1' ],
            'exped-silver-qualifier-dates' => [ 'Q-SILVER-1' ],
            'exped-gold-practice-dates' => [ 'P-GOLD-1' ],
            'exped-gold-qualifier-dates' => [ 'Q-GOLD-1' ],
        ], (object) [ 'id' => 7 ] );

        $this->assertTrue( true );
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

        $sync = new Fluent_Forms_Sync( $this->signup_repo, $this->unit_repo, $this->wpdb );
        
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

        $sync = new Fluent_Forms_Sync( $this->signup_repo, $this->unit_repo, $this->wpdb );

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

        $sync = new Fluent_Forms_Sync( $this->signup_repo, $this->unit_repo, $this->wpdb );
        
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
}
