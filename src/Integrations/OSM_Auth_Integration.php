<?php
namespace EMS\Integrations;

use EMS\Data\OSM_Explorer_Repository;

class OSM_Auth_Integration {
    private OSM_API_Client $api_client;
    private OSM_Parser $parser;
    private ?OSM_Explorer_Repository $explorer_repo;

    public function __construct(
        OSM_API_Client $api_client,
        OSM_Parser $parser,
        ?OSM_Explorer_Repository $explorer_repo = null
    ) {
        $this->api_client    = $api_client;
        $this->parser        = $parser;
        $this->explorer_repo = $explorer_repo;
        add_action( 'rtcamp.google_user_logged_in', [ $this, 'handle_osm_login' ], 10, 2 );
        add_action( 'rtcamp.google_user_created',   [ $this, 'handle_user_created' ], 10, 2 );
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
            $this->api_client->set_access_token( $data['access_token'] );
            $payload = $this->api_client->get_data_payload();

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
        } else {
            update_user_meta( $user->ID, 'ems_access_type', 'local' );
        }

        $this->maybe_link_explorer( $user );
    }

    /**
     * Handle new WP user registration via OIDC (rtcamp.google_user_created).
     *
     * @param int       $user_id  The new WP user ID.
     * @param \stdClass $raw_user Raw user data from the provider.
     */
    public function handle_user_created( int $user_id, \stdClass $raw_user ): void {
        $user = get_user_by( 'id', $user_id );
        if ( ! $user instanceof \WP_User ) {
            return;
        }
        $this->maybe_link_explorer( $user );
    }

    /**
     * Links the WP user to a matching explorer record by email, if a repository is available.
     */
    private function maybe_link_explorer( \WP_User $user ): void {
        if ( $this->explorer_repo === null ) {
            return;
        }
        if ( empty( $user->user_email ) ) {
            return;
        }
        $this->explorer_repo->link_wp_user_by_email( $user->user_email, $user->ID );
    }
}
