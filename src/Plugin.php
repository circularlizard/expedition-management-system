<?php
namespace EMS;

use EMS\Admin\Admin_Page;
use EMS\Admin\Diagnostic_Panel;
use EMS\Admin\Reconciliation_Controller;
use EMS\Admin\Settings_Page;
use EMS\Admin\Training_Report_Page;
use EMS\Core\CPT_Registry;
use EMS\Core\Table_Installer;
use EMS\Integrations\Drivers\Live_Driver;
use EMS\Integrations\Drivers\Mock_Driver;
use EMS\Integrations\Gravity_Forms_Client;
use EMS\Integrations\Mock_Gravity_Forms_Client;
use EMS\Integrations\OSM_API_Client;
use EMS\Integrations\OSM_Parser;
use EMS\Integrations\Rate_Limiter;
use EMS\Integrations\TutorLMS_Client;

class Plugin {
    private CPT_Registry $cpt_registry;

    public function __construct() {
        $this->cpt_registry = new CPT_Registry();
        $this->init_hooks();
    }

    private function init_hooks(): void {
        add_action( 'init', [ $this->cpt_registry, 'register' ] );

        $report_page = new Training_Report_Page( new TutorLMS_Client() );
        add_action( 'admin_menu', [ $report_page, 'register' ] );

        $api_mode   = get_option( 'ems_api_mode', 'mock' );
        $driver     = ( $api_mode === 'live' ) ? new Live_Driver() : new Mock_Driver();
        $osm_client = new OSM_API_Client( $driver, new OSM_Parser(), new Rate_Limiter( 10, 1.0 ) );
        $gf_client  = new Mock_Gravity_Forms_Client();

        $admin_page = new Admin_Page(
            new Reconciliation_Controller( $osm_client, $gf_client ),
            new Diagnostic_Panel()
        );
        add_action( 'admin_menu', [ $admin_page, 'register' ] );

        $settings_page = new Settings_Page();
        add_action( 'admin_menu', [ $settings_page, 'register' ] );

        // REST API
        add_action( 'rest_api_init', function() use ( $osm_client ) {
            $flexi_controller = new \EMS\Admin\Flexi_Mapper_Controller(
                $osm_client,
                new \EMS\Integrations\Flexi_Structure_Parser(),
                new \EMS\Integrations\Flexi_Column_Map(),
                new \EMS\Integrations\Flexi_Record_Importer(
                    new \EMS\Integrations\Flexi_Column_Map(),
                    new \EMS\Data\Expedition_Repository(),
                    new \EMS\Data\Team_Repository(),
                    new \EMS\Data\Team_Member_Repository()
                )
            );
            $flexi_controller->register_routes();

            $view_controller = new \EMS\Admin\Admin_View_Controller(
                new \EMS\Data\Expedition_Repository(),
                new \EMS\Data\Team_Repository(),
                new \EMS\Data\Team_Member_Repository(),
                new \EMS\Integrations\TutorLMS_Client()
            );
            $view_controller->register_routes();
        } );

        // OSM Sync Callback
        add_action( 'admin_post_ems_osm_callback', function() {
            $handler = new \EMS\Admin\OSM_Sync_Auth_Handler();
            $handler->handle_callback( function( $token ) {
                $api_mode   = get_option( 'ems_api_mode', 'mock' );
                $driver     = ( $api_mode === 'live' ) ? new Live_Driver() : new Mock_Driver();
                $osm_client = new OSM_API_Client( $driver, new OSM_Parser(), new Rate_Limiter( 10, 1.0 ) );
                $osm_client->set_access_token( $token );

                $importer = new \EMS\Integrations\OSM_Section_Importer( $osm_client );
                $importer->import_all();
            } );
        } );

        // Support for ES Modules in Admin
        add_filter( 'script_loader_tag', function ( $tag, $handle, $src ) {
            if ( str_starts_with( $handle, 'ems-' ) ) {
                return sprintf(
                    '<script type="module" src="%s" id="%s-js"></script>',
                    esc_url( $src ),
                    esc_attr( $handle )
                );
            }
            return $tag;
        }, 10, 3 );
    }

    public static function activate(): void {
        ( new Table_Installer() )->install();
    }
}
