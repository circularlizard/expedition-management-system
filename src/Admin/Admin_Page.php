<?php
namespace EMS\Admin;

class Admin_Page {
    private Reconciliation_Controller $reconciliation;
    private Diagnostic_Panel $diagnostic;

    public function __construct( Reconciliation_Controller $reconciliation, Diagnostic_Panel $diagnostic ) {
        $this->reconciliation = $reconciliation;
        $this->diagnostic     = $diagnostic;
    }

    public function register(): void {
        add_submenu_page(
            'ems',
            __( 'Dashboard', 'ems-plugin' ),
            __( 'Dashboard', 'ems-plugin' ),
            'manage_options',
            'ems',
            [ $this, 'render_dashboard' ]
        );

        add_submenu_page(
            'ems',
            __( 'Reconciliation', 'ems-plugin' ),
            __( 'Reconciliation', 'ems-plugin' ),
            'manage_options',
            'ems-reconciliation',
            [ $this, 'render_reconciliation' ]
        );

        add_submenu_page(
            'ems',
            __( 'Column Mapper', 'ems-plugin' ),
            __( 'Column Mapper', 'ems-plugin' ),
            'manage_options',
            'ems-column-mapper',
            [ $this, 'render_column_mapper' ]
        );
    }

    public function render_dashboard(): void {
        if ( isset( $_POST['ems_sync_osm'] ) && check_admin_referer( 'ems_sync_osm' ) ) {
            $handler = new OSM_Sync_Auth_Handler();
            $handler->initiate();
        }

        $asset_url = plugin_dir_url( EMS_PLUGIN_FILE ) . 'assets/js/expedition-board.js';

        wp_enqueue_script(
            'ems-expedition-board',
            $asset_url,
            [],
            EMS_VERSION,
            true
        );

        wp_localize_script( 'ems-expedition-board', 'emsExpeditionBoard', [
            'root_url' => get_rest_url( null, 'ems/v1' ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'sync_url' => admin_url( 'admin.php?page=ems' ),
            'sync_nonce' => wp_create_nonce( 'ems_sync_osm' ),
        ] );

        $user_id = get_current_user_id();
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'EMS Dashboard', 'ems-plugin' ) . '</h1>';

        if ( isset( $_GET['sync'] ) && $_GET['sync'] === 'success' ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'OSM data synced successfully.', 'ems-plugin' ) . '</p></div>';
        }

        echo '<div id="ems-expedition-board-root"></div>';

        echo '<hr />';
        echo '<h2>' . esc_html__( 'OSM Account Diagnostic', 'ems-plugin' ) . '</h2>';
        $this->diagnostic->render( $user_id );
        echo '</div>';
    }

    public function render_reconciliation(): void {
        $section_id = (int) get_option( 'ems_managed_sections_default', 99001 );
        $form_id    = (int) get_option( 'ems_gravity_form_id', 1 );

        $data = $this->reconciliation->reconcile( $section_id, $form_id );

        $asset_url = plugin_dir_url( EMS_PLUGIN_FILE ) . 'assets/js/reconciliation.js';

        wp_enqueue_script(
            'ems-reconciliation',
            $asset_url,
            [],
            EMS_VERSION,
            true
        );

        wp_localize_script( 'ems-reconciliation', 'emsReconciliation', $data );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Reconciliation Dashboard', 'ems-plugin' ) . '</h1>';
        echo '<div id="ems-reconciliation-root"></div>';
        echo '</div>';
    }

    public function render_column_mapper(): void {
        $asset_url = plugin_dir_url( EMS_PLUGIN_FILE ) . 'assets/js/column-mapper.js';

        wp_enqueue_script(
            'ems-column-mapper',
            $asset_url,
            [],
            EMS_VERSION,
            true
        );

        wp_localize_script( 'ems-column-mapper', 'emsColumnMapper', [
            'root_url' => get_rest_url( null, 'ems/v1' ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'sections' => (array) get_option( 'ems_managed_sections', [] ),
        ] );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Flexi-Record Column Mapper', 'ems-plugin' ) . '</h1>';
        echo '<div id="ems-column-mapper-root"></div>';
        echo '</div>';
    }
}
