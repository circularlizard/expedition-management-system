<?php
namespace EMS\Integrations;

class OSM_Auth_Integration {
    private OSM_API_Client $api_client;
    private OSM_Parser $parser;

    public function __construct( OSM_API_Client $api_client, OSM_Parser $parser ) {
        $this->api_client = $api_client;
        $this->parser     = $parser;
        add_action( 'rtcamp.google_user_logged_in', [ $this, 'handle_osm_login' ], 10, 2 );
    }

    /**
     * Handle data capture after successful OSM OIDC login.
     *
     * Fetches getDataPayload from OSM using the one-time access token, persists
     * hydration context to User Meta, then discards the token (ADR 009).
     *
     * @param \WP_User $user The WP user object
     * @param array    $data Raw data returned from the OAuth provider
     */
    public function handle_osm_login( \WP_User $user, array $data ): void {
        if ( isset( $data['osm_id'] ) ) {
            update_user_meta( $user->ID, 'ems_osm_id', $data['osm_id'] );
        }

        update_user_meta( $user->ID, 'ems_unit', $data['patrol'] ?? '' );

        if ( ! empty( $data['access_token'] ) ) {
            $payload = $this->api_client->get_data_payload( $data['access_token'] );

            if ( ! empty( $payload ) ) {
                update_user_meta( $user->ID, 'ems_access_type', $this->parser->parse_access_type( $payload ) );
                update_user_meta( $user->ID, 'ems_scout_ids',   $this->parser->parse_scout_ids( $payload ) );
                update_user_meta( $user->ID, 'ems_section_ids', $this->parser->parse_section_ids( $payload ) );

                $children = $this->parser->parse_children( $payload );
                if ( ! empty( $children ) ) {
                    update_user_meta( $user->ID, 'ems_children', $children );
                }
            }
            // Access token intentionally NOT stored — ADR 009.
        }
    }
}
