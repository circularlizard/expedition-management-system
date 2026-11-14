<?php
namespace EMS\Tests\Unit\Data;

use EMS\Data\OSM_Event_Repository;
use EMS\Tests\EMSTestCase;

class OSM_Event_RepositoryTest extends EMSTestCase {

    public function test_list_all_returns_events(): void {
        $wpdb = new class {
            public $prefix = 'wp_';
            public $last_query = '';
            public function get_results( string $sql, string $output ) {
                $this->last_query = $sql;
                $this->output_type = $output;
                return [
                    [ 'id' => 1, 'event_id' => 40001, 'section_id' => 99001, 'name' => 'Summer Camp', 'start_date' => '2026-07-01', 'end_date' => '2026-07-03', 'location' => 'Loch Lomond' ],
                    [ 'id' => 2, 'event_id' => 40002, 'section_id' => 99001, 'name' => 'Autumn Hike', 'start_date' => '2026-10-10', 'end_date' => '2026-10-11', 'location' => 'Pentlands' ],
                ];
            }
        };

        $repo   = new OSM_Event_Repository( $wpdb );
        $events = $repo->list_all();

        $this->assertCount( 2, $events );
        $this->assertSame( 'Summer Camp', $events[0]['name'] );
        $this->assertStringContainsString( 'wp_ems_osm_events', $wpdb->last_query );
    }
}
