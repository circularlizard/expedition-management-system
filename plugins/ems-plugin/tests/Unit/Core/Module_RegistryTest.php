<?php
namespace EMS\Tests\Unit\Core;

use EMS\Contracts\ModuleInterface;
use EMS\Core\Module_Registry;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;
use Mockery;

class Module_RegistryTest extends EMSTestCase {

	public function test_register_and_boot_modules(): void {
		$registry = new Module_Registry();

		Functions\when( 'get_option' )->alias( function ( $option, $default = false ) {
			if ( $option === 'ems_feature_flags' ) {
				return array();
			}
			return $default;
		} );

		$moduleA = Mockery::mock( ModuleInterface::class );
		$moduleA->shouldReceive( 'getSlug' )->andReturn( 'module-a' );
		$moduleA->shouldReceive( 'isAlwaysOn' )->andReturn( false );
		$moduleA->shouldReceive( 'register' )->once();
		$moduleA->shouldReceive( 'boot' )->once();

		$registry->register_module( $moduleA );

		$this->assertSame( array( 'module-a' => $moduleA ), $registry->get_modules() );
		$this->assertSame( $moduleA, $registry->get_module( 'module-a' ) );
		$this->assertNull( $registry->get_module( 'non-existent' ) );

		$registry->boot_all();

		$this->assertFalse( $registry->is_degraded( 'module-a' ) );
		$this->assertEmpty( $registry->get_degraded_modules() );
	}

	public function test_feature_flag_disabling_non_always_on_module(): void {
		$registry = new Module_Registry();

		Functions\when( 'get_option' )->alias( function ( $option, $default = false ) {
			if ( $option === 'ems_feature_flags' ) {
				return array(
					'disabled-module' => false,
					'always-on-slug'  => false,
				);
			}
			return $default;
		} );

		$disabledModule = Mockery::mock( ModuleInterface::class );
		$disabledModule->shouldReceive( 'getSlug' )->andReturn( 'disabled-module' );
		$disabledModule->shouldReceive( 'isAlwaysOn' )->andReturn( false );
		$disabledModule->shouldNotReceive( 'register' );
		$disabledModule->shouldNotReceive( 'boot' );

		$alwaysOnModule = Mockery::mock( ModuleInterface::class );
		$alwaysOnModule->shouldReceive( 'getSlug' )->andReturn( 'always-on-slug' );
		$alwaysOnModule->shouldReceive( 'isAlwaysOn' )->andReturn( true );
		$alwaysOnModule->shouldReceive( 'register' )->once();
		$alwaysOnModule->shouldReceive( 'boot' )->once();

		$registry->register_module( $disabledModule );
		$registry->register_module( $alwaysOnModule );

		$registry->boot_all();

		$this->assertFalse( $registry->is_degraded( 'disabled-module' ) );
		$this->assertFalse( $registry->is_degraded( 'always-on-slug' ) );
	}

