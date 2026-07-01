<?php
namespace EMS\Data;

class Unit_Repository {
    private object $wpdb;

    public function __construct( ?object $wpdb = null ) {
        if ( $wpdb === null ) {
            global $wpdb;
        }
        $this->wpdb = $wpdb;
    }

    /**
     * Sync patrol from OSM (Insert or update metadata while preserving admin configurations)
     */
    public function sync_patrol( array $data ): int {
        if ( empty( $data['patrol_id'] ) || empty( $data['section_id'] ) ) {
            throw new \InvalidArgumentException( 'patrol_id and section_id are required for syncing' );
        }

        $now = current_time( 'mysql' );
        $sql = "INSERT INTO {$this->wpdb->prefix}ems_units 
            (patrol_id, section_id, name, active, synced_at, short_code) 
            VALUES (%d, %d, %s, %d, %s, %s) 
            ON DUPLICATE KEY UPDATE 
            name = VALUES(name), 
            active = VALUES(active), 
            synced_at = VALUES(synced_at)";

        $prepared = $this->wpdb->prepare(
            $sql,
            $data['patrol_id'],
            $data['section_id'],
            $data['name'] ?? '',
            isset( $data['active'] ) ? (int) $data['active'] : 1,
            $data['synced_at'] ?? $now,
            $data['name'] ?? ''
        );

        $this->wpdb->query( $prepared );

        return (int) $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT id FROM {$this->wpdb->prefix}ems_units WHERE patrol_id = %d AND section_id = %d",
            $data['patrol_id'],
            $data['section_id']
        ) );
    }

    /**
     * Update custom mapping configurations manually set by administrator
     */
    public function update_custom_mappings( int $id, array $data ): bool {
        if ( isset( $data['leader_email'] ) && ! empty( $data['leader_email'] ) && ! is_email( $data['leader_email'] ) ) {
            throw new \InvalidArgumentException( 'Invalid leader email format' );
        }

        $update_data = [
            'updated_at' => current_time( 'mysql' ),
        ];

        $format = [ '%s' ];

        if ( array_key_exists( 'unit_id', $data ) ) {
            $update_data['unit_id'] = empty( $data['unit_id'] ) ? null : (int) $data['unit_id'];
            $format[] = empty( $data['unit_id'] ) ? '%d' : '%d'; // handles null correctly in wpdb
        }
        if ( isset( $data['short_code'] ) ) {
            $update_data['short_code'] = $data['short_code'];
            $format[] = '%s';
        }
        if ( isset( $data['leader_first_name'] ) ) {
            $update_data['leader_first_name'] = $data['leader_first_name'];
            $format[] = '%s';
        }
        if ( isset( $data['leader_last_name'] ) ) {
            $update_data['leader_last_name'] = $data['leader_last_name'];
            $format[] = '%s';
        }
        if ( isset( $data['leader_email'] ) ) {
            $update_data['leader_email'] = $data['leader_email'];
            $format[] = '%s';
        }

        $updated = $this->wpdb->update(
            $this->wpdb->prefix . 'ems_units',
            $update_data,
            [ 'id' => $id ],
            $format,
            [ '%d' ]
        );

        return $updated !== false;
    }

    public function find_by_id( int $id ): ?array {
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->wpdb->prefix}ems_units WHERE id = %d",
            $id
        ), ARRAY_A );
        return $row ?: null;
    }

    public function find_by_patrol_section( int $patrol_id, int $section_id ): ?array {
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->wpdb->prefix}ems_units WHERE patrol_id = %d AND section_id = %d",
            $patrol_id,
            $section_id
        ), ARRAY_A );
        return $row ?: null;
    }

    public function list_active_units(): array {
        $rows = $this->wpdb->get_results(
            "SELECT * FROM {$this->wpdb->prefix}ems_units WHERE active = 1 ORDER BY name, section_id",
            ARRAY_A
        );
        return $rows ?: [];
    }

    public function find_by_short_code( string $short_code ): ?array {
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->wpdb->prefix}ems_units WHERE short_code = %s AND active = 1 LIMIT 1",
            $short_code
        ), ARRAY_A );
        return $row ?: null;
    }
}
