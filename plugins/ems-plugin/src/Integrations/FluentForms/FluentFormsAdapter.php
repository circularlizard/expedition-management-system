<?php
declare(strict_types=1);

namespace EMS\Integrations\FluentForms;

use EMS\Contracts\DTOs\ExpeditionPreferenceDTO;
use EMS\Contracts\DTOs\ParticipationSignupDTO;
use EMS\Core\Event_Bus;

class FluentFormsAdapter {
	/**
	 * Register WordPress action hooks.
	 */
	public function register(): void {
		add_action( 'fluentform/submission_inserted', [ $this, 'handleSubmission' ], 10, 3 );
	}

	/**
	 * Alias for register() for backwards-compatibility with init_hooks naming.
	 */
	public function init_hooks(): void {
		$this->register();
	}

	/**
	 * Handle Fluent Forms submission inserted.
	 *
	 * @param int|string          $entryId
	 * @param array<string, mixed>|object $formData
	 * @param mixed               $form
	 */
	public function handleSubmission( mixed $entryId, mixed $formData, mixed $form ): void {
		$formId = 0;
		if ( is_array( $form ) ) {
			$formId = (int) ( $form['id'] ?? 0 );
		} elseif ( is_object( $form ) && isset( $form->id ) ) {
			$formId = (int) $form->id;
		} elseif ( is_numeric( $form ) ) {
			$formId = (int) $form;
		}

		$data = is_array( $formData ) ? $formData : (array) $formData;
		$id   = (int) $entryId;

		$participantFormId = (int) get_option( 'ems_fluent_participant_form_id', 6 );
		$expeditionFormId  = (int) get_option( 'ems_fluent_expedition_form_id', 7 );

		// Process if it matches participant or expedition forms, or if custom mappings exist
		if ( $formId > 0 && $participantFormId > 0 && $expeditionFormId > 0 ) {
			if ( $formId !== $participantFormId && $formId !== $expeditionFormId ) {
				return;
			}
		}

		$dtoPair = $this->mapSubmission( $id, $data, $formId );
		$signupDto     = $dtoPair['signup'];
		$preferenceDto = $dtoPair['preference'];

		$payload = (object) [
			'signup'     => $signupDto,
			'preference' => $preferenceDto,
		];

		if ( class_exists( Event_Bus::class ) && method_exists( Event_Bus::class, 'dispatch' ) ) {
			Event_Bus::dispatch( 'ems.signup.form_submitted', $payload );
		} else {
			do_action( 'ems.signup.form_submitted', $payload, $signupDto, $preferenceDto );
		}
	}

