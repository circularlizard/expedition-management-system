<?php
namespace EMS\Data;

class Unit_Leader_Repository {
    private object $wpdb;

    public function __construct( ?object $wpdb = null ) {
        if ( $wpdb === null ) {
            global $wpdb;
        }
        $this->wpdb = $wpdb;
    }

    public function create( array $data ): int {
        $this->validate( $data );

        // Ensure unit name uniqueness
        $existing = $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->wpdb->prefix}ems_unit_leaders WHERE unit_name = %s",
            $data['unit_name']
        ) );
        if ( (int) $existing > 0 ) {
            throw new \InvalidArgumentException( 'Leader mapping for this unit already exists' );
        }

        $now = current_time( 'mysql' );
        $insert_data = [
            'unit_name'         => $data['unit_name'],
            'leader_first_name' => $data['leader_first_name'] ?? '',
            'leader_last_name'  => $data['leader_last_name'] ?? '',
            'leader_email'      => $data['leader_email'] ?? '',
            'created_at'        => $now,
            'updated_at'        => $now,
        ];

        $inserted = $this->wpdb->insert(
            $this->wpdb->prefix . 'ems_unit_leaders',
            $insert_data,
            [ '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            throw new \RuntimeException( 'Failed to insert unit leader record' );
        }

        return (int) $this->wpdb->insert_id;
    }

    public function update( int $id, array $data ): bool {
        $this->validate( $data );

        // Ensure unit name uniqueness (not held by another leader mapping)
        $existing = $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->wpdb->prefix}ems_unit_leaders WHERE unit_name = %s AND id != %d",
            $data['unit_name'],
            $id
        ) );
        if ( (int) $existing > 0 ) {
            throw new \InvalidArgumentException( 'Leader mapping for this unit already exists' );
        }

        $update_data = [
            'unit_name'         => $data['unit_name'],
            'leader_first_name' => $data['leader_first_name'] ?? '',
            'leader_last_name'  => $data['leader_last_name'] ?? '',
            'leader_email'      => $data['leader_email'] ?? '',
            'updated_at'        => current_time( 'mysql' ),
        ];

        $updated = $this->wpdb->update(
            $this->wpdb->prefix . 'ems_unit_leaders',
            $update_data,
            [ 'id' => $id ],
            [ '%s', '%s', '%s', '%s', '%s' ],
            [ '%d' ]
        );

        return $updated !== false;
    }

    public function delete( int $id ): bool {
        $deleted = $this->wpdb->delete(
            $this->wpdb->prefix . 'ems_unit_leaders',
            [ 'id' => $id ],
            [ '%d' ]
        );
        return $deleted !== false;
    }

    public function find_by_id( int $id ): ?array {
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->wpdb->prefix}ems_unit_leaders WHERE id = %d",
            $id
        ), ARRAY_A );
        return $row ?: null;
    }

    public function find_by_unit_name( string $unit_name ): ?array {
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->wpdb->prefix}ems_unit_leaders WHERE unit_name = %s",
            $unit_name
        ), ARRAY_A );
        return $row ?: null;
    }

    public function list_all(): array {
        $rows = $this->wpdb->get_results(
            "SELECT * FROM {$this->wpdb->prefix}ems_unit_leaders ORDER BY unit_name",
            ARRAY_A
        );
        return $rows ?: [];
    }

    private function validate( array $data ): void {
        if ( empty( $data['unit_name'] ) ) {
            throw new \InvalidArgumentException( 'Unit name is required' );
        }

        if ( empty( $data['leader_email'] ) || ! is_email( $data['leader_email'] ) ) {
            throw new \InvalidArgumentException( 'Invalid leader email format' );
        }

        // Validate that unit_name exists as a synced patrol/unit name in the database.
        // The list of available unit_names is seeded from synced OSM patrol names (patrol column in ems_osm_explorers or active patrol names in ems_osm_patrols).
        $patrol_exists = $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->wpdb->prefix}ems_osm_explorers WHERE patrol = %s",
            $data['unit_name']
        ) );

        if ( (int) $patrol_exists === 0 ) {
            $patrol_exists = $this->wpdb->get_var( $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->wpdb->prefix}ems_osm_patrols WHERE name = %s AND active = 1",
                $data['unit_name']
            ) );
        }

        if ( (int) $patrol_exists === 0 ) {
            throw new \InvalidArgumentException( 'Unit name does not exist as a synced patrol' );
        }
    }
}
