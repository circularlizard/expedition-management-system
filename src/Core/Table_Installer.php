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
    }

    public function generate_sql( string $prefix = '', string $charset = '' ): array {
        $sql = [];

        $sql[] = "CREATE TABLE IF NOT EXISTS {$prefix}ems_team_members (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            team_post_id BIGINT UNSIGNED NOT NULL,
            user_id     BIGINT UNSIGNED NOT NULL,
            added_by    BIGINT UNSIGNED NOT NULL,
            added_at    DATETIME        NOT NULL,
            PRIMARY KEY (id),
            KEY idx_team_post_id (team_post_id),
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
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            scout_id     BIGINT UNSIGNED NOT NULL,
            wp_user_id   BIGINT UNSIGNED          DEFAULT NULL,
            section_id   BIGINT UNSIGNED NOT NULL,
            first_name   VARCHAR(100)    NOT NULL DEFAULT '',
            last_name    VARCHAR(100)    NOT NULL DEFAULT '',
            email        VARCHAR(100)    NOT NULL DEFAULT '',
            parent_email VARCHAR(100)    NOT NULL DEFAULT '',
            patrol       VARCHAR(100)    NOT NULL DEFAULT '',
            synced_at    DATETIME        NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idx_scout_id (scout_id),
            KEY idx_section_id (section_id),
            KEY idx_wp_user_id (wp_user_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE IF NOT EXISTS {$prefix}ems_osm_events (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id    BIGINT UNSIGNED NOT NULL,
            section_id  BIGINT UNSIGNED NOT NULL,
            name        VARCHAR(255)    NOT NULL DEFAULT '',
            start_date  DATETIME                 DEFAULT NULL,
            end_date    DATETIME                 DEFAULT NULL,
            location    VARCHAR(255)    NOT NULL DEFAULT '',
            synced_at   DATETIME        NOT NULL,
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
        ];
    }
}