	/**
	 * Maps raw form data into immutable DTOs without leaking raw input or querying external systems.
	 *
	 * @param int                  $entryId
	 * @param array<string, mixed> $formData
	 * @param int                  $formId
	 * @return array{signup: ParticipationSignupDTO, preference: ExpeditionPreferenceDTO}
	 */
	public function mapSubmission( int $entryId, array $formData, int $formId = 0 ): array {
		// Resolve config mappings if present
		$config = ( $formId === (int) get_option( 'ems_fluent_participant_form_id', 6 ) )
			? (array) get_option( 'ems_participant_form_mappings', [] )
			: (array) get_option( 'ems_expedition_form_mappings', [] );

		$scoutIdField   = (string) ( $config['scout_id_field'] ?? 'signup_child' );
		$firstNameField = (string) ( $config['first_name_field'] ?? 'signup_child_name' );
		$lastNameField  = (string) ( $config['last_name_field'] ?? 'signup_child_name' );
		$emailField     = (string) ( $config['explorer_email_field'] ?? 'signup_explorer_email' );
		$parentEmailField = (string) ( $config['parent_email_field'] ?? 'signup_parent_email' );

		// 1. Scout ID
		$rawScoutId = $formData[ $scoutIdField ] ?? $formData['scout_id'] ?? $formData['signup_scoutid'] ?? '';
		$scoutId    = is_scalar( $rawScoutId ) ? (string) $rawScoutId : '';

		// 2. Full Name
		$rawName = $formData[ $firstNameField ] ?? $formData['full_name'] ?? $formData['name'] ?? null;
		$fullName = '';
		if ( is_array( $rawName ) ) {
			$first    = (string) ( $rawName['first_name'] ?? '' );
			$last     = (string) ( $rawName['last_name'] ?? '' );
			$fullName = trim( "{$first} {$last}" );
		} elseif ( is_string( $rawName ) && $rawName !== '' ) {
			$fullName = trim( $rawName );
			if ( $firstNameField !== $lastNameField && ! empty( $formData[ $lastNameField ] ) ) {
				$last = (string) $formData[ $lastNameField ];
				$fullName = trim( "{$fullName} {$last}" );
			}
		} elseif ( ! empty( $formData['first_name'] ) || ! empty( $formData['last_name'] ) ) {
			$fullName = trim( (string) ( $formData['first_name'] ?? '' ) . ' ' . (string) ( $formData['last_name'] ?? '' ) );
		}

		// 3. Email
		$rawEmail = $formData[ $emailField ] ?? $formData['signup_email'] ?? $formData['email'] ?? $formData[ $parentEmailField ] ?? '';
		$email    = is_string( $rawEmail ) ? sanitize_email( $rawEmail ) : '';

		// 4. Submitted at
		$submittedAt = (string) ( $formData['created_at'] ?? $formData['submitted_at'] ?? current_time( 'mysql' ) );

		// 5. Preferences
		$preferences = [];
		$possiblePrefKeys = [
			'signup_level',
			'dofe_level',
			'exped_type',
			'exped_type_bronze',
			'exped_bronze_dates',
			'exped_practice_dates',
			'exped_qualifier_dates',
			'exped_silver_practice_dates',
			'exped_gold_practice_dates',
			'exped_silver_qualifier_dates',
			'exped_gold_qualifier_dates',
			'exped_team_names',
			'signup_unit',
			'signup_dob',
			'signup_dofe_registered',
			'signup_edofe_registered',
			'signup_dofe_number',
			'signup_dofe_org',
		];

		foreach ( $possiblePrefKeys as $key ) {
			if ( isset( $formData[ $key ] ) ) {
				$val = $formData[ $key ];
				if ( is_scalar( $val ) ) {
					$preferences[ $key ] = sanitize_text_field( (string) $val );
				} elseif ( is_array( $val ) ) {
					$preferences[ $key ] = $val;
				}
			}
		}

		$signupDto = new ParticipationSignupDTO(
			formId: $formId,
			entryId: $entryId,
			scoutId: $scoutId,
			fullName: $fullName,
			email: $email,
			preferences: $preferences,
			submittedAt: $submittedAt
		);

		// Expedition Preference DTO
		$expeditionId = (int) ( $formData['expedition_id'] ?? $formData['event_id'] ?? $formData['expedition'] ?? 0 );
		$dietary = (string) ( $formData['dietary_requirements'] ?? $formData['dietary'] ?? $formData['dietary_notes'] ?? '' );
		$medical = (string) ( $formData['medical_notes'] ?? $formData['medical'] ?? $formData['description'] ?? $formData['asn'] ?? '' );

		$additionalData = [];
		if ( isset( $formData['input_radio'] ) || isset( $formData['first_aid'] ) ) {
			$additionalData['first_aid'] = sanitize_text_field( (string) ( $formData['first_aid'] ?? $formData['input_radio'] ) );
		}
		if ( isset( $formData['datetime'] ) || isset( $formData['first_aid_expiry'] ) ) {
			$additionalData['first_aid_expiry'] = sanitize_text_field( (string) ( $formData['first_aid_expiry'] ?? $formData['datetime'] ) );
		}
		if ( ! empty( $preferences['signup_dofe_number'] ) ) {
			$additionalData['dofe_number'] = $preferences['signup_dofe_number'];
		}

		$preferenceDto = new ExpeditionPreferenceDTO(
			expeditionId: $expeditionId,
			dietaryRequirements: sanitize_text_field( $dietary ),
			medicalNotes: sanitize_textarea_field( $medical ),
			additionalData: $additionalData
		);

		return [
			'signup'     => $signupDto,
			'preference' => $preferenceDto,
		];
	}
}
