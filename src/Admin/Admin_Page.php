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

        if ( isset( $_GET['sync'] ) && $_GET['sync'] === 'success' ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'OSM data synced successfully.', 'ems-plugin' ) . '</p></div>';
        }

        $last_sync   = get_option( 'ems_osm_last_sync' );
        $base_url    = admin_url( 'admin.php?page=ems-reference' );

        echo '<div style="display:flex;align-items:center;gap:20px;margin-bottom:10px;">';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="ems_sync_osm" />';
        wp_nonce_field( 'ems_sync_osm' );
        echo '<button type="submit" class="button button-primary">' . esc_html__( 'Sync from OSM', 'ems-plugin' ) . '</button>';
        echo '</form>';
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

    private function render_diagnostics_tab(): void {
        $user_id = get_current_user_id();

        echo '<h3>' . esc_html__( 'System', 'ems-plugin' ) . '</h3>';
        echo $this->diagnostic->get_system_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        $access_type = get_user_meta( $user_id, 'ems_access_type', true );
        if ( ! empty( $access_type ) && $access_type !== 'local' ) {
            echo '<h3 style="margin-top:20px;">' . esc_html__( 'Your OSM Account', 'ems-plugin' ) . '</h3>';
            echo $this->diagnostic->get_user_html( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
