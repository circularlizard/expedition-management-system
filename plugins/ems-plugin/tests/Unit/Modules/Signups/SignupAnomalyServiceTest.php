<?php
declare(strict_types=1);

namespace EMS\Tests\Unit\Modules\Signups;

use EMS\Modules\Signups\Services\SignupAnomalyService;
use EMS\Tests\EMSTestCase;

class SignupAnomalyServiceTest extends EMSTestCase {

	public function test_computes_expedition_anomalies_and_ignores_osm_for_participant_only(): void {
		$service = new SignupAnomalyService();

		$participants = array(
			array(
				'id'                  => 1,
				'scout_id'            => 0,
				'explorer_first_name' => 'Alex',
				'explorer_last_name'  => 'Smith',
				'parent_email'        => 'p1@example.com',
				'dofe_level'          => 'bronze',
				'dofe_number'         => '',
				'signup_status'       => 'submitted',
				'kanban_stage'        => 'new',
				'is_synced_osm'       => false,
			),
			array(
				'id'                  => 2,
				'scout_id'            => 101,
				'explorer_first_name' => 'Sam',
				'explorer_last_name'  => 'Jones',
				'parent_email'        => 'p2@example.com',
				'dofe_level'          => 'silver',
				'dofe_number'         => '12345',
				'signup_status'       => 'submitted',
				'kanban_stage'        => 'new',
				'is_synced_osm'       => false,
			),
			array(
				'id'                  => 3,
				'scout_id'            => 101,
				'explorer_first_name' => 'Sam',
				'explorer_last_name'  => 'Jones',
				'parent_email'        => 'p2@example.com',
				'dofe_level'          => 'silver',
				'dofe_number'         => '12345',
				'signup_status'       => 'submitted',
				'kanban_stage'        => 'new',
				'is_synced_osm'       => false,
			),
		);

		$expeditions = array(
			array(
				'id'                          => 10,
				'scout_id'                    => 101,
				'explorer_first_name'         => 'Sam',
				'explorer_last_name'          => 'Jones',
				'parent_email'                => 'p2@example.com',
				'dofe_level'                  => 'bronze',
				'dofe_number'                 => '12345',
				'additional_support_needs'    => 'Asthma',
				'asn_validated'               => null,
				'participant_place_allocated' => 0,
				'signup_status'               => 'submitted',
				'kanban_stage'                => 'new',
				'is_synced_osm'               => false,
			),
			array(
				'id'                          => 11,
				'scout_id'                    => 0,
				'explorer_first_name'         => 'Pat',
				'explorer_last_name'          => 'Lee',
				'parent_email'                => 'p3@example.com',
				'dofe_level'                  => 'gold',
				'dofe_number'                 => '99999',
				'additional_support_needs'    => '',
				'asn_validated'               => null,
				'participant_place_allocated' => 1,
				'signup_status'               => 'submitted',
				'kanban_stage'                => 'new',
				'is_synced_osm'               => false,
			),
		);

		$result = $service->computeAnomalies( $participants, $expeditions );
		$byKey  = $result['by_key'];

		// Participant-only record does NOT get unmatched_osm or pending_osm_sync.
		$this->assertContains( 'place_without_expedition', $byKey['participant_1'] );
		$this->assertContains( 'missing_edofe', $byKey['participant_1'] );
		$this->assertNotContains( 'unmatched_osm', $byKey['participant_1'] );
		$this->assertNotContains( 'pending_osm_sync', $byKey['participant_1'] );

		$this->assertContains( 'duplicate_signup', $byKey['participant_2'] );
		$this->assertContains( 'level_mismatch', $byKey['participant_2'] );

		// Expedition record gets OSM, ASN, and pairing anomalies.
		$this->assertContains( 'expedition_without_place', $byKey['expedition_10'] );
		$this->assertContains( 'unvalidated_asn', $byKey['expedition_10'] );
		$this->assertContains( 'pending_osm_sync', $byKey['expedition_10'] );

		// participant_place_allocated = 1 suppresses expedition_without_place, while unmatched_osm still applies.
		$this->assertNotContains( 'expedition_without_place', $byKey['expedition_11'] );
		$this->assertContains( 'unmatched_osm', $byKey['expedition_11'] );
	}

	public function test_archived_participant_place_satisfies_expedition_pairing(): void {
		$service = new SignupAnomalyService();

		$participants = array(
			array(
				'id'                  => 20,
				'scout_id'            => 555,
				'explorer_first_name' => 'Riley',
				'explorer_last_name'  => 'Kay',
				'parent_email'        => 'p4@example.com',
				'dofe_level'          => 'silver',
				'dofe_number'         => '55555',
				'signup_status'       => 'archived',
				'kanban_stage'        => 'place_allocated',
				'is_synced_osm'       => true,
			),
		);

		$expeditions = array(
			array(
				'id'                          => 21,
				'scout_id'                    => 555,
				'explorer_first_name'         => 'Riley',
				'explorer_last_name'          => 'Kay',
				'parent_email'                => 'p4@example.com',
				'dofe_level'                  => 'silver',
				'dofe_number'                 => '55555',
				'participant_place_allocated' => 0,
				'signup_status'               => 'submitted',
				'kanban_stage'                => 'new',
				'is_synced_osm'               => true,
			),
		);

		$result = $service->computeAnomalies( $participants, $expeditions );
		$this->assertNotContains( 'expedition_without_place', $result['by_key']['expedition_21'] );
	}
}
