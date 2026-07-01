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

    public function test_handle_submission_creates_signup_record(): void {
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

        // wp_mail must NOT be called — email is handled by Fluent Forms notifications
        Functions\expect( 'wp_mail' )->never();

        $sync = new Fluent_Forms_Sync( $this->signup_repo, $this->unit_repo, $this->wpdb );
        $sync->handle_submission( 999, [
            'signup_child' => '30001|Mary|Smith',
            'signup_level' => 'Bronze',
            'signup_unit'  => 'BO-Kelso',
            'exped_type'   => 'Hillwalking',
        ], (object) [ 'id' => 4 ] );

        $this->assertTrue( true );
    }

    public function test_populate_parent_email_sets_value(): void {
        $user = Mockery::mock( \WP_User::class );
        $user->user_email = 'parent@example.com';
        Functions\when( 'get_userdata' )->justReturn( $user );

        $sync = new Fluent_Forms_Sync( $this->signup_repo, $this->unit_repo, $this->wpdb );
        $data = [
            'attributes' => [ 'name' => 'signup_parent_email', 'value' => '' ],
            'settings'   => [ 'value' => '' ],
        ];

        $result = $sync->populate_parent_email( $data, (object) [ 'id' => 4 ] );

        $this->assertSame( 'parent@example.com', $result['attributes']['value'] );
        $this->assertSame( 'parent@example.com', $result['settings']['value'] );
    }

    public function test_populate_parent_email_ignores_other_fields(): void {
        $sync = new Fluent_Forms_Sync( $this->signup_repo, $this->unit_repo, $this->wpdb );
        $data = [
            'attributes' => [ 'name' => 'some_other_email', 'value' => '' ],
            'settings'   => [ 'value' => '' ],
        ];

        $result = $sync->populate_parent_email( $data, (object) [ 'id' => 4 ] );

        $this->assertSame( '', $result['attributes']['value'] );
    }

    public function test_populate_explorer_email_uses_osm_record_when_synced(): void {
        $children = [
            [ 'scout_id' => 30001, 'section_ids' => [ 99001 ] ]
        ];
        Functions\when( 'get_user_meta' )->justReturn( $children );

        // Explorer is in the local sync table
        $this->wpdb->rows["SELECT email FROM wp_ems_osm_explorers WHERE scout_id = 30001 LIMIT 1"] = [
            'email' => 'explorer@example.com',
        ];

        $sync = new Fluent_Forms_Sync( $this->signup_repo, $this->unit_repo, $this->wpdb );
        $data = [
            'attributes' => [ 'name' => 'signup_explorer_email', 'value' => '' ],
            'settings'   => [ 'value' => '' ],
        ];

        $result = $sync->populate_explorer_email( $data, (object) [ 'id' => 4 ] );

        $this->assertSame( 'explorer@example.com', $result['attributes']['value'] );
    }

    public function test_populate_explorer_email_leaves_empty_when_not_synced(): void {
        $children = [
            [ 'scout_id' => 30001, 'section_ids' => [ 99001 ] ]
        ];
        Functions\when( 'get_user_meta' )->justReturn( $children );
        // No row in ems_osm_explorers for this scout_id

        $sync = new Fluent_Forms_Sync( $this->signup_repo, $this->unit_repo, $this->wpdb );
        $data = [
            'attributes' => [ 'name' => 'signup_explorer_email', 'value' => '' ],
            'settings'   => [ 'value' => '' ],
        ];

        $result = $sync->populate_explorer_email( $data, (object) [ 'id' => 4 ] );

        $this->assertSame( '', $result['attributes']['value'] );
    }

    public function test_populate_leader_email_resolves_from_unit(): void {
        $children = [
            [ 'scout_id' => 30001, 'section_ids' => [ 99001 ] ]
        ];
        Functions\when( 'get_user_meta' )->justReturn( $children );

        $this->wpdb->rows["SELECT short_code, unit_id, leader_email FROM wp_ems_units WHERE unit_id = 99001 AND active = 1 LIMIT 1"] = [
            'short_code'   => 'BO-Kelso',
            'unit_id'      => 99001,
            'leader_email' => 'leader@example.com',
        ];

        $sync = new Fluent_Forms_Sync( $this->signup_repo, $this->unit_repo, $this->wpdb );
        $data = [
            'attributes' => [ 'name' => 'signup_leader_email', 'value' => '' ],
            'settings'   => [ 'value' => '' ],
        ];

        $result = $sync->populate_leader_email( $data, (object) [ 'id' => 4 ] );

        $this->assertSame( 'leader@example.com', $result['attributes']['value'] );
    }

    public function test_handle_payment_status_paid_marks_signup_paid(): void {
        $this->signup_repo->shouldReceive( 'get_signup_by_submission_id' )
            ->once()
            ->with( 999 )
            ->andReturn( [ 'payment_status' => 'pending' ] );
        $this->signup_repo->shouldReceive( 'update_payment_status_by_submission_id' )
            ->once()
            ->with( 999, 'paid' )
            ->andReturn( true );

        $sync = new Fluent_Forms_Sync( $this->signup_repo, $this->unit_repo, $this->wpdb );
        $sync->handle_payment_status( 'paid', (object) [ 'id' => 999 ] );

        $this->assertTrue( true );
    }

    public function test_handle_payment_status_succeeded_alias_marks_signup_paid(): void {
        $this->signup_repo->shouldReceive( 'get_signup_by_submission_id' )
            ->once()
            ->with( 999 )
            ->andReturn( [ 'payment_status' => 'pending' ] );
        $this->signup_repo->shouldReceive( 'update_payment_status_by_submission_id' )
            ->once()
            ->with( 999, 'paid' )
            ->andReturn( true );

        $sync = new Fluent_Forms_Sync( $this->signup_repo, $this->unit_repo, $this->wpdb );
        $sync->handle_payment_status( 'succeeded', (object) [ 'id' => 999 ] );

        $this->assertTrue( true );
    }

    public function test_handle_payment_status_processing_maps_to_pending(): void {
        $this->signup_repo->shouldReceive( 'get_signup_by_submission_id' )
            ->once()
            ->with( 999 )
            ->andReturn( [ 'payment_status' => 'pending' ] );
        $this->signup_repo->shouldReceive( 'update_payment_status_by_submission_id' )
            ->once()
            ->with( 999, 'pending' )
            ->andReturn( true );

        $sync = new Fluent_Forms_Sync( $this->signup_repo, $this->unit_repo, $this->wpdb );
        $sync->handle_payment_status( 'processing', (object) [ 'id' => 999 ] );

        $this->assertTrue( true );
    }

    public function test_handle_payment_status_does_not_downgrade_paid_row(): void {
        // Row is already paid — a stale 'processing' event must not overwrite it.
        $this->signup_repo->shouldReceive( 'get_signup_by_submission_id' )
            ->once()
            ->with( 999 )
            ->andReturn( [ 'payment_status' => 'paid' ] );
        // update must NOT be called
        $this->signup_repo->shouldReceive( 'update_payment_status_by_submission_id' )->never();

        $sync = new Fluent_Forms_Sync( $this->signup_repo, $this->unit_repo, $this->wpdb );
        $sync->handle_payment_status( 'processing', (object) [ 'id' => 999 ] );

        $this->assertTrue( true );
    }
}
