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
}
