<?php
namespace EMS\Tests\Unit\Core;

use EMS\Core\Feature_Flags;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;

class Feature_FlagsTest extends EMSTestCase {

	public function test_always_on_returns_true_regardless_of_option(): void {
		Functions\when( 'get_option' )->alias( function ( $option, $default = false ) {
			if ( $option === 'ems_feature_flags' ) {
				return array( 'core-module' => false );
			}
			return $default;
		} );

		$this->assertTrue( Feature_Flags::is_enabled( 'core-module', true ) );
	}

	public function test_reads_flags_from_option(): void {
		Functions\when( 'get_option' )->alias( function ( $option, $default = false ) {
			if ( $option === 'ems_feature_flags' ) {
				return array(
					'module-on'  => true,
					'module-off' => false,
				);
			}
			return $default;
		} );

		$this->assertTrue( Feature_Flags::is_enabled( 'module-on' ) );
		$this->assertFalse( Feature_Flags::is_enabled( 'module-off' ) );
		$this->assertTrue( Feature_Flags::is_enabled( 'unspecified-module' ) );
	}

	public function test_constant_override_disables_module(): void {
		if ( ! defined( 'EMS_DISABLE_MODULE_TEST_OVERRIDE' ) ) {
			define( 'EMS_DISABLE_MODULE_TEST_OVERRIDE', true );
		}

		Functions\when( 'get_option' )->alias( function ( $option, $default = false ) {
			if ( $option === 'ems_feature_flags' ) {
				return array( 'test-override' => true );
			}
			return $default;
		} );

		$this->assertFalse( Feature_Flags::is_enabled( 'test-override' ) );
		$this->assertTrue( Feature_Flags::is_enabled( 'test-override', true ) );
	}

	public function test_set_flag_updates_option(): void {
		$savedOption = null;

		Functions\when( 'get_option' )->alias( function ( $option, $default = false ) {
			if ( $option === 'ems_feature_flags' ) {
				return array( 'existing' => true );
			}
			return $default;
		} );

		Functions\when( 'update_option' )->alias( function ( $option, $value ) use ( &$savedOption ) {
			if ( $option === 'ems_feature_flags' ) {
				$savedOption = $value;
			}
			return true;
		} );

		Feature_Flags::set_flag( 'new-feature', false );

		$this->assertSame(
			array(
				'existing'    => true,
				'new-feature' => false,
			),
			$savedOption
		);
	}
}
