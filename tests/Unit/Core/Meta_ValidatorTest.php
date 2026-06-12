<?php
namespace EMS\Tests\Unit\Core;

use EMS\Core\Meta_Validator;
use EMS\Tests\EMSTestCase;

class Meta_ValidatorTest extends EMSTestCase {
    private Meta_Validator $validator;

    protected function setUp(): void {
        parent::setUp();
        $this->validator = new Meta_Validator();
    }

    // -------------------------------------------------------------------
    // expedition meta
    // -------------------------------------------------------------------

    public function test_valid_expedition_levels_are_accepted(): void {
        foreach ( [ 'bronze', 'silver', 'gold' ] as $level ) {
            $this->assertTrue(
                $this->validator->validate_expedition( 'ems_level', $level ),
                "Expected '{$level}' to be valid"
            );
        }
    }

    public function test_invalid_expedition_level_is_rejected(): void {
        $this->assertFalse( $this->validator->validate_expedition( 'ems_level', 'diamond' ) );
    }

    public function test_valid_expedition_types_are_accepted(): void {
        foreach ( [ 'practice', 'qualifying' ] as $type ) {
            $this->assertTrue( $this->validator->validate_expedition( 'ems_type', $type ) );
        }
    }

    public function test_invalid_expedition_type_is_rejected(): void {
        $this->assertFalse( $this->validator->validate_expedition( 'ems_type', 'training' ) );
    }

    public function test_valid_expedition_statuses_are_accepted(): void {
        foreach ( [ 'planning', 'open', 'confirmed', 'completed' ] as $status ) {
            $this->assertTrue( $this->validator->validate_expedition( 'ems_status', $status ) );
        }
    }

    public function test_invalid_expedition_status_is_rejected(): void {
        $this->assertFalse( $this->validator->validate_expedition( 'ems_status', 'cancelled' ) );
    }

    public function test_valid_iso_date_is_accepted(): void {
        $this->assertTrue( $this->validator->validate_expedition( 'ems_start_date', '2026-08-01' ) );
        $this->assertTrue( $this->validator->validate_expedition( 'ems_end_date',   '2026-08-03' ) );
    }

    public function test_invalid_date_format_is_rejected(): void {
        $this->assertFalse( $this->validator->validate_expedition( 'ems_start_date', '01/08/2026' ) );
        $this->assertFalse( $this->validator->validate_expedition( 'ems_start_date', 'not-a-date' ) );
    }

    public function test_empty_expedition_code_is_rejected(): void {
        $this->assertFalse( $this->validator->validate_expedition( 'ems_expedition_code', '' ) );
    }

    public function test_non_empty_expedition_code_is_accepted(): void {
        $this->assertTrue( $this->validator->validate_expedition( 'ems_expedition_code', 'SP1' ) );
    }

    public function test_lic_id_must_be_positive_integer(): void {
        $this->assertTrue(  $this->validator->validate_expedition( 'ems_lic_id', 42 ) );
        $this->assertFalse( $this->validator->validate_expedition( 'ems_lic_id', 0 ) );
        $this->assertFalse( $this->validator->validate_expedition( 'ems_lic_id', -1 ) );
    }

    public function test_unknown_expedition_key_returns_true_permissive(): void {
        $this->assertTrue( $this->validator->validate_expedition( 'ems_location_name', 'Pentland Hills' ) );
    }

    // -------------------------------------------------------------------
    // team meta
    // -------------------------------------------------------------------

    public function test_valid_team_route_statuses_are_accepted(): void {
        foreach ( [ 'pending', 'feedback_required', 'approved' ] as $status ) {
            $this->assertTrue( $this->validator->validate_team( 'ems_route_status', $status ) );
        }
    }

    public function test_invalid_team_route_status_is_rejected(): void {
        $this->assertFalse( $this->validator->validate_team( 'ems_route_status', 'rejected' ) );
    }

    public function test_team_code_must_not_be_empty(): void {
        $this->assertFalse( $this->validator->validate_team( 'ems_team_code', '' ) );
        $this->assertTrue(  $this->validator->validate_team( 'ems_team_code', 'SP1-1' ) );
    }

    public function test_file_id_must_be_non_negative_integer(): void {
        $this->assertTrue(  $this->validator->validate_team( 'ems_gpx_file_id', 0 ) );
        $this->assertTrue(  $this->validator->validate_team( 'ems_gpx_file_id', 99 ) );
        $this->assertFalse( $this->validator->validate_team( 'ems_gpx_file_id', -1 ) );
    }
}
