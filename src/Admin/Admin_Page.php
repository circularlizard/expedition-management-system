<?php
namespace EMS\Admin;


class Admin_Page {
    private Diagnostic_Panel $diagnostic;

    public function __construct( Diagnostic_Panel $diagnostic ) {
        $this->diagnostic = $diagnostic;
    }

    public function register(): void {
        add_action( 'admin_footer', [ $this, 'render_build_timestamp' ] );

        add_menu_page(
            'EMS',
            'EMS',
            'manage_options',
            'ems',
            [ $this, 'render_dashboard' ],
            'dashicons-location-alt',
            5
        );

        $dashboard_hook = add_submenu_page(
            'ems',
            __( 'Expedition Board', 'ems-plugin' ),
            __( 'Expedition Board', 'ems-plugin' ),
            'manage_options',
            'ems',
            [ $this, 'render_dashboard' ]
        );

        add_action( 'admin_enqueue_scripts', function ( $hook ) use ( $dashboard_hook ) {
            if ( $hook === $dashboard_hook ) {
                $this->enqueue_dashboard_assets();
            }
        } );
    }

    /**
     * Registers the OSM Reference Data submenu.
     */
    public function register_reference_menu(): void {
        add_submenu_page(
            'ems',
            __( 'OSM Reference', 'ems-plugin' ),
            __( 'OSM Reference', 'ems-plugin' ),
            'manage_options',
            'ems-reference',
            [ $this, 'render_reference_page' ]
        );
    }

    /**
     * Registers the Column Mapper submenu (called at a later priority for correct ordering).
     */
    public function register_mapper_menu(): void {
        $mapper_hook = add_submenu_page(
            'ems',
            __( 'Column Mapper', 'ems-plugin' ),
            __( 'Column Mapper', 'ems-plugin' ),
            'manage_options',
            'ems-column-mapper',
            [ $this, 'render_column_mapper' ]
        );

        add_action( 'admin_enqueue_scripts', function ( $hook ) use ( $mapper_hook ) {
            if ( $hook === $mapper_hook ) {
                $this->enqueue_mapper_assets();
            }
        } );
    }


