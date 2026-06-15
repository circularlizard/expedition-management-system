<?php
namespace EMS\Tests\Unit\Integrations;

use EMS\Integrations\Flexi_Record_Importer;
use EMS\Integrations\Flexi_Column_Map;
use EMS\Data\Expedition_Repository;
use EMS\Data\Team_Repository;
use EMS\Data\Team_Member_Repository;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;
use Mockery;

class Flexi_Record_ImporterTest extends EMSTestCase {

    private $column_map;
    private $expeditions;
    private $teams;
    private $team_members;

    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        $this->column_map   = Mockery::mock( Flexi_Column_Map::class );
        $this->expeditions  = Mockery::mock( Expedition_Repository::class );
        $this->teams        = Mockery::mock( Team_Repository::class );
        $this->team_members = Mockery::mock( Team_Member_Repository::class );

        Functions\when( 'get_current_user_id' )->justReturn( 1 );

        $this->wpdb         = Mockery::mock( 'wpdb' );
        $this->wpdb->prefix = 'wp_';
        $GLOBALS['wpdb']    = $this->wpdb;

        $this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
            fn( $sql, ...$args ) => vsprintf( str_replace( '%d', '%s', $sql ), $args )
        );

        $this->wpdb->shouldReceive( 'get_row' )->andReturnUsing( function ( $sql ) {
            if ( str_contains( $sql, '1001' ) ) {
                return [ 'id' => 1, 'wp_user_id' => 123 ];
            }
            return null;
        } );
    }

    public function test_bucket_rows_categorizes_correctly(): void {
        $this->column_map->shouldReceive( 'get' )->andReturn( [
            'expedition_code' => 'f_1',
            'team_code'       => 'f_2',
        ] );

        $raw_items = [
            // Clean - scoutid auto-resolved from identifier
            [ 'f_1' => 'EXP1', 'f_2' => 'T1', 'scoutid' => '1001' ],
            // Partial - missing team_code
            [ 'f_1' => 'EXP1', 'f_2' => '', 'scoutid' => '1002' ],
            // Unparseable
            [ 'other' => 'data' ]
        ];

        $importer = new Flexi_Record_Importer(
            $this->column_map,
            $this->expeditions,
            $this->teams,
            $this->team_members
        );

        $buckets = $importer->bucket_rows( $raw_items, 'scoutid' );

        $this->assertCount( 1, $buckets['clean'] );
        $this->assertCount( 1, $buckets['partial'] );
        $this->assertCount( 1, $buckets['unparseable'] );

        $this->assertEquals( 'EXP1', $buckets['clean'][0]['expedition_code'] );
        $this->assertEquals( 123, $buckets['clean'][0]['_user_id'] );
    }

    public function test_commit_creates_records_idempotently(): void {
        $clean_rows = [
            [
                'expedition_code'      => 'EXP1',
                'team_code'            => 'T1',
                'participant_scout_id' => '1001',
                '_user_id'             => 123,
            ]
        ];

        // Flexible get_posts stub
        Functions\when( 'get_posts' )->alias( function( $args ) {
            return []; // Always return empty so it triggers creation
        } );

        $this->expeditions->shouldReceive( 'create' )
            ->once()
            ->with( Mockery::on( function( $args ) {
                return $args['ems_expedition_code'] === 'EXP1';
            } ) )
            ->andReturn( 501 );

        $this->teams->shouldReceive( 'create' )
            ->once()
            ->with( 501, 'EXP1', 'T1' )
            ->andReturn( 601 );

        // 3. Assignment
        $this->team_members->shouldReceive( 'assign' )
            ->once()
            ->with( 601, 123, 1 )
            ->andReturn( 701 );

        $option_updated = false;
        Functions\when( 'update_option' )->alias( function( $name, $value ) use ( &$option_updated ) {
            if ( $name === 'ems_osm_last_sync' ) {
                $option_updated = true;
            }
        } );

        $importer = new Flexi_Record_Importer(
            $this->column_map,
            $this->expeditions,
            $this->teams,
            $this->team_members
        );

        $count = $importer->commit( $clean_rows );
        $this->assertEquals( 1, $count );
        $this->assertTrue( $option_updated, 'ems_osm_last_sync was not updated' );
    }
}
