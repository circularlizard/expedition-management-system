<?php
declare(strict_types=1);

namespace EMS\Tests\Unit\Modules\Expeditions\Services;

use Brain\Monkey\Functions;
use EMS\Data\Team_Repository;
use EMS\Modules\Expeditions\Services\TeamSolverService;
use EMS\Modules\Expeditions\Services\WishlistMatcherService;
use EMS\Modules\Expeditions\Services\WishlistPreferenceRepository;
use EMS\Tests\EMSTestCase;

class TeamSolverServiceTest extends EMSTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_option' )->justReturn( array() );
	}

	private function makeSignup( int $id, string $first, string $last, string $level = 'silver', string $type = 'Hillwalking', string $unit = 'Lauderdale ESU', string $fa = 'none' ): array {
		return array(
			'id'                     => $id,
			'scout_id'               => 1000 + $id,
			'unit_id'                => 10,
			'unit_name'              => $unit,
			'explorer_first_name'    => $first,
			'explorer_last_name'     => $last,
			'dofe_level'             => $level,
			'expedition_preferences' => json_encode(
				array(
					'exped_type'            => $type,
					'exped_practice_dates'  => array( 'H-SP1', 'H-SP2' ),
					'exped_qualifier_dates' => array( 'H-SQ1' ),
					'exped_team_names'      => '',
				)
			),
			'first_aid_status'       => $fa,
			'first_aid_expiry'       => null,
			'osm_first_aid_level'    => null,
			'resolved_unit_name'     => $unit,
			'unit_short_code'        => 'LAU',
		);
	}

	private function mockPost( int $id, string $type, string $title, int $parent = 0 ): object {
		$p              = new \stdClass();
		$p->ID          = $id;
		$p->post_type   = $type;
		$p->post_title  = $title;
		$p->post_status = 'publish';
		$p->post_parent = $parent;
		return $p;
	}

	public function test_propose_enforces_max_teams_and_continues_practice_sequence_naming(): void {
		$signups = array();
		for ( $i = 1; $i <= 10; $i++ ) {
			$signups[] = $this->makeSignup( $i, 'Explorer', (string) $i, 'silver', 'Hillwalking', 'Lauderdale ESU', $i === 1 ? 'first-aid-yes' : 'none' );
		}

		// Expedition 101 (H-SP1, practice) has max_teams = 2 and 1 locked team H-SP1-1.
		// Expedition 102 (H-SP2, practice) has max_teams = 0 (uncapped).
		// Expedition 201 (H-SQ1, qualifier) is uncapped.
		$expPosts = array(
			$this->mockPost( 101, 'expedition', 'Silver Practice 1' ),
			$this->mockPost( 102, 'expedition', 'Silver Practice 2' ),
			$this->mockPost( 201, 'expedition', 'Silver Qualifier 1' ),
		);
		$teamPosts = array(
			$this->mockPost( 501, 'team', 'Team H-SP1-1', 101 ),
		);

		$meta = array(
			101 => array(
				'ems_event_code'  => 'H-SP1',
				'ems_level'       => 'silver',
				'ems_transport'   => 'hillwalking',
				'ems_type'        => 'practice',
				'ems_status'      => 'active',
				'_ems_max_teams'  => '2',
			),
			102 => array(
				'ems_event_code'  => 'H-SP2',
				'ems_level'       => 'silver',
				'ems_transport'   => 'hillwalking',
				'ems_type'        => 'practice',
				'ems_status'      => 'active',
				'_ems_max_teams'  => '0',
			),
			201 => array(
				'ems_event_code'  => 'H-SQ1',
				'ems_level'       => 'silver',
				'ems_transport'   => 'hillwalking',
				'ems_type'        => 'qualifying',
				'ems_status'      => 'active',
			),
			501 => array(
				'ems_team_code'   => 'H-SP1-1',
				'ems_team_number' => '1',
			),
		);

		Functions\when( 'get_posts' )->alias(
			static function ( array $args ) use ( $expPosts, $teamPosts ): array {
				if ( ( $args['post_type'] ?? '' ) === 'expedition' ) {
					return $expPosts;
				}
				if ( ( $args['post_type'] ?? '' ) === 'team' ) {
					return $teamPosts;
				}
				return array();
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $postId, string $key, bool $single = false ) use ( $meta ) {
				return $meta[ $postId ][ $key ] ?? '';
			}
		);

		$wpdb         = \Mockery::mock( \wpdb::class );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static fn( string $q, ...$args ): string => vsprintf( str_replace( array( '%d', '%s' ), array( '%s', "'%s'" ), $q ), $args )
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			static function ( string $query ) use ( $signups ): array {
				if ( str_contains( $query, 'ems_expedition_signups' ) ) {
					return $signups;
				}
				if ( str_contains( $query, 'ems_team_members' ) ) {
					return array(
						array(
							'team_post_id'        => 501,
							'expedition_signup_id' => 999,
							'notification_status' => 'notified',
						),
					);
				}
				if ( str_contains( $query, 'ems_teammate_preferences' ) ) {
					return array();
				}
				return array();
			}
		);

		$solver = new TeamSolverService( $wpdb );
		$result = $solver->propose(
			array(
				'scope'          => 'bucket',
				'dofe_level'     => 'silver',
				'transport_mode' => 'foot',
			)
		);

		// 10 explorers -> 2 teams (sizes between 4 and 7).
		$this->assertCount( 2, $result['proposed_teams'] );
		foreach ( $result['proposed_teams'] as $pt ) {
			$this->assertGreaterThanOrEqual( 4, count( $pt['members'] ) );
			$this->assertLessThanOrEqual( 7, count( $pt['members'] ) );
			$this->assertMatchesRegularExpression( '/^H-SP[12]-\d+$/', $pt['team_name'] );
		}

		// H-SP1 has 1 locked team and max_teams = 2, so total_teams on H-SP1 must not exceed 2.
		$capByCode = array();
		foreach ( $result['expedition_capacity'] as $cap ) {
			$capByCode[ $cap['event_code'] ] = $cap;
		}
		$this->assertLessThanOrEqual( 2, $capByCode['H-SP1']['total_teams'] );

		// If any proposed team was placed on H-SP1, it must be named H-SP1-2 (continuing after locked H-SP1-1).
		foreach ( $result['proposed_teams'] as $pt ) {
			if ( $pt['practice_code'] === 'H-SP1' ) {
				$this->assertSame( 'H-SP1-2', $pt['team_name'] );
			}
		}
	}

	public function test_apply_writes_strictly_draft_clears_only_drafts_preserves_notified_and_never_calls_wp_mail(): void {
		$mailCalled = false;
		Functions\when( 'wp_mail' )->alias(
			static function () use ( &$mailCalled ): bool {
				$mailCalled = true;
				return true;
			}
		);

		$teamPosts = array(
			$this->mockPost( 601, 'team', 'Team H-SP1-1', 101 ), // 100% draft -> should be cleared
			$this->mockPost( 602, 'team', 'Team H-SP1-2', 101 ), // has notified member -> MUST be preserved
		);

		$meta = array(
			101 => array( 'ems_level' => 'silver', 'ems_transport' => 'hillwalking' ),
			601 => array( 'ems_team_code' => 'H-SP1-1' ),
			602 => array( 'ems_team_code' => 'H-SP1-2' ),
		);

		Functions\when( 'get_posts' )->alias( static fn() => $teamPosts );
		Functions\when( 'get_post_meta' )->alias( static fn( int $id, string $key ) => $meta[ $id ][ $key ] ?? '' );

		$trashedIds = array();
		Functions\when( 'wp_trash_post' )->alias(
			static function ( int $id ) use ( &$trashedIds ): bool {
				$trashedIds[] = $id;
				return true;
			}
		);

		$wpdb         = \Mockery::mock( \wpdb::class );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static fn( string $q, ...$args ): string => vsprintf( str_replace( array( '%d', '%s' ), array( '%s', "'%s'" ), $q ), $args )
		);
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			static function ( string $query ): array {
				if ( str_contains( $query, '601' ) ) {
					return array( 'draft', 'draft' );
				}
				if ( str_contains( $query, '602' ) ) {
					return array( 'notified', 'draft' );
				}
				return array();
			}
		);

		$deletedTeams = array();
		$wpdb->shouldReceive( 'delete' )->andReturnUsing(
			static function ( string $table, array $where ) use ( &$deletedTeams ): int {
				$deletedTeams[] = (int) ( $where['team_post_id'] ?? 0 );
				return 1;
			}
		);

		$insertedMembers = array();
		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			static function ( string $table, array $data ) use ( &$insertedMembers ): int {
				$insertedMembers[] = $data;
				return 1;
			}
		);

		$teamRepo = \Mockery::mock( Team_Repository::class );
		$teamRepo->shouldReceive( 'create' )
			->with( 101, 'H-SP1', 'H-SP1-3' )
			->once()
			->andReturn( 701 );
		$teamRepo->shouldReceive( 'create' )
			->with( 201, 'H-SQ1', null )
			->once()
			->andReturn( 702 );

		$solver = new TeamSolverService( $wpdb, null, null, $teamRepo );
		$result = $solver->apply(
			array(
				'replace_existing_drafts' => true,
				'buckets'                 => array(
					array(
						'dofe_level'     => 'silver',
						'transport_mode' => 'foot',
					),
				),
				'proposed_teams'          => array(
					array(
						'team_name'               => 'H-SP1-3',
						'dofe_level'              => 'silver',
						'transport_mode'          => 'foot',
						'practice_expedition_id'  => 101,
						'practice_code'           => 'H-SP1',
						'qualifier_expedition_id' => 201,
						'qualifier_code'          => 'H-SQ1',
						'members'                 => array(
							array( 'expedition_signup_id' => 11, 'scout_id' => 1011 ),
							array( 'expedition_signup_id' => 12, 'scout_id' => 1012 ),
							array( 'expedition_signup_id' => 13, 'scout_id' => 1013 ),
							array( 'expedition_signup_id' => 14, 'scout_id' => 1014 ),
						),
					),
				),
			)
		);

		$this->assertFalse( $mailCalled, 'wp_mail() must NEVER be called by TeamSolverService::apply()' );
		$this->assertSame( 1, $result['cleared_draft_teams'] );
		$this->assertSame( 1, $result['preserved_notified_teams'] );
		$this->assertSame( array( 601 ), $trashedIds );
		$this->assertSame( array( 601 ), $deletedTeams );
		$this->assertSame( 2, $result['created_teams'] );
		$this->assertSame( 8, $result['created_members'] );

		foreach ( $insertedMembers as $row ) {
			$this->assertSame( 'draft', $row['notification_status'] );
		}
	}

	public function test_bucket_scoped_unknown_code_warnings_and_duplicate_code_post_title_fallback(): void {
		$silverSignups = array();
		for ( $i = 1; $i <= 4; $i++ ) {
			$silverSignups[] = $this->makeSignup( $i, 'Silver', (string) $i, 'silver', 'Hillwalking', 'Lauderdale ESU' );
		}

		$goldSignup = $this->makeSignup( 5, 'Gold', 'Explorer', 'gold', 'Hillwalking', 'Lauderdale ESU' );
		$goldSignup['expedition_preferences'] = json_encode(
			array(
				'exped_type'            => 'Hillwalking',
				'exped_practice_dates'  => array( 'H-GP2', 'G-UNKNOWN' ),
				'exped_qualifier_dates' => array( 'H-GQ1' ),
				'exped_team_names'      => '',
			)
		);
		$signups = array_merge( $silverSignups, array( $goldSignup ) );

		$expPosts = array(
			$this->mockPost( 101, 'expedition', 'H-SP1' ),
			$this->mockPost( 201, 'expedition', 'H-SQ1' ),
			$this->mockPost( 15493, 'expedition', 'H-GP1' ),
			$this->mockPost( 15494, 'expedition', 'H-GP2' ),
		);
		$meta = array(
			101   => array( 'ems_event_code' => 'H-SP1', 'ems_level' => 'silver', 'ems_transport' => 'hillwalking', 'ems_type' => 'practice', 'ems_status' => 'active' ),
			201   => array( 'ems_event_code' => 'H-SQ1', 'ems_level' => 'silver', 'ems_transport' => 'hillwalking', 'ems_type' => 'qualifying', 'ems_status' => 'active' ),
			15493 => array( 'ems_event_code' => 'H-GP1', 'ems_level' => 'gold', 'ems_transport' => 'hillwalking', 'ems_type' => 'practice', 'ems_status' => 'active' ),
			// Duplicate ems_event_code 'H-GP1' should fall back to post_title 'H-GP2'.
			15494 => array( 'ems_event_code' => 'H-GP1', 'ems_level' => 'gold', 'ems_transport' => 'hillwalking', 'ems_type' => 'practice', 'ems_status' => 'active' ),
		);

		Functions\when( 'get_posts' )->alias(
			static function ( array $args ) use ( $expPosts ): array {
				return ( $args['post_type'] ?? '' ) === 'expedition' ? $expPosts : array();
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $postId, string $key, bool $single = false ) use ( $meta ) {
				return $meta[ $postId ][ $key ] ?? '';
			}
		);

		$wpdb         = \Mockery::mock( \wpdb::class );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			static function ( string $query ) use ( $signups ): array {
				if ( str_contains( $query, 'ems_expedition_signups' ) ) {
					return $signups;
				}
				return array();
			}
		);

		$solver = new TeamSolverService( $wpdb );
		$result = $solver->propose(
			array(
				'scope'          => 'bucket',
				'dofe_level'     => 'silver',
				'transport_mode' => 'foot',
			)
		);

		// Gold unknown code 'G-UNKNOWN' must NOT be warned about when solving Silver.
		$joinedWarnings = implode( ' ', $result['warnings'] );
		$this->assertStringNotContainsString( 'G-UNKNOWN', $joinedWarnings );

		// Also verify full_season expedition_capacity has #15494 resolved as H-GP2.
		$full = $solver->propose( array( 'scope' => 'full_season' ) );
		$codesById = array();
		foreach ( $full['expedition_capacity'] as $cap ) {
			$codesById[ $cap['expedition_id'] ] = $cap['event_code'];
		}
		$this->assertSame( 'H-GP1', $codesById[15493] );
		$this->assertSame( 'H-GP2', $codesById[15494] );
	}

	public function test_unit_preference_bonus_and_solo_gender_penalty(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = false ) {
				if ( $key === 'ems_explorer_genders' ) {
					return array(
						1 => 'male',
						2 => 'male',
						3 => 'male',
						4 => 'male',
						5 => 'male',
						6 => 'male',
						7 => 'female',
						8 => 'female',
					);
				}
				return $default;
			}
		);

		// 8 explorers: 6 males, 2 females (IDs 7 & 8).
		// Explorer 1 has a unit_preference for 'Tormain ESU', and explorers 2, 3, 4 are in 'Tormain ESU'.
		$signups = array(
			$this->makeSignup( 1, 'Alex', 'One', 'silver', 'Hillwalking', 'Lauderdale ESU' ),
			$this->makeSignup( 2, 'Ben', 'Two', 'silver', 'Hillwalking', 'Tormain ESU' ),
			$this->makeSignup( 3, 'Cal', 'Three', 'silver', 'Hillwalking', 'Tormain ESU' ),
			$this->makeSignup( 4, 'Dan', 'Four', 'silver', 'Hillwalking', 'Tormain ESU' ),
			$this->makeSignup( 5, 'Eli', 'Five', 'silver', 'Hillwalking', 'Lauderdale ESU' ),
			$this->makeSignup( 6, 'Fin', 'Six', 'silver', 'Hillwalking', 'Lauderdale ESU' ),
			$this->makeSignup( 7, 'Gia', 'Seven', 'silver', 'Hillwalking', 'Lauderdale ESU' ),
			$this->makeSignup( 8, 'Hana', 'Eight', 'silver', 'Hillwalking', 'Lauderdale ESU' ),
		);

		$expPosts = array(
			$this->mockPost( 101, 'expedition', 'H-SP1' ),
			$this->mockPost( 201, 'expedition', 'H-SQ1' ),
		);
		$meta = array(
			101 => array( 'ems_event_code' => 'H-SP1', 'ems_level' => 'silver', 'ems_transport' => 'hillwalking', 'ems_type' => 'practice', 'ems_status' => 'active' ),
			201 => array( 'ems_event_code' => 'H-SQ1', 'ems_level' => 'silver', 'ems_transport' => 'hillwalking', 'ems_type' => 'qualifying', 'ems_status' => 'active' ),
		);

		Functions\when( 'get_posts' )->alias(
			static function ( array $args ) use ( $expPosts ): array {
				return ( $args['post_type'] ?? '' ) === 'expedition' ? $expPosts : array();
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $postId, string $key, bool $single = false ) use ( $meta ) {
				return $meta[ $postId ][ $key ] ?? '';
			}
		);

		$edges = array(
			array(
				'id'               => 1,
				'source_signup_id' => 1,
				'target_signup_id' => null,
				'unit_hint'        => 'Tormain ESU',
				'preference_type'  => 'prefer',
				'match_status'     => 'unit_preference',
				'confidence_score' => 90,
			),
		);

		$wpdb         = \Mockery::mock( \wpdb::class );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			static function ( string $query ) use ( $signups, $edges ): array {
				if ( str_contains( $query, 'ems_expedition_signups' ) ) {
					return $signups;
				}
				if ( str_contains( $query, 'ems_teammate_preferences' ) ) {
					return $edges;
				}
				return array();
			}
		);

		$solver = new TeamSolverService( $wpdb );
		$result = $solver->propose(
			array(
				'scope'          => 'bucket',
				'dofe_level'     => 'silver',
				'transport_mode' => 'foot',
			)
		);

		$this->assertCount( 2, $result['proposed_teams'] );
		$this->assertSame( array(), $result['compromises']['solo_gender_teams'], 'Solver should avoid 1-girl teams' );

		// Verify gender is included on every member DTO and both females are on the same team.
		$femaleTeamCounts = array();
		foreach ( $result['proposed_teams'] as $team ) {
			$fCount = 0;
			foreach ( $team['members'] as $m ) {
				$this->assertArrayHasKey( 'gender', $m );
				if ( $m['gender'] === 'female' ) {
					++$fCount;
				}
			}
			$femaleTeamCounts[] = $fCount;
		}
		sort( $femaleTeamCounts );
		$this->assertSame( array( 0, 2 ), $femaleTeamCounts );
	}
}
