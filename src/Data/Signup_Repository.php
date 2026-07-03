<?php
namespace EMS\Data;

class Signup_Repository {
    private object $wpdb;

    public function __construct( ?object $wpdb = null ) {
        if ( $wpdb === null ) {
            global $wpdb;
        }
        $this->wpdb = $wpdb;
    }

    /**
     * Create a new Participant Place signup
     */
    public function create_participant_signup( array $data ): int {
        $now = current_time( 'mysql' );
        
        $insert_data = [
            'scout_id'            => (int) ($data['scout_id'] ?? 0),
            'parent_user_id'      => (int) ($data['parent_user_id'] ?? get_current_user_id()),
            'unit_id'             => ! empty( $data['unit_id'] ) ? (int) $data['unit_id'] : null,
            'unit_name'           => sanitize_text_field( $data['unit_name'] ?? '' ),
            'explorer_first_name' => sanitize_text_field( $data['explorer_first_name'] ?? '' ),
            'explorer_last_name'  => sanitize_text_field( $data['explorer_last_name'] ?? '' ),
            'explorer_email'      => sanitize_email( $data['explorer_email'] ?? '' ),
            'parent_email'        => sanitize_email( $data['parent_email'] ?? '' ),
            'dofe_level'          => strtolower( sanitize_text_field( $data['dofe_level'] ?? '' ) ),
            'dob'                 => ! empty( $data['dob'] ) ? sanitize_text_field( $data['dob'] ) : null,
            'dofe_registered'     => sanitize_text_field( $data['dofe_registered'] ?? 'n' ),
            'dofe_number'         => ! empty( $data['dofe_number'] ) ? sanitize_text_field( $data['dofe_number'] ) : null,
            'dofe_org'            => ! empty( $data['dofe_org'] ) ? sanitize_text_field( $data['dofe_org'] ) : null,
            'bronze_completion'   => ! empty( $data['bronze_completion'] ) ? ( is_array( $data['bronze_completion'] ) ? json_encode( $data['bronze_completion'] ) : $data['bronze_completion'] ) : null,
            'silver_completion'   => ! empty( $data['silver_completion'] ) ? ( is_array( $data['silver_completion'] ) ? json_encode( $data['silver_completion'] ) : $data['silver_completion'] ) : null,
            'signup_status'       => sanitize_text_field( $data['signup_status'] ?? 'received' ),
            'payment_status'      => sanitize_text_field( $data['payment_status'] ?? 'pending' ),
            'form_submission_id'  => (int) ( $data['form_submission_id'] ?? 0 ),
            'created_at'          => $now,
            'updated_at'          => $now,
        ];

        $format = [
            '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s'
        ];

        $result = $this->wpdb->insert(
            "{$this->wpdb->prefix}ems_participant_signups",
            $insert_data,
            $format
        );

        if ( $result === false ) {
            return 0;
        }

        return (int) $this->wpdb->insert_id;
    }

    /**
     * Create a new Expedition signup
     */
    public function create_expedition_signup( array $data ): int {
        $now = current_time( 'mysql' );
        
        $insert_data = [
            'scout_id'                 => (int) ($data['scout_id'] ?? 0),
            'parent_user_id'           => (int) ($data['parent_user_id'] ?? get_current_user_id()),
            'unit_id'                  => ! empty( $data['unit_id'] ) ? (int) $data['unit_id'] : null,
            'unit_name'                => sanitize_text_field( $data['unit_name'] ?? '' ),
            'explorer_first_name'      => sanitize_text_field( $data['explorer_first_name'] ?? '' ),
            'explorer_last_name'       => sanitize_text_field( $data['explorer_last_name'] ?? '' ),
            'explorer_email'           => sanitize_email( $data['explorer_email'] ?? '' ),
            'parent_email'             => sanitize_email( $data['parent_email'] ?? '' ),
            'dofe_level'               => strtolower( sanitize_text_field( $data['dofe_level'] ?? '' ) ),
            'dofe_number'              => ! empty( $data['dofe_number'] ) ? sanitize_text_field( $data['dofe_number'] ) : null,
            'expedition_preferences'   => ! empty( $data['expedition_preferences'] ) ? ( is_array( $data['expedition_preferences'] ) ? json_encode( $data['expedition_preferences'] ) : $data['expedition_preferences'] ) : null,
            'additional_support_needs' => ! empty( $data['additional_support_needs'] ) ? sanitize_textarea_field( $data['additional_support_needs'] ) : null,
            'first_aid_status'         => sanitize_text_field( $data['first_aid_status'] ?? 'none' ),
            'first_aid_expiry'         => ! empty( $data['first_aid_expiry'] ) ? sanitize_text_field( $data['first_aid_expiry'] ) : null,
            'signup_status'            => sanitize_text_field( $data['signup_status'] ?? 'pending' ),
            'payment_status'           => sanitize_text_field( $data['payment_status'] ?? 'pending' ),
            'form_submission_id'       => (int) ( $data['form_submission_id'] ?? 0 ),
            'created_at'               => $now,
            'updated_at'               => $now,
        ];

        $format = [
            '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s'
        ];

        $result = $this->wpdb->insert(
            "{$this->wpdb->prefix}ems_expedition_signups",
            $insert_data,
            $format
        );

        if ( $result === false ) {
            return 0;
        }

        return (int) $this->wpdb->insert_id;
    }

