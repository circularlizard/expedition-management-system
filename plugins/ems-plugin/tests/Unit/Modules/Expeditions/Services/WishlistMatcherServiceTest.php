<?php
declare(strict_types=1);

namespace EMS\Tests\Unit\Modules\Expeditions\Services;

use Brain\Monkey\Functions;
use EMS\Modules\Expeditions\Services\WishlistMatcherService;
use EMS\Modules\Expeditions\Services\WishlistPreferenceRepository;
use EMS\Tests\EMSTestCase;

class WishlistMatcherServiceTest extends EMSTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_option' )->justReturn( array() );
	}

	private function makeWpdbMock( array $signupRows, array &$prefRows, array &$deletedQueries = array() ): \wpdb {
		$wpdb         = \Mockery::mock( \wpdb::class );
		$wpdb->prefix = 'wp_';
		$nextId       = 100;

		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( string $query, ...$args ): string {
				return vsprintf( strtr( $query, array( '%d' => '%s', '%s' => "'%s'" ) ), $args );
			}
		);

		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			static function ( string $query ) use ( $signupRows, &$prefRows ): array {
				if ( str_contains( $query, 'ems_expedition_signups' ) ) {
					return $signupRows;
				}
				if ( str_contains( $query, 'ems_teammate_preferences' ) ) {
					return array_values( $prefRows );
				}
				return array();
			}
		);

		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			static function ( string $query ) use ( &$prefRows, &$deletedQueries ): int {
				$deletedQueries[] = $query;
				if ( preg_match( '/DELETE FROM wp_ems_teammate_preferences WHERE source_signup_id = (\d+)/', $query, $m ) ) {
					$src     = (int) $m[1];
					$removed = 0;
					$onlyUnreviewed = str_contains( $query, "NOT IN ('confirmed', 'ignored')" );
					foreach ( $prefRows as $k => $row ) {
						if ( (int) $row['source_signup_id'] === $src ) {
							if ( $onlyUnreviewed && in_array( $row['match_status'], array( 'confirmed', 'ignored' ), true ) ) {
								continue;
							}
							unset( $prefRows[ $k ] );
							++$removed;
						}
					}
					return $removed;
				}
				return 0;
			}
		);

		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			static function ( string $table, array $data ) use ( &$prefRows, &$nextId, $wpdb ): int {
				++$nextId;
				$wpdb->insert_id   = $nextId;
				$data['id']        = $nextId;
				$prefRows[ $nextId ] = $data;
				return 1;
			}
		);

		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			static function ( string $table, array $data, array $where ) use ( &$prefRows ): int {
				$id = (int) ( $where['id'] ?? 0 );
				if ( isset( $prefRows[ $id ] ) ) {
					$prefRows[ $id ] = array_merge( $prefRows[ $id ], $data );
					return 1;
				}
				return 0;
			}
		);

		return $wpdb;
	}

	private function signupRow(
		int $id,
		string $first,
		string $last,
		string $level,
		string $expedType,
		string $wishlist,
		string $unitName = 'Lauderdale ESU',
		string $unitShort = 'LAU',
		array $practiceCodes = array( 'H-SP1' )
	): array {
		return array(
			'id'                  => $id,
			'scout_id'            => 0,
			'unit_id'             => $id,
			'unit_name'           => $unitName,
			'explorer_first_name' => $first,
			'explorer_last_name'  => $last,
			'dofe_level'          => $level,
			'expedition_preferences' => json_encode(
				array(
					'exped_type'            => $expedType,
					'exped_practice_dates'  => $practiceCodes,
					'exped_qualifier_dates' => array( 'H-SQ1' ),
					'exped_team_names'      => $wishlist,
				)
			),
			'first_aid_status'    => 'none',
			'first_aid_expiry'    => null,
			'osm_first_name'      => null,
			'osm_last_name'       => null,
			'osm_first_aid_level' => null,
			'resolved_unit_name'  => $unitName,
			'unit_short_code'     => $unitShort,
		);
	}

	public function test_hash_drift_preserves_confirmed_rows_and_purges_on_change(): void {
		$wishlist = 'Lucy Hann';
		$hash     = md5( $wishlist );

		$signups = array(
			$this->signupRow( 1, 'Sam', 'Taylor', 'silver', 'Hillwalking', $wishlist ),
			$this->signupRow( 2, 'Lucy', 'Hann', 'silver', 'Hillwalking', '' ),
			$this->signupRow( 3, 'Ben', 'Cole', 'silver', 'Hillwalking', '' ),
		);

		$prefRows = array(
			10 => array(
				'id'                 => 10,
				'source_signup_id'   => 1,
				'target_signup_id'   => 2,
				'wishlist_hash'      => $hash,
				'raw_token'          => 'Lucy Hann',
				'unit_hint'          => null,
				'preference_type'    => 'prefer',
				'match_status'       => 'confirmed',
				'confidence_score'   => 100,
				'candidate_ids_json' => null,
				'flag_negation'      => 0,
				'reviewed_by'        => 1,
				'reviewed_at'        => '2026-06-13 20:00:00',
				'created_at'         => '2026-06-13 20:00:00',
				'updated_at'         => '2026-06-13 20:00:00',
			),
		);

		$deletedQueries = array();
		$wpdb           = $this->makeWpdbMock( $signups, $prefRows, $deletedQueries );
		$service        = new WishlistMatcherService( $wpdb );

		// Same hash -> confirmed row is preserved and skipped.
		$res1 = $service->parseAll();
		$this->assertSame( 1, $res1['skipped_count'] );
		$this->assertSame( 0, $res1['parsed_count'] );
		$this->assertArrayHasKey( 10, $prefRows );

		// Now simulate explorer editing their wishlist -> hash drift triggers purge & re-parse.
		$signups[0] = $this->signupRow( 1, 'Sam', 'Taylor', 'silver', 'Hillwalking', 'Ben Cole' );
		$wpdb2      = $this->makeWpdbMock( $signups, $prefRows, $deletedQueries );
		$service2   = new WishlistMatcherService( $wpdb2 );

		$res2 = $service2->parseAll();
		$this->assertSame( 1, $res2['parsed_count'] );
		$this->assertSame( array( 'DELETE FROM wp_ems_teammate_preferences WHERE source_signup_id = 1' ), $deletedQueries );
		$this->assertArrayNotHasKey( 10, $prefRows );
		$remaining = array_values( $prefRows );
		$this->assertCount( 1, $remaining );
		$this->assertSame( 3, $remaining[0]['target_signup_id'] );
		$this->assertSame( 'auto_matched', $remaining[0]['match_status'] );
	}

	public function test_duplicate_name_disambiguation_with_and_without_unit_hint(): void {
		$signups = array(
			$this->signupRow( 1, 'Sam', 'Taylor', 'silver', 'Hillwalking', 'John Smith (Lauderdale ESU)' ),
			$this->signupRow( 2, 'Mia', 'Brown', 'silver', 'Hillwalking', 'John Smith' ),
			$this->signupRow( 3, 'John', 'Smith', 'silver', 'Hillwalking', '', 'Lauderdale ESU', 'LAU' ),
			$this->signupRow( 4, 'John', 'Smith', 'silver', 'Hillwalking', '', 'Craigmillar ESU', 'CRA' ),
		);

		$prefRows = array();
		$wpdb     = $this->makeWpdbMock( $signups, $prefRows );
		$service  = new WishlistMatcherService( $wpdb );

		$result = $service->parseAll();
		$this->assertSame( 2, $result['parsed_count'] );
		$this->assertSame( 1, $result['needs_review_count'] );

		$bySource = array();
		foreach ( $prefRows as $row ) {
			$bySource[ (int) $row['source_signup_id'] ] = $row;
		}

		// Source 1 had "(Lauderdale ESU)" -> promoted to auto_matched signup 3.
		$this->assertSame( 'auto_matched', $bySource[1]['match_status'] );
		$this->assertSame( 3, $bySource[1]['target_signup_id'] );

		// Source 2 had no unit hint -> ambiguous with both John Smiths in candidate_ids_json.
		$this->assertSame( 'ambiguous', $bySource[2]['match_status'] );
		$this->assertNull( $bySource[2]['target_signup_id'] );
		$cands = json_decode( (string) $bySource[2]['candidate_ids_json'], true );
		$this->assertCount( 2, $cands );
	}

	public function test_cross_bucket_and_negation_flagging(): void {
		$signups = array(
			$this->signupRow( 1, 'Sam', 'Taylor', 'silver', 'Hillwalking', 'Alice Gold, not Ben Cole' ),
			$this->signupRow( 2, 'Alice', 'Gold', 'gold', 'Hillwalking', '' ),
			$this->signupRow( 3, 'Ben', 'Cole', 'silver', 'Hillwalking', '' ),
		);

		$prefRows = array();
		$wpdb     = $this->makeWpdbMock( $signups, $prefRows );
		$service  = new WishlistMatcherService( $wpdb );

		$result = $service->parseAll();
		$this->assertSame( 2, $result['needs_review_count'] );

		$rows = array_values( $prefRows );
		$this->assertCount( 2, $rows );

		// Alice Gold is in Gold -> cross_bucket.
		$this->assertSame( 'cross_bucket', $rows[0]['match_status'] );
		$this->assertNull( $rows[0]['target_signup_id'] );

		// "not Ben Cole" -> flag_negation = 1, never auto_matched.
		$this->assertSame( 1, $rows[1]['flag_negation'] );
		$this->assertNotSame( 'auto_matched', $rows[1]['match_status'] );
		$this->assertSame( 'avoid', $rows[1]['preference_type'] );
	}

	public function test_bucket_scoped_candidate_search_excludes_other_buckets(): void {
		$signups = array(
			$this->signupRow( 1, 'Sam', 'Taylor', 'silver', 'Hillwalking', '' ),
			$this->signupRow( 2, 'Alex', 'Walker', 'silver', 'Hillwalking', '' ),
			$this->signupRow( 3, 'Alex', 'Rider', 'silver', 'Cycling', '' ),
			$this->signupRow( 4, 'Alex', 'Gold', 'gold', 'Hillwalking', '' ),
		);

		$prefRows = array();
		$wpdb     = $this->makeWpdbMock( $signups, $prefRows );
		$service  = new WishlistMatcherService( $wpdb );

		$candidates = $service->searchCandidates( 1, 'Alex' );
		$this->assertCount( 1, $candidates );
		$this->assertSame( 2, $candidates[0]['signup_id'] );
	}

	public function test_prose_prefix_and_sliding_two_token_window(): void {
		$signups = array(
			$this->signupRow( 1, 'Sam', 'Taylor', 'silver', 'Hillwalking', 'Same Wildlife group as per Bronze: Lucy Hann Ben Cole' ),
			$this->signupRow( 2, 'Lucy', 'Hann', 'silver', 'Hillwalking', '' ),
			$this->signupRow( 3, 'Ben', 'Cole', 'silver', 'Hillwalking', '' ),
		);

		$prefRows = array();
		$wpdb     = $this->makeWpdbMock( $signups, $prefRows );
		$service  = new WishlistMatcherService( $wpdb );

		$service->parseAll();
		$rows = array_values( $prefRows );
		$this->assertCount( 2, $rows );
		$targets = array_column( $rows, 'target_signup_id' );
		sort( $targets );
		$this->assertSame( array( 2, 3 ), $targets );
	}

	public function test_unit_preference_matching_for_unit_name_and_phrases(): void {
		$signups = array(
			$this->signupRow( 1, 'Sam', 'Taylor', 'silver', 'Hillwalking', 'Tormain Explorers, anyone from Lauderdale ESU', 'Lauderdale ESU', 'LAU' ),
			$this->signupRow( 2, 'Lucy', 'Hann', 'silver', 'Hillwalking', '', 'Tormain ESU', 'TOR' ),
			$this->signupRow( 3, 'Ben', 'Cole', 'silver', 'Hillwalking', '', 'Lauderdale ESU', 'LAU' ),
		);

		$prefRows = array();
		$wpdb     = $this->makeWpdbMock( $signups, $prefRows );
		$service  = new WishlistMatcherService( $wpdb );

		$summary = $service->parseAll();
		$this->assertSame( 0, $summary['needs_review_count'] );

		$rows = array_values( $prefRows );
		$this->assertCount( 2, $rows );
		foreach ( $rows as $row ) {
			$this->assertSame( 'unit_preference', $row['match_status'] );
			$this->assertNull( $row['target_signup_id'] );
			$this->assertSame( 90, $row['confidence_score'] );
		}

		$hints = array_column( $rows, 'unit_hint' );
		sort( $hints );
		$this->assertSame( array( 'Lauderdale ESU', 'Tormain ESU' ), $hints );

		$queue = $service->getQueue( array( 'filter' => 'all' ) );
		$this->assertSame( 2, $queue['summary']['auto_matched'] );
		$this->assertSame( 0, $queue['summary']['needs_review'] );
		$this->assertCount( 3, $queue['items'] );
		$this->assertSame( 'Lauderdale ESU', $queue['items'][0]['tokens'][1]['resolved_target_unit'] );
	}

	public function test_gender_storage_in_options_and_queue_and_candidates(): void {
		$options = array();
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = false ) use ( &$options ) {
				return $options[ $key ] ?? $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( string $key, $val ) use ( &$options ): bool {
				$options[ $key ] = $val;
				return true;
			}
		);

		$signups = array(
			$this->signupRow( 1, 'Sam', 'Taylor', 'silver', 'Hillwalking', 'Lucy Hann' ),
			$this->signupRow( 2, 'Lucy', 'Hann', 'silver', 'Hillwalking', '' ),
		);

		$prefRows = array();
		$wpdb     = $this->makeWpdbMock( $signups, $prefRows );
		$service  = new WishlistMatcherService( $wpdb );

		$service->saveDecisions( 1, array(), 'female' );
		$service->saveDecisions( 2, array(), 'male' );
		$this->assertSame( array( 1 => 'female', 2 => 'male' ), $options['ems_explorer_genders'] );

		$service->parseAll();
		$queue = $service->getQueue( array( 'filter' => 'all' ) );
		$this->assertCount( 2, $queue['items'] );
		$this->assertSame( 'female', $queue['items'][0]['gender'] );
		$this->assertSame( 'male', $queue['items'][1]['gender'] );
		$this->assertSame( array(), $queue['items'][1]['tokens'] );

		$candidates = $service->searchCandidates( 1, 'Lucy' );
		$this->assertCount( 1, $candidates );
		$this->assertSame( 'male', $candidates[0]['gender'] );
	}
}
