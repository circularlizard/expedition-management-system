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
     * Resolve ESU unit mapping details for a child
     */
    private function resolve_unit_for_child( array $child ): array {
        $section_ids = (array) ( $child['section_ids'] ?? [] );
        $section_ids = array_unique( array_filter( array_map( 'intval', $section_ids ) ) );

        if ( empty( $section_ids ) ) {
            return [ 'short_code' => '', 'unit_id' => 0 ];
        }

        // Match the child's section IDs against the unit_id column in ems_units
        foreach ( $section_ids as $sec_id ) {
            $unit = $this->wpdb->get_row(
                $this->wpdb->prepare(
                    "SELECT short_code, unit_id FROM {$this->wpdb->prefix}ems_units WHERE unit_id = %d AND active = 1 LIMIT 1",
                    $sec_id
                ),
                ARRAY_A
            );
            if ( ! empty( $unit ) ) {
                return [
                    'short_code' => $unit['short_code'] ?: '',
                    'unit_id'    => (int) ( $unit['unit_id'] ?? 0 ),
                ];
            }
        }

        return [ 'short_code' => '', 'unit_id' => 0 ];
    }

    /**
     * Fetch default unit short code based on parent's children section IDs
     */
    public function prepopulate_unit_select( array $data, $form ): array {
        if ( ( $data['attributes']['name'] ?? '' ) !== 'signup_unit' ) {
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
            if ( ! empty( $res['short_code'] ) ) {
                $data['attributes']['value'] = $res['short_code'];
                $data['settings']['value'] = $res['short_code'];
                return $data;
            }
        }

        return $data;
    }

    /**
     * Fetch default unit ID based on parent's children section IDs
     */
    public function prepopulate_unit_id_hidden( array $data, $form ): array {
        if ( ( $data['attributes']['name'] ?? '' ) !== 'signup_unitid' ) {
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
            if ( ! empty( $res['unit_id'] ) ) {
                $data['attributes']['value'] = $res['unit_id'];
                $data['settings']['value'] = $res['unit_id'];
                return $data;
            }
        }

        return $data;
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
                    "SELECT unit_id FROM {$this->wpdb->prefix}ems_units WHERE (short_code = %s OR name = %s) LIMIT 1",
                    $patrol_value,
                    $patrol_value
                ),
                ARRAY_A
            );
            if ( ! empty( $unit['unit_id'] ) ) {
                $unit_id = (int) $unit['unit_id'];
            }
        }

        if ( empty( $unit_id ) ) {
            if ( ! empty( $formData['signup_unitid'] ) ) {
                $unit_id = (int) $formData['signup_unitid'];
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
     * Handle Stripe/Fluent Forms payment status changes.
     *
     * Fluent Forms passes the raw Stripe-derived status string:
     *   'paid'             — card/immediate payment succeeded
     *   'succeeded'        — alias used by some Stripe intent paths
     *   'processing'       — async payment in progress (webhook will follow)
     *   'requires_capture' — authorise-only mode
     *   'failed'           — payment failed
     *
     * EMS persists only 'paid' | 'pending' and never downgrades a paid row.
     */
    public function handle_payment_status( string $status, $submission ): void {
        $entryId = (int) ( is_object( $submission )
            ? ( $submission->id ?? 0 )
            : ( is_array( $submission ) ? ( $submission['id'] ?? 0 ) : $submission ) );

        if ( $entryId <= 0 ) {
            return;
        }

        // Only 'paid' and 'succeeded' represent a completed payment.
        $mapped_status = in_array( $status, [ 'paid', 'succeeded' ], true ) ? 'paid' : 'pending';

        // Idempotency guard: never downgrade a row that is already marked paid.
        $existing = $this->signup_repo->get_signup_by_submission_id( $entryId );
        if ( $existing && $existing['payment_status'] === 'paid' && $mapped_status !== 'paid' ) {
            return;
        }

        $this->signup_repo->update_payment_status_by_submission_id( $entryId, $mapped_status );
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

    /**
     * Enqueue client-side synchronization JavaScript for dynamic form selections
     */
    public function enqueue_form_script( $form ): void {
        $mappings = get_option( 'ems_form_mappings', [] );
        $form_id = (int) ( is_array( $form ) ? ( $form['id'] ?? 0 ) : ( $form->id ?? 0 ) );
        if ( ! isset( $mappings[ $form_id ] ) ) {
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

        $js_mappings = [];
        foreach ( $children_meta as $child ) {
            $scout_id = (int) ( $child['scout_id'] ?? 0 );
            if ( ! $scout_id ) {
                continue;
            }

            $res = $this->resolve_unit_for_child( $child );
            $js_mappings[ $scout_id ] = [
                'unitCode' => $res['short_code'],
                'unitId'   => $res['unit_id'],
            ];
        }

        ?>
        <script type="text/javascript">
            if (typeof window.emsFormMappings === 'undefined') {
                window.emsFormMappings = new Object();
            }
            Object.assign(window.emsFormMappings, JSON.parse('<?php echo json_encode( $js_mappings, JSON_FORCE_OBJECT ); ?>'));
            console.log('[EMS Sync] Loaded children unit mappings:', window.emsFormMappings);

            /**
             * Retrieve the Choices.js instance for a <select> element.
             *
             * Fluent Forms initialises Choices.js via jQuery and stores the instance
             * using jQuery's internal data cache (key: 'choicesjs'). It is NOT exposed
             * as a DOM property, so `el.choicesInstance` is always undefined.
             * We must go through window.jQuery to read it.
             */
            function emsGetChoices(el) {
                return (window.jQuery && window.jQuery(el).data('choicesjs')) || null;
            }

            /**
             * Set a value on a <select> whether or not Choices.js wraps it.
             * Dispatches a native 'change' event so FF's own handlers stay in sync.
             */
            function emsSetSelectValue(el, value) {
                var choices = emsGetChoices(el);
                if (choices) {
                    choices.setChoiceByValue(value);
                } else {
                    el.value = value;
                }
                el.dispatchEvent(new Event('change', { bubbles: true }));
            }

            document.addEventListener('DOMContentLoaded', function() {

                function initEmsFormSync() {
                    var childSelect = document.querySelector('select[name="signup_child"]');
                    var unitSelect  = document.querySelector('select[name="signup_unit"]');
                    var unitIdInput = document.querySelector('input[name="signup_unitid"]');

                    if (!childSelect) {
                        console.log('[EMS Sync] childSelect not found in form.');
                        return;
                    }
                    console.log('[EMS Sync] Initializing sync hook. Fields found: unitSelect:', !!unitSelect, 'unitIdInput:', !!unitIdInput);

                    function updateUnit() {
                        var val = childSelect.value;
                        console.log('[EMS Sync] signup_child changed value:', val);
                        if (!val) return;
                        var scoutId = val.split('|')[0];
                        var mapping = window.emsFormMappings[scoutId];
                        if (!mapping) {
                            console.log('[EMS Sync] No unit mapping found for scout ID', scoutId);
                            return;
                        }
                        console.log('[EMS Sync] Found unit mapping for scout ID', scoutId, ':', mapping);

                        if (unitSelect && mapping.unitCode) {
                            // Choices.js may not be attached yet — poll until it is or
                            // fall back to native <select> after 3 s.
                            (function trySetUnit(deadline) {
                                var choices = emsGetChoices(unitSelect);
                                if (choices) {
                                    console.log('[EMS Sync] Setting signup_unit via Choices.js to:', mapping.unitCode);
                                    choices.setChoiceByValue(mapping.unitCode);
                                    unitSelect.dispatchEvent(new Event('change', { bubbles: true }));
                                } else if (Date.now() < deadline) {
                                    setTimeout(function() { trySetUnit(deadline); }, 100);
                                } else {
                                    console.log('[EMS Sync] Choices.js not found after 3 s, setting signup_unit via native select to:', mapping.unitCode);
                                    unitSelect.value = mapping.unitCode;
                                    unitSelect.dispatchEvent(new Event('change', { bubbles: true }));
                                }
                            })(Date.now() + 3000);
                        }

                        if (unitIdInput && mapping.unitId) {
                            console.log('[EMS Sync] Setting signup_unitid to:', mapping.unitId);
                            unitIdInput.value = mapping.unitId;
                            unitIdInput.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    }

                    childSelect.addEventListener('change', updateUnit);

                    // Auto-trigger if there is exactly 1 valid child option.
                    // We also need to wait for Choices.js on childSelect before calling
                    // setChoiceByValue, so we use the same polling approach.
                    var nonPlaceholderOptions = Array.from(childSelect.options).filter(function(o) {
                        return o.value && o.value.includes('|');
                    });
                    console.log('[EMS Sync] Total valid explorer options in select:', nonPlaceholderOptions.length);

                    if (nonPlaceholderOptions.length === 1) {
                        var targetVal = nonPlaceholderOptions[0].value;
                        console.log('[EMS Sync] Exactly 1 child option, auto-triggering selection:', targetVal);
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
