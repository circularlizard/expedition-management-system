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
     * Redirects to OSM authorization page, or uses a cached token if still valid.
     * When a cached token exists and $on_success is provided, calls it directly
     * without a browser redirect to OSM.
     * Callers must call exit after this returns to terminate the request.
     *
     * @param callable|null $on_success Invoked with token when cached token is used.
     */
    public function initiate( ?callable $on_success = null ): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=ems-reference&error=forbidden' ) );
            return;
        }

        if ( get_option( 'ems_api_blocked', false ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=ems-reference&error=api_blocked' ) );
            return;
        }

        $cached = $this->get_cached_token();
        if ( $cached !== '' && $on_success !== null ) {
            $on_success( $cached );
            return;
        }

        $scope = get_option( 'ems_osm_scope', 'section:member:read section:events:read section:flexirecord:read' );
        $state = wp_create_nonce( 'ems_osm_sync' );
        $query = http_build_query( [
            'client_id'     => $this->client_id,
            'response_type' => 'code',
            'scope'         => $scope,
            'redirect_uri'  => $this->get_redirect_uri(),
            'state'         => $state,
        ] );

        wp_redirect( $this->auth_url . '?' . $query ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- intentional external redirect to OSM OAuth
    }

    /**
     * Handles the callback from OSM.
     * Callers must call exit after this returns.
     *
     * @param callable $on_success Callback function to invoke with the access token.
     */
    public function handle_callback( callable $on_success ): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=ems-reference&error=forbidden' ) );
            return;
        }

        if ( get_option( 'ems_api_blocked', false ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=ems-reference&error=api_blocked' ) );
            return;
        }

        if ( ! empty( $_GET['error'] ) ) {
            $osm_error = sanitize_key( $_GET['error'] );
            wp_safe_redirect( admin_url( 'admin.php?page=ems-reference&error=osm_' . $osm_error ) );
            return;
        }

        $state = $_GET['state'] ?? '';
        if ( ! wp_verify_nonce( $state, 'ems_osm_sync' ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=ems-reference&error=invalid_state' ) );
            return;
        }

        $code = $_GET['code'] ?? '';
        if ( empty( $code ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=ems-reference&error=missing_code' ) );
            return;
        }

        $token_data = $this->exchange_code_for_token( $code );

        if ( is_wp_error( $token_data ) ) {
            $safe_msg = substr( $token_data->get_error_message(), 0, 100 );
            wp_safe_redirect( admin_url( 'admin.php?page=ems-reference&error=token_exchange&error_msg=' . rawurlencode( $safe_msg ) ) );
            return;
        }

        $access_token = $token_data['access_token'] ?? '';
        if ( empty( $access_token ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=ems-reference&error=no_access_token' ) );
            return;
        }

        $this->cache_token( $access_token, (int) ( $token_data['expires_in'] ?? 3600 ) );

        $on_success( $access_token );

        wp_safe_redirect( admin_url( 'admin.php?page=ems-reference&sync=success' ) );
    }

    /**
     * Returns a cached access token for the current user if one exists and hasn't expired.
     * Returns empty string if no valid token is cached.
     */
    public function get_cached_token(): string {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return '';
        }
        $expires = (int) get_user_meta( $user_id, '_ems_osm_token_expires', true );
        if ( $expires < time() ) {
            return '';
        }
        $encrypted = (string) get_user_meta( $user_id, '_ems_osm_token', true );
        return Encryption::decrypt( $encrypted ) ?: '';
    }

    /**
     * Stores the access token encrypted in current user meta with an expiry.
     */
    private function cache_token( string $token, int $expires_in ): void {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return;
        }
        $encrypted = Encryption::encrypt( $token );
        if ( $encrypted ) {
            update_user_meta( $user_id, '_ems_osm_token', $encrypted );
            update_user_meta( $user_id, '_ems_osm_token_expires', time() + max( 0, $expires_in - 300 ) );
        }
    }

    /**
     * Clears the cached token for the current user.
     */
    public function clear_cached_token(): void {
        $user_id = get_current_user_id();
        if ( $user_id ) {
            delete_user_meta( $user_id, '_ems_osm_token' );
            delete_user_meta( $user_id, '_ems_osm_token_expires' );
        }
    }

    /**
     * Exchanges the authorization code for an access token.
     */
    private function exchange_code_for_token( string $code ) {
        if ( ! str_starts_with( $this->token_url, 'https://' ) ) {
            return new \WP_Error( 'osm_token_error', 'Token URL must use HTTPS to protect client credentials.' );
        }

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

        $http_status = (int) wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );
        $data        = json_decode( $body, true );

        if ( $http_status < 200 || $http_status >= 300 ) {
            $detail = is_array( $data ) ? ( $data['error_description'] ?? $data['error'] ?? '' ) : '';
            return new \WP_Error( 'osm_token_error', trim( "HTTP {$http_status} from token endpoint. {$detail}" ) );
        }

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
