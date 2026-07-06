<?php
namespace EMS\Tests\Unit\Data;

use EMS\Data\Volunteer_Repository;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;

class Volunteer_RepositoryTest extends EMSTestCase {
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        $this->wpdb = \Mockery::mock('stdClass');
        $this->wpdb->prefix = 'wp_';
        $this->wpdb->volunteers = 'wp_ems_volunteers';
        $this->wpdb->volunteer_availability = 'wp_ems_volunteer_availability';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(
            static function ($q, ...$args) {
                $i = 0;
                $repl = preg_replace_callback('/%[sd]/', static function () use (&$args, &$i) {
                    return $args[$i++] ?? '';
                }, $q);
                return $repl;
            }
        );
    }

    public function test_save_volunteer_creates_new_record_if_not_exists(): void {
        $this->wpdb->shouldReceive('get_row')->once()->andReturn(null);
        $this->wpdb->shouldReceive('insert')->once()->andReturnUsing(function($table, $data) {
            $this->assertEquals('wp_ems_volunteers', $table);
            $this->assertEquals('John', $data['first_name']);
            return 1;
        });
        $this->wpdb->insert_id = 42;

        $repo = new Volunteer_Repository($this->wpdb);
        $result = $repo->save_volunteer([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'phone' => '123456',
        ]);

        $this->assertEquals(42, $result['id']);
    }

    public function test_save_volunteer_updates_existing_record_by_email(): void {
        $existing = (object)[
            'id' => 10,
            'email' => 'john@example.com',
        ];
        $this->wpdb->shouldReceive('get_row')->once()->andReturn($existing);
        $this->wpdb->shouldReceive('update')->once()->andReturnUsing(function($table, $data, $where) {
            $this->assertEquals('wp_ems_volunteers', $table);
            $this->assertEquals('John Updated', $data['first_name']);
            $this->assertEquals(10, $where['id']);
            return 1;
        });

        $repo = new Volunteer_Repository($this->wpdb);
        $result = $repo->save_volunteer([
            'first_name' => 'John Updated',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'phone' => '123456',
        ]);

        $this->assertEquals(10, $result['id']);
    }

    public function test_save_availability_inserts_records(): void {
        $this->wpdb->shouldReceive('query')->once(); // Delete previous query
        $this->wpdb->shouldReceive('insert')->times(2)->andReturn(1);

        $repo = new Volunteer_Repository($this->wpdb);
        $repo->save_availability(42, 10, [
            [
                'date' => '2026-08-14',
                'overnight' => 0,
            ],
            [
                'date' => '2026-08-15',
                'overnight' => 1,
            ]
        ]);
        $this->assertTrue(true);
    }

    public function test_confirm_overlapping_event_locks_out_alternatives(): void {
        // Arrange: volunteer confirmed on event A
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with('wp_ems_volunteer_availability', ['confirmed' => 1, 'confirmed_by' => 1, 'updated_at' => '2026-06-13 20:00:00'], ['id' => 100])
            ->andReturn(1);

        $avail_row = (object)[
            'volunteer_id' => 42,
            'date' => '2026-08-14',
            'expedition_post_id' => 10,
        ];
        $this->wpdb->shouldReceive('get_row')
            ->once()
            ->andReturn($avail_row);

        $this->wpdb->shouldReceive('get_col')
            ->once()
            ->andReturn(['2026-08-14']);

        // Conflict prevention updates other events for same volunteer & date to -1
        $this->wpdb->shouldReceive('query')
            ->once()
            ->andReturnUsing(function($query) {
                $this->assertStringContainsString('confirmed = -1', $query);
                $this->assertStringContainsString('volunteer_id = 42', $query);
                $this->assertStringContainsString("2026-08-14", $query);
                $this->assertStringContainsString('expedition_post_id != 10', $query);
                return 1;
            });

        $repo = new Volunteer_Repository($this->wpdb);
        $repo->confirm_availability(100, 1);
        $this->assertTrue(true);
    }

    public function test_unassigning_event_releases_conflict_lock(): void {
        // Arrange: unassigning availability row 100 sets confirmed = 0
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with('wp_ems_volunteer_availability', ['confirmed' => 0, 'confirmed_by' => null, 'updated_at' => '2026-06-13 20:00:00'], ['id' => 100])
            ->andReturn(1);

        $avail_row = (object)[
            'volunteer_id' => 42,
            'date' => '2026-08-14',
            'expedition_post_id' => 10,
        ];
        $this->wpdb->shouldReceive('get_row')
            ->once()
            ->andReturn($avail_row);

        $this->wpdb->shouldReceive('get_col')
            ->once()
            ->andReturn(['2026-08-14']);

        $this->wpdb->shouldReceive('get_var')
            ->once()
            ->andReturn(0); // No other events confirmed

        // Releases other events from -1 back to 0
        $this->wpdb->shouldReceive('query')
            ->once()
            ->andReturnUsing(function($query) {
                $this->assertStringContainsString('confirmed = 0', $query);
                $this->assertStringContainsString('volunteer_id = 42', $query);
                $this->assertStringContainsString("2026-08-14", $query);
                return 1;
            });

        $repo = new Volunteer_Repository($this->wpdb);
        $repo->confirm_availability(100, 0);
        $this->assertTrue(true);
    }
}
