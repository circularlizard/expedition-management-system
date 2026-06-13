<?php
namespace EMS\Admin;

use EMS\Core\Encryption;

/**
 * Handles the admin-triggered OAuth2 flow for syncing data from OSM.
 */
class OSM_Sync_Auth_Handler {

    private string $client_id;
    private string $client_secret;
    private string $auth_url;
    private string $token_url;

    public function __construct() {
        $this->client_id     = get_option( 'ems_osm_client_id', '' );
        $encrypted_secret    = get_option( 'ems_osm_client_secret', '' );
        $this->client_secret = Encryption::decrypt( $encrypted_secret ) ?: '';
        $this->auth_url      = get_option( 'ems_osm_auth_url', 'https://www.onlinescoutmanager.co.uk/oauth/authorize' );
        $this->token_url     = get_option( 'ems_osm_token_url', 'https://www.onlinescoutmanager.co.uk/oauth/token' );
    }

    /**
     * Initiates the OAuth flow by redirecting to OSM.
     */
    public function initiate(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'ems-plugin' ) );
        }

        $state = wp_create_nonce( 'ems_osm_sync' );
        $query = http_build_query( [
            'client_id'     => $this->client_id,
            'response_type' => 'code',
            'scope'         => 'section:member:read section:flexirecord:read',
            'redirect_uri'  => $this->get_redirect_uri(),
            'state'         => $state,
        ] );

        wp_redirect( $this->auth_url . '?' . $query );
    }

    /**
     * Handles the callback from OSM.
     *
     * @param callable $on_success Callback function to invoke with the access token.
     */
    public function handle_callback( callable $on_success ): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'ems-plugin' ) );
        }

        $state = $_GET['state'] ?? '';
        if ( ! wp_verify_nonce( $state, 'ems_osm_sync' ) ) {
            wp_die( esc_html__( 'Invalid state parameter.', 'ems-plugin' ) );
        }

        $code = $_GET['code'] ?? '';
        if ( empty( $code ) ) {
            wp_die( esc_html__( 'Authorization code missing.', 'ems-plugin' ) );
        }

        $token_data = $this->exchange_code_for_token( $code );

        if ( is_wp_error( $token_data ) ) {
            wp_die( esc_html( $token_data->get_error_message() ) );
        }

        $access_token = $token_data['access_token'] ?? '';
        if ( empty( $access_token ) ) {
            wp_die( esc_html__( 'Failed to retrieve access token.', 'ems-plugin' ) );
        }

        // Invoke the sync callback
        $on_success( $access_token );

        // Redirect back to dashboard with a success message
        wp_redirect( admin_url( 'admin.php?page=ems&sync=success' ) );
    }

    /**
     * Exchanges the authorization code for an access token.
     */
    private function exchange_code_for_token( string $code ) {
        $response = wp_remote_post( $this->token_url, [
            'body' => [
                'grant_type'    => 'authorization_code',
                'client_id'     => $this->client_id,
                'client_secret' => $this->client_secret,
                'redirect_uri'  => $this->get_redirect_uri(),
                'code'          => $code,
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! is_array( $data ) || isset( $data['error'] ) ) {
            return new \WP_Error( 'osm_token_error', $data['error_description'] ?? $data['error'] ?? 'Unknown error' );
        }

        return $data;
    }

    /**
     * Gets the redirect URI for the OAuth flow.
     */
    public function get_redirect_uri(): string {
        return admin_url( 'admin-post.php?action=ems_osm_callback' );
    }
}
