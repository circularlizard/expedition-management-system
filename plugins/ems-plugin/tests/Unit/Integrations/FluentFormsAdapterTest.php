<?php
declare(strict_types=1);

namespace EMS\Tests\Unit\Integrations;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use EMS\Contracts\DTOs\ExpeditionPreferenceDTO;
use EMS\Contracts\DTOs\ParticipationSignupDTO;
use EMS\Core\Event_Bus;
use EMS\Integrations\FluentForms\FluentFormsAdapter;
use EMS\Tests\EMSTestCase;

class FluentFormsAdapterTest extends EMSTestCase {
	private FluentFormsAdapter $adapter;

	protected function setUp(): void {
		parent::setUp();
		$this->adapter = new FluentFormsAdapter();

		Functions\when( 'get_option' )->alias( function( $key, $default = null ) {
			if ( $key === 'ems_fluent_participant_form_id' ) {
				return 6;
			}
			if ( $key === 'ems_fluent_expedition_form_id' ) {
				return 7;
			}
			if ( $key === 'ems_participant_form_mappings' ) {
				return [
					'scout_id_field'       => 'signup_child',
					'first_name_field'     => 'signup_child_name',
					'last_name_field'      => 'signup_child_name',
					'explorer_email_field' => 'signup_explorer_email',
					'parent_email_field'   => 'signup_parent_email',
				];
			}
			if ( $key === 'ems_expedition_form_mappings' ) {
				return [
					'scout_id_field'       => 'signup_child',
					'first_name_field'     => 'signup_child_name',
					'last_name_field'      => 'signup_child_name',
					'explorer_email_field' => 'signup_email',
					'parent_email_field'   => 'signup_parent_email',
				];
			}
			return $default ?? [];
		} );
	}

	public function test_register_hooks_into_fluentform_submission_inserted(): void {
		$this->adapter->register();
		$this->assertTrue( Actions\has( 'fluentform/submission_inserted' ) );
	}

	public function test_maps_participant_submission_into_immutable_dtos(): void {
		$formData = [
			'signup_child'          => '12345',
			'signup_child_name'     => [
				'first_name' => 'John',
				'last_name'  => 'Doe',
			],
			'signup_explorer_email' => 'john.doe@example.com',
			'signup_level'          => 'Bronze',
			'signup_unit'           => 'Highland ESU',
			'signup_dob'            => '2010-05-15',
			'signup_dofe_number'    => 'EDOFE999',
			'created_at'            => '2026-06-01 10:00:00',
			'unmapped_secret_data'  => 'should_not_leak_here',
		];

		$result = $this->adapter->mapSubmission( 101, $formData, 6 );

		$this->assertInstanceOf( ParticipationSignupDTO::class, $result['signup'] );
		$this->assertInstanceOf( ExpeditionPreferenceDTO::class, $result['preference'] );

		$signup = $result['signup'];
		$this->assertSame( 6, $signup->formId );
		$this->assertSame( 101, $signup->entryId );
		$this->assertSame( '12345', $signup->scoutId );
		$this->assertSame( 'John Doe', $signup->fullName );
		$this->assertSame( 'john.doe@example.com', $signup->email );
		$this->assertSame( '2026-06-01 10:00:00', $signup->submittedAt );
		$this->assertSame( 'Bronze', $signup->preferences['signup_level'] );
		$this->assertSame( 'Highland ESU', $signup->preferences['signup_unit'] );
		$this->assertArrayNotHasKey( 'unmapped_secret_data', $signup->preferences );
	}

	public function test_maps_expedition_preference_submission_into_immutable_dtos(): void {
		$formData = [
			'signup_child'         => '67890',
			'signup_child_name'    => 'Jane Smith',
			'signup_email'         => 'jane.smith@example.com',
			'expedition_id'        => 42,
			'dietary_requirements' => 'Vegetarian, Nut Allergy',
			'medical_notes'        => 'Carries EpiPen',
			'first_aid'            => 'Yes',
			'first_aid_expiry'     => '2027-01-01',
			'signup_dofe_number'   => 'EDOFE777',
			'created_at'           => '2026-06-02 11:30:00',
		];

		$result = $this->adapter->mapSubmission( 202, $formData, 7 );

		$pref = $result['preference'];
		$this->assertSame( 42, $pref->expeditionId );
		$this->assertSame( 'Vegetarian, Nut Allergy', $pref->dietaryRequirements );
		$this->assertSame( 'Carries EpiPen', $pref->medicalNotes );
		$this->assertSame( 'Yes', $pref->additionalData['first_aid'] );
		$this->assertSame( '2027-01-01', $pref->additionalData['first_aid_expiry'] );
		$this->assertSame( 'EDOFE777', $pref->additionalData['dofe_number'] );
	}

	public function test_handle_submission_dispatches_event(): void {
		$form = (object) [ 'id' => 6 ];
		$formData = [
			'signup_child'          => '555',
			'signup_child_name'     => 'Sam Explorer',
			'signup_explorer_email' => 'sam@example.com',
			'signup_level'          => 'Silver',
		];

		$dispatchedPayload = null;
		Actions\expectDone( 'ems.signup.form_submitted' )
			->once()
			->whenHappen( function( $payload ) use ( &$dispatchedPayload ) {
				$dispatchedPayload = $payload;
			} );

		$this->adapter->handleSubmission( 88, $formData, $form );

		$this->assertNotNull( $dispatchedPayload );
		$this->assertInstanceOf( ParticipationSignupDTO::class, $dispatchedPayload->signup );
		$this->assertInstanceOf( ExpeditionPreferenceDTO::class, $dispatchedPayload->preference );
		$this->assertSame( '555', $dispatchedPayload->signup->scoutId );
		$this->assertSame( 'Sam Explorer', $dispatchedPayload->signup->fullName );
	}

	public function test_ignores_non_whitelisted_form_id(): void {
		$form = (object) [ 'id' => 999 ];
		$formData = [ 'foo' => 'bar' ];

		Actions\expectDone( 'ems.signup.form_submitted' )->never();

		$this->adapter->handleSubmission( 10, $formData, $form );
		$this->assertFalse( Actions\did( 'ems.signup.form_submitted' ) > 0 );
	}
}
