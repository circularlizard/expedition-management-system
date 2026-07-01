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
     * Create a new signup record
     */
    public function create_signup( array $data ): int {
        $now = current_time( 'mysql' );
        
        $insert_data = [
            'scout_id'               => ! empty( $data['scout_id'] ) ? (int) $data['scout_id'] : null,
            'parent_user_id'         => (int) ($data['parent_user_id'] ?? get_current_user_id()),
            'unit_id'                => ! empty( $data['unit_id'] ) ? (int) $data['unit_id'] : null,
            'explorer_first_name'    => sanitize_text_field( $data['explorer_first_name'] ?? '' ),
            'explorer_last_name'     => sanitize_text_field( $data['explorer_last_name'] ?? '' ),
            'dofe_level'             => strtolower( sanitize_text_field( $data['dofe_level'] ?? '' ) ),
            'expedition_preferences' => ! empty( $data['expedition_preferences'] ) ? ( is_array( $data['expedition_preferences'] ) ? json_encode( $data['expedition_preferences'] ) : $data['expedition_preferences'] ) : null,
            'first_aid_status'       => sanitize_text_field( $data['first_aid_status'] ?? 'none' ),
            'signup_status'          => sanitize_text_field( $data['signup_status'] ?? 'pending' ),
            'payment_status'         => sanitize_text_field( $data['payment_status'] ?? 'pending' ),
            'form_submission_id'     => (int) ( $data['form_submission_id'] ?? 0 ),
            'created_at'             => $now,
            'updated_at'             => $now,
        ];

        $format = [
            '%d', // scout_id
            '%d', // parent_user_id
            '%d', // unit_id
            '%s', // explorer_first_name
            '%s', // explorer_last_name
            '%s', // dofe_level
            '%s', // expedition_preferences
            '%s', // first_aid_status
            '%s', // signup_status
            '%s', // payment_status
            '%d', // form_submission_id
            '%s', // created_at
            '%s', // updated_at
        ];

        $result = $this->wpdb->insert(
            "{$this->wpdb->prefix}ems_signups",
            $insert_data,
            $format
        );

        if ( $result === false ) {
            return 0;
        }

        return (int) $this->wpdb->insert_id;
    }

    /**
     * Get a signup record by ID
     */
    public function get_signup( int $id ): ?array {
        $sql = "SELECT * FROM {$this->wpdb->prefix}ems_signups WHERE id = %d";
        $row = $this->wpdb->get_row( $this->wpdb->prepare( $sql, $id ), ARRAY_A );
        return $row ?: null;
    }

    /**
     * Get a signup record by form submission ID
     */
    public function get_signup_by_submission_id( int $submission_id ): ?array {
        $sql = "SELECT * FROM {$this->wpdb->prefix}ems_signups WHERE form_submission_id = %d";
        $row = $this->wpdb->get_row( $this->wpdb->prepare( $sql, $submission_id ), ARRAY_A );
        return $row ?: null;
    }

    /**
     * Update payment status of a signup by Fluent Form submission entry ID
     */
    public function update_payment_status_by_submission_id( int $form_submission_id, string $status ): bool {
        $result = $this->wpdb->update(
            "{$this->wpdb->prefix}ems_signups",
            [
                'payment_status' => sanitize_text_field( $status ),
                'updated_at'     => current_time( 'mysql' ),
            ],
            [ 'form_submission_id' => $form_submission_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        return $result !== false;
    }

    /**
     * Get all signups
     */
    public function get_all_signups(): array {
        $sql = "SELECT * FROM {$this->wpdb->prefix}ems_signups ORDER BY created_at DESC";
        return $this->wpdb->get_results( $sql, ARRAY_A ) ?: [];
    }
}
