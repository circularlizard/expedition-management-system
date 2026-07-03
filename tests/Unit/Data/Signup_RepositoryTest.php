<?php
namespace EMS\Tests\Unit\Data;

use EMS\Data\Signup_Repository;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;

class Signup_RepositoryTest extends EMSTestCase {

    public function test_create_signup_inserts_correct_record(): void {
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
        $id = $repo->create_signup( [
            'scout_id'               => 30001,
            'parent_user_id'         => 1,
            'unit_id'                => 10,
            'explorer_first_name'    => 'Mary',
            'explorer_last_name'     => 'Smith',
            'dofe_level'             => 'Bronze',
            'expedition_preferences' => [ 'exped_type' => 'Hillwalking' ],
            'first_aid_status'       => 'first-response',
            'form_submission_id'     => 1234,
        ] );

        $this->assertEquals( 99, $id );
        $this->assertCount( 1, $wpdb->inserted );
        $inserted_data = $wpdb->inserted[0]['data'];
        
        $this->assertEquals( 30001, $inserted_data['scout_id'] );
        $this->assertEquals( 'Mary', $inserted_data['explorer_first_name'] );
        $this->assertEquals( 'Smith', $inserted_data['explorer_last_name'] );
        $this->assertEquals( 'bronze', $inserted_data['dofe_level'] );
        $this->assertEquals( 'first-response', $inserted_data['first_aid_status'] );
        $this->assertEquals( 'pending', $inserted_data['signup_status'] );
        $this->assertEquals( 'pending', $inserted_data['payment_status'] );
        $this->assertEquals( 1234, $inserted_data['form_submission_id'] );
        $this->assertJson( $inserted_data['expedition_preferences'] );
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
        $this->assertEquals( 'paid', $wpdb->updated[0]['data']['payment_status'] );
        $this->assertEquals( 1234, $wpdb->updated[0]['where']['form_submission_id'] );
    }

    public function test_create_signup_includes_dofe_number(): void {
        $wpdb = new class {
            public $prefix = 'wp_';
            public $inserted = [];
            public $insert_id = 100;

            public function insert( string $table, array $data, array $format = [] ) {
                $this->inserted[] = [ 'table' => $table, 'data' => $data, 'format' => $format ];
                return 1;
            }
        };

        $repo = new Signup_Repository( $wpdb );
        $repo->create_signup( [
            'dofe_number' => 'D-123456',
        ] );

        $this->assertCount( 1, $wpdb->inserted );
        $this->assertEquals( 'D-123456', $wpdb->inserted[0]['data']['dofe_number'] );
    }

    public function test_reconcile_signup_updates_columns(): void {
        $wpdb = new class {
            public $prefix = 'wp_';
            public $updated = [];

            public function update( string $table, array $data, array $where, array $format = [], array $where_format = [] ) {
                $this->updated[] = [ 'table' => $table, 'data' => $data, 'where' => $where ];
                return 1;
            }
        };

        $repo = new Signup_Repository( $wpdb );
        $result = $repo->reconcile_signup( 10, 102, 42 );

        $this->assertTrue( $result );
        $this->assertCount( 1, $wpdb->updated );
        $this->assertEquals( 102, $wpdb->updated[0]['data']['scout_id'] );
        $this->assertEquals( 42, $wpdb->updated[0]['data']['reconciled_by'] );
        $this->assertNotNull( $wpdb->updated[0]['data']['reconciled_at'] );
        $this->assertEquals( 10, $wpdb->updated[0]['where']['id'] );
    }

    public function test_process_signup_updates_columns(): void {
        $wpdb = new class {
            public $prefix = 'wp_';
            public $updated = [];

            public function update( string $table, array $data, array $where, array $format = [], array $where_format = [] ) {
                $this->updated[] = [ 'table' => $table, 'data' => $data, 'where' => $where ];
                return 1;
            }
        };

        $repo = new Signup_Repository( $wpdb );
        $result = $repo->process_signup( 11, 42 );

        $this->assertTrue( $result );
        $this->assertCount( 1, $wpdb->updated );
        $this->assertEquals( 'processed', $wpdb->updated[0]['data']['signup_status'] );
        $this->assertEquals( 42, $wpdb->updated[0]['data']['processed_by'] );
        $this->assertNotNull( $wpdb->updated[0]['data']['processed_at'] );
        $this->assertEquals( 11, $wpdb->updated[0]['where']['id'] );
    }

    public function test_archive_signup_updates_columns(): void {
        $wpdb = new class {
            public $prefix = 'wp_';
            public $updated = [];

            public function update( string $table, array $data, array $where, array $format = [], array $where_format = [] ) {
                $this->updated[] = [ 'table' => $table, 'data' => $data, 'where' => $where ];
                return 1;
            }
        };

        $repo = new Signup_Repository( $wpdb );
        $result = $repo->archive_signup( 12 );

        $this->assertTrue( $result );
        $this->assertCount( 1, $wpdb->updated );
        $this->assertEquals( 'archived', $wpdb->updated[0]['data']['signup_status'] );
        $this->assertEquals( 12, $wpdb->updated[0]['where']['id'] );
    }

    public function test_get_signups_by_status(): void {
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
        $repo->get_signups_by_status( 'processed' );
        $repo->get_signups_by_status( 'all' );

        $this->assertCount( 2, $wpdb->queries );
        $this->assertStringContainsString( "signup_status = 'processed'", $wpdb->queries[0] );
        $this->assertStringNotContainsString( "WHERE", $wpdb->queries[1] );
    }
}
