<?php
namespace EMS\Integrations;

class OSM_Auth_Integration {
    public function __construct() {
        // Hook into the login-with-google plugin
        add_action( 'rtcamp.google_user_logged_in', [ $this, 'handle_osm_login' ], 10, 2 );
    }

    /**
     * Handle data capture after successful OSM OIDC login
     *
     * @param \WP_User $user The WP user object
     * @param array $data Raw data returned from the OAuth provider
     */
    public function handle_osm_login( \WP_User $user, array $data ): void {
        // Capture OSM Record ID
        if ( isset( $data['osm_id'] ) ) {
            update_user_meta( $user->ID, 'ems_osm_id', $data['osm_id'] );
        }

        // Capture Access Token for API calls (session-only for security)
        if ( isset( $data['access_token'] ) ) {
            if ( ! session_id() ) {
                session_start();
            }
            $_SESSION['ems_osm_access_token'] = $data['access_token'];
        }

        // Update basic info
        update_user_meta( $user->ID, 'ems_unit', $data['patrol'] ?? '' );
    }
}
