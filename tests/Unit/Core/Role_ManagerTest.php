<?php
namespace EMS\Tests\Unit\Core;

use EMS\Core\Role_Manager;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;
use Mockery;

class Role_ManagerTest extends EMSTestCase {
    public function test_register_roles_adds_roles_if_not_existing(): void {
        $added_roles = [];
        Functions\when( 'get_role' )->justReturn( null );
        Functions\when( 'add_role' )->alias(
            static function ( string $role, string $display_name, array $caps ) use ( &$added_roles ): void {
                $added_roles[ $role ] = [
                    'display_name' => $display_name,
                    'caps'         => $caps,
                ];
            }
        );

        ( new Role_Manager() )->register_roles();

        $this->assertArrayHasKey( 'ems_parent', $added_roles );
        $this->assertSame( 'EMS Parent', $added_roles['ems_parent']['display_name'] );
        $this->assertTrue( $added_roles['ems_parent']['caps']['access_ems_parent_portal'] );

        $this->assertArrayHasKey( 'ems_explorer', $added_roles );
        $this->assertTrue( $added_roles['ems_explorer']['caps']['access_ems_explorer_portal'] );

        $this->assertArrayHasKey( 'ems_leader', $added_roles );
        $this->assertTrue( $added_roles['ems_leader']['caps']['access_ems_leader_portal'] );
        $this->assertTrue( $added_roles['ems_leader']['caps']['edit_posts'] );
    }

    public function test_register_roles_updates_capabilities_if_role_exists(): void {
        $wp_role = Mockery::mock( 'WP_Role' );
        $wp_role->shouldReceive( 'add_cap' )->with( 'read' )->times( 3 );
        $wp_role->shouldReceive( 'add_cap' )->with( 'access_ems_parent_portal' )->once();
        $wp_role->shouldReceive( 'add_cap' )->with( 'access_ems_explorer_portal' )->once();
        $wp_role->shouldReceive( 'add_cap' )->with( 'access_ems_leader_portal' )->once();
        $wp_role->shouldReceive( 'add_cap' )->with( 'edit_posts' )->once();

        Functions\when( 'get_role' )->justReturn( $wp_role );
        Functions\expect( 'add_role' )->never();

        ( new Role_Manager() )->register_roles();
        $this->addToAssertionCount( 1 );
    }
}
