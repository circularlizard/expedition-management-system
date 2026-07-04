<?php
namespace EMS\Core;

class Table_Installer {
    public function install(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = $this->generate_sql( $wpdb->prefix, $wpdb->get_charset_collate() );

        foreach ( $sql as $statement ) {
            dbDelta( $statement );
        }

        $this->run_migrations( $wpdb );
    }

     /**
     * Idempotent column/index migrations for tables that already existed before
     * a schema change. dbDelta is unreliable at ALTERing existing tables, so we
     * apply additive changes explicitly here.
     */
    private function run_migrations( object $wpdb ): void {
        $table = $wpdb->prefix . 'ems_team_members';

        if ( ! $this->column_exists( $wpdb, $table, 'scout_id' ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN scout_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER team_post_id" );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( "ALTER TABLE {$table} ADD KEY idx_scout_id (scout_id)" );
        }

        $explorers_table = $wpdb->prefix . 'ems_osm_explorers';
        if ( ! $this->column_exists( $wpdb, $explorers_table, 'first_aid_level' ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( "ALTER TABLE {$explorers_table} ADD COLUMN first_aid_level VARCHAR(30) NOT NULL DEFAULT 'none' AFTER patrol" );
        }

        if ( ! $this->column_exists( $wpdb, $explorers_table, 'last_local_update_at' ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( "ALTER TABLE {$explorers_table} ADD COLUMN last_local_update_at DATETIME DEFAULT NULL AFTER first_aid_level" );
        }

        if ( ! $this->column_exists( $wpdb, $explorers_table, 'last_ems_push_at' ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( "ALTER TABLE {$explorers_table} ADD COLUMN last_ems_push_at DATETIME DEFAULT NULL AFTER last_local_update_at" );
        }

        if ( ! $this->column_exists( $wpdb, $explorers_table, 'dofe_number' ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( "ALTER TABLE {$explorers_table} ADD COLUMN dofe_number VARCHAR(50) DEFAULT NULL AFTER first_aid_level" );
        }

        $participant_table = $wpdb->prefix . 'ems_participant_signups';
        if ( ! $this->column_exists( $wpdb, $participant_table, 'leader_email' ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( "ALTER TABLE {$participant_table} ADD COLUMN leader_email VARCHAR(100) DEFAULT NULL AFTER parent_email" );
        }

        $expedition_table = $wpdb->prefix . 'ems_expedition_signups';
        if ( ! $this->column_exists( $wpdb, $expedition_table, 'leader_email' ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( "ALTER TABLE {$expedition_table} ADD COLUMN leader_email VARCHAR(100) DEFAULT NULL AFTER parent_email" );
        }

        $this->migrate_season_deprecation( $wpdb );
    }

    private function column_exists( object $wpdb, string $table, string $column ): bool {
        $found = $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            $table,
            $column
        ) );

        return ! empty( $found );
    }

    private function migrate_season_deprecation( object $wpdb ): void {
        if ( get_option( 'ems_season_migration_done' ) ) {
            return;
        }

        // Detach all expedition posts from any season parent.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            "UPDATE {$wpdb->posts} SET post_parent = 0 WHERE post_type = 'expedition'"
        );

        // Collect all season post IDs before deleting.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $season_ids = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'season'"
        );

        if ( ! empty( $season_ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $season_ids ), '%d' ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$placeholders})",
                ...$season_ids
            ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->posts} WHERE ID IN ({$placeholders})",
                ...$season_ids
            ) );
        }

        if ( ! get_option( 'ems_unallocated_migration_done' ) ) {
            $expedition_ids = $wpdb->get_col(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'expedition' AND post_status = 'publish'"
            );
            foreach ( $expedition_ids as $event_id ) {
                $event_id = (int) $event_id;
                $has_unallocated = $wpdb->get_var( $wpdb->prepare(
                    "SELECT p.ID FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                     WHERE p.post_type = 'team' AND p.post_parent = %d
                     AND pm.meta_key = 'ems_team_code' AND pm.meta_value = 'UNALLOCATED'
                     LIMIT 1",
                    $event_id
                ) );

                if ( ! $has_unallocated ) {
                    $post_id = wp_insert_post( [
                        'post_type'   => 'team',
                        'post_title'  => 'Unallocated',
                        'post_status' => 'publish',
                        'post_parent' => $event_id,
                    ], true );

                    if ( ! is_wp_error( $post_id ) ) {
                        update_post_meta( $post_id, 'ems_team_code', 'UNALLOCATED' );
                        update_post_meta( $post_id, 'ems_team_number', 0 );
                    }
                }
            }
            update_option( 'ems_unallocated_migration_done', 1 );
        }

        update_option( 'ems_season_migration_done', 1 );
    }

    public function generate_sql( string $prefix = '', string $charset = '' ): array {
        $sql = [];

        $sql[] = "CREATE TABLE IF NOT EXISTS {$prefix}ems_team_members (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            team_post_id BIGINT UNSIGNED NOT NULL,
            scout_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
            user_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
            added_by    BIGINT UNSIGNED NOT NULL,
            added_at    DATETIME        NOT NULL,
            PRIMARY KEY (id),
            KEY idx_team_post_id (team_post_id),
            KEY idx_scout_id (scout_id),
            KEY idx_user_id (user_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE IF NOT EXISTS {$prefix}ems_volunteer_availability (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id             BIGINT UNSIGNED NOT NULL,
            expedition_post_id  BIGINT UNSIGNED NOT NULL,
            date                DATE            NOT NULL,
            overnight           TINYINT(1)      NOT NULL DEFAULT 0,
            confirmed           TINYINT(1)      NOT NULL DEFAULT 0,
            confirmed_by        BIGINT UNSIGNED          DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_user_expedition (user_id, expedition_post_id),
            KEY idx_date (date)
        ) {$charset};";

        $sql[] = "CREATE TABLE IF NOT EXISTS {$prefix}ems_route_submissions (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            team_post_id    BIGINT UNSIGNED NOT NULL,
            version         INT             NOT NULL DEFAULT 1,
            file_type       VARCHAR(20)     NOT NULL,
            wp_media_id     BIGINT UNSIGNED NOT NULL,
            submitted_by    BIGINT UNSIGNED NOT NULL,
            submitted_at    DATETIME        NOT NULL,
            feedback        TEXT                     DEFAULT NULL,
            status          VARCHAR(30)     NOT NULL DEFAULT 'pending',
            PRIMARY KEY (id),
            KEY idx_team_post_id (team_post_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE IF NOT EXISTS {$prefix}ems_osm_explorers (
            id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            scout_id             BIGINT UNSIGNED NOT NULL,
            wp_user_id           BIGINT UNSIGNED          DEFAULT NULL,
            section_id           BIGINT UNSIGNED NOT NULL,
            first_name           VARCHAR(100)    NOT NULL DEFAULT '',
            last_name            VARCHAR(100)    NOT NULL DEFAULT '',
            email                VARCHAR(100)    NOT NULL DEFAULT '',
            parent_email         VARCHAR(100)    NOT NULL DEFAULT '',
            patrol               VARCHAR(100)    NOT NULL DEFAULT '',
            first_aid_level      VARCHAR(30)     NOT NULL DEFAULT 'none',
            dofe_number          VARCHAR(50)              DEFAULT NULL,
            last_local_update_at DATETIME                 DEFAULT NULL,
            last_ems_push_at     DATETIME                 DEFAULT NULL,
            synced_at            DATETIME        NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idx_scout_id (scout_id),
            KEY idx_section_id (section_id),
            KEY idx_wp_user_id (wp_user_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE IF NOT EXISTS {$prefix}ems_osm_events (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id     BIGINT UNSIGNED NOT NULL,
            section_id   BIGINT UNSIGNED NOT NULL,
            name         VARCHAR(255)    NOT NULL DEFAULT '',
            start_date   DATETIME                 DEFAULT NULL,
            end_date     DATETIME                 DEFAULT NULL,
            location     VARCHAR(255)    NOT NULL DEFAULT '',
            yes_members  INT             NOT NULL DEFAULT 0,
            yes_leaders  INT             NOT NULL DEFAULT 0,
            no           INT             NOT NULL DEFAULT 0,
            synced_at    DATETIME        NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idx_event_section (event_id, section_id),
            KEY idx_section_id (section_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE IF NOT EXISTS {$prefix}ems_osm_event_attendance (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id   BIGINT UNSIGNED NOT NULL,
            scout_id   BIGINT UNSIGNED NOT NULL,
            status     VARCHAR(50)     NOT NULL DEFAULT '',
            synced_at  DATETIME        NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idx_event_scout (event_id, scout_id),
            KEY idx_event_id (event_id),
            KEY idx_scout_id (scout_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE IF NOT EXISTS {$prefix}ems_units (
            id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            patrol_id         BIGINT          NOT NULL,
            section_id        BIGINT UNSIGNED NOT NULL,
            name              VARCHAR(100)    NOT NULL DEFAULT '',
            active            TINYINT(1)      NOT NULL DEFAULT 1,
            synced_at         DATETIME        NOT NULL,
            unit_id           BIGINT UNSIGNED          DEFAULT NULL,
            short_code        VARCHAR(100)    NOT NULL DEFAULT '',
            leader_first_name VARCHAR(100)    NOT NULL DEFAULT '',
            leader_last_name  VARCHAR(100)    NOT NULL DEFAULT '',
            leader_email      VARCHAR(100)    NOT NULL DEFAULT '',
            updated_at        DATETIME                 DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idx_patrol_section (patrol_id, section_id),
            KEY idx_unit_id (unit_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE IF NOT EXISTS {$prefix}ems_participant_signups (
            id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            scout_id               BIGINT UNSIGNED NOT NULL,
            parent_user_id         BIGINT UNSIGNED NOT NULL,
            unit_id                BIGINT UNSIGNED          DEFAULT NULL,
            unit_name              VARCHAR(100)             DEFAULT NULL,
            explorer_first_name    VARCHAR(100)    NOT NULL DEFAULT '',
            explorer_last_name     VARCHAR(100)    NOT NULL DEFAULT '',
            explorer_email         VARCHAR(100)    NOT NULL DEFAULT '',
            parent_email           VARCHAR(100)             DEFAULT NULL,
            leader_email           VARCHAR(100)             DEFAULT NULL,
            dofe_level             VARCHAR(20)     NOT NULL,
            dob                    DATE                     DEFAULT NULL,
            dofe_registered        VARCHAR(30)     NOT NULL DEFAULT 'n',
            dofe_number            VARCHAR(20)              DEFAULT NULL,
            dofe_org               VARCHAR(100)             DEFAULT NULL,
            bronze_completion      TEXT                     DEFAULT NULL,
            silver_completion      TEXT                     DEFAULT NULL,
            signup_status          VARCHAR(30)     NOT NULL DEFAULT 'received',
            payment_status         VARCHAR(30)     NOT NULL DEFAULT 'pending',
            processed_by           BIGINT UNSIGNED          DEFAULT NULL,
            processed_at           DATETIME                 DEFAULT NULL,
            form_submission_id     BIGINT UNSIGNED NOT NULL,
            created_at             DATETIME        NOT NULL,
            updated_at             DATETIME        NOT NULL,
            PRIMARY KEY (id),
            KEY idx_scout_id (scout_id),
            KEY idx_parent_user_id (parent_user_id),
            KEY idx_unit_id (unit_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE IF NOT EXISTS {$prefix}ems_expedition_signups (
            id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            scout_id               BIGINT UNSIGNED NOT NULL,
            parent_user_id         BIGINT UNSIGNED NOT NULL,
            unit_id                BIGINT UNSIGNED          DEFAULT NULL,
            unit_name              VARCHAR(100)             DEFAULT NULL,
            explorer_first_name    VARCHAR(100)    NOT NULL DEFAULT '',
            explorer_last_name     VARCHAR(100)    NOT NULL DEFAULT '',
            explorer_email         VARCHAR(100)    NOT NULL DEFAULT '',
            parent_email           VARCHAR(100)             DEFAULT NULL,
            leader_email           VARCHAR(100)             DEFAULT NULL,
            dofe_level             VARCHAR(20)     NOT NULL,
            expedition_preferences TEXT                     DEFAULT NULL,
            additional_support_needs TEXT                   DEFAULT NULL,
            first_aid_status       VARCHAR(30)     NOT NULL DEFAULT 'none',
            first_aid_expiry       DATE                     DEFAULT NULL,
            signup_status          VARCHAR(30)     NOT NULL DEFAULT 'pending',
            form_submission_id     BIGINT UNSIGNED NOT NULL,
            created_at             DATETIME        NOT NULL,
            updated_at             DATETIME        NOT NULL,
            PRIMARY KEY (id),
            KEY idx_scout_id (scout_id),
            KEY idx_parent_user_id (parent_user_id),
            KEY idx_unit_id (unit_id)
        ) {$charset};";

        return $sql;
    }

    public function get_table_names(): array {
        global $wpdb;
        return [
            'team_members'          => $wpdb->prefix . 'ems_team_members',
            'volunteer_availability' => $wpdb->prefix . 'ems_volunteer_availability',
            'route_submissions'     => $wpdb->prefix . 'ems_route_submissions',
            'osm_explorers'         => $wpdb->prefix . 'ems_osm_explorers',
            'osm_events'            => $wpdb->prefix . 'ems_osm_events',
            'osm_event_attendance'  => $wpdb->prefix . 'ems_osm_event_attendance',
            'units'                 => $wpdb->prefix . 'ems_units',
            'participant_signups'   => $wpdb->prefix . 'ems_participant_signups',
            'expedition_signups'    => $wpdb->prefix . 'ems_expedition_signups',
        ];
    }
}
