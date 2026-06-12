<?php
namespace EMS\Core;

class Table_Installer {
    public function install(): void {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();

        $sql = [];

        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ems_team_members (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            team_post_id BIGINT UNSIGNED NOT NULL,
            user_id     BIGINT UNSIGNED NOT NULL,
            added_by    BIGINT UNSIGNED NOT NULL,
            added_at    DATETIME        NOT NULL,
            PRIMARY KEY (id),
            KEY idx_team_post_id (team_post_id),
            KEY idx_user_id (user_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ems_volunteer_availability (
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

        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ems_route_submissions (
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

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        foreach ( $sql as $statement ) {
            dbDelta( $statement );
        }
    }

    public function get_table_names(): array {
        global $wpdb;
        return [
            'team_members'          => $wpdb->prefix . 'ems_team_members',
            'volunteer_availability' => $wpdb->prefix . 'ems_volunteer_availability',
            'route_submissions'     => $wpdb->prefix . 'ems_route_submissions',
        ];
    }
}
