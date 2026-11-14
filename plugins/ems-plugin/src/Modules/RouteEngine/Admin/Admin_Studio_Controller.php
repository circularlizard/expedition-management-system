<?php
/**
 * Admin Studio Controller
 */

declare(strict_types=1);

namespace EMS\Modules\RouteEngine\Admin;

class Admin_Studio_Controller {
    public static function init(): void {
        add_action( 'admin_menu', [ self::class, 'register_admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
    }

    public static function register_admin_menu(): void {
        // Main placement under EMS navigation
        add_submenu_page(
            'ems',
            __( 'Route Studio', 'ems-plugin' ),
            __( 'Route Studio', 'ems-plugin' ),
            'manage_options',
            'ems-route-studio',
            [ self::class, 'render_studio_page' ]
        );

        // Management page alias under Tools
        add_management_page(
            __( 'DofE Route Card Engine', 'ems-plugin' ),
            __( 'Route Card Engine', 'ems-plugin' ),
            'manage_options',
            'ems-route-card-studio',
            [ self::class, 'render_studio_page' ]
        );
    }

    public static function enqueue_assets( string $hook ): void {
        if ( 'tools_page_ems-route-card-studio' !== $hook && 'ems_page_ems-route-studio' !== $hook ) {
            return;
        }

        $base_path = defined( 'EMS_PLUGIN_PATH' ) ? constant( 'EMS_PLUGIN_PATH' ) : ( defined( 'EMS_ROUTE_CARD_PATH' ) ? constant( 'EMS_ROUTE_CARD_PATH' ) : '' );
        $base_url  = defined( 'EMS_PLUGIN_URL' ) ? constant( 'EMS_PLUGIN_URL' ) : ( defined( 'EMS_ROUTE_CARD_URL' ) ? constant( 'EMS_ROUTE_CARD_URL' ) : '' );

        $script_path = $base_path . 'assets/js/route-card-studio.js';
        $style_path  = $base_path . 'assets/js/route-card-studio.css';

        if ( file_exists( $script_path ) ) {
            $ver = (string) filemtime( $script_path );
            wp_enqueue_script(
                'ems-route-card-studio',
                $base_url . 'assets/js/route-card-studio.js',
                [ 'wp-element' ],
                $ver,
                true
            );

            $osApiKey    = defined( 'EMS_OS_MAPS_API_KEY' ) ? constant( 'EMS_OS_MAPS_API_KEY' ) : get_option( 'ems_os_maps_api_key', '' );
            $osApiSecret = defined( 'EMS_OS_MAPS_API_SECRET' ) ? constant( 'EMS_OS_MAPS_API_SECRET' ) : get_option( 'ems_os_maps_api_secret', '' );

            wp_localize_script( 'ems-route-card-studio', 'emsRouteCardStudioConfig', [
                'root_url'           => esc_url_raw( rest_url( 'ems-route/v1' ) ),
                'nonce'              => wp_create_nonce( 'wp_rest' ),
                'os_maps_api_key'    => sanitize_text_field( (string) $osApiKey ),
                'os_maps_api_secret' => sanitize_text_field( (string) $osApiSecret ),
            ] );
        }

        if ( file_exists( $style_path ) ) {
            $style_ver = (string) filemtime( $style_path );
            wp_enqueue_style(
                'ems-route-card-studio',
                $base_url . 'assets/js/route-card-studio.css',
                [],
                $style_ver
            );
        }
    }

    public static function render_studio_page(): void {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'DofE Route Card & Terrain Studio', 'ems-plugin' ) . '</h1>';
        echo '<div id="ems-route-card-studio-root"></div>';
        echo '</div>';
    }
}

class_alias( Admin_Studio_Controller::class, 'EMSRoute\\Admin\\Admin_Studio_Controller' );
