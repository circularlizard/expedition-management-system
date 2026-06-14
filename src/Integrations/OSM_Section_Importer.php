<?php
namespace EMS\Integrations;

/**
 * Handles importing section members from OSM into WordPress users.
 */
class OSM_Section_Importer {

    private OSM_API_Client $api_client;

    public function __construct( OSM_API_Client $api_client ) {
        $this->api_client = $api_client;
    }

    /**
     * Imports members for all managed sections.
     */
    public function import_all(): void {
        $managed_sections = (array) get_option( 'ems_managed_sections', [] );
        $section_ids      = array_keys( $managed_sections );

        foreach ( $section_ids as $section_id ) {
            $this->import_section( (int) $section_id );
        }
    }

    /**
     * Imports members for a list of section IDs.
     *
     * @param int[] $section_ids
     */
    public function import_sections( array $section_ids ): void {
        foreach ( $section_ids as $section_id ) {
            $this->import_section( (int) $section_id );
        }
    }

    /**
     * Imports members for a specific section.
     */
    public function import_section( int $section_id ): void {
        $members = $this->api_client->get_section_participants( $section_id );

        foreach ( $members as $member ) {
            $this->upsert_member( $member );
        }
    }

    /**
     * Upserts a single member into WordPress.
     */
    private function upsert_member( array $member ): void {
        $scout_id = (int) $member['member_id'];
        if ( ! $scout_id ) {
            return;
        }

        // 1. Find existing user by ems_scout_id
        $users = get_users( [
            'meta_key'   => 'ems_scout_id',
            'meta_value' => $scout_id,
            'number'     => 1,
        ] );

        $user_id = ! empty( $users ) ? $users[0]->ID : 0;

        // 2. If not found, try to create a new user
        if ( ! $user_id ) {
            $user_id = $this->create_new_user( $member );
        }

        if ( ! $user_id ) {
            return;
        }

        // 3. Update user meta
        update_user_meta( $user_id, 'ems_scout_id', $scout_id );
        update_user_meta( $user_id, 'ems_first_name', $member['first_name'] );
        update_user_meta( $user_id, 'ems_last_name', $member['last_name'] );
        update_user_meta( $user_id, 'ems_explorer_email', $member['email'] );
        update_user_meta( $user_id, 'ems_parent_email', $member['parent_email'] );
        update_user_meta( $user_id, 'ems_unit', $member['patrol'] );
    }

    /**
     * Creates a new WordPress user for an OSM member.
     */
    private function create_new_user( array $member ): int {
        $email = $member['email'] ?: $member['parent_email'] ?: '';
        
        // Handle missing email gracefully
        if ( ! $email ) {
            $user_login = 'scout_' . $member['member_id'];
            $user_email = $user_login . '@ems-local.arpa';
        } else {
            $user_login = $email;
            $user_email = $email;
        }

        // Check if user_login already exists (e.g. email used by another account)
        if ( username_exists( $user_login ) || email_exists( $user_email ) ) {
            // If it exists but wasn't found by ems_scout_id, we might have a collision.
            // For safety, we append the scout ID.
            $user_login .= '_' . $member['member_id'];
            $user_email = $member['member_id'] . '_' . $user_email;
        }

        $user_id = wp_insert_user( [
            'user_login' => $user_login,
            'user_pass'  => wp_generate_password(),
            'user_email' => $user_email,
            'first_name' => $member['first_name'],
            'last_name'  => $member['last_name'],
            'role'       => 'subscriber',
        ] );

        return is_wp_error( $user_id ) ? 0 : $user_id;
    }
}
