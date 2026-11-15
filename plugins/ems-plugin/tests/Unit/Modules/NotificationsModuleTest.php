<?php
namespace EMS\Tests\Unit\Modules;

use EMS\Tests\EMSTestCase;
use EMS\Modules\Notifications\NotificationsModule;

class NotificationsModuleTest extends EMSTestCase {

	public function test_getters(): void {
		$module = new NotificationsModule();
		$this->assertSame( 'notifications', $module->getSlug() );
		$this->assertFalse( $module->isAlwaysOn() );
	}

	public function test_register_and_boot(): void {
		$module = new NotificationsModule();
		$module->register();
		$module->boot();

		$this->assertTrue( has_action( 'ems_daily_log_cleanup' ) );
		$this->assertTrue( has_action( 'ems.signup.form_submitted' ) );
	}
}
