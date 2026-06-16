<?php
namespace EMS\Admin;

class Diagnostic_Panel {

    /**
     * System-level diagnostics — always populated regardless of who is logged in.
     */
    public function get_system_html(): string {
        global $wpdb;

        $api_mode         = get_option( 'ems_api_mode', 'mock' );
        $client_id_set    = ! empty( get_option( 'ems_osm_client_id', '' ) );
        $managed_sections = (array) get_option( 'ems_managed_sections', [] );
        $last_sync        = get_option( 'ems_osm_last_sync' ) ?: null;

        $section_labels = [];
        foreach ( $managed_sections as $id => $data ) {
            $section_labels[] = esc_html( $data['name'] ?? $id ) . ' (' . (int) $id . ')';
        }

        $explorers_table   = $wpdb->prefix . 'ems_osm_explorers';
        $events_table      = $wpdb->prefix . 'ems_osm_events';
        $attendance_table  = $wpdb->prefix . 'ems_osm_event_attendance';

        $explorer_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$explorers_table}" );
        $event_count      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events_table}" );
        $attendance_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$attendance_table}" );

        $html  = '<dl class="ems-diagnostic">';
        $html .= '<dt>' . esc_html__( 'API Mode', 'ems-plugin' ) . '</dt>';
        $html .= '<dd><strong>' . esc_html( $api_mode ) . '</strong></dd>';
        $html .= '<dt>' . esc_html__( 'OSM Client ID configured', 'ems-plugin' ) . '</dt>';
        $html .= '<dd>' . ( $client_id_set ? '<span style="color:green;">Yes</span>' : '<span style="color:red;">No</span>' ) . '</dd>';
        $html .= '<dt>' . esc_html__( 'Managed Sections', 'ems-plugin' ) . '</dt>';
        $html .= '<dd>' . ( $section_labels ? implode( ', ', $section_labels ) : esc_html__( 'None configured', 'ems-plugin' ) ) . '</dd>';
        $html .= '<dt>' . esc_html__( 'Last Sync', 'ems-plugin' ) . '</dt>';
        $html .= '<dd>' . ( $last_sync ? esc_html( $last_sync ) : '<em>' . esc_html__( 'Never', 'ems-plugin' ) . '</em>' ) . '</dd>';
        $html .= '<dt>' . esc_html__( 'DB Rows', 'ems-plugin' ) . '</dt>';
        $html .= '<dd>' . sprintf(
            esc_html__( 'Explorers: %d &nbsp; Events: %d &nbsp; Attendance: %d', 'ems-plugin' ),
            $explorer_count,
            $event_count,
            $attendance_count
        ) . '</dd>';
        $html .= '</dl>';

        $rate_limit = get_transient( 'ems_rate_limit_status' );
        if ( $rate_limit ) {
            $reset        = $rate_limit['x-ratelimit-reset'] ?? null;
            $html .= '<h4 style="margin-top:12px;">' . esc_html__( 'OSM Rate Limit', 'ems-plugin' ) . '</h4>';
            $html .= '<dl class="ems-diagnostic">';
            $html .= '<dt>' . esc_html__( 'Limit', 'ems-plugin' ) . '</dt>';
            $html .= '<dd>' . esc_html( $rate_limit['x-ratelimit-limit'] ?? '?' ) . '</dd>';
            $html .= '<dt>' . esc_html__( 'Remaining', 'ems-plugin' ) . '</dt>';
            $html .= '<dd>' . esc_html( $rate_limit['x-ratelimit-remaining'] ?? '?' ) . '</dd>';
            $html .= '<dt>' . esc_html__( 'Reset', 'ems-plugin' ) . '</dt>';
            $html .= '<dd>' . ( $reset ? esc_html( date( 'Y-m-d H:i:s', (int) $reset ) ) : '?' ) . '</dd>';
            $html .= '</dl>';
        }

        return $html;
    }

    /**
     * Per-user OIDC diagnostics — only meaningful when ems_access_type is set via OSM login.
     */
    public function get_user_html( int $user_id ): string {
        $access_type = get_user_meta( $user_id, 'ems_access_type', true );

        if ( empty( $access_type ) || $access_type === 'local' ) {
            return '<p><em>' . esc_html__( 'No OSM account linked to this user.', 'ems-plugin' ) . '</em></p>';
        }

        $section_ids = (array) get_user_meta( $user_id, 'ems_section_ids', true );
        $scout_ids   = (array) get_user_meta( $user_id, 'ems_scout_ids', true );

        $html  = '<dl class="ems-diagnostic">';
        $html .= '<dt>' . esc_html__( 'Access Type', 'ems-plugin' ) . '</dt>';
        $html .= '<dd>' . esc_html( $access_type ) . '</dd>';
        $html .= '<dt>' . esc_html__( 'Section IDs', 'ems-plugin' ) . '</dt>';
        $html .= '<dd>' . esc_html( implode( ', ', $section_ids ) ) . '</dd>';
        $html .= '<dt>' . esc_html__( 'Scout IDs', 'ems-plugin' ) . '</dt>';
        $html .= '<dd>' . esc_html( implode( ', ', $scout_ids ) ) . '</dd>';
        $html .= '</dl>';

        return $html;
    }

    /**
     * Backward-compatible alias — returns per-user HTML only.
     */
    public function get_html( int $user_id ): string {
        return $this->get_user_html( $user_id );
    }

    public function render( int $user_id ): void {
        echo $this->get_html( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
}
