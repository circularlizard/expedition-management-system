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
        $user_id = get_current_user_id();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Expedition Board', 'ems-plugin' ) . '</h1>';

        if ( isset( $_GET['sync'] ) && $_GET['sync'] === 'success' ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'OSM data synced successfully.', 'ems-plugin' ) . '</p></div>';
        }

        echo '<div id="ems-expedition-board-root"></div>';

        echo '<hr />';
        echo '<h2>' . esc_html__( 'OSM Account Diagnostic', 'ems-plugin' ) . '</h2>';
        $this->diagnostic->render( $user_id );
        echo '</div>';
    }


    public function render_reference_page(): void {
        global $wpdb;

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'OSM Reference Data', 'ems-plugin' ) . '</h1>';

        if ( isset( $_GET['sync'] ) && $_GET['sync'] === 'success' ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'OSM data synced successfully.', 'ems-plugin' ) . '</p></div>';
        }

        $last_sync = get_option( 'ems_osm_last_sync' );
        echo '<div style="display:flex;align-items:center;gap:20px;margin-bottom:20px;">';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="ems_sync_osm" />';
        wp_nonce_field( 'ems_sync_osm' );
        echo '<button type="submit" class="button button-primary">' . esc_html__( 'Sync from OSM', 'ems-plugin' ) . '</button>';
        echo '</form>';
        if ( $last_sync ) {
            echo '<span style="color:#666;">Last synced: ' . esc_html( $last_sync ) . '</span>';
        } else {
            echo '<span style="color:#999;">Never synced</span>';
        }
        echo '</div>';

        $explorers_table = $wpdb->prefix . 'ems_osm_explorers';
        $explorers       = $wpdb->get_results( "SELECT * FROM {$explorers_table} ORDER BY last_name, first_name", ARRAY_A );

        if ( $wpdb->last_error ) {
            echo '<div class="notice notice-error"><p><strong>Database error:</strong> ' . esc_html( $wpdb->last_error ) . '</p></div>';
        }

        echo '<h2>' . esc_html__( 'Explorers', 'ems-plugin' ) . ' <span style="font-size:0.7em;font-weight:normal;color:#666;">(' . (int) count( (array) $explorers ) . ')</span></h2>';
        if ( ! empty( $explorers ) ) {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr><th>' . esc_html__( 'Scout ID', 'ems-plugin' ) . '</th><th>' . esc_html__( 'Name', 'ems-plugin' ) . '</th><th>' . esc_html__( 'Patrol', 'ems-plugin' ) . '</th><th>' . esc_html__( 'Email', 'ems-plugin' ) . '</th></tr></thead><tbody>';
            foreach ( $explorers as $row ) {
                echo '<tr>';
                echo '<td>' . esc_html( $row['scout_id'] ) . '</td>';
                echo '<td>' . esc_html( $row['first_name'] . ' ' . $row['last_name'] ) . '</td>';
                echo '<td>' . esc_html( $row['patrol'] ) . '</td>';
                echo '<td>' . esc_html( $row['email'] ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>' . esc_html__( 'No explorer data. Run an OSM sync first.', 'ems-plugin' ) . '</p>';
        }

        echo '</div>';
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
