<?php
namespace EMS\Tests\Unit\Core;

use EMS\Core\CPT_Registry;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;

class CPT_RegistryTest extends EMSTestCase {
    public function test_register_calls_register_post_type_for_expedition(): void {
        $registered = [];
        Functions\when( 'register_post_type' )->alias(
            static function ( string $type, array $args ) use ( &$registered ): void {
                $registered[] = $type;
            }
        );

        ( new CPT_Registry() )->register();

        $this->assertContains( 'expedition', $registered );
    }

    public function test_register_calls_register_post_type_for_team(): void {
        $registered = [];
        Functions\when( 'register_post_type' )->alias(
            static function ( string $type, array $args ) use ( &$registered ): void {
                $registered[] = $type;
            }
        );

        ( new CPT_Registry() )->register();

        $this->assertContains( 'team', $registered );
    }

    public function test_expedition_args_include_show_in_rest(): void {
        $captured = [];
        Functions\when( 'register_post_type' )->alias(
            static function ( string $type, array $args ) use ( &$captured ): void {
                $captured[ $type ] = $args;
            }
        );

        ( new CPT_Registry() )->register();

        $this->assertTrue( $captured['expedition']['show_in_rest'] );
    }

    public function test_expedition_args_include_menu_icon(): void {
        $captured = [];
        Functions\when( 'register_post_type' )->alias(
            static function ( string $type, array $args ) use ( &$captured ): void {
                $captured[ $type ] = $args;
            }
        );

        ( new CPT_Registry() )->register();

        $this->assertArrayHasKey( 'menu_icon', $captured['expedition'] );
    }

    public function test_team_args_set_post_parent_support(): void {
        $captured = [];
        Functions\when( 'register_post_type' )->alias(
            static function ( string $type, array $args ) use ( &$captured ): void {
                $captured[ $type ] = $args;
            }
        );

        ( new CPT_Registry() )->register();

        $this->assertContains( 'custom-fields', $captured['team']['supports'] );
    }

    public function test_expedition_meta_field_list_covers_required_keys(): void {
        $registry = new CPT_Registry();
        $fields   = $registry->get_expedition_meta_fields();

        foreach ( [
            'ems_level',
            'ems_type',
            'ems_expedition_code',
            'ems_start_date',
            'ems_end_date',
            'ems_status',
            'ems_lic_id',
            'ems_expedition_lic_name',
            'ems_expedition_lic_phone',
            'ems_expedition_lic_email',
            'ems_expedition_whatsapp_explorers',
            'ems_expedition_whatsapp_parents',
            'ems_expedition_route_info',
            'ems_route_received',
            'ems_route_approved',
            'ems_osm_event_id',
        ] as $key ) {
            $this->assertArrayHasKey( $key, $fields, "Missing expedition meta field: {$key}" );
        }
    }

    public function test_team_meta_field_list_covers_required_keys(): void {
        $registry = new CPT_Registry();
        $fields   = $registry->get_team_meta_fields();

        foreach ( [
            'ems_team_code',
            'ems_route_status',
            'ems_gpx_file_id',
            'ems_route_card_file_id',
        ] as $key ) {
            $this->assertArrayHasKey( $key, $fields, "Missing team meta field: {$key}" );
        }
    }

    public function test_register_calls_register_post_type_for_season(): void {
        $registered = [];
        Functions\when( 'register_post_type' )->alias(
            static function ( string $type, array $args ) use ( &$registered ): void {
                $registered[] = $type;
            }
        );

        ( new CPT_Registry() )->register();

        $this->assertContains( 'season', $registered );
    }

    public function test_season_args_include_show_in_rest(): void {
        $captured = [];
        Functions\when( 'register_post_type' )->alias(
            static function ( string $type, array $args ) use ( &$captured ): void {
                $captured[ $type ] = $args;
            }
        );

        ( new CPT_Registry() )->register();

        $this->assertTrue( $captured['season']['show_in_rest'] );
    }

    public function test_season_meta_field_list_covers_required_keys(): void {
        $registry = new CPT_Registry();
        $fields   = $registry->get_season_meta_fields();

        foreach ( [ 'ems_season_year', 'ems_season_status' ] as $key ) {
            $this->assertArrayHasKey( $key, $fields, "Missing season meta field: {$key}" );
        }
    }

    public function test_season_status_enum_values(): void {
        $registry = new CPT_Registry();
        $fields   = $registry->get_season_meta_fields();

        $this->assertSame( [ 'active', 'archived' ], $fields['ems_season_status']['enum'] );
    }

    public function test_expedition_meta_includes_new_planner_fields(): void {
        $registry = new CPT_Registry();
        $fields   = $registry->get_expedition_meta_fields();

        foreach ( [
            'ems_event_code',
            'ems_transport',
            'ems_lic_name',
            'ems_lic_email',
            'ems_lic_phone',
            'ems_start_location',
            'ems_end_location',
            'ems_start_time',
            'ems_end_time',
            'ems_route_info',
        ] as $key ) {
            $this->assertArrayHasKey( $key, $fields, "Missing expedition planner meta field: {$key}" );
        }
    }

    public function test_expedition_type_enum_includes_training(): void {
        $registry = new CPT_Registry();
        $fields   = $registry->get_expedition_meta_fields();

        $this->assertContains( 'training', $fields['ems_type']['enum'] );
    }

    public function test_expedition_transport_enum_values(): void {
        $registry = new CPT_Registry();
        $fields   = $registry->get_expedition_meta_fields();

        $this->assertSame( [ 'hillwalking', 'biking', 'paddling' ], $fields['ems_transport']['enum'] );
    }

    public function test_team_meta_includes_team_number(): void {
        $registry = new CPT_Registry();
        $fields   = $registry->get_team_meta_fields();

        $this->assertArrayHasKey( 'ems_team_number', $fields );
        $this->assertSame( 'integer', $fields['ems_team_number']['type'] );
    }
}
