<?php
namespace EMS\Admin;

use EMS\Integrations\Drivers\Mock_Driver;
use EMS\Integrations\OSM_API_Client;
use EMS\Integrations\OSM_Parser;
use EMS\Integrations\Rate_Limiter;
use EMS\Integrations\OSM_Section_Importer;

class Admin_Page {
    private Reconciliation_Controller $reconciliation;
    private Diagnostic_Panel $diagnostic;

    public function __construct( Reconciliation_Controller $reconciliation, Diagnostic_Panel $diagnostic ) {
        $this->reconciliation = $reconciliation;
        $this->diagnostic     = $diagnostic;
    }

   public function register(): void {
        $dashboard_hook = add_submenu_page(
            'ems',
            __( 'Dashboard', 'ems-plugin' ),
            __( 'Dashboard', 'ems-plugin' ),
            'manage_options',
            'ems',
            [ $this, 'render_dashboard' ]
        );

        $reconciliation_hook = add_submenu_page(
            'ems',
            __( 'Reconciliation', 'ems-plugin' ),
            __( 'Reconciliation', 'ems-plugin' ),
            'manage_options',
            'ems-reconciliation',
            [ $this, 'render_reconciliation' ]
        );

        $mapper_hook = add_submenu_page(
            'ems',
            __( 'Column Mapper', 'ems-plugin' ),
            __( 'Column Mapper', 'ems-plugin' ),
            'manage_options',
            'ems-column-mapper',
            [ $this, 'render_column_mapper' ]
        );

        add_action( "admin_enqueue_scripts", function( $hook ) use ( $dashboard_hook, $reconciliation_hook, $mapper_hook ) {
            if ( $hook === $dashboard_hook ) {
                $this->enqueue_dashboard_assets();
            } elseif ( $hook === $reconciliation_hook ) {
                $this->enqueue_reconciliation_assets();
            } elseif ( $hook === $mapper_hook ) {
                $this->enqueue_mapper_assets();
            }
        } );

        add_action( 'admin_init', [ $this, 'handle_sync_post' ] );
    }

    /**
     * Handles the OSM sync POST request before any output is sent.
     */
    public function handle_sync_post(): void {
        if ( ! isset( $_POST['ems_sync_osm'] ) ) {
            return;
        }

        if ( ! check_admin_referer( 'ems_sync_osm' ) ) {
            return;
        }

        $api_mode = get_option( 'ems_api_mode', 'mock' );
        if ( $api_mode === 'mock' ) {
            $this->do_mock_sync();
        } else {
            $handler = new OSM_Sync_Auth_Handler();
            $handler->initiate();
        }
        exit;
    }

    private function enqueue_dashboard_assets(): void {
        $this->enqueue_admin_script( 'ems-expedition-board', 'assets/js/expedition-board.js' );
        wp_localize_script( 'ems-expedition-board', 'emsExpeditionBoard', [
            'root_url'   => get_rest_url( null, 'ems/v1' ),
            'nonce'      => wp_create_nonce( 'wp_rest' ),
            'sync_url'   => admin_url( 'admin.php?page=ems' ),
            'sync_nonce' => wp_create_nonce( 'ems_sync_osm' ),
        ] );
    }

    private function enqueue_reconciliation_assets(): void {
        $section_id = (int) get_option( 'ems_managed_sections_default', 99001 );
        $form_id    = (int) get_option( 'ems_gravity_form_id', 1 );
        $data       = $this->reconciliation->reconcile( $section_id, $form_id );

        $this->enqueue_admin_script( 'ems-reconciliation', 'assets/js/reconciliation.js' );
        wp_localize_script( 'ems-reconciliation', 'emsReconciliation', $data );
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

    private function do_mock_sync(): void {
        $driver     = new Mock_Driver();
        $parser     = new OSM_Parser();
        $osm_client = new OSM_API_Client( $driver, $parser, new Rate_Limiter( 10, 1.0 ) );
        $importer   = new OSM_Section_Importer( $osm_client );

        // Import from all accessible sections from the data payload (not just managed sections)
        $payload     = $osm_client->get_data_payload( 'mock_token' );
        $section_ids = $parser->parse_section_ids( $payload );

        // Also import from any configured managed sections
        $managed_sections = (array) get_option( 'ems_managed_sections', [] );
        $managed_ids      = array_keys( $managed_sections );
        $all_ids          = array_unique( array_merge( $section_ids, $managed_ids ) );

        $importer->import_sections( $all_ids );
        update_option( 'ems_osm_last_sync', current_time( 'iso' ) );
        wp_safe_redirect( admin_url( 'admin.php?page=ems&sync=success' ) );
        exit;
    }

    public function render_reconciliation(): void {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Reconciliation Dashboard', 'ems-plugin' ) . '</h1>';
        echo '<div id="ems-reconciliation-root"></div>';
        echo '</div>';
    }

    public function render_column_mapper(): void {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Flexi-Record Column Mapper', 'ems-plugin' ) . '</h1>';
        echo '<div id="ems-column-mapper-root"></div>';
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
