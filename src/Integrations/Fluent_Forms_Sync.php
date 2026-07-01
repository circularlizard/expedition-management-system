<?php
namespace EMS\Integrations;

use EMS\Data\Signup_Repository;
use EMS\Data\Unit_Repository;

class Fluent_Forms_Sync {
    private Signup_Repository $signup_repo;
    private Unit_Repository $unit_repo;
    private object $wpdb;

    public function __construct( ?Signup_Repository $signup_repo = null, ?Unit_Repository $unit_repo = null, ?object $wpdb = null ) {
        if ( $wpdb === null ) {
            global $wpdb;
        }
        $this->wpdb = $wpdb;
        $this->signup_repo = $signup_repo ?: new Signup_Repository( $wpdb );
        $this->unit_repo = $unit_repo ?: new Unit_Repository( $wpdb );
    }

    public function init_hooks(): void {
        // Dropdown dynamic choices population filter
        add_filter( 'fluentform/rendering_field_data_select', [ $this, 'populate_child_dropdown' ], 10, 2 );

        // Validation bypass for dynamically generated dropdown choices
        add_filter( 'fluentform/validate_input_item_select', [ $this, 'bypass_dropdown_validation' ], 10, 2 );

        // Dynamic pre-population of unit default value
        add_filter( 'fluentform/input_default_value_signup_unit', [ $this, 'get_default_unit_value' ], 10, 2 );
        add_filter( 'fluentform/input_default_value_hidden_1', [ $this, 'get_default_unit_id_value' ], 10, 2 );

        // Form validation hook
        add_filter( 'fluentform/validation_errors', [ $this, 'validate_submission' ], 10, 2 );

        // Form submission callback
        add_action( 'fluentform/submission_inserted', [ $this, 'handle_submission' ], 10, 3 );

        // Stripe Payment status callbacks
        add_action( 'fluentform/payment_status_updated', [ $this, 'handle_payment_status' ], 10, 2 );
    }

    /**
     * Dynamically populate child dropdown select field
     */
    public function populate_child_dropdown( array $data, $form ): array {
        if ( ( $data['attributes']['name'] ?? '' ) !== 'signup_child' ) {
            return $data;
        }

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return $data;
        }

        $children_meta = get_user_meta( $user_id, 'ems_children', true );
        if ( empty( $children_meta ) || ! is_array( $children_meta ) ) {
            return $data;
        }

        $options = [];
        foreach ( $children_meta as $child ) {
            $scout_id = (int) ( $child['scout_id'] ?? 0 );
            if ( ! $scout_id ) {
                continue;
            }

            // Resolve name from synced local explorers table
            $explorer = $this->wpdb->get_row(
                $this->wpdb->prepare(
                    "SELECT first_name, last_name FROM {$this->wpdb->prefix}ems_osm_explorers WHERE scout_id = %d",
                    $scout_id
                ),
                ARRAY_A
            );

            $first_name = $explorer['first_name'] ?? $child['first_name'] ?? '';
            $last_name  = $explorer['last_name']  ?? $child['last_name']  ?? '';
            $label      = trim( "{$first_name} {$last_name}" ) ?: "Explorer #{$scout_id}";
            $value      = "{$scout_id}|{$first_name}|{$last_name}";

            $options[] = [
                'label'      => $label,
                'value'      => $value,
                'calc_value' => '',
            ];
        }

        if ( ! empty( $options ) ) {
            $data['settings']['advanced_options'] = $options;
        }