    /**
     * Get a participant signup record by ID
     */
    public function get_participant_signup( int $id ): ?array {
        $sql = "SELECT * FROM {$this->wpdb->prefix}ems_participant_signups WHERE id = %d";
        $row = $this->wpdb->get_row( $this->wpdb->prepare( $sql, $id ), ARRAY_A );
        return $row ?: null;
    }

    /**
     * Get an expedition signup record by ID
     */
    public function get_expedition_signup( int $id ): ?array {
        $sql = "SELECT * FROM {$this->wpdb->prefix}ems_expedition_signups WHERE id = %d";
        $row = $this->wpdb->get_row( $this->wpdb->prepare( $sql, $id ), ARRAY_A );
        return $row ?: null;
    }

    /**
     * Update payment status of a signup by Fluent Form submission entry ID
     */
    public function update_payment_status_by_submission_id( int $form_submission_id, string $status ): bool {
        $now = current_time( 'mysql' );
        $sanitized_status = sanitize_text_field( $status );

        $p_result = $this->wpdb->update(
            "{$this->wpdb->prefix}ems_participant_signups",
            [ 'payment_status' => $sanitized_status, 'updated_at' => $now ],
            [ 'form_submission_id' => $form_submission_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        $e_result = $this->wpdb->update(
            "{$this->wpdb->prefix}ems_expedition_signups",
            [ 'payment_status' => $sanitized_status, 'updated_at' => $now ],
            [ 'form_submission_id' => $form_submission_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        return $p_result !== false || $e_result !== false;
    }

    /**
     * Get participant signups filtered by status
     */
    public function get_participant_signups( string $status = 'all' ): array {
        if ( $status === 'all' ) {
            $sql = "SELECT * FROM {$this->wpdb->prefix}ems_participant_signups ORDER BY created_at DESC";
            return $this->wpdb->get_results( $sql, ARRAY_A ) ?: [];
        }

        $db_status = 'received';
        if ( $status === 'allocated' ) {
            $db_status = 'allocated';
        } elseif ( $status === 'archived' ) {
            $db_status = 'archived';
        }

        $sql = "SELECT * FROM {$this->wpdb->prefix}ems_participant_signups WHERE signup_status = %s ORDER BY created_at DESC";
        return $this->wpdb->get_results( $this->wpdb->prepare( $sql, $db_status ), ARRAY_A ) ?: [];
    }

    /**
     * Get expedition signups filtered by status
     */
    public function get_expedition_signups( string $status = 'all' ): array {
        if ( $status === 'all' ) {
            $sql = "SELECT * FROM {$this->wpdb->prefix}ems_expedition_signups ORDER BY created_at DESC";
            return $this->wpdb->get_results( $sql, ARRAY_A ) ?: [];
        }

        $db_status = 'pending';
        if ( $status === 'processed' ) {
            $db_status = 'processed';
        } elseif ( $status === 'archived' ) {
            $db_status = 'archived';
        }

        $sql = "SELECT * FROM {$this->wpdb->prefix}ems_expedition_signups WHERE signup_status = %s ORDER BY created_at DESC";
        return $this->wpdb->get_results( $this->wpdb->prepare( $sql, $db_status ), ARRAY_A ) ?: [];
    }

    /**
     * Process (allocate) a participant place signup
     */
    public function process_participant_signup( int $id, int $user_id, ?string $dofe_number = null ): bool {
        $update_data = [
            'signup_status' => 'allocated',
            'processed_by'  => $user_id,
            'processed_at'  => current_time( 'mysql' ),
            'updated_at'    => current_time( 'mysql' ),
        ];

        $format = [ '%s', '%d', '%s', '%s' ];

        if ( ! empty( $dofe_number ) ) {
            $update_data['dofe_number'] = sanitize_text_field( $dofe_number );
            $format[] = '%s';
        }

        $result = $this->wpdb->update(
            "{$this->wpdb->prefix}ems_participant_signups",
            $update_data,
            [ 'id' => $id ],
            $format,
            [ '%d' ]
        );
        return $result !== false;
    }

    /**
     * Process an expedition signup
     */
    public function process_expedition_signup( int $id, int $user_id ): bool {
        $result = $this->wpdb->update(
            "{$this->wpdb->prefix}ems_expedition_signups",
            [
                'signup_status' => 'processed',
                'processed_by'  => $user_id,
                'processed_at'  => current_time( 'mysql' ),
                'updated_at'    => current_time( 'mysql' ),
            ],
            [ 'id' => $id ],
            [ '%s', '%d', '%s', '%s' ],
            [ '%d' ]
        );
        return $result !== false;
    }

    /**
     * Archive a participant signup
     */
    public function archive_participant_signup( int $id ): bool {
        $result = $this->wpdb->update(
            "{$this->wpdb->prefix}ems_participant_signups",
            [
                'signup_status' => 'archived',
                'updated_at'    => current_time( 'mysql' ),
            ],
            [ 'id' => $id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
        return $result !== false;
    }

    /**
     * Archive an expedition signup
     */
    public function archive_expedition_signup( int $id ): bool {
        $result = $this->wpdb->update(
            "{$this->wpdb->prefix}ems_expedition_signups",
            [
                'signup_status' => 'archived',
                'updated_at'    => current_time( 'mysql' ),
            ],
            [ 'id' => $id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
        return $result !== false;
    }
}
