<?php
namespace EMS\Data;

class OSM_Event_Repository {

    private object $wpdb;

    public function __construct( ?object $wpdb = null ) {
        if ( $wpdb === null ) {
            global $wpdb;
        }
        $this->wpdb = $wpdb;
    }

    public function list_all(): array {
        $table = $this->wpdb->prefix . 'ems_osm_events';
        $rows  = $this->wpdb->get_results( "SELECT * FROM {$table} ORDER BY start_date DESC, name ASC", ARRAY_A );
        return $rows ?: [];
    }
}
