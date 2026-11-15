<?php
declare(strict_types=1);

namespace EMS\Tests\Unit\Integrations;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use EMS\Data\Expedition_Repository;
use EMS\Data\Team_Member_Repository;
use EMS\Data\Team_Repository;
use EMS\Integrations\RouteEngine\EmsRouteBridgeAdapter;
use EMS\Tests\EMSTestCase;
use Mockery;

class EmsRouteBridgeAdapterTest extends EMSTestCase {
	private $expeditionRepo;
	private $teamRepo;
	private $teamMemberRepo;
	private $wpdb;
	private EmsRouteBridgeAdapter $adapter;

	protected function setUp(): void {
		parent::setUp();

		$this->expeditionRepo = Mockery::mock( Expedition_Repository::class );
		$this->teamRepo       = Mockery::mock( Team_Repository::class );
		$this->teamMemberRepo = Mockery::mock( Team_Member_Repository::class );

		$this->wpdb = new class {
			public string $prefix = 'wp_';
			/** @var array<string, array<string, string>> */
			public array $rows = [];

			public function prepare( string $sql, ...$args ): string {
				return vsprintf( str_replace( '%d', '%d', str_replace( '%s', "'%s'", $sql ) ), $args );
			}

			/**
			 * @param string $sql
			 * @param string $output
			 * @return array<string, string>|null
			 */
			public function get_row( string $sql, string $output = ARRAY_A ): ?array {
				return $this->rows[ $sql ] ?? null;
			}
		};

		$this->adapter = new EmsRouteBridgeAdapter(
			$this->expeditionRepo,
			$this->teamRepo,
			$this->teamMemberRepo,
			$this->wpdb
		);
	}

	public function test_register_hooks_into_context_provider_filters(): void {
		$this->adapter->register();

		$this->assertTrue( Filters\has( 'ems_route_engine/context_provider' ) );
		$this->assertTrue( Filters\has( 'ems_get_route_card_context' ) );
	}

	public function test_provides_live_expedition_and_team_roster(): void {
		$expeditionId = 10;
		$teamId       = 20;

		$this->teamRepo->shouldReceive( 'get_by_id' )
			->with( $teamId )
			->once()
			->andReturn( [
				'ID'              => $teamId,
				'post_title'      => 'Bravo Patrol',
				'ems_team_number' => 2,
				'event_id'        => $expeditionId,
			] );

		$this->expeditionRepo->shouldReceive( 'get_by_id' )
			->with( $expeditionId )
			->once()
			->andReturn( [
				'ID'                     => $expeditionId,
				'post_title'             => 'Pentlands Silver Qualifying',
				'ems_level'              => 'Silver',
				'ems_transport'          => 'Foot',
				'ems_start_date'         => '2026-07-10',
				'ems_end_date'           => '2026-07-12',
				'ems_lic_name'           => 'Leader In Charge',
				'ems_lic_phone'          => '07111222333',
				'ems_lic_private_phone'  => '07999888777',
			] );

		$this->teamMemberRepo->shouldReceive( 'list_by_team' )
			->with( $teamId )
			->once()
			->andReturn( [
				[ 'scout_id' => 1001, 'user_id' => 11 ],
				[ 'scout_id' => 1002, 'user_id' => 12 ],
			] );

		$this->wpdb->rows["SELECT first_name, last_name FROM wp_ems_osm_explorers WHERE scout_id = 1001"] = [
			'first_name' => 'Alice',
			'last_name'  => 'Wonderland',
		];
		$this->wpdb->rows["SELECT first_name, last_name FROM wp_ems_osm_explorers WHERE scout_id = 1002"] = [
			'first_name' => 'Bob',
			'last_name'  => 'Builder',
		];

		$context = $this->adapter->provideContext( null, $expeditionId, $teamId, 1 );

		$this->assertSame( $expeditionId, $context['expedition_id'] );
		$this->assertSame( $teamId, $context['team_id'] );
		$this->assertSame( 'Pentlands Silver Qualifying', $context['expedition_name'] );
		$this->assertSame( 'Silver', $context['level'] );
		$this->assertSame( 'Foot', $context['mode_of_travel'] );
		$this->assertSame( 3, $context['total_days'] );
		$this->assertSame( [ '2026-07-10', '2026-07-11', '2026-07-12' ], $context['dates'] );
		$this->assertSame( 'Bravo Patrol', $context['team_name'] );
		$this->assertSame( [ 'Alice Wonderland', 'Bob Builder' ], $context['team_members'] );
		$this->assertSame( 'Leader In Charge', $context['supervisor_name'] );
		$this->assertSame( '07111222333', $context['supervisor_phone'] );
		$this->assertSame( '07999888777', $context['emergency_contact'] );
		$this->assertFalse( $context['is_standalone'] );
	}

	public function test_handles_missing_expedition_and_team_gracefully(): void {
		$context = $this->adapter->provideContext( [ 'existing' => true ], 0, 0 );

		$this->assertSame( [ 'existing' => true ], $context );
	}
}