        return $data;
    }

    /**
     * Bypass Fluent Forms dropdown mismatch validation
     */
    public function bypass_dropdown_validation( array $errors, $field ): array {
        if ( ( $field['attributes']['name'] ?? '' ) === 'signup_child' ) {
            return [];
        }
        return $errors;
    }

    /**
     * Fetch default unit short code based on parent's children section IDs
     */
    public function get_default_unit_value( $default_val, $field ) {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return $default_val;
        }

        $children_meta = get_user_meta( $user_id, 'ems_children', true );
        if ( empty( $children_meta ) || ! is_array( $children_meta ) ) {
            return $default_val;
        }

        foreach ( $children_meta as $child ) {
            $scout_id = (int) ( $child['scout_id'] ?? 0 );
            if ( ! $scout_id ) {
                continue;
            }

            $explorer = $this->wpdb->get_row(
                $this->wpdb->prepare(
                    "SELECT section_id FROM {$this->wpdb->prefix}ems_osm_explorers WHERE scout_id = %d",
                    $scout_id
                ),
                ARRAY_A
            );

            $section_ids = ! empty( $explorer['section_id'] ) ? [ (int) $explorer['section_id'] ] : (array) ( $child['section_ids'] ?? [] );
            if ( empty( $section_ids ) ) {
                continue;
            }

            foreach ( $section_ids as $sec_id ) {
                $unit = $this->wpdb->get_row(
                    $this->wpdb->prepare(
                        "SELECT short_code FROM {$this->wpdb->prefix}ems_units WHERE section_id = %d AND active = 1 LIMIT 1",
                        $sec_id
                    ),
                    ARRAY_A
                );

                if ( ! empty( $unit['short_code'] ) ) {
                    return $unit['short_code'];
                }
            }
        }

        return $default_val;
    }

    /**
     * Fetch default unit ID based on parent's children section IDs
     */
    public function get_default_unit_id_value( $default_val, $field ) {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return $default_val;
        }

        $children_meta = get_user_meta( $user_id, 'ems_children', true );
        if ( empty( $children_meta ) || ! is_array( $children_meta ) ) {
            return $default_val;
        }

        foreach ( $children_meta as $child ) {
            $scout_id = (int) ( $child['scout_id'] ?? 0 );
            if ( ! $scout_id ) {
                continue;
            }

            $explorer = $this->wpdb->get_row(
                $this->wpdb->prepare(
                    "SELECT section_id FROM {$this->wpdb->prefix}ems_osm_explorers WHERE scout_id = %d",
                    $scout_id
                ),
                ARRAY_A
            );

            $section_ids = ! empty( $explorer['section_id'] ) ? [ (int) $explorer['section_id'] ] : (array) ( $child['section_ids'] ?? [] );
            if ( empty( $section_ids ) ) {
                continue;
            }

            foreach ( $section_ids as $sec_id ) {
                $unit = $this->wpdb->get_row(
                    $this->wpdb->prepare(
                        "SELECT unit_id FROM {$this->wpdb->prefix}ems_units WHERE section_id = %d AND active = 1 LIMIT 1",
                        $sec_id
                    ),
                    ARRAY_A
                );

                if ( ! empty( $unit['unit_id'] ) ) {
                    return (int) $unit['unit_id'];
                }
            }
        }

        return $default_val;
    }

    /**
     * Validate submission constraints
     */
    public function validate_submission( array $errors, $form ): array {
        $mappings = get_option( 'ems_form_mappings', [] );
        $form_id = (int) ( is_array( $form ) ? ( $form['id'] ?? 0 ) : ( $form->id ?? 0 ) );
        
        if ( ! isset( $mappings[ $form_id ] ) ) {
            return $errors;
        }

        $config = $mappings[ $form_id ];
        $scout_field = $config['scout_id_field'] ?? 'signup_child';
        $level_field = $config['dofe_level_field'] ?? 'signup_level';

        $submitted_child = $_POST[ $scout_field ] ?? '';
        $submitted_level = strtolower( sanitize_text_field( $_POST[ $level_field ] ?? '' ) );

        if ( ! empty( $submitted_child ) ) {
            $parts = explode( '|', $submitted_child );
            $scout_id = (int) $parts[0];

            $user_id = get_current_user_id();
            $children_meta = get_user_meta( $user_id, 'ems_children', true ) ?: [];
            $allowed_ids = array_map( 'intval', array_column( $children_meta, 'scout_id' ) );

            if ( ! in_array( $scout_id, $allowed_ids, true ) ) {
                $errors[ $scout_field ] = [ __( 'You do not have permission to register this child.', 'ems-plugin' ) ];
            }
        }

        if ( ! empty( $submitted_level ) && ! in_array( $submitted_level, [ 'bronze', 'silver', 'gold' ], true ) ) {
            $errors[ $level_field ] = [ __( 'Invalid DofE Level selected.', 'ems-plugin' ) ];
        }

        return $errors;
    }

    /**
     * Handle Fluent Forms successful submission inserting
     */
    public function handle_submission( $entryId, $formData, $form ): void {
        $mappings = get_option( 'ems_form_mappings', [] );
        $form_id = (int) ( is_array( $form ) ? ( $form['id'] ?? 0 ) : ( $form->id ?? 0 ) );

        if ( ! isset( $mappings[ $form_id ] ) ) {
            return;
        }

        $config = $mappings[ $form_id ];
        $scout_field      = $config['scout_id_field'] ?? 'signup_child';
        $first_name_field = $config['first_name_field'] ?? '';
        $last_name_field  = $config['last_name_field'] ?? '';
        $level_field      = $config['dofe_level_field'] ?? 'signup_level';
        $patrol_field     = $config['esu_patrol_field'] ?? 'signup_unit';
        $first_aid_field  = $config['first_aid_field'] ?? 'input_radio';
        $pref_keys        = $config['pref_fields'] ?? [];

        $submitted_child = $formData[ $scout_field ] ?? '';
        $scout_id        = null;
        $first_name      = '';
        $last_name       = '';

        if ( ! empty( $submitted_child ) ) {
            $parts = explode( '|', $submitted_child );
            $scout_id = (int) $parts[0];
            $first_name = $parts[1] ?? '';
            $last_name  = $parts[2] ?? '';
        }

        if ( empty( $first_name ) && ! empty( $first_name_field ) ) {
            $first_name = $formData[ $first_name_field ] ?? '';
        }
        if ( empty( $last_name ) && ! empty( $last_name_field ) ) {
            $last_name = $formData[ $last_name_field ] ?? '';
        }

        $patrol_value = $formData[ $patrol_field ] ?? '';
        $unit_id      = null;

        if ( ! empty( $patrol_value ) ) {
            $unit = $this->wpdb->get_row(
                $this->wpdb->prepare(
                    "SELECT unit_id FROM {$this->wpdb->prefix}ems_units WHERE short_code = %s AND active = 1 LIMIT 1",
                    $patrol_value
                ),
                ARRAY_A
            );
            if ( ! empty( $unit['unit_id'] ) ) {
                $unit_id = (int) $unit['unit_id'];
            }
        }

        // Serialize preference fields
        $preferences = [];
        foreach ( $pref_keys as $key ) {
            if ( isset( $formData[ $key ] ) ) {
                $preferences[ $key ] = $formData[ $key ];
            }
        }

        $signup_data = [
            'scout_id'               => $scout_id,
            'parent_user_id'         => get_current_user_id() ?: 1,
            'unit_id'                => $unit_id,
            'explorer_first_name'    => $first_name,
            'explorer_last_name'     => $last_name,
            'dofe_level'             => $formData[ $level_field ] ?? '',
            'expedition_preferences' => $preferences,
            'first_aid_status'       => $formData[ $first_aid_field ] ?? 'none',
            'form_submission_id'     => $entryId,
            'payment_status'         => 'pending',
            'signup_status'          => 'pending',
        ];

        $signup_id = $this->signup_repo->create_signup( $signup_data );

        // Send notifications
        if ( $signup_id > 0 ) {
            $this->send_notifications( $signup_data, $patrol_value );
        }
    }

    /**
     * Handle Stripe/Fluent Forms payment status changes
     */
    public function handle_payment_status( $submission, $status ): void {
        $entryId = (int) ( $submission->id ?? $submission );
        if ( $entryId > 0 ) {
            $mapped_status = ( $status === 'completed' || $status === 'paid' ) ? 'paid' : 'pending';
            $this->signup_repo->update_payment_status_by_submission_id( $entryId, $mapped_status );
        }
    }

    /**
     * Send email notifications using wp_mail
     */
    private function send_notifications( array $signup, string $patrol_code ): void {
        $parent_user = get_userdata( $signup['parent_user_id'] );
        $parent_email = $parent_user ? $parent_user->user_email : '';

        // Get leader email
        $leader_email = '';
        if ( ! empty( $patrol_code ) ) {
            $unit = $this->wpdb->get_row(
                $this->wpdb->prepare(
                    "SELECT leader_email FROM {$this->wpdb->prefix}ems_units WHERE short_code = %s AND active = 1 LIMIT 1",
                    $patrol_code
                ),
                ARRAY_A
            );
            $leader_email = $unit['leader_email'] ?? '';
        }

        // Email parents
        if ( ! empty( $parent_email ) ) {
            wp_mail(
                $parent_email,
                __( 'DofE Signup Confirmation', 'ems-plugin' ),
                sprintf(
                    __( "Thank you for registering %s %s for the %s DofE award.", "ems-plugin" ),
                    $signup['explorer_first_name'],
                    $signup['explorer_last_name'],
                    ucfirst( $signup['dofe_level'] )
                )
            );
        }

        // Email leader
        if ( ! empty( $leader_email ) ) {
            wp_mail(
                $leader_email,
                __( 'New Explorer DofE Signup', 'ems-plugin' ),
                sprintf(
                    __( "Explorer %s %s has registered for the %s DofE award in your unit.", "ems-plugin" ),
                    $signup['explorer_first_name'],
                    $signup['explorer_last_name'],
                    ucfirst( $signup['dofe_level'] )
                )
            );
        }
    }
}
