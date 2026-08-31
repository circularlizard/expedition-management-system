<?php
namespace EMS\Tests\Core;

use EMS\Tests\EMSTestCase;
use EMS\Core\Portability_Engine;
use Brain\Monkey\Functions;
use Mockery;

class Portability_EngineTest extends EMSTestCase {

	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', sys_get_temp_dir() . '/' );
		}
		// Create a dummy upgrade.php file in temp dir to satisfy require_once
		$dir = ABSPATH . 'wp-admin/includes';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		file_put_contents( $dir . '/upgrade.php', '<?php function dbDelta($queries) {}' );

		$this->wpdb = Mockery::mock( 'wpdb' );
		$this->wpdb->prefix = 'wp_';
		$this->wpdb->shouldReceive( 'get_charset_collate' )->byDefault()->andReturn( 'utf8_general_ci' );
		$this->wpdb->shouldReceive( 'prepare' )->byDefault()->andReturnUsing( fn( $sql, ...$args ) => vsprintf( str_replace( '%d', '%s', $sql ), $args ) );
		$this->wpdb->shouldReceive( 'get_var' )->byDefault()->andReturn( 'scout_id' );
		$this->wpdb->shouldReceive( 'get_col' )->byDefault()->andReturn( array() );
		$GLOBALS['wpdb'] = $this->wpdb;
		Functions\when( 'get_option' )->alias( fn( $option, $default = false ) => $default );
	}

	public function test_export_data_serializes_options_and_custom_tables(): void {
		$stored_options = array();
		Functions\when( 'get_option' )->alias( function( $key, $default = null ) use ( &$stored_options ) {
			if ( $key === 'ems_api_mode' ) return 'live-limited';
			if ( $key === 'ems_fluent_participant_form_id' ) return 6;
			if ( $key === 'ems_fluent_expedition_form_id' ) return 7;
			return $default;
		} );

		$this->wpdb->shouldReceive( 'get_results' )
			->andReturn( [ [ 'id' => 1, 'name' => 'Alice' ] ] );

		$engine = new Portability_Engine();
		$json = $engine->export_data();

		$data = json_decode( $json, true );
		$this->assertSame( '0.1.x', $data['version'] );
		$this->assertSame( 'live-limited', $data['options']['ems_api_mode'] );
		$this->assertSame( 6, $data['options']['ems_fluent_participant_form_id'] );
		$this->assertSame( 7, $data['options']['ems_fluent_expedition_form_id'] );
		$this->assertArrayNotHasKey( 'ems_signups', $data['tables'] );
		$this->assertArrayHasKey( 'ems_participant_signups', $data['tables'] );
		$this->assertArrayHasKey( 'ems_expedition_signups', $data['tables'] );
		$this->assertArrayHasKey( 'ems_volunteers', $data['tables'] );
		$this->assertArrayHasKey( 'ems_audit_logs', $data['tables'] );
		$this->assertSame( [ [ 'id' => 1, 'name' => 'Alice' ] ], $data['tables']['ems_team_members'] );
	}

	public function test_import_data_restores_tables_and_options(): void {
		$backup_data = array(
			'options' => array(
				'ems_api_mode'                   => 'mock',
				'ems_sync_limit'                  => 10,
				'ems_fluent_participant_form_id' => 6,
			),
			'tables' => array(
				'ems_team_members'        => array(
					array( 'id' => 42, 'scout_id' => 30001 )
				),
				'ems_participant_signups' => array(
					array( 'id' => 1, 'scout_id' => 30001, 'explorer_first_name' => 'Alice' )
				),
			)
		);
		$json = json_encode( $backup_data );

		// Expect table setup triggers
		$this->wpdb->shouldReceive( 'query' )->andReturn( true );
		$this->wpdb->shouldReceive( 'insert' )
			->with( 'wp_ems_team_members', [ 'id' => 42, 'scout_id' => 30001 ] )
			->once()
			->andReturn( true );
		$this->wpdb->shouldReceive( 'insert' )
			->with( 'wp_ems_participant_signups', [ 'id' => 1, 'scout_id' => 30001, 'explorer_first_name' => 'Alice' ] )
			->once()
			->andReturn( true );

		$updated_options = array();
		Functions\when( 'update_option' )->alias( function( $key, $value ) use ( &$updated_options ) {
			$updated_options[ $key ] = $value;
			return true;
		} );

		$engine = new Portability_Engine();
		$result = $engine->import_data( $json );

		$this->assertSame( 'mock', $updated_options['ems_api_mode'] );
		$this->assertSame( 10, $updated_options['ems_sync_limit'] );
		$this->assertSame( 6, $updated_options['ems_fluent_participant_form_id'] );

		$this->assertTrue( $result );
	}

	public function test_export_units_serializes_only_ems_units(): void {
		$this->wpdb->shouldReceive( 'get_results' )
			->with( 'SELECT * FROM wp_ems_units', ARRAY_A )
			->once()
			->andReturn( [
				[
					'id' => 1,
					'unit_id' => 10,
					'district' => 'Braid',
					'name' => 'Kelso ESU',
					'short_code' => 'BO-Kelso',
				]
			] );

		$this->wpdb->shouldReceive( 'get_results' )
			->with( 'SELECT * FROM wp_ems_unit_patrols', ARRAY_A )
			->once()
			->andReturn( [
				[
					'id' => 1,
					'unit_id' => 10,
					'section_id' => 201,
					'patrol_id' => 101,
					'name' => 'Kelso',
					'active' => 1,
				]
			] );

		$engine = new Portability_Engine();
		$json = $engine->export_units();

		$data = json_decode( $json, true );
		$this->assertSame( 'ems_units_export', $data['type'] );
		$this->assertSame( '0.1.x', $data['version'] );
		$this->assertArrayNotHasKey( 'options', $data );
		$this->assertArrayNotHasKey( 'tables', $data );
		$this->assertSame( [
			[
				'id' => 1,
				'unit_id' => 10,
				'district' => 'Braid',
				'name' => 'Kelso ESU',
				'short_code' => 'BO-Kelso',
			]
		], $data['units'] );
		$this->assertSame( [
			[
				'id' => 1,
				'unit_id' => 10,
				'section_id' => 201,
				'patrol_id' => 101,
				'name' => 'Kelso',
				'active' => 1,
			]
		], $data['unit_patrols'] );
	}

	public function test_import_units_restores_only_ems_units(): void {
		$backup_data = array(
			'type'    => 'ems_units_export',
			'version' => '0.1.x',
			'units'   => array(
				array(
					'id'        => 5,
					'unit_id'   => 10,
					'district'  => 'Braid',
					'name'      => 'Kelso ESU',
					'short_code'=> 'BO-Kelso',
				)
			),
			'unit_patrols' => array(
				array(
					'id'        => 1,
					'unit_id'   => 10,
					'section_id'=> 201,
					'patrol_id' => 101,
					'name'      => 'Kelso',
					'active'    => 1,
				)
			)
		);
		$json = json_encode( $backup_data );

		$this->wpdb->shouldReceive( 'query' )
			->with( 'TRUNCATE TABLE wp_ems_units' )
			->once()
			->andReturn( true );

		$this->wpdb->shouldReceive( 'query' )
			->with( 'TRUNCATE TABLE wp_ems_unit_patrols' )
			->once()
			->andReturn( true );

		$this->wpdb->shouldReceive( 'insert' )
			->with( 'wp_ems_units', [
				'id'        => 5,
				'unit_id'   => 10,
				'district'  => 'Braid',
				'name'      => 'Kelso ESU',
				'short_code'=> 'BO-Kelso',
			] )
			->once()
			->andReturn( true );

		$this->wpdb->shouldReceive( 'insert' )
			->with( 'wp_ems_unit_patrols', [
				'id'        => 1,
				'unit_id'   => 10,
				'section_id'=> 201,
				'patrol_id' => 101,
				'name'      => 'Kelso',
				'active'    => 1,
			] )
			->once()
			->andReturn( true );

		$engine = new Portability_Engine();
		$result = $engine->import_units( $json );

		$this->assertTrue( $result );
	}

	public function test_import_units_throws_on_invalid_structure(): void {
		$invalid_data = array(
			'options' => array(),
			'tables' => array(),
		);
		$json = json_encode( $invalid_data );

		$this->wpdb->shouldNotReceive( 'query' );
		$this->wpdb->shouldNotReceive( 'insert' );

		$engine = new Portability_Engine();

		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Invalid units backup file structure.' );

		$engine->import_units( $json );
	}
}
