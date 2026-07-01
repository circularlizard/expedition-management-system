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
    }

    public function test_init_hooks_adds_filters_and_actions(): void {
        $sync = new Fluent_Forms_Sync( $this->signup_repo, $this->unit_repo, $this->wpdb );
        $sync->init_hooks();

        $this->assertTrue( Filters\has( 'fluentform/rendering_field_data_select' ) );
        $this->assertTrue( Filters\has( 'fluentform/validate_input_item_select' ) );
        $this->assertTrue( Filters\has( 'fluentform/input_default_value_signup_unit' ) );
        $this->assertTrue( Filters\has( 'fluentform/validation_errors' ) );
        $this->assertTrue( Actions\has( 'fluentform/submission_inserted' ) );
        $this->assertTrue( Actions\has( 'fluentform/after_payment_status_change' ) );
    }

    public function test_populate_child_dropdown_injects_choices(): void {
        $children = [
            [ 'scout_id' => 30001, 'first_name' => 'Mary', 'last_name' => 'Smith', 'section_ids' => [ 99001 ] ]
        ];
        Functions\when( 'get_user_meta' )->justReturn( $children );

        $this->wpdb->rows["SELECT first_name, last_name FROM wp_ems_osm_explorers WHERE scout_id = 30001"] = [
            'first_name' => 'Mary',
            'last_name'  => 'Smith',
        ];

        $sync = new Fluent_Forms_Sync( $this->signup_repo, $this->unit_repo, $this->wpdb );
        
        $field_data = [
            'attributes' => [ 'name' => 'signup_child' ],
            'settings'   => [ 'advanced_options' => [] ],
        ];
        $form = (object) [ 'id' => 4 ];

        $result = $sync->populate_child_dropdown( $field_data, $form );

        $options = $result['settings']['advanced_options'];
        $this->assertCount( 1, $options );
        $this->assertEquals( 'Mary Smith', $options[0]['label'] );
        $this->assertEquals( '30001|Mary|Smith', $options[0]['value'] );
    }

    public function test_get_default_unit_value_matches_explorer_section(): void {
        $children = [
            [ 'scout_id' => 30001, 'first_name' => 'Mary', 'last_name' => 'Smith', 'section_ids' => [ 99001 ] ]
        ];
        Functions\when( 'get_user_meta' )->justReturn( $children );

        $this->wpdb->rows["SELECT section_id FROM wp_ems_osm_explorers WHERE scout_id = 30001"] = [
            'section_id' => 99001,
        ];
        $this->wpdb->rows["SELECT short_code FROM wp_ems_units WHERE section_id = 99001 AND active = 1 LIMIT 1"] = [
            'short_code' => 'BO-Kelso',
        ];

        $sync = new Fluent_Forms_Sync( $this->signup_repo, $this->unit_repo, $this->wpdb );
        $result = $sync->get_default_unit_value( '', [] );

        $this->assertEquals( 'BO-Kelso', $result );
    }

    public function test_validate_submission_errors_on_invalid_level(): void {
        Functions\when( 'get_option' )->justReturn( [
            4 => [
                'scout_id_field'   => 'signup_child',
                'dofe_level_field' => 'signup_level',
            ]
        ] );

        $sync = new Fluent_Forms_Sync( $this->signup_repo, $this->unit_repo, $this->wpdb );

        $_POST['signup_child'] = '30001|Mary|Smith';
        $_POST['signup_level'] = 'Platinum'; // Invalid Level

        Functions\when( 'get_user_meta' )->justReturn( [
            [ 'scout_id' => 30001 ]
        ] );

        $errors = $sync->validate_submission( [], (object) [ 'id' => 4 ] );

        $this->assertArrayHasKey( 'signup_level', $errors );
    }

    public function test_handle_submission_creates_signup_and_triggers_notifications(): void {
        Functions\when( 'get_option' )->justReturn( [
            4 => [
                'scout_id_field'   => 'signup_child',
                'dofe_level_field' => 'signup_level',
                'esu_patrol_field' => 'signup_unit',
                'pref_fields'      => [ 'exped_type' ],
            ]
        ] );

        $this->wpdb->rows["SELECT unit_id FROM wp_ems_units WHERE (short_code = 'BO-Kelso' OR name = 'BO-Kelso') LIMIT 1"] = [
            'unit_id' => 10,
        ];
        $this->wpdb->rows["SELECT leader_email FROM wp_ems_units WHERE short_code = 'BO-Kelso' AND active = 1 LIMIT 1"] = [
            'leader_email' => 'leader@example.com',
        ];

        $parent_user = Mockery::mock( \WP_User::class );
        $parent_user->user_email = 'parent@example.com';
        Functions\when( 'get_userdata' )->justReturn( $parent_user );

        $this->signup_repo->shouldReceive( 'create_signup' )
            ->once()
            ->with( Mockery::on( function( $data ) {
                return $data['scout_id'] === 30001 &&
                       $data['explorer_first_name'] === 'Mary' &&
                       $data['explorer_last_name'] === 'Smith' &&
                       $data['unit_id'] === 10 &&
                       $data['dofe_level'] === 'Bronze';
            } ) )
            ->andReturn( 123 );

        Functions\expect( 'wp_mail' )->twice()->andReturn( true );

        $sync = new Fluent_Forms_Sync( $this->signup_repo, $this->unit_repo, $this->wpdb );
        $sync->handle_submission( 999, [
            'signup_child' => '30001|Mary|Smith',
            'signup_level' => 'Bronze',
            'signup_unit'  => 'BO-Kelso',
            'exped_type'   => 'Hillwalking',
        ], (object) [ 'id' => 4 ] );

        $this->assertTrue( true ); // Verify execution finished without exception
    }
}