    private function enqueue_dashboard_assets(): void {
        $this->enqueue_admin_script( 'ems-expedition-board', 'assets/js/expedition-board.js' );
        wp_localize_script( 'ems-expedition-board', 'emsExpeditionBoard', [
            'root_url' => get_rest_url( null, 'ems/v1' ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
        ] );
    }

    private function enqueue_mapper_assets(): void {
        $this->enqueue_admin_script( 'ems-column-mapper', 'assets/js/column-mapper.js' );
        wp_localize_script( 'ems-column-mapper', 'emsColumnMapper', [
            'root_url' => get_rest_url( null, 'ems/v1' ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'sections' => (array) get_option( 'ems_managed_sections', [] ),
        ] );
    }

    public function render_dashboard(): void {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Expedition Board', 'ems-plugin' ) . '</h1>';

        if ( isset( $_GET['sync'] ) && $_GET['sync'] === 'success' ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'OSM data synced successfully.', 'ems-plugin' ) . '</p></div>';
        }

        echo '<div id="ems-expedition-board-root"></div>';
        echo '</div>';
    }


    public function render_reference_page(): void {
        global $wpdb;

        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'explorers';
        $valid_tabs = [ 'explorers', 'patrols', 'events', 'diagnostics' ];
        if ( ! in_array( $active_tab, $valid_tabs, true ) ) {
            $active_tab = 'explorers';
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'OSM Reference Data', 'ems-plugin' ) . '</h1>';

        $this->render_error_notices();
        $this->render_sync_result_panel();

        $last_sync = get_option( 'ems_osm_last_sync' );
        $base_url  = admin_url( 'admin.php?page=ems-reference' );

        $is_blocked = (bool) get_option( 'ems_api_blocked', false );

        echo '<div style="display:flex;align-items:center;gap:20px;margin-bottom:10px;">';
        if ( ! $is_blocked ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            echo '<input type="hidden" name="action" value="ems_sync_osm" />';
            wp_nonce_field( 'ems_sync_osm' );
            echo '<button type="submit" class="button button-primary">' . esc_html__( 'Sync from OSM', 'ems-plugin' ) . '</button>';
            echo '</form>';
        }
        if ( $last_sync ) {
            echo '<span style="color:#666;">' . esc_html__( 'Last synced:', 'ems-plugin' ) . ' ' . esc_html( $last_sync ) . '</span>';
        } else {
            echo '<span style="color:#999;">' . esc_html__( 'Never synced', 'ems-plugin' ) . '</span>';
        }
        echo '</div>';

        $tab_labels = [
            'explorers'   => __( 'Explorers', 'ems-plugin' ),
            'patrols'     => __( 'Patrols', 'ems-plugin' ),
            'events'      => __( 'Events', 'ems-plugin' ),
            'diagnostics' => __( 'Diagnostics', 'ems-plugin' ),
        ];

        echo '<nav class="nav-tab-wrapper" style="margin-bottom:0;">';
        foreach ( $tab_labels as $slug => $label ) {
            $class = ( $slug === $active_tab ) ? 'nav-tab nav-tab-active' : 'nav-tab';
            echo '<a href="' . esc_url( $base_url . '&tab=' . $slug ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</nav>';
        echo '<div style="border:1px solid #ccd0d4;border-top:none;background:#fff;padding:20px;margin-bottom:20px;">';

        if ( $active_tab === 'explorers' ) {
            $this->render_explorers_tab( $wpdb );
        } elseif ( $active_tab === 'patrols' ) {
            $this->render_patrols_tab( $wpdb );
        } elseif ( $active_tab === 'events' ) {
            $this->render_events_tab( $wpdb );
        } elseif ( $active_tab === 'diagnostics' ) {
            $this->render_diagnostics_tab();
        }

        echo '</div>';
        echo '</div>';
    }

    private function render_explorers_tab( $wpdb ): void {
        $table     = $wpdb->prefix . 'ems_osm_explorers';
        $explorers = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY last_name, first_name", ARRAY_A );

        if ( $wpdb->last_error ) {
            echo '<div class="notice notice-error inline"><p><strong>DB error:</strong> ' . esc_html( $wpdb->last_error ) . '</p></div>';
            return;
        }

        if ( empty( $explorers ) ) {
            echo '<p>' . esc_html__( 'No explorer data. Run an OSM sync first.', 'ems-plugin' ) . '</p>';
            return;
        }

        echo '<p style="color:#666;">' . sprintf( esc_html__( '%d explorers', 'ems-plugin' ), count( $explorers ) ) . '</p>';
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Scout ID', 'ems-plugin' ) . '</th>';
        echo '<th>' . esc_html__( 'Name', 'ems-plugin' ) . '</th>';
        echo '<th>' . esc_html__( 'Patrol', 'ems-plugin' ) . '</th>';
        echo '<th>' . esc_html__( 'Email', 'ems-plugin' ) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ( $explorers as $row ) {
            echo '<tr>';
            echo '<td>' . esc_html( $row['scout_id'] ) . '</td>';
            echo '<td>' . esc_html( $row['first_name'] . ' ' . $row['last_name'] ) . '</td>';
            echo '<td>' . esc_html( $row['patrol'] ) . '</td>';
            echo '<td>' . esc_html( $row['email'] ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private function render_patrols_tab( $wpdb ): void {
        $table  = $wpdb->prefix . 'ems_osm_explorers';
        $rows   = $wpdb->get_results(
            "SELECT patrol, COUNT(*) AS member_count FROM {$table} GROUP BY patrol ORDER BY patrol",
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            echo '<p>' . esc_html__( 'No patrol data. Run an OSM sync first.', 'ems-plugin' ) . '</p>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Patrol', 'ems-plugin' ) . '</th>';
        echo '<th>' . esc_html__( 'Members', 'ems-plugin' ) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ( $rows as $row ) {
            echo '<tr>';
            echo '<td>' . esc_html( $row['patrol'] ?: __( '(none)', 'ems-plugin' ) ) . '</td>';
            echo '<td>' . (int) $row['member_count'] . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private function render_events_tab( $wpdb ): void {
        $events_table     = $wpdb->prefix . 'ems_osm_events';
        $attendance_table = $wpdb->prefix . 'ems_osm_event_attendance';

        $rows = $wpdb->get_results(
            "SELECT e.event_id, e.name, e.start_date, e.end_date, e.location,
                    COUNT(a.id) AS attendance_count
             FROM {$events_table} e
             LEFT JOIN {$attendance_table} a ON a.event_id = e.event_id
             GROUP BY e.event_id
             ORDER BY e.start_date DESC",
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            echo '<p>' . esc_html__( 'No event data. Run an OSM sync first.', 'ems-plugin' ) . '</p>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Name', 'ems-plugin' ) . '</th>';
        echo '<th>' . esc_html__( 'Start', 'ems-plugin' ) . '</th>';
        echo '<th>' . esc_html__( 'End', 'ems-plugin' ) . '</th>';
        echo '<th>' . esc_html__( 'Location', 'ems-plugin' ) . '</th>';
        echo '<th>' . esc_html__( 'Attendance', 'ems-plugin' ) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ( $rows as $row ) {
            echo '<tr>';
            echo '<td>' . esc_html( $row['name'] ) . '</td>';
            echo '<td>' . esc_html( $row['start_date'] ) . '</td>';
            echo '<td>' . esc_html( $row['end_date'] ) . '</td>';
            echo '<td>' . esc_html( $row['location'] ) . '</td>';
            echo '<td>' . (int) $row['attendance_count'] . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private function render_error_notices(): void {
        $error_map = [
            'forbidden'       => __( 'You do not have permission to perform that action.', 'ems-plugin' ),
            'invalid_state'   => __( 'Invalid OAuth state. Please try again.', 'ems-plugin' ),
            'missing_code'    => __( 'Authorization code was missing from OSM callback.', 'ems-plugin' ),
            'token_exchange'  => __( 'Failed to exchange authorization code for token.', 'ems-plugin' ),
            'no_access_token' => __( 'OSM did not return an access token.', 'ems-plugin' ),
            'api_blocked'     => __( 'Sync is disabled: this application has been blocked by OSM. Clear the block flag below before retrying.', 'ems-plugin' ),
        ];

        if ( isset( $_GET['error'] ) ) {
            $slug = sanitize_key( $_GET['error'] );
            $msg  = $error_map[ $slug ] ?? esc_html__( 'An unknown error occurred during OSM authorization.', 'ems-plugin' );
            if ( isset( $_GET['error_msg'] ) ) {
                $msg .= ' ' . esc_html( sanitize_text_field( urldecode( $_GET['error_msg'] ) ) );
            }
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
        }

        if ( isset( $_GET['sync'] ) && $_GET['sync'] === 'success' ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'OSM sync complete.', 'ems-plugin' ) . '</p></div>';
        }

        if ( isset( $_GET['block_cleared'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'API block flag cleared. You may now attempt a sync.', 'ems-plugin' ) . '</p></div>';
        }
    }

    private function render_sync_result_panel(): void {
        if ( get_option( 'ems_api_blocked', false ) ) {
            echo '<div class="notice notice-error">';
            echo '<p><strong>' . esc_html__( 'OSM has permanently blocked this application.', 'ems-plugin' ) . '</strong> ';
            echo esc_html__( 'No further sync attempts will be made. Contact OSM support to resolve the block, then clear the flag.', 'ems-plugin' ) . '</p>';
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px;">';
            echo '<input type="hidden" name="action" value="ems_clear_api_block" />';
            wp_nonce_field( 'ems_clear_api_block' );
            echo '<button type="submit" class="button button-secondary">' . esc_html__( 'Clear block flag', 'ems-plugin' ) . '</button>';
            echo '</form>';
            echo '</div>';
            return;
        }

        $result = get_transient( 'ems_last_sync_result' );
        if ( empty( $result ) ) {
            return;
        }

        if ( ! empty( $result['rate_limited'] ) ) {
            $retry = (int) ( $result['retry_after_seconds'] ?? 0 );
            $reset = (int) ( $result['rate_limit_reset_seconds'] ?? 0 );
            echo '<div class="notice notice-warning is-dismissible"><p>';
            echo '<strong>' . esc_html__( 'Sync stopped: OSM rate limit reached.', 'ems-plugin' ) . '</strong> ';
            printf( esc_html__( 'Retry after: %ds (resets in %ds).', 'ems-plugin' ), $retry, $reset );
            echo '</p></div>';
        }

        if ( ! empty( $result['deprecated_endpoints'] ) ) {
            echo '<div class="notice notice-info is-dismissible"><p>';
            echo esc_html__( 'Deprecated OSM endpoints detected: ', 'ems-plugin' );
            echo esc_html( implode( ', ', (array) $result['deprecated_endpoints'] ) );
            echo '</p></div>';
        }

        $mode      = esc_html( $result['mode'] ?? 'unknown' );
        $started   = esc_html( $result['started_at'] ?? '' );
        $m_ok      = (int) ( $result['members_upserted'] ?? 0 );
        $m_fail    = (int) ( $result['members_failed']   ?? 0 );
        $e_ok      = (int) ( $result['events_upserted']  ?? 0 );
        $e_fail    = (int) ( $result['events_failed']    ?? 0 );
        $err_count = count( (array) ( $result['errors'] ?? [] ) );

        echo '<div style="background:#fff;border:1px solid #ccd0d4;padding:12px 16px;margin-bottom:16px;border-radius:2px;">';
        echo '<strong>' . esc_html__( 'Last Sync Result', 'ems-plugin' ) . '</strong>';
        echo ' <span style="color:#666;font-size:12px;">(' . $mode . ' — ' . $started . ')</span>';
        echo '<ul style="margin:.5em 0 0 1.5em;">';
        echo '<li>' . sprintf( esc_html__( 'Members: %d upserted, %d failed', 'ems-plugin' ), $m_ok, $m_fail ) . '</li>';
        echo '<li>' . sprintf( esc_html__( 'Events: %d upserted, %d failed', 'ems-plugin' ), $e_ok, $e_fail ) . '</li>';
        if ( $err_count > 0 ) {
            echo '<li>';
            echo '<details><summary>' . sprintf( esc_html__( '%d error(s)', 'ems-plugin' ), $err_count ) . '</summary>';
            echo '<ul style="margin:.5em 0 0 1.5em;">';
            foreach ( (array) $result['errors'] as $err ) {
                echo '<li>' . esc_html( $err ) . '</li>';
            }
            echo '</ul></details>';
            echo '</li>';
        }
        echo '</ul>';
        echo '</div>';
    }

    private function render_diagnostics_tab(): void {
        $user_id = get_current_user_id();

        echo '<h3>' . esc_html__( 'System', 'ems-plugin' ) . '</h3>';
        echo $this->diagnostic->get_system_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        $access_type = get_user_meta( $user_id, 'ems_access_type', true );
        if ( ! empty( $access_type ) && $access_type !== 'local' ) {
            echo '<h3 style="margin-top:20px;">' . esc_html__( 'Your OSM Account', 'ems-plugin' ) . '</h3>';
            echo $this->diagnostic->get_user_html( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }

        $log = get_transient( 'ems_last_sync_log' );
        if ( ! empty( $log ) ) {
            echo '<h3 style="margin-top:20px;">' . esc_html__( 'Last Sync Log', 'ems-plugin' ) . '</h3>';
            $log_json = wp_json_encode( $log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
            echo '<pre style="background:#f6f7f7;padding:10px;overflow:auto;max-height:300px;font-size:11px;">';
            echo esc_html( $log_json );
            echo '</pre>';
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px;">';
            echo '<input type="hidden" name="action" value="ems_download_sync_log" />';
            wp_nonce_field( 'ems_download_sync_log' );
            echo '<button type="submit" class="button">' . esc_html__( 'Download log (JSON)', 'ems-plugin' ) . '</button>';
            echo '</form>';
        }

        $dump = get_transient( 'ems_last_payload_dump' );
        if ( ! empty( $dump ) ) {
            echo '<h3 style="margin-top:20px;">' . esc_html__( 'Last Payload Dump (get_data_payload)', 'ems-plugin' ) . '</h3>';
            $roles       = $dump['data']['globals']['roles'] ?? [];
            $role_labels = array_map( fn( $r ) => ( $r['section'] ?? '?' ) . ' @ ' . ( $r['groupname'] ?? '?' ), $roles );
            $summary = [
                'userid'      => $dump['data']['globals']['userid'] ?? null,
                'email'       => $dump['data']['globals']['email'] ?? null,
                'roles'       => $role_labels,
                'section_ids' => array_keys( $dump['data']['globals']['member_access'] ?? [] ),
                'term_count'  => count( $dump['data']['globals']['terms'] ?? [] ),
            ];
            echo '<pre style="background:#f6f7f7;padding:10px;font-size:11px;">';
            echo esc_html( wp_json_encode( $summary, JSON_PRETTY_PRINT ) );
            echo '</pre>';
        }
    }

    public function render_column_mapper(): void {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Flexi-Record Column Mapper', 'ems-plugin' ) . '</h1>';
        echo '<div id="ems-column-mapper-root"></div>';
        echo '</div>';
    }

    /**
     * Renders a build timestamp footer on EMS admin pages.
     */
    public function render_build_timestamp(): void {
        $screen = get_current_screen();
        if ( ! $screen || strpos( $screen->id, 'ems' ) === false ) {
            return;
        }

        $manifest_path = plugin_dir_path( EMS_PLUGIN_FILE ) . 'assets/build-manifest.json';
        $built_at      = '';

        if ( file_exists( $manifest_path ) ) {
            $data     = json_decode( file_get_contents( $manifest_path ), true );
            $built_at = $data['built_at'] ?? '';
        }

        if ( ! $built_at ) {
            return;
        }

        $dt      = new \DateTime( $built_at );
        $display = $dt->format( 'j M Y H:i' ) . ' UTC';

        echo '<div style="position:fixed;bottom:0;right:0;padding:4px 10px;font-size:11px;color:#999;background:rgba(255,255,255,.85);border-top-left-radius:4px;z-index:9999;">';
        echo 'Build: ' . esc_html( $display );
        echo '</div>';
    }

    /**
     * Enqueues an admin script as an ES module.
     */
    private function enqueue_admin_script( string $handle, string $rel_path ): void {
        $script_url = plugin_dir_url( EMS_PLUGIN_FILE ) . $rel_path;

        wp_enqueue_script(
            $handle,
            $script_url,
            [],
            EMS_VERSION,
            true
        );
    }
}
