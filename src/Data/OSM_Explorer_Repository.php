<?php
namespace EMS\Data;

class OSM_Explorer_Repository {

    private object $wpdb;

    public function __construct( ?object $wpdb = null ) {
        if ( $wpdb === null ) {
            global $wpdb;
        }
        $this->wpdb = $wpdb;
    }

    public function find_by_scout_id( int $scout_id ): ?array {
        $table = 'ems_osm_explorers';
        $row   = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->wpdb->prefix}{$table} WHERE scout_id = %d",
            $scout_id
        ), ARRAY_A );

        return $row ?: null;
    }

    public function find_by_wp_user_id( int $user_id ): ?array {
        $table = 'ems_osm_explorers';
        $row   = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->wpdb->prefix}{$table} WHERE wp_user_id = %d",
            $user_id
        ), ARRAY_A );

        return $row ?: null;
    }

    public function list_all(): array {
        $table = 'ems_osm_explorers';
        $rows  = $this->wpdb->get_results( "SELECT * FROM {$this->wpdb->prefix}{$table}", ARRAY_A );
        return $rows ?: [];
    }

    public function update_first_aid_level( int $scout_id, string $level ): bool {
        $table   = $this->wpdb->prefix . 'ems_osm_explorers';
        $allowed = [ 'none', 'first_response', 'full_first_aid' ];
        if ( ! in_array( $level, $allowed, true ) ) {
            return false;
        }
        $result = $this->wpdb->query( $this->wpdb->prepare(
            "UPDATE {$table} SET first_aid_level = %s WHERE scout_id = %d",
            $level,
            $scout_id
        ) );
        return $result !== false && $result > 0;
    }
}
