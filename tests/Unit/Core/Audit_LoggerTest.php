<?php
namespace EMS\Tests\Unit\Core;

use EMS\Core\Audit_Logger;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;
use Mockery;

class Audit_LoggerTest extends EMSTestCase {

    protected function setUp(): void {
        parent::setUp();
        // Clear global server variables to control test state
        unset($_SERVER['REMOTE_ADDR']);
        unset($_SERVER['HTTP_USER_AGENT']);
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    }

    public function test_log_inserts_correct_record(): void {
        global $wpdb;

        // Mock $wpdb
        $wpdb = Mockery::mock('stdClass');
        $wpdb->prefix = 'wp_';

        $_SERVER['REMOTE_ADDR'] = '192.168.1.50';
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent 1.0';

        Functions\when('get_current_user_id')->justReturn(12);

        $inserted = [];
        $wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_ems_audit_logs',
                Mockery::on(function ($data) use (&$inserted) {
                    $inserted = $data;
                    return true;
                }),
                Mockery::any()
            )
            ->andReturn(true);

        Audit_Logger::log('team_member_add', 30001);

        $this->assertSame(12, $inserted['user_id']);
        $this->assertSame('team_member_add', $inserted['action']);
        $this->assertSame(30001, $inserted['target_scout_id']);
        $this->assertSame('192.168.1.50', $inserted['ip_address']);
        $this->assertSame('TestAgent 1.0', $inserted['user_agent']);
        $this->assertNotEmpty($inserted['timestamp']);
    }

    public function test_log_resolves_x_forwarded_for_ip(): void {
        global $wpdb;
        $wpdb = Mockery::mock('stdClass');
        $wpdb->prefix = 'wp_';

        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.195, 70.41.3.18';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';

        $inserted = [];
        $wpdb->shouldReceive('insert')
            ->once()
            ->with('wp_ems_audit_logs', Mockery::on(function ($data) use (&$inserted) {
                $inserted = $data;
                return true;
            }), Mockery::any())
            ->andReturn(true);

        Audit_Logger::log('setting_update');

        $this->assertSame('203.0.113.195', $inserted['ip_address']);
    }

    public function test_log_defaults_user_id_to_zero_when_not_authenticated(): void {
        global $wpdb;
        $wpdb = Mockery::mock('stdClass');
        $wpdb->prefix = 'wp_';

        Functions\when('get_current_user_id')->justReturn(0);

        $inserted = [];
        $wpdb->shouldReceive('insert')
            ->once()
            ->with('wp_ems_audit_logs', Mockery::on(function ($data) use (&$inserted) {
                $inserted = $data;
                return true;
            }), Mockery::any())
            ->andReturn(true);

        Audit_Logger::log('login_failure');

        $this->assertSame(0, $inserted['user_id']);
    }

    public function test_log_accepts_explicit_user_id(): void {
        global $wpdb;
        $wpdb = Mockery::mock('stdClass');
        $wpdb->prefix = 'wp_';

        $inserted = [];
        $wpdb->shouldReceive('insert')
            ->once()
            ->with('wp_ems_audit_logs', Mockery::on(function ($data) use (&$inserted) {
                $inserted = $data;
                return true;
            }), Mockery::any())
            ->andReturn(true);

        Audit_Logger::log('login_success', null, 42);

        $this->assertSame(42, $inserted['user_id']);
    }
}