	public function test_fault_isolation_broken_module_in_boot(): void {
		global $wpdb;

		$wpdb = Mockery::mock( 'stdClass' );
		$wpdb->prefix = 'wp_';

		Functions\when( 'get_option' )->alias( function ( $option, $default = false ) {
			if ( $option === 'ems_feature_flags' ) {
				return array();
			}
			return $default;
		} );

		$auditLoggedAction = null;
		$wpdb->shouldReceive( 'insert' )
			->atLeast()->once()
			->with(
				'wp_ems_audit_logs',
				Mockery::on( function ( $data ) use ( &$auditLoggedAction ) {
					if ( isset( $data['action'] ) && $data['action'] === 'module_boot_failed:broken-module' ) {
						$auditLoggedAction = $data['action'];
					}
					return true;
				} ),
				Mockery::any()
			)
			->andReturn( true );

		$registry = new Module_Registry();

		$goodModule1 = Mockery::mock( ModuleInterface::class );
		$goodModule1->shouldReceive( 'getSlug' )->andReturn( 'good-module-1' );
		$goodModule1->shouldReceive( 'isAlwaysOn' )->andReturn( false );
		$goodModule1->shouldReceive( 'register' )->once();
		$goodModule1->shouldReceive( 'boot' )->once();

		$brokenModule = Mockery::mock( ModuleInterface::class );
		$brokenModule->shouldReceive( 'getSlug' )->andReturn( 'broken-module' );
		$brokenModule->shouldReceive( 'isAlwaysOn' )->andReturn( false );
		$brokenModule->shouldReceive( 'register' )->once();
		$brokenModule->shouldReceive( 'boot' )->once()->andThrow( new \RuntimeException( 'Fatal boot explosion' ) );

		$goodModule2 = Mockery::mock( ModuleInterface::class );
		$goodModule2->shouldReceive( 'getSlug' )->andReturn( 'good-module-2' );
		$goodModule2->shouldReceive( 'isAlwaysOn' )->andReturn( false );
		$goodModule2->shouldReceive( 'register' )->once();
		$goodModule2->shouldReceive( 'boot' )->once();

		$registry->register_module( $goodModule1 );
		$registry->register_module( $brokenModule );
		$registry->register_module( $goodModule2 );

		// boot_all should not throw
		$registry->boot_all();

		$this->assertSame( 'module_boot_failed:broken-module', $auditLoggedAction );
		$this->assertTrue( $registry->is_degraded( 'broken-module' ) );
		$this->assertSame( 'Fatal boot explosion', $registry->get_degraded_modules()['broken-module'] );
		$this->assertFalse( $registry->is_degraded( 'good-module-1' ) );
		$this->assertFalse( $registry->is_degraded( 'good-module-2' ) );
	}

	public function test_fault_isolation_broken_module_in_register(): void {
		global $wpdb;

		$wpdb = Mockery::mock( 'stdClass' );
		$wpdb->prefix = 'wp_';

		Functions\when( 'get_option' )->alias( function ( $option, $default = false ) {
			if ( $option === 'ems_feature_flags' ) {
				return array();
			}
			return $default;
		} );

		$auditLoggedAction = null;
		$wpdb->shouldReceive( 'insert' )
			->atLeast()->once()
			->with(
				'wp_ems_audit_logs',
				Mockery::on( function ( $data ) use ( &$auditLoggedAction ) {
					if ( isset( $data['action'] ) && $data['action'] === 'module_boot_failed:broken-register' ) {
						$auditLoggedAction = $data['action'];
					}
					return true;
				} ),
				Mockery::any()
			)
			->andReturn( true );

		$registry = new Module_Registry();

		$brokenRegisterModule = Mockery::mock( ModuleInterface::class );
		$brokenRegisterModule->shouldReceive( 'getSlug' )->andReturn( 'broken-register' );
		$brokenRegisterModule->shouldReceive( 'isAlwaysOn' )->andReturn( false );
		$brokenRegisterModule->shouldReceive( 'register' )->once()->andThrow( new \RuntimeException( 'Register failed' ) );
		$brokenRegisterModule->shouldNotReceive( 'boot' );

		$goodModule = Mockery::mock( ModuleInterface::class );
		$goodModule->shouldReceive( 'getSlug' )->andReturn( 'good-module' );
		$goodModule->shouldReceive( 'isAlwaysOn' )->andReturn( false );
		$goodModule->shouldReceive( 'register' )->once();
		$goodModule->shouldReceive( 'boot' )->once();

		$registry->register_module( $brokenRegisterModule );
		$registry->register_module( $goodModule );

		$registry->boot_all();

		$this->assertSame( 'module_boot_failed:broken-register', $auditLoggedAction );
		$this->assertTrue( $registry->is_degraded( 'broken-register' ) );
		$this->assertSame( 'Register failed', $registry->get_degraded_modules()['broken-register'] );
		$this->assertFalse( $registry->is_degraded( 'good-module' ) );
	}
}
