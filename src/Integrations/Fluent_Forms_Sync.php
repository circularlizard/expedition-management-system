<?php
namespace EMS\Integrations;

use EMS\Data\Signup_Repository;
use EMS\Data\Unit_Repository;

class Fluent_Forms_Sync {
	private Signup_Repository $signup_repo;
	private Unit_Repository $unit_repo;
	private object $wpdb;
	private bool $parent_email_verified = false;
	private bool $explorer_email_verified = false;

	public function __construct( ?Signup_Repository $signup_repo = null, ?Unit_Repository $unit_repo = null, ?object $wpdb = null ) {
		if ( $wpdb === null ) {
			global $wpdb;
		}
		$this->wpdb        = $wpdb;
		$this->signup_repo = $signup_repo ?: new Signup_Repository( $wpdb );
		$this->unit_repo   = $unit_repo ?: new Unit_Repository( $wpdb );
	}

	public function init_hooks(): void {
		// Dropdown dynamic choices population filter
		add_filter( 'fluentform/rendering_field_data_select', array( $this, 'populate_child_dropdown' ), 10, 2 );

		// Validation bypass for dynamically generated dropdown choices
		add_filter( 'fluentform/validate_input_item_select', array( $this, 'bypass_dropdown_validation' ), 10, 2 );

		// Form validation hook
		add_filter( 'fluentform/validation_errors', array( $this, 'validate_submission' ), 10, 2 );

		// Form submission callback
		add_action( 'fluentform/submission_inserted', array( $this, 'handle_submission' ), 10, 3 );

		// Stripe Payment status callbacks
		add_action( 'fluentform/after_payment_status_change', array( $this, 'handle_payment_status' ), 10, 2 );

		// Enqueue form interaction script
		add_action( 'fluentform/before_form_render', array( $this, 'enqueue_form_script' ), 10, 1 );

		// Hidden email field pre-population
		add_filter( 'fluentform/rendering_field_data_input_email', array( $this, 'populate_parent_email' ), 10, 2 );

		// OTP verification hooks
		add_action( 'wp_ajax_send_fluent_otp', array( $this, 'handle_send_fluent_otp' ) );
		add_action( 'wp_ajax_nopriv_send_fluent_otp', array( $this, 'handle_send_fluent_otp' ) );
		add_action( 'wp_ajax_verify_fluent_otp', array( $this, 'handle_verify_fluent_otp' ) );
		add_action( 'wp_ajax_nopriv_verify_fluent_otp', array( $this, 'handle_verify_fluent_otp' ) );

		add_filter( 'fluentform/validate_input_item_email', array( $this, 'validate_email_otp' ), 10, 5 );
		add_filter( 'fluentform/validation_rules', array( $this, 'bypass_otp_required_validation' ), 10, 2 );
		add_filter( 'fluentform/rendering_field_data_input_text', array( $this, 'bypass_otp_frontend_required' ), 10, 2 );
		add_filter( 'fluentform/rendering_field_data_input_number', array( $this, 'bypass_otp_frontend_required' ), 10, 2 );
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

		$children_meta = $this->get_allowed_children_for_user( $user_id );
		if ( empty( $children_meta ) || ! is_array( $children_meta ) ) {
			return $data;
		}

		$options = array();
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
			$last_name  = $explorer['last_name'] ?? $child['last_name'] ?? '';
			$label      = trim( "{$first_name} {$last_name}" ) ?: "Explorer #{$scout_id}";
			$value      = (string) $scout_id;

			$options[] = array(
				'label'      => $label,
				'value'      => $value,
				'calc_value' => '',
			);
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
		$section_ids = (array) ( $child['section_ids'] ?? array() );
		$section_ids = array_unique( array_filter( array_map( 'intval', $section_ids ) ) );

		if ( empty( $section_ids ) ) {
			return array(
				'short_code'   => '',
				'unit_id'      => 0,
				'leader_email' => '',
			);
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
				return array(
					'short_code'   => $unit['short_code'] ?: '',
					'unit_id'      => (int) ( $unit['unit_id'] ?? 0 ),
					'leader_email' => $unit['leader_email'] ?: '',
				);
			}
		}

