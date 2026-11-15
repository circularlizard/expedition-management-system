<?php
namespace EMS\Tests\Unit\Core;

use EMS\Contracts\BackupContributorInterface;
use EMS\Core\Backup_Manager;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;
use Mockery;

class Backup_ManagerTest extends EMSTestCase {

	public function test_contributor_registration_and_getters(): void {
		$manager = new Backup_Manager();

		$contributor = Mockery::mock( BackupContributorInterface::class );
		$contributor->shouldReceive( 'getBackupTables' )->andReturn( array( 'ems_custom_table' ) );
		$contributor->shouldReceive( 'getBackupPostTypes' )->andReturn( array( 'custom_cpt' ) );
		$contributor->shouldReceive( 'getBackupOptionNames' )->andReturn( array( 'custom_option' ) );

		$manager->register_contributor( $contributor );

		$this->assertCount( 1, $manager->get_contributors() );
		$this->assertContains( 'ems_custom_table', $manager->get_all_tables() );
		$this->assertContains( 'ems_osm_explorers', $manager->get_all_tables() );
		$this->assertContains( 'custom_cpt', $manager->get_all_cpts() );
		$this->assertContains( 'expedition', $manager->get_all_cpts() );
		$this->assertContains( 'custom_option', $manager->get_contributor_option_names() );
	}

	public function test_export_backup_structure(): void {
		global $wpdb;

		$wpdb = Mockery::mock( 'stdClass' );
		$wpdb->prefix = 'wp_';
		$wpdb->options = 'wp_options';

		$wpdb->shouldReceive( 'esc_like' )->with( 'ems_' )->andReturn( 'ems_' );
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SELECT ...' );
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		Functions\when( 'get_posts' )->justReturn( array() );

		$manager = new Backup_Manager();

		$contributor = Mockery::mock( BackupContributorInterface::class );
		$contributor->shouldReceive( 'getBackupTables' )->andReturn( array() );
		$contributor->shouldReceive( 'getBackupPostTypes' )->andReturn( array() );
		$contributor->shouldReceive( 'getBackupOptionNames' )->andReturn( array( 'contributor_opt' ) );
		$contributor->shouldReceive( 'exportData' )->andReturn( array( 'custom_key' => 'custom_val' ) );

		Functions\when( 'get_option' )->alias( function ( $name ) {
			if ( $name === 'contributor_opt' ) {
				return 'saved_contributor_val';
			}
			return false;
		} );

		$manager->register_contributor( $contributor );

		$export = $manager->export_backup();

		$this->assertSame( '1.0.0', $export['version'] );
		$this->assertArrayHasKey( 'exported_at', $export );
		$this->assertArrayHasKey( 'options', $export );
		$this->assertSame( 'saved_contributor_val', $export['options']['contributor_opt'] );
		$this->assertArrayHasKey( 'tables', $export );
		$this->assertArrayHasKey( 'cpts', $export );
		$this->assertArrayHasKey( 'contributors', $export );
	}

	public function test_generate_parity_manifest(): void {
		global $wpdb;

		$wpdb = Mockery::mock( 'stdClass' );
		$wpdb->prefix = 'wp_';
		$wpdb->options = 'wp_options';

		$wpdb->shouldReceive( 'get_var' )->andReturn( '5' );
		$wpdb->shouldReceive( 'esc_like' )->with( 'ems_' )->andReturn( 'ems_' );
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SELECT COUNT(*) ...' );

		Functions\when( 'wp_count_posts' )->alias( function () {
			$obj = new \stdClass();
			$obj->publish = 3;
			return $obj;
		} );

		$manager = new Backup_Manager();
		$manifest = $manager->generate_parity_manifest();

		$this->assertArrayHasKey( 'generated_at', $manifest );
		$this->assertArrayHasKey( 'tables', $manifest );
		$this->assertArrayHasKey( 'cpts', $manifest );
		$this->assertSame( 5, $manifest['options_count'] );
		$this->assertNotEmpty( $manifest['checksum'] );
	}

	public function test_import_backup(): void {
		global $wpdb;

		$wpdb = Mockery::mock( 'stdClass' );
		$wpdb->prefix = 'wp_';

		$truncatedTables = array();
		$wpdb->shouldReceive( 'query' )->andReturnUsing( function ( $query ) use ( &$truncatedTables ) {
			if ( str_contains( $query, 'TRUNCATE TABLE' ) ) {
				$truncatedTables[] = trim( $query );
			}
			return true;
		} );

		$insertedRows = array();
		$wpdb->shouldReceive( 'insert' )->andReturnUsing( function ( $table, $row ) use ( &$insertedRows ) {
			$insertedRows[] = array( 'table' => $table, 'row' => $row );
			return 1;
		} );

		$updatedOptions = array();
		Functions\when( 'update_option' )->alias( function ( $key, $val ) use ( &$updatedOptions ) {
			$updatedOptions[ $key ] = $val;
			return true;
		} );

		$manager = new Backup_Manager();

		$contributor = Mockery::mock( BackupContributorInterface::class );
		$contributor->shouldReceive( 'getBackupTables' )->andReturn( array() );
		$contributor->shouldReceive( 'getBackupPostTypes' )->andReturn( array() );
		$contributor->shouldReceive( 'getBackupOptionNames' )->andReturn( array() );
		$contributor->shouldReceive( 'importData' )->once()->with( array( 'imported' => true ) )->andReturn( true );

		$manager->register_contributor( $contributor );

		$payload = array(
			'version'      => '1.0.0',
			'options'      => array(
				'ems_setting_one' => 'alpha',
			),
			'tables'       => array(
				'ems_osm_explorers' => array(
					array( 'id' => 1, 'first_name' => 'John' ),
				),
			),
			'cpts'         => array(),
			'contributors' => array(
				get_class( $contributor ) => array( 'imported' => true ),
			),
		);

		$result = $manager->import_backup( $payload );

		$this->assertTrue( $result );
		$this->assertSame( 'alpha', $updatedOptions['ems_setting_one'] );
		$this->assertCount( 1, $truncatedTables );
		$this->assertCount( 1, $insertedRows );
		$this->assertSame( 'wp_ems_osm_explorers', $insertedRows[0]['table'] );
	}
}
