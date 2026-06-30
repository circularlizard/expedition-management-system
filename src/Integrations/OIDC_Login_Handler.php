<?php
namespace EMS\Integrations;

use EMS\Data\OSM_Explorer_Repository;

class OIDC_Login_Handler {
    private OSM_API_Client $api_client;
    private OSM_Parser $parser;
    private ?OSM_Explorer_Repository $explorer_repo;

    private static string $captured_token = '';

    public function __construct(
        OSM_API_Client $api_client,
        OSM_Parser $parser,
        ?OSM_Explorer_Repository $explorer_repo = null
    ) {
        $this->api_client    = $api_client;
        $this->parser        = $parser;
        $this->explorer_repo = $explorer_repo;
        add_action( 'rtcamp.google_user_logged_in', [ $this, 'handle_osm_login'    ], 10, 2 );
        add_action( 'rtcamp.google_user_created',   [ $this, 'handle_user_created' ], 10, 2 );
        add_filter( 'http_response',                [ $this, 'capture_token_from_response' ], 10, 3 );
    }

    /**
     * Captures the access token from the HTTP response when the OAuth provider token handshake occurs.
     */
    public function capture_token_from_response( $response, array $parsed_args, string $url ) {
        if ( is_array( $response ) && isset( $response['body'] ) ) {
            $body = json_decode( $response['body'], true );
            if ( is_array( $body ) && ! empty( $body['access_token'] ) ) {
                if ( str_contains( $url, '/oauth/token' ) || str_contains( $url, '/token' ) ) {
                    self::$captured_token = $body['access_token'];
                }
            }
        }
        return $response;
    }

    /**
     * Handle data capture after successful OSM OIDC login.
     *
     * Fetches getDataPayload from OSM using the one-time access token, persists
     * hydration context to User Meta, then discards the token (ADR 009).
     *
     * @param \WP_User     $user The WP user object
     * @param array|object $data Raw data returned from the OAuth provider
     */
    public function handle_osm_login( \WP_User $user, $data ): void {
        $data = (array) $data;
        if ( isset( $data['osm_id'] ) ) {
            update_user_meta( $user->ID, 'ems_osm_id', $data['osm_id'] );
        }

        update_user_meta( $user->ID, 'ems_unit', $data['patrol'] ?? '' );

        $access_type = 'local';
        $section_ids = [];

        $token = $data['access_token'] ?? '';
        if ( empty( $token ) ) {
            $token = self::$captured_token;
        }

        if ( ! empty( $token ) ) {
            $this->api_client->set_access_token( $token );
            $payload = $this->api_client->get_data_payload();

            if ( ! empty( $payload ) ) {
                // Validation: Check for globals & member_access
                if ( ! isset( $payload['data']['globals'] ) || ! isset( $payload['data']['globals']['member_access'] ) ) {
                    error_log( '[EMS] OIDC Omit: payload is missing critical globals or member_access fields' );
                    $this->maybe_link_explorer( $user );
                    return;
                }

                $access_type = $this->parser->parse_access_type( $payload );
                $scout_ids   = $this->parser->parse_scout_ids( $payload );
                $section_ids = $this->parser->parse_section_ids( $payload );

                update_user_meta( $user->ID, 'ems_access_type', $access_type );
                update_user_meta( $user->ID, 'ems_scout_ids',   $scout_ids );
                update_user_meta( $user->ID, 'ems_section_ids', $section_ids );

                $children = $this->parser->parse_children( $payload );
                if ( ! empty( $children ) ) {
                    update_user_meta( $user->ID, 'ems_children', $children );
                }
            }
            // Access token intentionally NOT stored — ADR 009.
        } else {
            update_user_meta( $user->ID, 'ems_access_type', 'local' );
        }

        $this->assign_user_role( $user, $access_type, $section_ids );
        $this->maybe_link_explorer( $user );
    }

    /**
     * Assigns the appropriate custom WordPress role to the user based on access type and section IDs.
     */
    private function assign_user_role( \WP_User $user, string $access_type, array $section_ids ): void {
        $target_role = '';
        if ( $access_type === 'member' ) {
            $target_role = 'ems_explorer';
        } elseif ( $access_type === 'parent' ) {
            $target_role = 'ems_parent';
        } elseif ( $access_type === 'local' || ! empty( $section_ids ) ) {
            $target_role = 'ems_leader';
        }

        if ( ! empty( $target_role ) ) {
            $user->set_role( $target_role );
        }
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
        $this->handle_osm_login( $user, $raw_user );
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
