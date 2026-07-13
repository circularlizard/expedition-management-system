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

        // Form validation hook
        add_filter( 'fluentform/validation_errors', [ $this, 'validate_submission' ], 10, 2 );

        // Form submission callback
        add_action( 'fluentform/submission_inserted', [ $this, 'handle_submission' ], 10, 3 );

        // Stripe Payment status callbacks
        add_action( 'fluentform/after_payment_status_change', [ $this, 'handle_payment_status' ], 10, 2 );

        // Enqueue form interaction script
        add_action( 'fluentform/before_form_render', [ $this, 'enqueue_form_script' ], 10, 1 );

        // Hidden email field pre-population
        add_filter( 'fluentform/rendering_field_data_input_email', [ $this, 'populate_parent_email' ], 10, 2 );
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
    public function bypass_dropdown_validation( $errors, $field ) {
        if ( ( $field['attributes']['name'] ?? '' ) === 'signup_child' ) {
            return '';
        }
        return $errors;
    }

    /**
     * Resolve ESU unit mapping details for a child, including leader email.
     */
    private function resolve_unit_for_child( array $child ): array {
        $section_ids = (array) ( $child['section_ids'] ?? [] );
        $section_ids = array_unique( array_filter( array_map( 'intval', $section_ids ) ) );

        if ( empty( $section_ids ) ) {
            return [ 'short_code' => '', 'unit_id' => 0, 'leader_email' => '' ];
        }

        foreach ( $section_ids as $sec_id ) {
            $unit = $this->wpdb->get_row(
                $this->wpdb->prepare(
                    "SELECT short_code, unit_id, leader_email FROM {$this->wpdb->prefix}ems_units WHERE unit_id = %d AND active = 1 LIMIT 1",
                    $sec_id
                ),
                ARRAY_A
            );
            if ( ! empty( $unit ) ) {
                return [
                    'short_code'   => $unit['short_code']   ?: '',
                    'unit_id'      => (int) ( $unit['unit_id']  ?? 0 ),
                    'leader_email' => $unit['leader_email'] ?: '',
                ];
            }
        }

        return [ 'short_code' => '', 'unit_id' => 0, 'leader_email' => '' ];
    }

    /**
     * Validate submission constraints
     */
    public function validate_submission( array $errors, $form ): array {
        $form_id = (int) ( is_array( $form ) ? ( $form['id'] ?? 0 ) : ( $form->id ?? 0 ) );
        $participant_form_id = (int) get_option( 'ems_fluent_participant_form_id', 6 );
        $expedition_form_id  = (int) get_option( 'ems_fluent_expedition_form_id', 7 );

        if ( $form_id === $participant_form_id ) {
            $config = get_option( 'ems_participant_form_mappings', [] );
            $config = array_merge( [
                'scout_id_field'   => 'signup_child',
                'dofe_level_field' => 'signup_level',
            ], $config );
        } elseif ( $form_id === $expedition_form_id ) {
            $config = get_option( 'ems_expedition_form_mappings', [] );
            $config = array_merge( [
                'scout_id_field'   => 'signup_child',
                'dofe_level_field' => 'signup_level',
            ], $config );
        } else {
            return $errors;
        }

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
        $form_id = (int) ( is_array( $form ) ? ( $form['id'] ?? 0 ) : ( $form->id ?? 0 ) );
        $participant_form_id = (int) get_option( 'ems_fluent_participant_form_id', 6 );
        $expedition_form_id  = (int) get_option( 'ems_fluent_expedition_form_id', 7 );

        if ( $form_id === $participant_form_id ) {
            $config = get_option( 'ems_participant_form_mappings', [] );
            $config = array_merge( [
                'scout_id_field'          => 'signup_child',
                'first_name_field'        => 'signup_child_name',
                'last_name_field'         => 'signup_child_name',
                'dofe_level_field'        => 'signup_level',
                'dob_field'               => 'signup_dob',
                'dofe_registered_field'   => 'signup_dofe_registered',
                'dofe_number_field'       => 'signup_dofe_number',
                'dofe_org_field'          => 'signup_dofe_org',
                'bronze_completion_field' => 'signup_bronze_completion',
                'silver_completion_field' => 'signup_silver_completion',
                'esu_patrol_field'        => 'signup_unit',
                'explorer_email_field'    => 'signup_explorer_email',
                'parent_email_field'      => 'signup_parent_email',
                'leader_email_field'      => 'signup_leader_email',
            ], $config );
            
            $this->save_participant_submission( $entryId, $formData, $config );
        } elseif ( $form_id === $expedition_form_id ) {
            $config = get_option( 'ems_expedition_form_mappings', [] );
            $config = array_merge( [
                'scout_id_field'               => 'signup_child',
                'first_name_field'             => 'signup_child_name',
                'last_name_field'              => 'signup_child_name',
                'dofe_level_field'             => 'signup_level',
                'dofe_number_field'            => 'signup_dofe_number',
                'esu_patrol_field'             => 'signup_unit',
                'explorer_email_field'         => 'signup_explorer_email',
                'parent_email_field'           => 'signup_parent_email',
                'leader_email_field'           => 'signup_leader_email',
                'exped_type_field'             => 'exped_type',
                'practice_dates_field'         => 'exped_practice_dates',
                'qualifier_dates_field'        => 'exped_qualifier_dates',
                'silver_practice_dates_field'  => 'exped-silver-practice-dates',
                'gold_practice_dates_field'    => 'exped-gold-practice-dates',
                'silver_qualifier_dates_field' => 'exped-silver-qualifier-dates',
                'gold_qualifier_dates_field'   => 'exped-gold-qualifier-dates',
                'team_names_field'             => 'exped_team_names',
                'asn_field'                    => 'exped_asn',
                'first_aid_field'              => 'input_radio',
                'first_aid_expiry_field'       => 'datetime',
            ], $config );

            $this->save_expedition_submission( $entryId, $formData, $config );
        }
    }

    private function parse_name_and_scout_id( array $formData, array $config ): array {
        $submitted_child = $formData[ $config['scout_id_field'] ] ?? '';
        $scout_id = 0;
        $first_name = '';
        $last_name = '';

        if ( ! empty( $submitted_child ) ) {
            $parts = explode( '|', $submitted_child );
            $scout_id = (int) $parts[0];
            $first_name = $parts[1] ?? '';
            $last_name  = $parts[2] ?? '';
        }

        if ( ! empty( $formData['signup_scoutid'] ) ) {
            $scout_id = (int) $formData['signup_scoutid'];
        }

        $child_name_input = $formData[ $config['first_name_field'] ] ?? null;
        if ( is_array( $child_name_input ) ) {
            $first_name = $child_name_input['first_name'] ?? $first_name;
            $last_name  = $child_name_input['last_name'] ?? $last_name;
        } else {
            if ( ! empty( $config['first_name_field'] ) && ! empty( $formData[ $config['first_name_field'] ] ) ) {
                $first_name = $formData[ $config['first_name_field'] ];
            }
            if ( ! empty( $config['last_name_field'] ) && ! empty( $formData[ $config['last_name_field'] ] ) ) {
                $last_name = $formData[ $config['last_name_field'] ];
            }
        }

        return [ $scout_id, $first_name, $last_name ];
    }

    private function save_participant_submission( int $entryId, array $formData, array $config ): void {
        list( $scout_id, $first_name, $last_name ) = $this->parse_name_and_scout_id( $formData, $config );

        $dob = $formData[ $config['dob_field'] ] ?? null;
        if ( ! empty( $dob ) ) {
            // Fluent Forms sometimes formats dates as YYYY-MM-DD or d/m/Y.
            // We store standard YYYY-MM-DD.
            if ( strpos( $dob, '/' ) !== false ) {
                $parts = explode( '/', $dob );
                if ( count( $parts ) === 3 ) {
                    $dob = "{$parts[2]}-{$parts[1]}-{$parts[0]}";
                }
            }
        }

        $bronze = $formData[ $config['bronze_completion_field'] ] ?? null;
        $silver = $formData[ $config['silver_completion_field'] ] ?? null;

        $insert_data = [
            'scout_id'            => $scout_id,
            'parent_user_id'      => get_current_user_id() ?: 1,
            'unit_name'           => sanitize_text_field( $formData[ $config['esu_patrol_field'] ] ?? '' ),
            'explorer_first_name' => sanitize_text_field( $first_name ),
            'explorer_last_name'  => sanitize_text_field( $last_name ),
            'explorer_email'      => sanitize_email( $formData[ $config['explorer_email_field'] ] ?? '' ),
            'parent_email'        => sanitize_email( $formData[ $config['parent_email_field'] ] ?? '' ),
            'dofe_level'          => strtolower( sanitize_text_field( $formData[ $config['dofe_level_field'] ] ?? '' ) ),
            'dob'                 => $dob,
            'dofe_registered'     => sanitize_text_field( $formData[ $config['dofe_registered_field'] ] ?? 'n' ),
            'dofe_number'         => sanitize_text_field( $formData[ $config['dofe_number_field'] ] ?? '' ),
            'dofe_org'            => sanitize_text_field( $formData[ $config['dofe_org_field'] ] ?? '' ),
            'bronze_completion'   => $bronze,
            'silver_completion'   => $silver,
            'signup_status'       => 'received',
            'payment_status'      => 'pending',
            'form_submission_id'  => $entryId,
        ];

        $leader_email = sanitize_email( $formData[ $config['leader_email_field'] ] ?? '' );
        $leader_email_resolved = '';

        // Resolve unit_id & leader_email
        if ( ! empty( $insert_data['unit_name'] ) ) {
            $unit = $this->wpdb->get_row(
                $this->wpdb->prepare(
                    "SELECT unit_id, leader_email FROM {$this->wpdb->prefix}ems_units WHERE (short_code = %s OR name = %s) LIMIT 1",
                    $insert_data['unit_name'],
                    $insert_data['unit_name']
                ),
                ARRAY_A
            );
            if ( ! empty( $unit['unit_id'] ) ) {
                $insert_data['unit_id'] = (int) $unit['unit_id'];
            }
            if ( ! empty( $unit['leader_email'] ) ) {
                $leader_email_resolved = $unit['leader_email'];
            }
        }

        if ( empty( $insert_data['unit_id'] ) && ! empty( $formData['signup_unitid'] ) ) {
            $insert_data['unit_id'] = (int) $formData['signup_unitid'];
        }

        $insert_data['leader_email'] = ! empty( $leader_email ) ? $leader_email : $leader_email_resolved;

        $this->signup_repo->create_participant_signup( $insert_data );
    }

    private function save_expedition_submission( int $entryId, array $formData, array $config ): void {
        list( $scout_id, $first_name, $last_name ) = $this->parse_name_and_scout_id( $formData, $config );

        $level = strtolower( sanitize_text_field( $formData[ $config['dofe_level_field'] ?? 'signup_level' ] ?? '' ) );

        $practice_key = 'exped_practice_dates';
        $qualifier_key = 'exped_qualifier_dates';

        if ( $level === 'silver' ) {
            $practice_key  = $config['silver_practice_dates_field'] ?? 'exped-silver-practice-dates';
            $qualifier_key = $config['silver_qualifier_dates_field'] ?? 'exped-silver-qualifier-dates';
        } elseif ( $level === 'gold' ) {
            $practice_key  = $config['gold_practice_dates_field'] ?? 'exped-gold-practice-dates';
            $qualifier_key = $config['gold_qualifier_dates_field'] ?? 'exped-gold-qualifier-dates';
        } else {
            $practice_key  = $config['practice_dates_field'] ?? 'exped_practice_dates';
            $qualifier_key = $config['qualifier_dates_field'] ?? 'exped_qualifier_dates';
        }

        $prefs = [];
        $pref_fields = [
            'exped_type'             => $config['exped_type_field'] ?? 'exped_type',
            'exped_practice_dates'   => $practice_key,
            'exped_qualifier_dates'  => $qualifier_key,
            'exped_team_names'       => $config['team_names_field'] ?? 'exped_team_names',
        ];

        foreach ( $pref_fields as $key => $form_key ) {
            if ( isset( $formData[ $form_key ] ) ) {
                $prefs[ $key ] = $formData[ $form_key ];
            }
        }

        $fa_expiry = $formData[ $config['first_aid_expiry_field'] ] ?? null;
        if ( ! empty( $fa_expiry ) ) {
            if ( strpos( $fa_expiry, '/' ) !== false ) {
                $parts = explode( '/', $fa_expiry );
                if ( count( $parts ) === 3 ) {
                    $fa_expiry = "{$parts[2]}-{$parts[1]}-{$parts[0]}";
                }
            }
        }

        $insert_data = [
            'scout_id'                 => $scout_id,
            'parent_user_id'           => get_current_user_id() ?: 1,
            'unit_name'                => sanitize_text_field( $formData[ $config['esu_patrol_field'] ] ?? '' ),
            'explorer_first_name'      => sanitize_text_field( $first_name ),
            'explorer_last_name'       => sanitize_text_field( $last_name ),
            'explorer_email'           => sanitize_email( $formData[ $config['explorer_email_field'] ] ?? '' ),
            'parent_email'             => sanitize_email( $formData[ $config['parent_email_field'] ] ?? '' ),
            'dofe_level'               => strtolower( sanitize_text_field( $formData[ $config['dofe_level_field'] ] ?? '' ) ),
            'expedition_preferences'   => $prefs,
            'additional_support_needs' => sanitize_textarea_field( $formData[ $config['asn_field'] ] ?? '' ),
            'first_aid_status'         => sanitize_text_field( $formData[ $config['first_aid_field'] ] ?? 'none' ),
            'first_aid_expiry'         => $fa_expiry,
            'signup_status'            => 'pending',
            'form_submission_id'       => $entryId,
        ];

        $leader_email = sanitize_email( $formData[ $config['leader_email_field'] ] ?? '' );
        $leader_email_resolved = '';

        // Resolve unit_id & leader_email
        if ( ! empty( $insert_data['unit_name'] ) ) {
            $unit = $this->wpdb->get_row(
                $this->wpdb->prepare(
                    "SELECT unit_id, leader_email FROM {$this->wpdb->prefix}ems_units WHERE (short_code = %s OR name = %s) LIMIT 1",
                    $insert_data['unit_name'],
                    $insert_data['unit_name']
                ),
                ARRAY_A
            );
            if ( ! empty( $unit['unit_id'] ) ) {
                $insert_data['unit_id'] = (int) $unit['unit_id'];
            }
            if ( ! empty( $unit['leader_email'] ) ) {
                $leader_email_resolved = $unit['leader_email'];
            }
        }

        if ( empty( $insert_data['unit_id'] ) && ! empty( $formData['signup_unitid'] ) ) {
            $insert_data['unit_id'] = (int) $formData['signup_unitid'];
        }

        $insert_data['leader_email'] = ! empty( $leader_email ) ? $leader_email : $leader_email_resolved;

        $this->signup_repo->create_expedition_signup( $insert_data );
    }

    public function handle_payment_status( $status, $submission ): void {
        $entryId = (int) ( is_object( $submission )
            ? ( $submission->id ?? 0 )
            : ( is_array( $submission ) ? ( $submission['id'] ?? 0 ) : $submission ) );

        if ( $entryId <= 0 ) {
            return;
        }

        $mapped_status = in_array( $status, [ 'paid', 'succeeded' ], true ) ? 'paid' : 'pending';

        // Check participant signup
        $p_existing = $this->signup_repo->get_participant_signup( $entryId );
        if ( $p_existing && $p_existing['payment_status'] === 'paid' && $mapped_status !== 'paid' ) {
            return;
        }

        // Check expedition signup
        $e_existing = $this->signup_repo->get_expedition_signup( $entryId );
        if ( $e_existing && $e_existing['payment_status'] === 'paid' && $mapped_status !== 'paid' ) {
            return;
        }

        $this->signup_repo->update_payment_status_by_submission_id( $entryId, $mapped_status );
    }

    public function populate_parent_email( array $data, $form ): array {
        $form_id = (int) ( is_array( $form ) ? ( $form['id'] ?? 0 ) : ( $form->id ?? 0 ) );
        $participant_form_id = (int) get_option( 'ems_fluent_participant_form_id', 6 );
        $expedition_form_id  = (int) get_option( 'ems_fluent_expedition_form_id', 7 );

        $target_field = 'signup_parent_email';
        if ( $form_id === $participant_form_id ) {
            $config = get_option( 'ems_participant_form_mappings', [] );
            $target_field = $config['parent_email_field'] ?? 'signup_parent_email';
        } elseif ( $form_id === $expedition_form_id ) {
            $config = get_option( 'ems_expedition_form_mappings', [] );
            $target_field = $config['parent_email_field'] ?? 'signup_parent_email';
        }

        if ( ( $data['attributes']['name'] ?? '' ) !== $target_field ) {
            return $data;
        }

        $user = get_userdata( get_current_user_id() );
        if ( $user && ! empty( $user->user_email ) ) {
            $data['attributes']['value'] = $user->user_email;
            $data['settings']['value']   = $user->user_email;
        }
        return $data;
    }

    public function populate_explorer_email( array $data, $form ): array {
        $form_id = (int) ( is_array( $form ) ? ( $form['id'] ?? 0 ) : ( $form->id ?? 0 ) );
        $participant_form_id = (int) get_option( 'ems_fluent_participant_form_id', 6 );
        $expedition_form_id  = (int) get_option( 'ems_fluent_expedition_form_id', 7 );

        $target_field = 'signup_explorer_email';
        if ( $form_id === $participant_form_id ) {
            $config = get_option( 'ems_participant_form_mappings', [] );
            $target_field = $config['explorer_email_field'] ?? 'signup_explorer_email';
        } elseif ( $form_id === $expedition_form_id ) {
            $config = get_option( 'ems_expedition_form_mappings', [] );
            $target_field = $config['explorer_email_field'] ?? 'signup_explorer_email';
        }

        if ( ( $data['attributes']['name'] ?? '' ) !== $target_field ) {
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

        foreach ( $children_meta as $child ) {
            $scout_id = (int) ( $child['scout_id'] ?? 0 );
            if ( ! $scout_id ) {
                continue;
            }

            $row = $this->wpdb->get_row(
                $this->wpdb->prepare(
                    "SELECT email FROM {$this->wpdb->prefix}ems_osm_explorers WHERE scout_id = %d LIMIT 1",
                    $scout_id
                ),
                ARRAY_A
            );

            if ( ! empty( $row['email'] ) ) {
                $data['attributes']['value'] = $row['email'];
                $data['settings']['value']   = $row['email'];
                
                // Add ff-read-only class
                if ( isset( $data['attributes']['class'] ) && is_array( $data['attributes']['class'] ) ) {
                    if ( ! in_array( 'ff-read-only', $data['attributes']['class'], true ) ) {
                        $data['attributes']['class'][] = 'ff-read-only';
                    }
                } else {
                    $current_class = $data['attributes']['class'] ?? '';
                    if ( strpos( $current_class, 'ff-read-only' ) === false ) {
                        $data['attributes']['class'] = trim( $current_class . ' ff-read-only' );
                    }
                }
                
                return $data;
            }
        }

        return $data;
    }

    public function populate_leader_email( array $data, $form ): array {
        $form_id = (int) ( is_array( $form ) ? ( $form['id'] ?? 0 ) : ( $form->id ?? 0 ) );
        $participant_form_id = (int) get_option( 'ems_fluent_participant_form_id', 6 );
        $expedition_form_id  = (int) get_option( 'ems_fluent_expedition_form_id', 7 );

        $target_field = 'signup_leader_email';
        if ( $form_id === $participant_form_id ) {
            $config = get_option( 'ems_participant_form_mappings', [] );
            $target_field = $config['leader_email_field'] ?? 'signup_leader_email';
        } elseif ( $form_id === $expedition_form_id ) {
            $config = get_option( 'ems_expedition_form_mappings', [] );
            $target_field = $config['leader_email_field'] ?? 'signup_leader_email';
        }

        if ( ( $data['attributes']['name'] ?? '' ) !== $target_field ) {
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

        foreach ( $children_meta as $child ) {
            $res = $this->resolve_unit_for_child( $child );
            if ( ! empty( $res['leader_email'] ) ) {
                $data['attributes']['value'] = $res['leader_email'];
                $data['settings']['value']   = $res['leader_email'];
                return $data;
            }
        }

        return $data;
    }

    public function enqueue_form_script( $form ): void {
        $form_id = (int) ( is_array( $form ) ? ( $form['id'] ?? 0 ) : ( $form->id ?? 0 ) );
        $participant_form_id = (int) get_option( 'ems_fluent_participant_form_id', 6 );
        $expedition_form_id  = (int) get_option( 'ems_fluent_expedition_form_id', 7 );

        if ( $form_id !== $participant_form_id && $form_id !== $expedition_form_id ) {
            return;
        }

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return;
        }

        $children_meta = get_user_meta( $user_id, 'ems_children', true );
        if ( empty( $children_meta ) || ! is_array( $children_meta ) ) {
            return;
        }

        $config = [];
        if ( $form_id === $participant_form_id ) {
            $config = get_option( 'ems_participant_form_mappings', [] );
        } elseif ( $form_id === $expedition_form_id ) {
            $config = get_option( 'ems_expedition_form_mappings', [] );
        }

        $js_fields = [
            'scoutField'         => $config['scout_id_field'] ?? 'signup_child',
            'unitField'          => $config['esu_patrol_field'] ?? 'signup_unit',
            'explorerEmailField' => $config['explorer_email_field'] ?? 'signup_explorer_email',
            'leaderEmailField'   => $config['leader_email_field'] ?? 'signup_leader_email',
            'firstNameField'     => $config['first_name_field'] ?? 'signup_child_name',
            'lastNameField'      => $config['last_name_field'] ?? 'signup_child_name',
            'dobField'           => $config['dob_field'] ?? 'signup_dob',
        ];

        $temp_children = [];
        $session_token = wp_get_session_token();
        if ( ! empty( $session_token ) ) {
            $encrypted = get_transient( 'ems_sess_children_' . md5( $session_token ) );
            if ( $encrypted ) {
                $decrypted = \EMS\Core\Encryption::decrypt( $encrypted );
                if ( $decrypted ) {
                    $temp_children = json_decode( $decrypted, true ) ?: [];
                }
            }
        }
        $temp_by_id = array_column( $temp_children, null, 'scout_id' );

        $js_mappings = [];
        foreach ( $children_meta as $child ) {
            $scout_id = (int) ( $child['scout_id'] ?? 0 );
            if ( ! $scout_id ) {
                continue;
            }

            $res = $this->resolve_unit_for_child( $child );

            $explorer_row = $this->wpdb->get_row(
                $this->wpdb->prepare(
                    "SELECT email FROM {$this->wpdb->prefix}ems_osm_explorers WHERE scout_id = %d LIMIT 1",
                    $scout_id
                ),
                ARRAY_A
            );

            $child_enrich = $temp_by_id[ $scout_id ] ?? [];
            $explorer_email = $child_enrich['email'] ?? $explorer_row['email'] ?? '';
            $dob = $child_enrich['dob'] ?? '';

            $js_mappings[ $scout_id ] = [
                'firstName'     => $child['first_name'] ?? '',
                'lastName'      => $child['last_name'] ?? '',
                'unitCode'      => $res['short_code'],
                'unitId'        => $res['unit_id'],
                'explorerEmail' => $explorer_email,
                'dob'           => $dob,
                'leaderEmail'   => $res['leader_email'],
            ];
        }

        ?>
        <script type="text/javascript">
            if (typeof window.emsFormMappings === 'undefined') {
                window.emsFormMappings = new Object();
            }
            if (typeof window.emsFields === 'undefined') {
                window.emsFields = JSON.parse('<?php echo json_encode( $js_fields, JSON_FORCE_OBJECT ); ?>');
            }
            Object.assign(window.emsFormMappings, JSON.parse('<?php echo json_encode( $js_mappings, JSON_FORCE_OBJECT ); ?>'));
            console.log('[EMS Sync] Loaded children mappings & fields:', window.emsFormMappings, window.emsFields);

            function emsGetChoices(el) {
                return (window.jQuery && window.jQuery(el).data('choicesjs')) || null;
            }

            document.addEventListener('DOMContentLoaded', function() {
                function initEmsFormSync() {
                    var childSelect = document.querySelector('select[name="' + window.emsFields.scoutField + '"]');
                    var unitSelect  = document.querySelector('select[name="' + window.emsFields.unitField + '"]');
                    var unitIdInput = document.querySelector('input[name="signup_unitid"]');

                    if (!childSelect) return;

                    function updateUnit() {
                        var val = childSelect.value;
                        if (!val) return;
                        var scoutId = val.split('|')[0];
                        var mapping = window.emsFormMappings[scoutId];
                        if (!mapping) return;

                        // 1. Hidden scout_id field
                        var scoutIdInput = document.querySelector('input[name="signup_scoutid"]');
                        if (scoutIdInput) {
                            scoutIdInput.value = scoutId;
                            scoutIdInput.dispatchEvent(new Event('change', { bubbles: true }));
                        }

                        // 2. Name elements
                        var firstNameInput = document.querySelector('input[name="' + window.emsFields.firstNameField + '[first_name]"]');
                        if (firstNameInput) {
                            firstNameInput.value = mapping.firstName || '';
                            firstNameInput.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                        var lastNameInput = document.querySelector('input[name="' + window.emsFields.lastNameField + '[last_name]"]');
                        if (lastNameInput) {
                            lastNameInput.value = mapping.lastName || '';
                            lastNameInput.dispatchEvent(new Event('change', { bubbles: true }));
                        }

                        // 3. Unit dropdown
                        if (unitSelect && mapping.unitCode) {
                            (function trySetUnit(deadline) {
                                var choices = emsGetChoices(unitSelect);
                                if (choices) {
                                    choices.setChoiceByValue(mapping.unitCode);
                                    unitSelect.dispatchEvent(new Event('change', { bubbles: true }));
                                } else if (Date.now() < deadline) {
                                    setTimeout(function() { trySetUnit(deadline); }, 100);
                                } else {
                                    unitSelect.value = mapping.unitCode;
                                    unitSelect.dispatchEvent(new Event('change', { bubbles: true }));
                                }
                            })(Date.now() + 3000);
                        }

                        if (unitIdInput && mapping.unitId) {
                            unitIdInput.value = mapping.unitId;
                            unitIdInput.dispatchEvent(new Event('change', { bubbles: true }));
                        }

                        // 4. Emails with retry loop to ensure they populate at the same time as unit does
                        (function trySetExplorerEmail(deadline) {
                            var explorerEmailInput = document.querySelector('input[name="' + window.emsFields.explorerEmailField + '"]');
                            if (explorerEmailInput) {
                                explorerEmailInput.value = mapping.explorerEmail || '';
                                explorerEmailInput.dispatchEvent(new Event('change', { bubbles: true }));
                                if (mapping.explorerEmail) {
                                    explorerEmailInput.classList.add('ff-read-only');
                                } else {
                                    explorerEmailInput.classList.remove('ff-read-only');
                                }
                            } else if (Date.now() < deadline) {
                                setTimeout(function() { trySetExplorerEmail(deadline); }, 100);
                            }
                        })(Date.now() + 3000);

                        (function trySetLeaderEmail(deadline) {
                            var leaderEmailInput = document.querySelector('input[name="' + window.emsFields.leaderEmailField + '"]');
                            if (leaderEmailInput) {
                                leaderEmailInput.value = mapping.leaderEmail || '';
                                leaderEmailInput.dispatchEvent(new Event('change', { bubbles: true }));
                            } else if (Date.now() < deadline) {
                                setTimeout(function() { trySetLeaderEmail(deadline); }, 100);
                            }
                        })(Date.now() + 3000);

                        (function trySetDob(deadline) {
                            var dobInput = document.querySelector('input[name="' + window.emsFields.dobField + '"]');
                            if (dobInput) {
                                var rawDob = mapping.dob || '';
                                var formattedDob = rawDob;
                                if (rawDob && rawDob.includes('-')) {
                                    var parts = rawDob.split('-');
                                    if (parts.length === 3) {
                                        formattedDob = parts[2] + '/' + parts[1] + '/' + parts[0];
                                    }
                                }
                                console.log('[EMS Sync] Pre-populating DOB. Scout ID:', scoutId, 'Raw:', rawDob, 'Formatted:', formattedDob);
                                if (dobInput._flatpickr) {
                                    console.log('[EMS Sync] Flatpickr instance found. Setting date.');
                                    dobInput._flatpickr.setDate(rawDob, true);
                                } else {
                                    dobInput.value = formattedDob;
                                    dobInput.dispatchEvent(new Event('change', { bubbles: true }));
                                    dobInput.dispatchEvent(new Event('input', { bubbles: true }));
                                }
                            } else if (Date.now() < deadline) {
                                setTimeout(function() { trySetDob(deadline); }, 100);
                            }
                        })(Date.now() + 3000);
                    }

                    childSelect.addEventListener('change', updateUnit);

                    var nonPlaceholderOptions = Array.from(childSelect.options).filter(function(o) {
                        return o.value && o.value.includes('|');
                    });

                    if (nonPlaceholderOptions.length === 1) {
                        var targetVal = nonPlaceholderOptions[0].value;
                        (function trySetChild(deadline) {
                            var choices = emsGetChoices(childSelect);
                            if (choices) {
                                choices.setChoiceByValue(targetVal);
                                childSelect.dispatchEvent(new Event('change', { bubbles: true }));
                            } else if (Date.now() < deadline) {
                                setTimeout(function() { trySetChild(deadline); }, 100);
                            } else {
                                childSelect.value = targetVal;
                                childSelect.dispatchEvent(new Event('change', { bubbles: true }));
                            }
                        })(Date.now() + 3000);
                    } else {
                        updateUnit();
                    }
                }
                initEmsFormSync();
            });
        </script>
        <?php
    }
}
