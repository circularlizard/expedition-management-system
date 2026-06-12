<?php
namespace EMS\Admin;

class Admin_Page {
    private Reconciliation_Controller $reconciliation;

    public function __construct( Reconciliation_Controller $reconciliation ) {
        $this->reconciliation = $reconciliation;
    }

    public function register(): void {
        add_submenu_page(
            'ems',
            __( 'Reconciliation', 'ems-plugin' ),
            __( 'Reconciliation', 'ems-plugin' ),
            'manage_options',
            'ems-reconciliation',
            [ $this, 'render_reconciliation' ]
        );
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
}
