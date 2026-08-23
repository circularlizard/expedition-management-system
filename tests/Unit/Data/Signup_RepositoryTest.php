<?php
namespace EMS\Tests\Unit\Data;

use EMS\Data\Signup_Repository;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;

class Signup_RepositoryTest extends EMSTestCase {

    public function test_create_participant_signup_inserts_correct_record(): void {
        $wpdb = new class {
            public $prefix = 'wp_';
            public $inserted = [];
            public $insert_id = 99;

            public function insert( string $table, array $data, array $format = [] ) {
                $this->inserted[] = [ 'table' => $table, 'data' => $data, 'format' => $format ];
                return 1;
            }
        };

        $repo = new Signup_Repository( $wpdb );
        $id = $repo->create_participant_signup( [
            'scout_id'            => 30001,
            'parent_user_id'      => 1,
            'unit_name'           => 'Kelso',
            'explorer_first_name' => 'Mary',
            'explorer_last_name'  => 'Smith',
            'explorer_email'      => 'mary@example.com',
            'leader_email'        => 'leader@example.com',
            'dofe_level'          => 'Bronze',
            'dob'                 => '2010-05-15',
            'dofe_registered'     => 'y-other',
            'dofe_number'         => '123456',
            'dofe_org'            => 'Borders Scout Region',
            'form_submission_id'  => 1234,
        ] );

        $this->assertEquals( 99, $id );
        $this->assertCount( 1, $wpdb->inserted );
        $inserted_data = $wpdb->inserted[0]['data'];
        $this->assertEquals( 'wp_ems_participant_signups', $wpdb->inserted[0]['table'] );
        
        $this->assertEquals( 30001, $inserted_data['scout_id'] );
        $this->assertEquals( 'Mary', $inserted_data['explorer_first_name'] );
        $this->assertEquals( 'Smith', $inserted_data['explorer_last_name'] );
        $this->assertEquals( 'leader@example.com', $inserted_data['leader_email'] );
        $this->assertEquals( 'y-other', $inserted_data['dofe_registered'] );
        $this->assertEquals( '123456', $inserted_data['dofe_number'] );
        $this->assertEquals( 'Borders Scout Region', $inserted_data['dofe_org'] );
        $this->assertEquals( 'bronze', $inserted_data['dofe_level'] );
        $this->assertEquals( '2010-05-15', $inserted_data['dob'] );
        $this->assertEquals( 'submitted', $inserted_data['signup_status'] );
        $this->assertEquals( 1234, $inserted_data['form_submission_id'] );
    }

    public function test_create_expedition_signup_inserts_correct_record(): void {
        $wpdb = new class {
            public $prefix = 'wp_';
            public $inserted = [];
            public $insert_id = 101;

            public function insert( string $table, array $data, array $format = [] ) {
                $this->inserted[] = [ 'table' => $table, 'data' => $data, 'format' => $format ];
                return 1;
            }
        };

        $repo = new Signup_Repository( $wpdb );
        $id = $repo->create_expedition_signup( [
            'scout_id'               => 30001,
            'parent_user_id'         => 1,
            'unit_name'              => 'SMESU',
            'explorer_first_name'    => 'John',
            'explorer_last_name'     => 'Doe',
            'explorer_email'         => 'john@example.com',
            'leader_email'           => 'leader@example.com',
            'dofe_level'             => 'Silver',
            'expedition_preferences' => [ 'exped_type' => 'Paddling' ],
            'first_aid_status'       => 'first-response',
            'first_aid_expiry'       => '2028-06-13',
            'form_submission_id'     => 5678,
        ] );

        $this->assertEquals( 101, $id );
        $this->assertCount( 1, $wpdb->inserted );
        $inserted_data = $wpdb->inserted[0]['data'];
        $this->assertEquals( 'wp_ems_expedition_signups', $wpdb->inserted[0]['table'] );
        $this->assertEquals( 'leader@example.com', $inserted_data['leader_email'] );

        $this->assertEquals( 30001, $inserted_data['scout_id'] );
        $this->assertEquals( 'silver', $inserted_data['dofe_level'] );
        $this->assertEquals( 'submitted', $inserted_data['signup_status'] );
        $this->assertJson( $inserted_data['expedition_preferences'] );
        $this->assertEquals( '2028-06-13', $inserted_data['first_aid_expiry'] );
    }

    public function test_update_payment_status_by_submission_id(): void {
        $wpdb = new class {
            public $prefix = 'wp_';
            public $updated = [];

            public function update( string $table, array $data, array $where, array $format = [], array $where_format = [] ) {
                $this->updated[] = [ 'table' => $table, 'data' => $data, 'where' => $where ];
                return 1;
            }
        };

        $repo = new Signup_Repository( $wpdb );
        $result = $repo->update_payment_status_by_submission_id( 1234, 'paid' );

        $this->assertTrue( $result );
        $this->assertCount( 1, $wpdb->updated );
        $this->assertEquals( 'wp_ems_participant_signups', $wpdb->updated[0]['table'] );
        $this->assertEquals( 'paid', $wpdb->updated[0]['data']['payment_status'] );
        $this->assertEquals( 1234, $wpdb->updated[0]['where']['form_submission_id'] );
    }

