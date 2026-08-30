<?php
namespace EMS\Tests\Unit\Admin;

use EMS\Admin\Audit_Log_Controller;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;
use Mockery;

class Audit_Log_ControllerTest extends EMSTestCase {

    public function test_get_audit_logs_requires_manage_options_permission(): void {
        $controller = new Audit_Log_Controller();

        Functions\when( 'current_user_can' )->alias( function( $cap ) {
            return $cap === 'manage_options' ? false : true;
        } );

        $request = new \WP_REST_Request( 'GET', '/ems/v1/audit-logs' );
        $response = $controller->get_audit_logs( $request );

        $this->assertTrue( is_wp_error( $response ) );
        $this->assertSame( 403, $response->get_error_data()['status'] );
    }

    public function test_get_audit_logs_returns_list_of_logs_with_pagination(): void {
        global $wpdb;
        $wpdb = Mockery::mock('stdClass');
        $wpdb->prefix = 'wp_';

        $controller = new Audit_Log_Controller();
        Functions\when( 'current_user_can' )->justReturn( true );

        // Mock counting logs (for pagination headers)
        $wpdb->shouldReceive('get_var')
            ->once()
            ->with(Mockery::on(function ($sql) {
                return str_contains($sql, 'SELECT COUNT(*) FROM wp_ems_audit_logs');
            }))
            ->andReturn(125);

        $wpdb->shouldReceive('prepare')
            ->once()
            ->with(' LIMIT %d OFFSET %d', 10, 20)
            ->andReturn(' LIMIT 10 OFFSET 20');

        // Mock retrieving logs
        $wpdb->shouldReceive('get_results')
            ->once()
            ->with(Mockery::on(function ($sql) {
                return str_contains($sql, 'SELECT * FROM wp_ems_audit_logs') && str_contains($sql, 'LIMIT 10 OFFSET 20');
            }), ARRAY_A)
            ->andReturn([
                [
                    'id' => 3,
                    'user_id' => 1,
                    'action' => 'team_member_add',
                    'target_scout_id' => 30001,
                    'ip_address' => '127.0.0.1',
                    'user_agent' => 'Browser',
                    'timestamp' => '2026-07-15 21:00:00'
                ]
            ]);

        $request = new \WP_REST_Request( 'GET', '/ems/v1/audit-logs' );
        $request->set_param( 'page', 3 );
        $request->set_param( 'per_page', 10 );

        $response = $controller->get_audit_logs( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertCount( 1, $data );
        $this->assertSame( 'team_member_add', $data[0]['action'] );

        $headers = $response->get_headers();
        $this->assertSame( '125', $headers['X-WP-Total'] );
        $this->assertSame( '13', $headers['X-WP-TotalPages'] );
    }

    public function test_get_audit_logs_applies_filters_correctly(): void {
        global $wpdb;
        $wpdb = Mockery::mock('stdClass');
        $wpdb->prefix = 'wp_';

        $controller = new Audit_Log_Controller();
        Functions\when( 'current_user_can' )->justReturn( true );

        // Expect count with filters
        $wpdb->shouldReceive('prepare')
            ->once()
            ->with(
                Mockery::on(function ($sql) {
                    return str_contains($sql, 'SELECT COUNT(*) FROM wp_ems_audit_logs WHERE 1=1')
                        && str_contains($sql, 'AND action = %s')
                        && str_contains($sql, 'AND target_scout_id = %d')
                        && str_contains($sql, 'AND timestamp >= %s')
                        && str_contains($sql, 'AND timestamp <= %s');
                }),
                'explorer_update',
                30001,
                '2026-07-01 00:00:00',
                '2026-07-15 23:59:59'
            )
            ->andReturn('PREPARED_COUNT_SQL');

        $wpdb->shouldReceive('get_var')
            ->once()
            ->with('PREPARED_COUNT_SQL')
            ->andReturn(5);

        $wpdb->shouldReceive('prepare')
            ->once()
            ->with(
                Mockery::on(function ($sql) {
                    return str_contains($sql, 'SELECT * FROM wp_ems_audit_logs WHERE 1=1')
                        && str_contains($sql, 'AND action = %s')
                        && str_contains($sql, 'AND target_scout_id = %d')
                        && str_contains($sql, 'AND timestamp >= %s')
                        && str_contains($sql, 'AND timestamp <= %s')
                        && str_contains($sql, 'ORDER BY id DESC');
                }),
                'explorer_update',
                30001,
                '2026-07-01 00:00:00',
                '2026-07-15 23:59:59'
            )
            ->andReturn('PREPARED_RESULTS_SQL');

        $wpdb->shouldReceive('prepare')
            ->once()
            ->with(' LIMIT %d OFFSET %d', 20, 0)
            ->andReturn(' LIMIT 20 OFFSET 0');

        $wpdb->shouldReceive('get_results')
            ->once()
            ->with('PREPARED_RESULTS_SQL LIMIT 20 OFFSET 0', ARRAY_A)
            ->andReturn([]);

        $request = new \WP_REST_Request( 'GET', '/ems/v1/audit-logs' );
        $request->set_param( 'action', 'explorer_update' );
        $request->set_param( 'target_scout_id', 30001 );
        $request->set_param( 'start_date', '2026-07-01' );
        $request->set_param( 'end_date', '2026-07-15' );

        $response = $controller->get_audit_logs( $request );
        $this->assertSame( 200, $response->get_status() );
    }
}
