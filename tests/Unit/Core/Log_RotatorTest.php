<?php
namespace EMS\Tests\Unit\Core;

use EMS\Core\Log_Rotator;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;
use Mockery;

class Log_RotatorTest extends EMSTestCase {

    public function test_purge_old_logs_deletes_records_older_than_365_days(): void {
        global $wpdb;
        $wpdb = Mockery::mock('stdClass');
        $wpdb->prefix = 'wp_';

        // Set expectations for daily purge query
        $wpdb->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($sql) {
                return str_contains($sql, 'DELETE FROM wp_ems_audit_logs WHERE timestamp < DATE_SUB');
            }))
            ->andReturn(10); // 10 rows deleted

        // Set expectations for row capping (under the limit)
        $wpdb->shouldReceive('get_var')
            ->once()
            ->with(Mockery::on(function ($sql) {
                return str_contains($sql, 'SELECT COUNT(*) FROM wp_ems_audit_logs');
            }))
            ->andReturn(20000); // 20,000 rows

        Log_Rotator::purge_old_logs();
        $this->addToAssertionCount(1);
    }

    public function test_purge_old_logs_caps_table_rows_if_exceeding_limit(): void {
        global $wpdb;
        $wpdb = Mockery::mock('stdClass');
        $wpdb->prefix = 'wp_';

        // 1. Purge query
        $wpdb->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($sql) {
                return str_contains($sql, 'DELETE FROM wp_ems_audit_logs WHERE timestamp < DATE_SUB');
            }))
            ->andReturn(0);

        // 2. Count query
        $wpdb->shouldReceive('get_var')
            ->once()
            ->with(Mockery::on(function ($sql) {
                return str_contains($sql, 'SELECT COUNT(*) FROM wp_ems_audit_logs');
            }))
            ->andReturn(55000); // 55,000 rows, which is above the 50,000 limit

        // 3. Get boundary ID query (offset is 50000)
        $wpdb->shouldReceive('get_var')
            ->once()
            ->with(Mockery::on(function ($sql) {
                return str_contains($sql, 'ORDER BY id DESC LIMIT 1 OFFSET 50000');
            }))
            ->andReturn(100500); // Mocked boundary ID

        // 4. Delete surplus rows
        $wpdb->shouldReceive('prepare')
            ->once()
            ->with(
                Mockery::on(function ($sql) {
                    return str_contains($sql, 'DELETE FROM wp_ems_audit_logs WHERE id <=');
                }),
                100500
            )
            ->andReturn('PREPARED_DELETE_SQL');

        $wpdb->shouldReceive('query')
            ->once()
            ->with('PREPARED_DELETE_SQL')
            ->andReturn(5000);

        Log_Rotator::purge_old_logs();
        $this->addToAssertionCount(1);
    }
}
