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

    /**
     * Links a WP user ID to all explorer rows matching the given email address.
     *
     * Skips rows already linked to a different WP user (logs a warning).
     * Returns the number of rows updated.
     */
    public function link_wp_user_by_email( string $email, int $wp_user_id ): int {
        if ( $email === '' ) {
            return 0;
        }

        $table = $this->wpdb->prefix . 'ems_osm_explorers';
        $rows  = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT scout_id, wp_user_id FROM {$table} WHERE email = %s",
                $email
            )
        );

        if ( empty( $rows ) ) {
            return 0;
        }

        $linked = 0;
        foreach ( $rows as $row ) {
            $existing = $row->wp_user_id !== null ? (int) $row->wp_user_id : null;

            if ( $existing === $wp_user_id ) {
                continue;
            }

            if ( $existing !== null ) {
                error_log( sprintf(
                    '[EMS] link_wp_user_by_email: scout %d already linked to wp_user %d, refusing to overwrite with %d',
                    (int) $row->scout_id,
                    $existing,
                    $wp_user_id
                ) );
                continue;
            }

            $this->wpdb->query(
                $this->wpdb->prepare(
                    "UPDATE {$table} SET wp_user_id = %d WHERE scout_id = %d",
                    $wp_user_id,
                    (int) $row->scout_id
                )
            );
            $linked++;
        }

        return $linked;
    }

    public function update_first_aid_level( int $scout_id, string $level ): bool {
        $table   = $this->wpdb->prefix . 'ems_osm_explorers';
        $allowed = [ 'none', 'first_response', 'full_first_aid' ];
        if ( ! in_array( $level, $allowed, true ) ) {
            return false;
        }
        $result = $this->wpdb->query( $this->wpdb->prepare(
            "UPDATE {$table} SET first_aid_level = %s, last_local_update_at = NOW() WHERE scout_id = %d",
            $level,
            $scout_id
        ) );
        return $result !== false && $result > 0;
    }
}