    public function test_process_participant_signup_updates_dofe_number(): void {
        $wpdb = new class {
            public $prefix = 'wp_';
            public $updated = [];

            public function update( string $table, array $data, array $where, array $format = [], array $where_format = [] ) {
                $this->updated[] = [ 'table' => $table, 'data' => $data, 'where' => $where ];
                return 1;
            }
        };

        $repo = new Signup_Repository( $wpdb );
        $result = $repo->process_participant_signup( 55, 42, 'D-778899' );

        $this->assertTrue( $result );
        $this->assertCount( 1, $wpdb->updated );
        $this->assertEquals( 'wp_ems_participant_signups', $wpdb->updated[0]['table'] );
        $this->assertArrayNotHasKey( 'signup_status', $wpdb->updated[0]['data'] );
        $this->assertEquals( 'D-778899', $wpdb->updated[0]['data']['dofe_number'] );
        $this->assertEquals( 42, $wpdb->updated[0]['data']['processed_by'] );
        $this->assertNotNull( $wpdb->updated[0]['data']['processed_at'] );
        $this->assertEquals( 55, $wpdb->updated[0]['where']['id'] );
    }

    public function test_archive_participant_signup_updates_status(): void {
        $wpdb = new class {
            public $prefix = 'wp_';
            public $updated = [];

            public function update( string $table, array $data, array $where, array $format = [], array $where_format = [] ) {
                $this->updated[] = [ 'table' => $table, 'data' => $data, 'where' => $where ];
                return 1;
            }
        };

        $repo = new Signup_Repository( $wpdb );
        $result = $repo->archive_participant_signup( 66 );

        $this->assertTrue( $result );
        $this->assertCount( 1, $wpdb->updated );
        $this->assertEquals( 'wp_ems_participant_signups', $wpdb->updated[0]['table'] );
        $this->assertEquals( 'archived', $wpdb->updated[0]['data']['signup_status'] );
        $this->assertEquals( 66, $wpdb->updated[0]['where']['id'] );
    }

    public function test_get_participant_signups_by_status(): void {
        $wpdb = new class {
            public $prefix = 'wp_';
            public $queries = [];

            public function prepare( string $sql, ...$args ): string {
                return vsprintf( str_replace( '%s', "'%s'", $sql ), $args );
            }

            public function get_results( string $sql, string $output = ARRAY_A ) {
                $this->queries[] = $sql;
                return [];
            }
        };

        $repo = new Signup_Repository( $wpdb );
        $repo->get_participant_signups( 'submitted' );
        $repo->get_participant_signups( 'all' );

        $this->assertCount( 2, $wpdb->queries );
        $this->assertStringContainsString( "signup_status = 'submitted'", $wpdb->queries[0] );
        $this->assertStringNotContainsString( "WHERE", $wpdb->queries[1] );
    }

    public function test_reconcile_signup_runs_transactional_batch_updates(): void {
        $wpdb = new class {
            public $prefix = 'wp_';
            public $queries = [];
            public $row = [
                'explorer_first_name' => 'Mary',
                'explorer_last_name' => 'Smith',
                'explorer_email' => 'mary@example.com',
                'parent_email' => 'parent@example.com',
            ];

            public function prepare( string $sql, ...$args ): string {
                return vsprintf( str_replace( '%s', "'%s'", $sql ), $args );
            }

            public function get_row( string $sql, string $output = ARRAY_A ) {
                return $this->row;
            }

            public function query( string $sql ) {
                $this->queries[] = $sql;
                return true;
            }
        };

        $repo = new Signup_Repository( $wpdb );
        $result = $repo->reconcile_signup( 500, 'participant', 12345 );

        $this->assertTrue( $result );
        $this->assertContains( 'START TRANSACTION', $wpdb->queries );
        $this->assertContains( 'COMMIT', $wpdb->queries );
        
        $participant_update = null;
        $expedition_update = null;
        foreach ( $wpdb->queries as $q ) {
            if ( strpos( $q, 'wp_ems_participant_signups' ) !== false && strpos( $q, 'SET scout_id = 12345' ) !== false ) {
                $participant_update = $q;
            }
            if ( strpos( $q, 'wp_ems_expedition_signups' ) !== false && strpos( $q, 'SET scout_id = 12345' ) !== false ) {
                $expedition_update = $q;
            }
        }
        $this->assertNotNull( $participant_update );
        $this->assertNotNull( $expedition_update );
    }

    public function test_unlink_signup_throws_exception_if_assigned_to_team(): void {
        $wpdb = new class {
            public $prefix = 'wp_';
            public $row = [
                'scout_id' => 12345,
                'explorer_first_name' => 'Mary',
                'explorer_last_name' => 'Smith',
                'explorer_email' => 'mary@example.com',
                'parent_email' => 'parent@example.com',
            ];

            public function prepare( string $sql, ...$args ): string {
                return vsprintf( str_replace( '%d', '%d', $sql ), $args );
            }

            public function get_row( string $sql, string $output = ARRAY_A ) {
                return $this->row;
            }

            public function get_var( string $sql ) {
                // Return 1 to indicate the scout is active in ems_team_members
                return 1;
            }
        };

        $repo = new Signup_Repository( $wpdb );
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'Cannot unlink explorer who is currently assigned to a team.' );
        $repo->unlink_signup( 500, 'participant' );
    }
}