		return array(
			'short_code'   => '',
			'unit_id'      => 0,
			'leader_email' => '',
		);
	}

	/**
	 * Validate submission constraints
	 */
	public function validate_submission( array $errors, $form ): array {
		$form_id             = (int) ( is_array( $form ) ? ( $form['id'] ?? 0 ) : ( $form->id ?? 0 ) );
		$participant_form_id = (int) get_option( 'ems_fluent_participant_form_id', 6 );
		$expedition_form_id  = (int) get_option( 'ems_fluent_expedition_form_id', 7 );

		if ( $form_id === $participant_form_id ) {
			$config = get_option( 'ems_participant_form_mappings', array() );
			$config = array_merge(
				array(
					'scout_id_field'   => 'signup_child',
					'dofe_level_field' => 'signup_level',
				),
				$config
			);
		} elseif ( $form_id === $expedition_form_id ) {
			$config = get_option( 'ems_expedition_form_mappings', array() );
			$config = array_merge(
				array(
					'scout_id_field'   => 'signup_child',
					'dofe_level_field' => 'signup_level',
				),
				$config
			);
		} else {
			return $errors;
		}

		$scout_field = $config['scout_id_field'] ?? 'signup_child';
		$level_field = $config['dofe_level_field'] ?? 'signup_level';

		$submitted_child = $_POST[ $scout_field ] ?? '';
		$submitted_level = strtolower( sanitize_text_field( $_POST[ $level_field ] ?? '' ) );

		if ( ! empty( $submitted_child ) ) {
			$scout_id = (int) $submitted_child;

			$user_id = get_current_user_id();
			if ( $user_id !== 0 ) {
				$children_meta = $this->get_allowed_children_for_user( $user_id );
				$allowed_ids   = array_map( 'intval', array_column( $children_meta, 'scout_id' ) );

				if ( ! in_array( $scout_id, $allowed_ids, true ) ) {
					$errors[ $scout_field ] = array( __( 'You do not have permission to register this child.', 'ems-plugin' ) );
				}
			}
		}

		if ( ! empty( $submitted_level ) && ! in_array( $submitted_level, array( 'bronze', 'silver', 'gold' ), true ) ) {
			$errors[ $level_field ] = array( __( 'Invalid DofE Level selected.', 'ems-plugin' ) );
		}

		return $errors;
	}

	/**
	 * Handle Fluent Forms successful submission inserting
	 */
	public function handle_submission( $entryId, $formData, $form ): void {
		$form_id             = (int) ( is_array( $form ) ? ( $form['id'] ?? 0 ) : ( $form->id ?? 0 ) );
		$participant_form_id = (int) get_option( 'ems_fluent_participant_form_id', 6 );
		$expedition_form_id  = (int) get_option( 'ems_fluent_expedition_form_id', 7 );

		if ( $form_id === $participant_form_id ) {
			$config = get_option( 'ems_participant_form_mappings', array() );
			$config = array_merge(
				array(
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
				),
				$config
			);

			$this->save_participant_submission( $entryId, $formData, $config );
		} elseif ( $form_id === $expedition_form_id ) {
			$config = get_option( 'ems_expedition_form_mappings', array() );
			$config = array_merge(
				array(
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
				),
				$config
			);

			$this->save_expedition_submission( $entryId, $formData, $config );
		}
	}

	private function parse_name_and_scout_id( array $formData, array $config ): array {
		$submitted_child = $formData[ $config['scout_id_field'] ] ?? '';
		$scout_id        = 0;
		$first_name      = '';
		$last_name       = '';

		if ( ! empty( $submitted_child ) ) {
			$scout_id = (int) $submitted_child;
			if ( $scout_id > 0 ) {
				$explorer = $this->wpdb->get_row(
					$this->wpdb->prepare(
						"SELECT first_name, last_name FROM {$this->wpdb->prefix}ems_osm_explorers WHERE scout_id = %d",
						$scout_id
					),
					ARRAY_A
				);
				if ( $explorer ) {
					$first_name = $explorer['first_name'] ?? '';
					$last_name  = $explorer['last_name'] ?? '';
				}
			}
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

		return array( $scout_id, $first_name, $last_name );
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

		$insert_data = array(
			'scout_id'            => $scout_id,
			'parent_user_id'      => get_current_user_id(),
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
		);

		$leader_email          = sanitize_email( $formData[ $config['leader_email_field'] ] ?? '' );
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

		$practice_key  = 'exped_practice_dates';
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

		$prefs       = array();
		$pref_fields = array(
			'exped_type'            => $config['exped_type_field'] ?? 'exped_type',
			'exped_practice_dates'  => $practice_key,
			'exped_qualifier_dates' => $qualifier_key,
			'exped_team_names'      => $config['team_names_field'] ?? 'exped_team_names',
		);

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

		$insert_data = array(
			'scout_id'                 => $scout_id,
			'parent_user_id'           => get_current_user_id(),
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
		);

		$leader_email          = sanitize_email( $formData[ $config['leader_email_field'] ] ?? '' );
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

		$mapped_status = in_array( $status, array( 'paid', 'succeeded' ), true ) ? 'paid' : 'pending';

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
		$form_id             = (int) ( is_array( $form ) ? ( $form['id'] ?? 0 ) : ( $form->id ?? 0 ) );
		$participant_form_id = (int) get_option( 'ems_fluent_participant_form_id', 6 );
		$expedition_form_id  = (int) get_option( 'ems_fluent_expedition_form_id', 7 );

		$target_field = 'signup_parent_email';
		if ( $form_id === $participant_form_id ) {
			$config       = get_option( 'ems_participant_form_mappings', array() );
			$target_field = $config['parent_email_field'] ?? 'signup_parent_email';
		} elseif ( $form_id === $expedition_form_id ) {
			$config       = get_option( 'ems_expedition_form_mappings', array() );
			$target_field = $config['parent_email_field'] ?? 'signup_parent_email';
		}

		if ( ( $data['attributes']['name'] ?? '' ) !== $target_field ) {
			return $data;
		}

		$user = get_userdata( get_current_user_id() );
		if ( $user && ! empty( $user->user_email ) ) {
			$data['attributes']['value']    = $user->user_email;
			$data['settings']['value']      = $user->user_email;
			$data['attributes']['readonly'] = 'readonly';

			$classes = $data['attributes']['class'] ?? '';
			if ( strpos( $classes, 'ff-read-only' ) === false ) {
				$data['attributes']['class'] = trim( $classes . ' ff-read-only' );
			}
		}
		return $data;
	}

	public function populate_explorer_email( array $data, $form ): array {
		$form_id             = (int) ( is_array( $form ) ? ( $form['id'] ?? 0 ) : ( $form->id ?? 0 ) );
		$participant_form_id = (int) get_option( 'ems_fluent_participant_form_id', 6 );
		$expedition_form_id  = (int) get_option( 'ems_fluent_expedition_form_id', 7 );

		$target_field = 'signup_explorer_email';
		if ( $form_id === $participant_form_id ) {
			$config       = get_option( 'ems_participant_form_mappings', array() );
			$target_field = $config['explorer_email_field'] ?? 'signup_explorer_email';
		} elseif ( $form_id === $expedition_form_id ) {
			$config       = get_option( 'ems_expedition_form_mappings', array() );
			$target_field = $config['explorer_email_field'] ?? 'signup_explorer_email';
		}

		if ( ( $data['attributes']['name'] ?? '' ) !== $target_field ) {
			return $data;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return $data;
		}

		$children_meta = $this->get_allowed_children_for_user( $user_id );
		if ( empty( $children_meta ) || ! is_array( $children_meta ) ) {
			return $data;
		}

		$temp_children = array();
		$encrypted     = get_transient( 'ems_sess_children_' . $user_id );
		if ( $encrypted !== false ) {
			$decrypted = \EMS\Core\Encryption::decrypt( $encrypted );
			if ( $decrypted !== false ) {
				$temp_children = json_decode( $decrypted, true ) ?: array();
			}
		}
		$temp_by_id = array_column( $temp_children, null, 'scout_id' );

		foreach ( $children_meta as $child ) {
			$scout_id = (int) ( $child['scout_id'] ?? 0 );
			if ( ! $scout_id ) {
				continue;
			}

			$child_enrich = $temp_by_id[ $scout_id ] ?? array();
			$email        = $child_enrich['email'] ?? '';

			if ( ! empty( $email ) ) {
				$data['attributes']['value'] = $email;
				$data['settings']['value']   = $email;

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
		$form_id             = (int) ( is_array( $form ) ? ( $form['id'] ?? 0 ) : ( $form->id ?? 0 ) );
		$participant_form_id = (int) get_option( 'ems_fluent_participant_form_id', 6 );
		$expedition_form_id  = (int) get_option( 'ems_fluent_expedition_form_id', 7 );

		$target_field = 'signup_leader_email';
		if ( $form_id === $participant_form_id ) {
			$config       = get_option( 'ems_participant_form_mappings', array() );
			$target_field = $config['leader_email_field'] ?? 'signup_leader_email';
		} elseif ( $form_id === $expedition_form_id ) {
			$config       = get_option( 'ems_expedition_form_mappings', array() );
			$target_field = $config['leader_email_field'] ?? 'signup_leader_email';
		}

		if ( ( $data['attributes']['name'] ?? '' ) !== $target_field ) {
			return $data;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return $data;
		}

		$children_meta = $this->get_allowed_children_for_user( $user_id );
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
		$form_id             = (int) ( is_array( $form ) ? ( $form['id'] ?? 0 ) : ( $form->id ?? 0 ) );
		$participant_form_id = (int) get_option( 'ems_fluent_participant_form_id', 6 );
		$expedition_form_id  = (int) get_option( 'ems_fluent_expedition_form_id', 7 );

		if ( $form_id !== $participant_form_id && $form_id !== $expedition_form_id ) {
			return;
		}

		$user_id       = get_current_user_id();
		$children_meta = $user_id ? $this->get_allowed_children_for_user( $user_id ) : array();
		if ( ! is_array( $children_meta ) ) {
			$children_meta = array();
		}

		$units = $this->wpdb->get_results(
			"SELECT short_code, leader_email FROM {$this->wpdb->prefix}ems_units WHERE active = 1",
			ARRAY_A
		);
		$unit_mappings = array();
		if ( is_array( $units ) ) {
			foreach ( $units as $u ) {
				if ( ! empty( $u['short_code'] ) ) {
					$unit_mappings[ $u['short_code'] ] = $u['leader_email'] ?: '';
				}
			}
		}

		$config = array();
		if ( $form_id === $participant_form_id ) {
			$config = get_option( 'ems_participant_form_mappings', array() );
		} elseif ( $form_id === $expedition_form_id ) {
			$config = get_option( 'ems_expedition_form_mappings', array() );
		}

		$js_fields = array(
			'scoutField'         => $config['scout_id_field'] ?? 'signup_child',
			'unitField'          => $config['esu_patrol_field'] ?? 'signup_unit',
			'explorerEmailField' => $config['explorer_email_field'] ?? 'signup_explorer_email',
			'leaderEmailField'   => $config['leader_email_field'] ?? 'signup_leader_email',
			'parentEmailField'   => $config['parent_email_field'] ?? 'signup_parent_email',
			'parentOtpField'     => $config['parent_otp_field'] ?? 'signup_parent_otp_code',
			'explorerOtpField'   => $config['explorer_otp_field'] ?? 'signup_explorer_otp_code',
			'firstNameField'     => $config['first_name_field'] ?? 'signup_child_name',
			'lastNameField'      => $config['last_name_field'] ?? 'signup_child_name',
			'dobField'           => $config['dob_field'] ?? 'signup_dob',
			'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
			'nonce'              => wp_create_nonce( 'fluent_otp_nonce' ),
			'isLoggedIn'         => is_user_logged_in(),
		);

		$js_mappings = array();
		foreach ( $children_meta as $child ) {
			$scout_id = (int) ( $child['scout_id'] ?? 0 );
			if ( ! $scout_id ) {
				continue;
			}

			$res = $this->resolve_unit_for_child( $child );

			$js_mappings[ $scout_id ] = array(
				'firstName'     => $child['first_name'] ?? '',
				'lastName'      => $child['last_name'] ?? '',
				'unitCode'      => $res['short_code'],
				'unitId'        => $res['unit_id'],
				'explorerEmail' => '',
				'dob'           => '',
				'leaderEmail'   => $res['leader_email'],
			);
		}

		?>
		<script type="text/javascript">
			if (typeof window.emsFormMappings === 'undefined') {
				window.emsFormMappings = new Object();
			}
			if (typeof window.emsUnitMappings === 'undefined') {
				window.emsUnitMappings = new Object();
			}
			if (typeof window.emsFields === 'undefined') {
				window.emsFields = <?php echo wp_json_encode( $js_fields ); ?>;
			}
			Object.assign(window.emsFormMappings, <?php echo wp_json_encode( $js_mappings ); ?>);
			Object.assign(window.emsUnitMappings, <?php echo wp_json_encode( $unit_mappings ); ?>);
			console.log('[EMS Sync] Loaded children mappings, unit mappings & fields:', window.emsFormMappings, window.emsUnitMappings, window.emsFields);

			function emsGetChoices(el) {
				return (window.jQuery && window.jQuery(el).data('choicesjs')) || null;
			}

			document.addEventListener('DOMContentLoaded', function() {
				function initEmsFormSync() {
					var childSelect = document.querySelector('select[name="' + window.emsFields.scoutField + '"]');
					var unitSelect  = document.querySelector('select[name="' + window.emsFields.unitField + '"]');
					var unitIdInput = document.querySelector('input[name="signup_unitid"]');

					function updateLeaderEmail() {
						if (!unitSelect) return;
						var unitVal = unitSelect.value;
						if (!unitVal) return;
						var leaderEmail = window.emsUnitMappings[unitVal] || '';

						(function trySetLeader(deadline) {
							var leaderEmailInput = document.querySelector('input[name="' + window.emsFields.leaderEmailField + '"]');
							if (leaderEmailInput) {
								leaderEmailInput.value = leaderEmail;
								leaderEmailInput.dispatchEvent(new Event('change', { bubbles: true }));
							} else if (Date.now() < deadline) {
								setTimeout(function() { trySetLeader(deadline); }, 100);
							}
						})(Date.now() + 3000);
					}

					if (unitSelect) {
						unitSelect.addEventListener('change', updateLeaderEmail);
						updateLeaderEmail();
					}

					function updateUnit() {
						if (!childSelect) return;
						var val = childSelect.value;
						if (!val) return;
						var scoutId = val;
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
									try {
										choices.setChoiceByValue(mapping.unitCode);
										unitSelect.dispatchEvent(new Event('change', { bubbles: true }));
									} catch (e) {
										console.warn('[EMS Sync] Choices.js failed to set unit choice:', mapping.unitCode, e);
									}
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

						// 4. Emails
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
									if (rawDob && rawDob.includes('-')) {
										var parts = rawDob.split('-');
										var localDate = new Date(parts[0], parts[1] - 1, parts[2]);
										dobInput._flatpickr.setDate(localDate, true);
									} else {
										dobInput._flatpickr.setDate(rawDob, true);
									}
								} else {
									dobInput.value = formattedDob;
									dobInput.dispatchEvent(new Event('change', { bubbles: true }));
									dobInput.dispatchEvent(new Event('input', { bubbles: true }));
								}
								if (rawDob) {
									dobInput.classList.add('ff-read-only');
									dobInput.setAttribute('readonly', 'readonly');
									dobInput.style.pointerEvents = 'none';
								} else {
									dobInput.classList.remove('ff-read-only');
									dobInput.removeAttribute('readonly');
									dobInput.style.pointerEvents = '';
								}
							} else if (Date.now() < deadline) {
								setTimeout(function() { trySetDob(deadline); }, 100);
							}
						})(Date.now() + 3000);
					}

					if (childSelect) {
						childSelect.addEventListener('change', updateUnit);

						var nonPlaceholderOptions = Array.from(childSelect.options).filter(function(o) {
							return o.value && window.emsFormMappings[o.value];
						});

						if (nonPlaceholderOptions.length === 1) {
							var targetVal = nonPlaceholderOptions[0].value;
							(function trySetChild(deadline) {
								var choices = emsGetChoices(childSelect);
								if (choices) {
									try {
										choices.setChoiceByValue(targetVal);
										childSelect.dispatchEvent(new Event('change', { bubbles: true }));
									} catch (e) {
										console.warn('[EMS Sync] Choices.js failed to set child choice:', targetVal, e);
									}
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

					// --- OTP Verification Logic (Event Delegation) ---
					document.addEventListener('click', function(e) {
						var btn = e.target.closest('.ems-otp-wrap button');
						if (!btn) return;
						e.preventDefault();
						console.log('[EMS Sync] OTP Send Code button clicked:', btn);

						var container = btn.closest('.ems-otp-wrap');
						if (!container) {
							console.warn('[EMS Sync] OTP button wrapper not found for click.');
							return;
						}
						var targetField = container.getAttribute('data-target');
						if (!targetField) {
							console.warn('[EMS Sync] data-target attribute missing on OTP wrapper.');
							return;
						}
						console.log('[EMS Sync] Target email field name:', targetField);

						var emailInput = document.querySelector('input[name="' + targetField + '"]');
						var statusText = container.querySelector('.fluent-otp-status');

						if (!emailInput) {
							console.error('[EMS Sync] Email input element not found for name:', targetField);
							return;
						}
						if (!statusText) {
							console.log('[EMS Sync] Status text container missing, creating dynamically...');
							statusText = document.createElement('span');
							statusText.className = 'fluent-otp-status';
							statusText.style.marginLeft = '10px';
							statusText.style.fontSize = '0.9em';
							btn.parentNode.insertBefore(statusText, btn.nextSibling);
						}

						var email = emailInput.value.trim();
						console.log('[EMS Sync] Retrieved email value:', email);

						if (!email || !email.includes('@')) {
							statusText.style.color = '#dc3545';
							statusText.textContent = 'Please enter a valid email address first.';
							console.warn('[EMS Sync] Invalid email format, blocking dispatch.');
							return;
						}

						btn.disabled = true;
						statusText.style.color = '#6c757d';
						statusText.textContent = 'Sending code...';
						console.log('[EMS Sync] Sending AJAX request to send_fluent_otp...');

						var formData = new FormData();
						formData.append('action', 'send_fluent_otp');
						formData.append('email', email);
						formData.append('field_name', targetField);
						formData.append('security', window.emsFields.nonce);

						fetch(window.emsFields.ajaxUrl, {
							method: 'POST',
							body: formData,
							credentials: 'same-origin'
						})
						.then(function(res) { 
							console.log('[EMS Sync] Received response from send_fluent_otp server:', res);
							return res.json(); 
						})
						.then(function(data) {
							console.log('[EMS Sync] Decoded server response:', data);
							if (data.success) {
								statusText.style.color = '#28a745';
								statusText.textContent = data.data.message || 'Code sent! Check your inbox.';
								
								var countdown = 60;
								btn.textContent = 'Resend in ' + countdown + 's';
								var interval = setInterval(function() {
									countdown--;
									btn.textContent = 'Resend in ' + countdown + 's';
									if (countdown <= 0) {
										clearInterval(interval);
										btn.disabled = false;
										btn.textContent = 'Resend Verification Code';
									}
								}, 1000);
							} else {
								btn.disabled = false;
								statusText.style.color = '#dc3545';
								statusText.textContent = data.data.message || 'Error sending code.';
							}
						})
						.catch(function(err) {
							btn.disabled = false;
							statusText.style.color = '#dc3545';
							statusText.textContent = 'Network error. Please try again.';
							console.error('[EMS Sync] Fetch error in send_fluent_otp:', err);
						});
					});

					// Real-time inline verification (Event Delegation)
					function checkOtp(otpInput, emailFieldName) {
						if (window.emsFields.isLoggedIn && emailFieldName === window.emsFields.parentEmailField) {
							return;
						}
						var emailInput = document.querySelector('input[name="' + emailFieldName + '"]');
						if (!emailInput) {
							console.warn('[EMS Sync] Email input not found for inline verify name:', emailFieldName);
							return;
						}
						var email = emailInput.value.trim();
						var code = otpInput.value.trim();
						var otpFieldName = (emailFieldName === window.emsFields.parentEmailField) ? window.emsFields.parentOtpField : window.emsFields.explorerOtpField;
						console.log('[EMS Sync] Inline checkOtp triggered. Email:', email, 'Code:', code);

						var container = otpInput.closest('.ff-el-group');
						var statusEl = container ? container.querySelector('.ems-inline-otp-status') : null;
						if (container && !statusEl) {
							statusEl = document.createElement('span');
							statusEl.className = 'ems-inline-otp-status';
							statusEl.style.marginLeft = '10px';
							statusEl.style.fontSize = '0.9em';
							otpInput.parentNode.insertBefore(statusEl, otpInput.nextSibling);
						}

						if (!email || code.length !== 6) {
							if (statusEl) statusEl.textContent = '';
							otpInput.style.borderColor = '';
							return;
						}

						if (code === '000000') {
							var explorerEmailInput = document.querySelector('input[name="' + window.emsFields.explorerEmailField + '"]');
							var parentEmailInput   = document.querySelector('input[name="' + window.emsFields.parentEmailField + '"]');
							var explorerEmail = (explorerEmailInput ? explorerEmailInput.value.trim() : sessionStorage.getItem('ems_val_' + window.emsFields.explorerEmailField)) || '';
							var parentEmail   = (parentEmailInput ? parentEmailInput.value.trim() : sessionStorage.getItem('ems_val_' + window.emsFields.parentEmailField)) || '';
							
							if (explorerEmail && explorerEmail === parentEmail && otpFieldName === window.emsFields.explorerOtpField) {
								console.log('[EMS Sync] checkOtp: detected duplicate bypass code 000000, skipping AJAX verification.');
								if (statusEl) {
									statusEl.style.color = '#28a745';
									statusEl.textContent = '✓ Email verified!';
								}
								otpInput.style.borderColor = '#28a745';
								emailInput.setAttribute('readonly', 'readonly');
								emailInput.classList.add('ff-read-only');
								sessionStorage.setItem('ems_verified_' + emailFieldName, 'true');
								return;
							}
						}

						if (statusEl) {
							statusEl.style.color = '#6c757d';
							statusEl.textContent = 'Verifying code...';
						}
						console.log('[EMS Sync] Sending AJAX request to verify_fluent_otp for code:', code);

						var formData = new FormData();
						formData.append('action', 'verify_fluent_otp');
						formData.append('email', email);
						formData.append('field_name', emailFieldName);
						formData.append('code', code);
						formData.append('security', window.emsFields.nonce);

						fetch(window.emsFields.ajaxUrl, {
							method: 'POST',
							body: formData,
							credentials: 'same-origin'
						})
						.then(function(res) { return res.json(); })
						.then(function(data) {
							console.log('[EMS Sync] verify_fluent_otp response:', data);
							if (data.success) {
								sessionStorage.setItem('ems_verified_' + emailFieldName, 'true');
								sessionStorage.setItem('ems_val_' + otpFieldName, code);
								if (statusEl) {
									statusEl.style.color = '#28a745';
									statusEl.textContent = '✓ ' + (data.data.message || 'Email verified!');
								}
								otpInput.style.borderColor = '#28a745';
								emailInput.setAttribute('readonly', 'readonly');
								emailInput.classList.add('ff-read-only');
							} else {
								sessionStorage.removeItem('ems_verified_' + emailFieldName);
								if (statusEl) {
									statusEl.style.color = '#dc3545';
									statusEl.textContent = '✗ ' + (data.data.message || 'Incorrect code.');
								}
								otpInput.style.borderColor = '#dc3545';
								emailInput.removeAttribute('readonly');
								emailInput.classList.remove('ff-read-only');
							}
						})
						.catch(function(err) {
							if (statusEl) {
								statusEl.style.color = '#dc3545';
								statusEl.textContent = 'Verification error.';
							}
							console.error('[EMS Sync] verify_fluent_otp fetch error:', err);
						});
					}

					document.addEventListener('input', function(e) {
						var target = e.target;
						if (!target || !target.name) return;
						if (target.name === window.emsFields.parentOtpField) {
							checkOtp(target, window.emsFields.parentEmailField);
						} else if (target.name === window.emsFields.explorerOtpField) {
							checkOtp(target, window.emsFields.explorerEmailField);
						}
					});

					// Helper to update React-controlled inputs safely
					function setInputValue(input, value) {
						if (!input) return;
						var nativeInputValueSetter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value') ? 
							Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set : null;
						if (nativeInputValueSetter) {
							nativeInputValueSetter.call(input, value);
						} else {
							input.value = value;
						}
						input.dispatchEvent(new Event('input', { bubbles: true }));
						input.dispatchEvent(new Event('change', { bubbles: true }));
					}

					// Helper to display/hide duplicate bypass warning banner
					function showDuplicateMessage(emailInput, show) {
						if (!emailInput) return;
						var dupStatusEl = emailInput.parentNode.querySelector('.ems-dup-email-status');
						if (show) {
							if (!dupStatusEl) {
								dupStatusEl = document.createElement('div');
								dupStatusEl.className = 'ems-dup-email-status';
								dupStatusEl.style.marginTop = '8px';
								dupStatusEl.style.padding = '6px 12px';
								dupStatusEl.style.backgroundColor = '#e6f4ea';
								dupStatusEl.style.color = '#137333';
								dupStatusEl.style.borderRadius = '4px';
								dupStatusEl.style.border = '1px solid #ceead6';
								dupStatusEl.style.fontSize = '0.9em';
								dupStatusEl.style.fontWeight = '500';
								emailInput.parentNode.insertBefore(dupStatusEl, emailInput.nextSibling);
							}
							dupStatusEl.style.setProperty('display', 'block', 'important');
							dupStatusEl.textContent = '✓ Email matches verified duplicate field (Verification bypassed).';
						} else {
							if (dupStatusEl) {
								dupStatusEl.style.setProperty('display', 'none', 'important');
								dupStatusEl.textContent = '';
							}
						}
					}

					// Dynamic Deduplication (Event Delegation)
					function checkDeduplicate() {
						var explorerEmailInput = document.querySelector('input[name="' + window.emsFields.explorerEmailField + '"]');
						var parentEmailInput   = document.querySelector('input[name="' + window.emsFields.parentEmailField + '"]');
						var explorerOtpInput   = document.querySelector('input[name="' + window.emsFields.explorerOtpField + '"]');
						var parentOtpInput     = document.querySelector('input[name="' + window.emsFields.parentOtpField + '"]');
						
						var explorerEmail = (explorerEmailInput ? explorerEmailInput.value.trim() : sessionStorage.getItem('ems_val_' + window.emsFields.explorerEmailField)) || '';
						var parentEmail   = (parentEmailInput ? parentEmailInput.value.trim() : sessionStorage.getItem('ems_val_' + window.emsFields.parentEmailField)) || '';

						console.log('[EMS Sync] checkDeduplicate check. explorerEmailInput:', !!explorerEmailInput, 'parentEmailInput:', !!parentEmailInput, 'explorerOtpInput:', !!explorerOtpInput, 'parentOtpInput:', !!parentOtpInput);
						console.log('[EMS Sync] comparing explorerEmail:', explorerEmail, 'with parentEmail:', parentEmail);

						if (explorerEmail && explorerEmail === parentEmail) {
							console.log('[EMS Sync] Duplicate email detected.');
							
							var parentTime = parseInt(sessionStorage.getItem('ems_time_' + window.emsFields.parentEmailField) || '0', 10);
							var explorerTime = parseInt(sessionStorage.getItem('ems_time_' + window.emsFields.explorerEmailField) || '0', 10);
							
							var secondField = window.emsFields.explorerEmailField; // Default
							if (parentTime > explorerTime) {
								secondField = window.emsFields.parentEmailField;
							}
							
							console.log('[EMS Sync] secondField entered is:', secondField);

							if (secondField === window.emsFields.explorerEmailField) {
								// Explorer is second -> bypass explorer
								// 1. Bypass explorer OTP
								if (explorerOtpInput) {
									var otpGroup = explorerOtpInput.closest('.ff-el-group') || explorerOtpInput.closest('.ff-el-form-element') || explorerOtpInput.parentElement;
									if (otpGroup) otpGroup.style.setProperty('display', 'none', 'important');
									setInputValue(explorerOtpInput, '000000');
								}
								var btnWrap  = document.querySelector('.ems-otp-wrap[data-target="' + window.emsFields.explorerEmailField + '"]');
								if (btnWrap) btnWrap.style.setProperty('display', 'none', 'important');

								// 2. Restore parent OTP (since it is first/verified)
								if (parentOtpInput && !window.emsFields.isLoggedIn) {
									var parentGroup = parentOtpInput.closest('.ff-el-group') || parentOtpInput.closest('.ff-el-form-element') || parentOtpInput.parentElement;
									if (parentGroup && !parentOtpInput.classList.contains('ff-read-only') && sessionStorage.getItem('ems_verified_' + window.emsFields.parentEmailField) !== 'true') {
										parentGroup.style.removeProperty('display');
									}
									if (parentOtpInput.value === '000000') {
										setInputValue(parentOtpInput, '');
									}
								}
								var parentBtnWrap = document.querySelector('.ems-otp-wrap[data-target="' + window.emsFields.parentEmailField + '"]');
								if (parentBtnWrap && !window.emsFields.isLoggedIn && sessionStorage.getItem('ems_verified_' + window.emsFields.parentEmailField) !== 'true') {
									parentBtnWrap.style.removeProperty('display');
								}

								// 3. Show message on explorer email field, hide message on parent email field
								showDuplicateMessage(explorerEmailInput, true);
								showDuplicateMessage(parentEmailInput, false);

							} else {
								// Parent is second -> bypass parent (Only if parent is NOT logged in. Logged in parent is always bypassed)
								if (window.emsFields.isLoggedIn) {
									showDuplicateMessage(explorerEmailInput, false);
									showDuplicateMessage(parentEmailInput, false);
								} else {
									// 1. Bypass parent OTP
									if (parentOtpInput) {
										var otpGroup = parentOtpInput.closest('.ff-el-group') || parentOtpInput.closest('.ff-el-form-element') || parentOtpInput.parentElement;
										if (otpGroup) otpGroup.style.setProperty('display', 'none', 'important');
										setInputValue(parentOtpInput, '000000');
									}
									var btnWrap  = document.querySelector('.ems-otp-wrap[data-target="' + window.emsFields.parentEmailField + '"]');
									if (btnWrap) btnWrap.style.setProperty('display', 'none', 'important');

									// 2. Restore explorer OTP (since it is first/verified)
									if (explorerOtpInput) {
										var explorerGroup = explorerOtpInput.closest('.ff-el-group') || explorerOtpInput.closest('.ff-el-form-element') || explorerOtpInput.parentElement;
										if (explorerGroup && !explorerOtpInput.classList.contains('ff-read-only') && sessionStorage.getItem('ems_verified_' + window.emsFields.explorerEmailField) !== 'true') {
											explorerGroup.style.removeProperty('display');
										}
										if (explorerOtpInput.value === '000000') {
											setInputValue(explorerOtpInput, '');
										}
									}
									var explorerBtnWrap = document.querySelector('.ems-otp-wrap[data-target="' + window.emsFields.explorerEmailField + '"]');
									if (explorerBtnWrap && sessionStorage.getItem('ems_verified_' + window.emsFields.explorerEmailField) !== 'true') {
										explorerBtnWrap.style.removeProperty('display');
									}

									// 3. Show message on parent email field, hide message on explorer email field
									showDuplicateMessage(parentEmailInput, true);
									showDuplicateMessage(explorerEmailInput, false);
								}
							}
						} else {
							// No duplicate: restore both fields to normal
							if (explorerOtpInput) {
								var explorerGroup = explorerOtpInput.closest('.ff-el-group') || explorerOtpInput.closest('.ff-el-form-element') || explorerOtpInput.parentElement;
								if (explorerGroup) explorerGroup.style.removeProperty('display');
								if (explorerOtpInput.value === '000000') {
									setInputValue(explorerOtpInput, '');
								}
							}
							var explorerBtnWrap = document.querySelector('.ems-otp-wrap[data-target="' + window.emsFields.explorerEmailField + '"]');
							if (explorerBtnWrap) explorerBtnWrap.style.removeProperty('display');

							if (parentOtpInput && !window.emsFields.isLoggedIn) {
								var parentGroup = parentOtpInput.closest('.ff-el-group') || parentOtpInput.closest('.ff-el-form-element') || parentOtpInput.parentElement;
								if (parentGroup) parentGroup.style.removeProperty('display');
								if (parentOtpInput.value === '000000') {
									setInputValue(parentOtpInput, '');
								}
							}
							var parentBtnWrap = document.querySelector('.ems-otp-wrap[data-target="' + window.emsFields.parentEmailField + '"]');
							if (parentBtnWrap && !window.emsFields.isLoggedIn) parentBtnWrap.style.removeProperty('display');

							showDuplicateMessage(explorerEmailInput, false);
							showDuplicateMessage(parentEmailInput, false);
						}
					}

					// Multi-Page State Sync & Restoration
					function syncFormState() {
						var parentEmailInput   = document.querySelector('input[name="' + window.emsFields.parentEmailField + '"]');
						var parentOtpInput     = document.querySelector('input[name="' + window.emsFields.parentOtpField + '"]');
						var explorerEmailInput = document.querySelector('input[name="' + window.emsFields.explorerEmailField + '"]');
						var explorerOtpInput   = document.querySelector('input[name="' + window.emsFields.explorerOtpField + '"]');

						// 0. If user is logged in, make parent name fields read-only and bypass parent OTP code validation
						if (window.emsFields.isLoggedIn) {
							var parentNameInputs = document.querySelectorAll('input[name^="names["], input[name="parent_name"], input[name^="parent_name["]');
							parentNameInputs.forEach(function(input) {
								input.setAttribute('readonly', 'readonly');
								input.classList.add('ff-read-only');
								input.style.pointerEvents = 'none';
							});

							if (parentOtpInput && parentOtpInput.value !== '000000') {
								setInputValue(parentOtpInput, '000000');
							}
						}

						// 1. Sync values to sessionStorage when elements are visible
						if (parentEmailInput) sessionStorage.setItem('ems_val_' + window.emsFields.parentEmailField, parentEmailInput.value.trim());
						if (parentOtpInput) {
							if (parentOtpInput.value.trim()) {
								sessionStorage.setItem('ems_val_' + window.emsFields.parentOtpField, parentOtpInput.value.trim());
							}
						}
						if (explorerEmailInput) sessionStorage.setItem('ems_val_' + window.emsFields.explorerEmailField, explorerEmailInput.value.trim());
						if (explorerOtpInput) {
							if (explorerOtpInput.value !== '000000' && explorerOtpInput.value.trim()) {
								sessionStorage.setItem('ems_val_' + window.emsFields.explorerOtpField, explorerOtpInput.value.trim());
							}
						}

						// 2. Restore verified states (readonly and badge)
						[window.emsFields.parentEmailField, window.emsFields.explorerEmailField].forEach(function(emailFieldName) {
							var emailInput = document.querySelector('input[name="' + emailFieldName + '"]');
							if (!emailInput) return;
							
							var isVerified = sessionStorage.getItem('ems_verified_' + emailFieldName) === 'true';
							if (window.emsFields.isLoggedIn && emailFieldName === window.emsFields.parentEmailField) {
								isVerified = true;
							}

							if (isVerified) {
								emailInput.setAttribute('readonly', 'readonly');
								emailInput.classList.add('ff-read-only');
								
								// Ensure success badge is drawn
								var otpFieldName = (emailFieldName === window.emsFields.parentEmailField) ? window.emsFields.parentOtpField : window.emsFields.explorerOtpField;
								var otpInput = document.querySelector('input[name="' + otpFieldName + '"]');
								if (otpInput && (!window.emsFields.isLoggedIn || emailFieldName !== window.emsFields.parentEmailField)) {
									var container = otpInput.closest('.ff-el-group');
									var statusEl = container ? container.querySelector('.ems-inline-otp-status') : null;
									if (container && !statusEl) {
										statusEl = document.createElement('span');
										statusEl.className = 'ems-inline-otp-status';
										statusEl.style.marginLeft = '10px';
										statusEl.style.fontSize = '0.9em';
										otpInput.parentNode.insertBefore(statusEl, otpInput.nextSibling);
									}
									if (statusEl) {
										statusEl.style.color = '#28a745';
										statusEl.textContent = '✓ Email verified!';
									}
									otpInput.style.borderColor = '#28a745';
									
									// Restore code value if empty in DOM
									var cachedCode = sessionStorage.getItem('ems_val_' + otpFieldName);
									if (cachedCode && !otpInput.value) {
										setInputValue(otpInput, cachedCode);
									}
								}
							}
						});

						// 3. Deduplicate
						checkDeduplicate();
					}

					document.addEventListener('input', function(e) {
						var name = e.target.name;
						if (name === window.emsFields.explorerEmailField || name === window.emsFields.parentEmailField) {
							sessionStorage.setItem('ems_time_' + name, Date.now().toString());
							syncFormState();
						}
					});
					document.addEventListener('change', function(e) {
						var name = e.target.name;
						if (name === window.emsFields.explorerEmailField || name === window.emsFields.parentEmailField) {
							sessionStorage.setItem('ems_time_' + name, Date.now().toString());
							syncFormState();
						}
					});
					document.addEventListener('keyup', function(e) {
						var name = e.target.name;
						if (name === window.emsFields.explorerEmailField || name === window.emsFields.parentEmailField) {
							sessionStorage.setItem('ems_time_' + name, Date.now().toString());
							syncFormState();
						}
					});

					// Periodic polling handles page-change rendering instantly
					setInterval(syncFormState, 1000);
					setTimeout(syncFormState, 500);
				}
				initEmsFormSync();
			});
		</script>
		<?php
	}

	private function get_allowed_children_for_user( int $user_id ): array {
		$children = get_user_meta( $user_id, 'ems_children', true );
		if ( ! empty( $children ) && is_array( $children ) ) {
			return $children;
		}

		$access_type = get_user_meta( $user_id, 'ems_access_type', true );
		if ( $access_type === 'member' || $access_type === 'network_member' ) {
			$scout_ids = get_user_meta( $user_id, 'ems_scout_ids', true ) ?: array();
			if ( ! empty( $scout_ids ) ) {
				$user = get_userdata( $user_id );
				return array(
					array(
						'scout_id'    => (int) $scout_ids[0],
						'first_name'  => get_user_meta( $user_id, 'first_name', true ) ?: ( $user->first_name ?? '' ),
						'last_name'   => get_user_meta( $user_id, 'last_name', true ) ?: ( $user->last_name ?? '' ),
						'section_ids' => get_user_meta( $user_id, 'ems_section_ids', true ) ?: array(),
						'patrol'      => get_user_meta( $user_id, 'ems_unit', true ) ?: '',
					)
				);
			}
		}
		return array();
	}

	public function handle_send_fluent_otp(): void {
		check_ajax_referer( 'fluent_otp_nonce', 'security' );

		$email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$field_name = isset( $_POST['field_name'] ) ? sanitize_key( wp_unslash( $_POST['field_name'] ) ) : '';

		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Please provide a valid email address.', 'ems-plugin' ) ) );
		}

		$rate_limit_key = 'fluent_otp_limit_' . md5( $email . '_' . $field_name );
		if ( get_transient( $rate_limit_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Please wait 60 seconds before requesting another code.', 'ems-plugin' ) ) );
		}

		$otp = wp_rand( 100000, 999999 );
		$transient_key = 'fluent_otp_' . md5( $email . '_' . $field_name );

		set_transient( $transient_key, hash( 'sha256', (string) $otp ), 30 * MINUTE_IN_SECONDS );
		set_transient( $rate_limit_key, true, 60 );

		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject   = sprintf( __( '[%s] Your Verification Code: %s', 'ems-plugin' ), $site_name, $otp );
		
		$message  = "Hello,\n\n";
		$message .= "Your verification code is: {$otp}\n\n";
		$message .= "This code is valid for 30 minutes. If you did not request this code, please ignore this email.\n\n";
		$message .= "Regards,\n{$site_name}";

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		$mail_sent = wp_mail( $email, $subject, $message, $headers );

		if ( $mail_sent ) {
			wp_send_json_success( array( 'message' => __( 'Verification code sent to your email.', 'ems-plugin' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to send verification email. Please try again.', 'ems-plugin' ) ) );
		}
	}

	public function handle_verify_fluent_otp(): void {
		check_ajax_referer( 'fluent_otp_nonce', 'security' );

		$email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$field_name = isset( $_POST['field_name'] ) ? sanitize_key( wp_unslash( $_POST['field_name'] ) ) : '';
		$code       = isset( $_POST['code'] ) ? sanitize_text_field( trim( wp_unslash( $_POST['code'] ) ) ) : '';

		if ( ! is_email( $email ) || empty( $code ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid parameters.', 'ems-plugin' ) ) );
		}

		$transient_key = 'fluent_otp_' . md5( $email . '_' . $field_name );
		$stored_hash   = get_transient( $transient_key );

		if ( ! $stored_hash ) {
			wp_send_json_error( array( 'message' => __( 'Verification code has expired or not requested.', 'ems-plugin' ) ) );
		}

		if ( hash_equals( $stored_hash, hash( 'sha256', $code ) ) ) {
			wp_send_json_success( array( 'message' => __( 'Email verified!', 'ems-plugin' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Incorrect verification code.', 'ems-plugin' ) ) );
		}
	}

	public function validate_email_otp( $errorMessage, $field, $formData, $fields, $form ) {
		$form_id             = (int) ( is_array( $form ) ? ( $form['id'] ?? 0 ) : ( $form->id ?? 0 ) );
		$participant_form_id = (int) get_option( 'ems_fluent_participant_form_id', 6 );
		$expedition_form_id  = (int) get_option( 'ems_fluent_expedition_form_id', 7 );

		$config = array();
		if ( $form_id === $participant_form_id ) {
			$config = get_option( 'ems_participant_form_mappings', array() );
		} elseif ( $form_id === $expedition_form_id ) {
			$config = get_option( 'ems_expedition_form_mappings', array() );
		}

		$parent_email_field   = $config['parent_email_field'] ?? 'signup_parent_email';
		$parent_otp_field     = $config['parent_otp_field'] ?? 'signup_parent_otp_code';
		$explorer_email_field = $config['explorer_email_field'] ?? 'signup_explorer_email';
		$explorer_otp_field   = $config['explorer_otp_field'] ?? 'signup_explorer_otp_code';

		$current_field = $field['name'] ?? '';

		if ( $current_field !== $parent_email_field && $current_field !== $explorer_email_field ) {
			return $errorMessage;
		}

		if ( $current_field === $parent_email_field && is_user_logged_in() ) {
			return $errorMessage;
		}

		$email = isset( $formData[ $current_field ] ) ? sanitize_email( $formData[ $current_field ] ) : '';
		if ( empty( $email ) ) {
			return $errorMessage;
		}

		if ( $current_field === $explorer_email_field ) {
			$parent_email = isset( $formData[ $parent_email_field ] ) ? sanitize_email( $formData[ $parent_email_field ] ) : '';
			if ( $email === $parent_email ) {
				if ( is_user_logged_in() ) {
					return $errorMessage;
				}
				if ( $this->parent_email_verified ) {
					return $errorMessage;
				}
				$parent_code = isset( $formData[ $parent_otp_field ] ) ? sanitize_text_field( trim( $formData[ $parent_otp_field ] ) ) : '';
				if ( ! empty( $parent_code ) ) {
					$parent_transient_key = 'fluent_otp_' . md5( $parent_email . '_' . $parent_email_field );
					$parent_stored_hash   = get_transient( $parent_transient_key );
					if ( $parent_stored_hash && hash_equals( $parent_stored_hash, hash( 'sha256', $parent_code ) ) ) {
						return $errorMessage;
					}
				}
			}
		} elseif ( $current_field === $parent_email_field ) {
			$explorer_email = isset( $formData[ $explorer_email_field ] ) ? sanitize_email( $formData[ $explorer_email_field ] ) : '';
			if ( $email === $explorer_email ) {
				if ( $this->explorer_email_verified ) {
					return $errorMessage;
				}
				$explorer_code = isset( $formData[ $explorer_otp_field ] ) ? sanitize_text_field( trim( $formData[ $explorer_otp_field ] ) ) : '';
				if ( ! empty( $explorer_code ) ) {
					$explorer_transient_key = 'fluent_otp_' . md5( $explorer_email . '_' . $explorer_email_field );
					$explorer_stored_hash   = get_transient( $explorer_transient_key );
					if ( $explorer_stored_hash && hash_equals( $explorer_stored_hash, hash( 'sha256', $explorer_code ) ) ) {
						return $errorMessage;
					}
				}
			}
		}

		$otp_field_name = ( $current_field === $parent_email_field ) ? $parent_otp_field : $explorer_otp_field;
		$user_otp       = isset( $formData[ $otp_field_name ] ) ? sanitize_text_field( trim( $formData[ $otp_field_name ] ) ) : '';

		if ( empty( $user_otp ) ) {
			return __( 'Please verify this email address with the 6-digit code.', 'ems-plugin' );
		}

		$transient_key = 'fluent_otp_' . md5( $email . '_' . $current_field );
		$stored_hash   = get_transient( $transient_key );

		if ( ! $stored_hash ) {
			return __( 'The verification code has expired or was not requested.', 'ems-plugin' );
		}

		if ( ! hash_equals( $stored_hash, hash( 'sha256', $user_otp ) ) ) {
			return __( 'The verification code is incorrect.', 'ems-plugin' );
		}

		if ( $current_field === $parent_email_field ) {
			$this->parent_email_verified = true;
		} elseif ( $current_field === $explorer_email_field ) {
			$this->explorer_email_verified = true;
		}

		delete_transient( $transient_key );

		return $errorMessage;
	}

	public function bypass_otp_required_validation( $rules, $form ) {
		if ( ! is_user_logged_in() ) {
			return $rules;
		}

		$form_id             = (int) ( is_array( $form ) ? ( $form['id'] ?? 0 ) : ( $form->id ?? 0 ) );
		$participant_form_id = (int) get_option( 'ems_fluent_participant_form_id', 6 );
		$expedition_form_id  = (int) get_option( 'ems_fluent_expedition_form_id', 7 );

		$config = array();
		if ( $form_id === $participant_form_id ) {
			$config = get_option( 'ems_participant_form_mappings', array() );
		} elseif ( $form_id === $expedition_form_id ) {
			$config = get_option( 'ems_expedition_form_mappings', array() );
		}

		$parent_otp_field = $config['parent_otp_field'] ?? 'signup_parent_otp_code';

		if ( isset( $rules[ $parent_otp_field ]['required'] ) ) {
			unset( $rules[ $parent_otp_field ]['required'] );
		}

		return $rules;
	}

	public function bypass_otp_frontend_required( array $data, $form ): array {
		if ( ! is_user_logged_in() ) {
			return $data;
		}

		$form_id             = (int) ( is_array( $form ) ? ( $form['id'] ?? 0 ) : ( $form->id ?? 0 ) );
		$participant_form_id = (int) get_option( 'ems_fluent_participant_form_id', 6 );
		$expedition_form_id  = (int) get_option( 'ems_fluent_expedition_form_id', 7 );

		$config = array();
		if ( $form_id === $participant_form_id ) {
			$config = get_option( 'ems_participant_form_mappings', array() );
		} elseif ( $form_id === $expedition_form_id ) {
			$config = get_option( 'ems_expedition_form_mappings', array() );
		}

		$parent_otp_field = $config['parent_otp_field'] ?? 'signup_parent_otp_code';

		if ( ( $data['attributes']['name'] ?? '' ) === $parent_otp_field ) {
			unset( $data['attributes']['required'] );
			if ( isset( $data['settings']['validation_rules']['required'] ) ) {
				unset( $data['settings']['validation_rules']['required'] );
			}
		}

		return $data;
	}
}
