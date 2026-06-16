<?php
namespace EMS;

use EMS\Admin\Admin_Page;
use EMS\Admin\Diagnostic_Panel;
use EMS\Admin\Settings_Page;
use EMS\Admin\Training_Report_Page;
use EMS\Core\CPT_Registry;
use EMS\Core\Table_Installer;
use EMS\Integrations\Drivers\Live_Driver;
use EMS\Integrations\Drivers\Mock_Driver;
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

        $admin_page = new Admin_Page(
            new Diagnostic_Panel()
        );
        add_action( 'admin_menu', [ $admin_page, 'register' ], 10 );

        add_action( 'admin_menu', [ $admin_page, 'register_reference_menu' ], 12 );

        $report_page = new Training_Report_Page( new TutorLMS_Client() );
        add_action( 'admin_menu', [ $report_page, 'register' ], 14 );

        add_action( 'admin_menu', [ $admin_page, 'register_mapper_menu' ], 16 );

        $api_mode   = get_option( 'ems_api_mode', 'mock' );
        $driver     = ( $api_mode === 'live' ) ? new Live_Driver() : new Mock_Driver();
        $osm_client = new OSM_API_Client( $driver, new OSM_Parser(), new Rate_Limiter( 10, 1.0 ) );

        $settings_page = new Settings_Page();
        add_action( 'admin_menu', [ $settings_page, 'register' ], 18 );

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

        // Settings page "Fetch sections from OSM" handler — mock mode only for now (live deferred to 1.10 OAuth callback)
        add_action( 'admin_post_ems_fetch_sections', function() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_safe_redirect( admin_url( 'admin.php?page=ems-settings&tab=sections&error=forbidden' ) );
                exit;
            }
            check_admin_referer( 'ems_fetch_sections' );

            $api_mode = get_option( 'ems_api_mode', 'mock' );
            $parser   = new OSM_Parser();

            if ( in_array( $api_mode, [ 'live', 'live-auth-only', 'live-limited' ], true ) ) {
                $handler = new \EMS\Admin\OSM_Sync_Auth_Handler();
                $handler->initiate();
            } else {
                $driver     = new Mock_Driver();
                $osm_client = new OSM_API_Client( $driver, $parser, new Rate_Limiter( 10, 1.0 ) );
                $payload    = $osm_client->get_data_payload();
                set_transient( 'ems_available_sections', $parser->parse_section_names( $payload ), HOUR_IN_SECONDS );
                wp_safe_redirect( admin_url( 'admin.php?page=ems-settings&tab=sections&fetched=1' ) );
            }
            exit;
        } );

        // OSM Reference page "Sync from OSM" form handler
        add_action( 'admin_post_ems_sync_osm', function() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_safe_redirect( admin_url( 'admin.php?page=ems-reference&error=forbidden' ) );
                exit;
            }
            check_admin_referer( 'ems_sync_osm' );

            $api_mode = get_option( 'ems_api_mode', 'mock' );

            if ( in_array( $api_mode, [ 'live', 'live-auth-only', 'live-limited' ], true ) ) {
                ( new \EMS\Admin\OSM_Sync_Auth_Handler() )->initiate();
            } else {
                $parser     = new OSM_Parser();
                $driver     = new Mock_Driver();
                $logger     = new \EMS\Integrations\OSM_Sync_Logger();
                $osm_client = new OSM_API_Client( $driver, $parser, new Rate_Limiter( 10, 1.0 ), $logger );

                $payload     = $osm_client->get_data_payload();
                $section_ids = $parser->parse_section_ids( $payload );

                set_transient( 'ems_last_payload_dump', $payload, HOUR_IN_SECONDS );
                set_transient( 'ems_available_sections', $parser->parse_section_names( $payload ), HOUR_IN_SECONDS );

                $managed_sections = (array) get_option( 'ems_managed_sections', [] );
                $managed_ids      = array_map( 'intval', array_keys( $managed_sections ) );
                $all_ids          = array_unique( array_merge( $section_ids, $managed_ids ) );

                ( new \EMS\Integrations\OSM_Reference_Sync( $osm_client, $parser ) )
                    ->sync( $all_ids, $payload, $api_mode, 0, $logger );

                wp_safe_redirect( admin_url( 'admin.php?page=ems-reference&sync=success' ) );
            }
            exit;
        } );

        // OSM OAuth Callback — handles live, live-auth-only, live-limited
        add_action( 'admin_post_ems_osm_callback', function() {
            $handler = new \EMS\Admin\OSM_Sync_Auth_Handler();
            $handler->handle_callback( function( string $token ) {
                $api_mode = get_option( 'ems_api_mode', 'mock' );
                $parser   = new OSM_Parser();
                $driver   = new Live_Driver();
                $logger   = new \EMS\Integrations\OSM_Sync_Logger();
                $osm_client = new OSM_API_Client( $driver, $parser, new Rate_Limiter( 10, 1.0 ), $logger );
                $osm_client->set_access_token( $token );

                $payload = $osm_client->get_data_payload();

                set_transient( 'ems_last_payload_dump', $payload, HOUR_IN_SECONDS );
                set_transient( 'ems_available_sections', $parser->parse_section_names( $payload ), HOUR_IN_SECONDS );

                if ( $api_mode === 'live-auth-only' ) {
                    $logger->persist();
                    return;
                }

                $section_ids      = $parser->parse_section_ids( $payload );
                $managed_sections = (array) get_option( 'ems_managed_sections', [] );
                $managed_ids      = array_map( 'intval', array_keys( $managed_sections ) );
                $all_ids          = array_unique( array_merge( $section_ids, $managed_ids ) );

                $member_limit = ( $api_mode === 'live-limited' )
                    ? max( 1, (int) get_option( 'ems_sync_limit', 5 ) )
                    : 0;

                $sync_ids = ( $api_mode === 'live-limited' )
                    ? array_slice( $all_ids, 0, 1 )
                    : $all_ids;

                ( new \EMS\Integrations\OSM_Reference_Sync( $osm_client, $parser ) )
                    ->sync( $sync_ids, $payload, $api_mode, $member_limit, $logger );
            } );
        } );

        // Clear API blocked flag
        add_action( 'admin_post_ems_clear_api_block', function() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( 'Forbidden' );
            }
            check_admin_referer( 'ems_clear_api_block' );
            delete_option( 'ems_api_blocked' );
            wp_safe_redirect( admin_url( 'admin.php?page=ems-reference&block_cleared=1' ) );
            exit;
        } );

        // Sync log download handler
        add_action( 'admin_post_ems_download_sync_log', function() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( 'Forbidden' );
            }
            check_admin_referer( 'ems_download_sync_log' );

            $log  = get_transient( 'ems_last_sync_log' );
            $json = wp_json_encode( $log ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
            $name = 'ems-sync-log-' . gmdate( 'Ymd-His' ) . '.json';

            header( 'Content-Type: application/json; charset=utf-8' );
            header( 'Content-Disposition: attachment; filename="' . $name . '"' );
            header( 'Content-Length: ' . strlen( $json ) );
            echo $json; // phpcs:ignore WordPress.Security.EscapeOutput
            exit;
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
        update_option( 'ems_db_version', EMS_VERSION );
    }

    public static function maybe_upgrade(): void {
        if ( get_option( 'ems_db_version' ) !== EMS_VERSION ) {
            ( new Table_Installer() )->install();
            update_option( 'ems_db_version', EMS_VERSION );
        }
    }
}
