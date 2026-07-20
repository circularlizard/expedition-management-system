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
			return $default;
		} );

		$this->wpdb->shouldReceive( 'get_results' )
			->andReturn( [ [ 'id' => 1, 'name' => 'Alice' ] ] );

		$engine = new Portability_Engine();
		$json = $engine->export_data();

		$data = json_decode( $json, true );
		$this->assertSame( '0.1.x', $data['version'] );
		$this->assertSame( 'live-limited', $data['options']['ems_api_mode'] );
		$this->assertSame( [ [ 'id' => 1, 'name' => 'Alice' ] ], $data['tables']['ems_team_members'] );
	}

	public function test_import_data_restores_tables_and_options(): void {
		$backup_data = array(
			'options' => array(
				'ems_api_mode' => 'mock',
				'ems_sync_limit' => 10,
			),
			'tables' => array(
				'ems_team_members' => array(
					array( 'id' => 42, 'scout_id' => 30001 )
				)
			)
		);
		$json = json_encode( $backup_data );

		// Expect table setup triggers
		$this->wpdb->shouldReceive( 'query' )->andReturn( true );
		$this->wpdb->shouldReceive( 'insert' )
			->with( 'wp_ems_team_members', [ 'id' => 42, 'scout_id' => 30001 ] )
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

		$this->assertTrue( $result );
	}
}
