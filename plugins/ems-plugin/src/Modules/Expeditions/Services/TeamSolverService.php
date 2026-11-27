<?php
declare(strict_types=1);

namespace EMS\Modules\Expeditions\Services;

use EMS\Data\Team_Repository;

/**
 * Partitioned greedy + Simulated Annealing team solver per (dofe_level, transport_mode).
 *
 * Hard constraints:
 * - 4 to 7 members per team per stage
 * - Per-expedition max_teams capacity (_ems_max_teams / _ems_capacity_teams; uncapped when 0/unset)
 * - Fixed locked / notified teams
 * - Strictly draft-only writes on apply(); never calls wp_mail() or ExpeditionOfferDispatcher
 */
class TeamSolverService {

	public const DEFAULT_WEIGHTS = array(
		'avoid_exclusion'            => -500,
		'cross_stage_continuity'     => 100,
		'code_availability'          => 80,
		'mutual_wishlist'            => 25,
		'one_way_wishlist'           => 10,
		'same_unit'                  => 5,
		'same_district'              => 3,
		'first_aid_presence'         => 5,
		'solo_gender_penalty'        => -40,
		'solo_unit_district_penalty' => -30,
	);

	private ?\wpdb $wpdb;
	private WishlistPreferenceRepository $prefs;
	private WishlistMatcherService $matcher;
	private Team_Repository $teams;

	public function __construct(
		?\wpdb $wpdb = null,
		?WishlistPreferenceRepository $prefs = null,
		?WishlistMatcherService $matcher = null,
		?Team_Repository $teams = null
	) {
		$this->wpdb    = $wpdb;
		$this->prefs   = $prefs ?? new WishlistPreferenceRepository( $wpdb );
		$this->matcher = $matcher ?? new WishlistMatcherService( $wpdb, $this->prefs );
		$this->teams   = $teams ?? new Team_Repository();
	}

	private function get_wpdb(): \wpdb {
		if ( $this->wpdb === null ) {
			global $wpdb;
			$this->wpdb = $wpdb;
		}
		return $this->wpdb;
	}

	/**
	 * Generate a team proposal in memory (zero DB writes).
	 *
	 * @param array<string, mixed> $params
	 * @return array<string, mixed>
	 */
	public function propose( array $params = array() ): array {
		$scope        = (string) ( $params['scope'] ?? 'bucket' ) === 'full_season' ? 'full_season' : 'bucket';
		$filterLevels = array();
		if ( ! empty( $params['dofe_levels'] ) && is_array( $params['dofe_levels'] ) ) {
			foreach ( $params['dofe_levels'] as $lvl ) {
				$clean = strtolower( trim( (string) $lvl ) );
				if ( $clean !== '' ) {
					$filterLevels[] = $clean;
				}
			}
		} elseif ( isset( $params['dofe_level'] ) && trim( (string) $params['dofe_level'] ) !== '' ) {
			foreach ( explode( ',', (string) $params['dofe_level'] ) as $lvl ) {
				$clean = strtolower( trim( $lvl ) );
				if ( $clean !== '' ) {
					$filterLevels[] = $clean;
				}
			}
		}
		$filterLevels = array_values( array_unique( $filterLevels ) );
		$filterMode   = isset( $params['transport_mode'] ) && $params['transport_mode'] !== ''
			? $this->matcher->normalizeTransportMode( (string) $params['transport_mode'] )
			: '';
		$weights      = $this->resolveWeights( is_array( $params['weights'] ?? null ) ? $params['weights'] : array() );

		$signups     = $this->matcher->loadActiveSignups();
		$expeditions = $this->loadActiveExpeditions();
		$lockedInfo  = $this->loadLockedTeamsInfo();
		$edges       = $this->prefs->getActiveEdges();

		$warnings = array();

		// Index known expedition codes; unknown-code warnings are emitted only for solved buckets below.
		$knownCodes = array();
		foreach ( $expeditions as $exp ) {
			if ( $exp['event_code'] !== '' ) {
				$knownCodes[ strtoupper( $exp['event_code'] ) ] = true;
			}
		}

		// Group unlocked signups by (dofe_level, transport_mode).
		$buckets = array();
		foreach ( $signups as $s ) {
			$level = $s['dofe_level'];
			$mode  = $s['transport_mode'];
			if ( $level === '' || $mode === '' ) {
				continue;
			}
			if ( $scope === 'bucket' ) {
				if ( ! empty( $filterLevels ) && ! in_array( $level, $filterLevels, true ) ) {
					continue;
				}
				if ( $filterMode !== '' && $mode !== $filterMode ) {
					continue;
				}
			}
			$key = $level . '|' . $mode;
			if ( ! isset( $buckets[ $key ] ) ) {
				$buckets[ $key ] = array(
					'dofe_level'     => $level,
					'transport_mode' => $mode,
					'signups'        => array(),
				);
			}
			$buckets[ $key ]['signups'][] = $s;
		}

		$proposedTeams     = array();
		$bucketsSolved     = array();
		$proposedCountByExp = array();
		$compromises       = array(
			'date_conflict_pending'    => 0,
			'split_date'               => 0,
			'unfulfilled_mutual_pairs' => 0,
			'solo_gender_teams'        => array(),
		);
		$stats             = array(
			'total_explorers'         => 0,
			'total_teams'             => 0,
			'mutual_pairs_fulfilled'  => 0,
			'mutual_pairs_total'      => 0,
			'first_aid_teams_covered' => 0,
		);

		foreach ( $buckets as $bucketKey => $bucket ) {
			$level = $bucket['dofe_level'];
			$mode  = $bucket['transport_mode'];

			// Exclude explorers already on a locked team in this bucket.
			$unlockedSignups = array();
			foreach ( $bucket['signups'] as $s ) {
				$sid = (int) $s['id'];
				if ( isset( $lockedInfo['locked_signups'][ $sid ] ) ) {
					continue;
				}
				$unlockedSignups[] = $s;
			}

			$n = count( $unlockedSignups );
			if ( $n === 0 ) {
				continue;
			}
			if ( $n < 4 ) {
				$warnings[] = sprintf(
					'Bucket %s %s has only %d unlocked explorer(s); minimum team size is 4.',
					ucfirst( $level ),
					ucfirst( $mode ),
					$n
				);
				continue;
			}

			$practiceExps  = $this->filterExpeditionsForBucket( $expeditions, $level, $mode, 'practice' );
			$qualifierExps = $this->filterExpeditionsForBucket( $expeditions, $level, $mode, 'qualifier' );

			if ( empty( $practiceExps ) || empty( $qualifierExps ) ) {
				$warnings[] = sprintf(
					'Bucket %s %s is missing active Practice or Qualifier expeditions.',
					ucfirst( $level ),
					ucfirst( $mode )
				);
				continue;
			}

			$bucketResult = $this->solveBucket(
				$level,
				$mode,
				$unlockedSignups,
				$practiceExps,
				$qualifierExps,
				$edges,
				$weights,
				$lockedInfo,
				$proposedCountByExp
			);

			$bucketsSolved[] = $bucketKey;
			foreach ( $bucket['signups'] as $s ) {
				foreach ( array_merge( $s['practice_codes'], $s['qualifier_codes'] ) as $code ) {
					$upper = strtoupper( (string) $code );
					if ( $upper !== '' && ! isset( $knownCodes[ $upper ] ) ) {
						$warnings[] = sprintf( 'Explorer %s references unknown expedition code "%s".', $s['explorer_name'], $upper );
					}
				}
			}
			foreach ( $bucketResult['warnings'] as $w ) {
				$warnings[] = $w;
			}
			foreach ( $bucketResult['teams'] as $team ) {
				$proposedTeams[] = $team;
			}

			$compromises['date_conflict_pending']    += $bucketResult['compromises']['date_conflict_pending'];
			$compromises['split_date']               += $bucketResult['compromises']['split_date'];
			$compromises['unfulfilled_mutual_pairs'] += $bucketResult['compromises']['unfulfilled_mutual_pairs'];
			$compromises['solo_gender_teams']         = array_merge(
				$compromises['solo_gender_teams'],
				$bucketResult['compromises']['solo_gender_teams']
			);

			$stats['total_explorers']         += $bucketResult['stats']['total_explorers'];
			$stats['total_teams']             += $bucketResult['stats']['total_teams'];
			$stats['mutual_pairs_fulfilled']  += $bucketResult['stats']['mutual_pairs_fulfilled'];
			$stats['mutual_pairs_total']      += $bucketResult['stats']['mutual_pairs_total'];
			$stats['first_aid_teams_covered'] += $bucketResult['stats']['first_aid_teams_covered'];
		}

		usort(
			$proposedTeams,
			static fn( array $a, array $b ): int => strnatcasecmp( (string) ( $a['team_name'] ?? '' ), (string) ( $b['team_name'] ?? '' ) )
		);

		$expeditionCapacity = array();
		foreach ( $expeditions as $exp ) {
			$eid    = (int) $exp['id'];
			$locked = (int) ( $lockedInfo['locked_count_by_exp'][ $eid ] ?? 0 );
			$prop   = (int) ( $proposedCountByExp[ $eid ] ?? 0 );
			if ( $scope === 'bucket' ) {
				if ( ! empty( $filterLevels ) && ! in_array( $exp['dofe_level'], $filterLevels, true ) && $exp['dofe_level'] !== 'multiple' ) {
					continue;
				}
				if ( $filterMode !== '' && $exp['transport_mode'] !== $filterMode ) {
					continue;
				}
			}
			$expeditionCapacity[] = array(
				'expedition_id'  => $eid,
				'event_code'     => $exp['event_code'],
				'title'          => $exp['title'],
				'stage'          => $exp['stage'],
				'locked_teams'   => $locked,
				'proposed_teams' => $prop,
				'total_teams'    => $locked + $prop,
				'max_teams'      => $exp['max_teams'],
			);
		}

		return array(
			'scope'               => $scope,
			'buckets_solved'      => $bucketsSolved,
			'proposed_teams'      => $proposedTeams,
			'expedition_capacity' => $expeditionCapacity,
			'compromises'         => $compromises,
			'warnings'            => array_values( array_unique( $warnings ) ),
			'stats'               => $stats,
		);
	}

	/**
	 * Commit an accepted proposal strictly as notification_status = 'draft' teams.
	 * Never calls wp_mail() or ExpeditionOfferDispatcher. Never modifies/deletes notified teams.
	 *
	 * @param array<string, mixed> $params
	 * @return array{created_teams: int, created_members: int, cleared_draft_teams: int, preserved_notified_teams: int}
	 */
	public function apply( array $params ): array {
		$proposedTeams        = is_array( $params['proposed_teams'] ?? null ) ? $params['proposed_teams'] : array();
		$replaceExistingDraft = ! empty( $params['replace_existing_drafts'] );
		$buckets              = is_array( $params['buckets'] ?? null ) ? $params['buckets'] : array();

		$wpdb     = $this->get_wpdb();
		$tmTable  = $wpdb->prefix . 'ems_team_members';
		$now      = current_time( 'mysql', true );
		$userId   = (int) get_current_user_id();

		$clearedDraftTeams     = 0;
		$preservedNotifiedTeams = 0;

		$targetBuckets = $this->normalizeBucketFilterSet( $buckets, $proposedTeams );

		if ( ! empty( $targetBuckets ) ) {
			$existingTeams = get_posts(
				array(
					'post_type'      => 'team',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
				)
			);
			if ( is_array( $existingTeams ) ) {
				foreach ( $existingTeams as $tp ) {
					$tid      = (int) $tp->ID;
					$teamCode = trim( (string) get_post_meta( $tid, 'ems_team_code', true ) );
					if ( strtoupper( $teamCode ) === 'UNALLOCATED' || strtoupper( trim( (string) $tp->post_title ) ) === 'UNALLOCATED' ) {
						continue;
					}
					$expId = (int) $tp->post_parent;
					if ( $expId <= 0 ) {
						continue;
					}
					$evLevel = strtolower( trim( (string) get_post_meta( $expId, 'ems_level', true ) ) );
					$evMode  = $this->matcher->normalizeTransportMode( (string) ( get_post_meta( $expId, 'ems_transport', true ) ?: 'foot' ) );
					if ( ! isset( $targetBuckets[ $evLevel . '|' . $evMode ] ) ) {
						continue;
					}

					$statuses = $wpdb->get_col(
						$wpdb->prepare(
							"SELECT notification_status FROM {$tmTable} WHERE team_post_id = %d",
							$tid
						)
					) ?: array();

					$hasNotified = false;
					foreach ( $statuses as $st ) {
						if ( (string) $st !== 'draft' ) {
							$hasNotified = true;
							break;
						}
					}

					if ( $hasNotified ) {
						++$preservedNotifiedTeams;
						continue;
					}

					if ( $replaceExistingDraft ) {
						$wpdb->delete( $tmTable, array( 'team_post_id' => $tid ), array( '%d' ) );
						wp_trash_post( $tid );
						++$clearedDraftTeams;
					}
				}
			}
		}

		$createdTeams   = 0;
		$createdMembers = 0;

		foreach ( $proposedTeams as $pt ) {
			if ( ! is_array( $pt ) ) {
				continue;
			}
			$members       = is_array( $pt['members'] ?? null ) ? $pt['members'] : array();
			$practiceExpId = (int) ( $pt['practice_expedition_id'] ?? 0 );
			$qualExpId     = (int) ( $pt['qualifier_expedition_id'] ?? 0 );
			$teamName      = sanitize_text_field( (string) ( $pt['team_name'] ?? '' ) );
			$practiceCode  = sanitize_text_field( (string) ( $pt['practice_code'] ?? '' ) );
			$qualCode      = sanitize_text_field( (string) ( $pt['qualifier_code'] ?? '' ) );

			if ( empty( $members ) ) {
				continue;
			}

			if ( $practiceExpId > 0 ) {
				$practiceTeamId = $this->createDraftTeamPost( $practiceExpId, $practiceCode, $teamName );
				if ( $practiceTeamId > 0 ) {
					++$createdTeams;
					$createdMembers += $this->insertDraftMembers( $practiceTeamId, $members, $userId, $now );
				}
			}

			if ( $qualExpId > 0 && $qualExpId !== $practiceExpId ) {
				$qualTeamId = $this->createDraftTeamPost( $qualExpId, $qualCode, null );
				if ( $qualTeamId > 0 ) {
					++$createdTeams;
					$createdMembers += $this->insertDraftMembers( $qualTeamId, $members, $userId, $now );
				}
			}
		}

		return array(
			'created_teams'            => $createdTeams,
			'created_members'          => $createdMembers,
			'cleared_draft_teams'      => $clearedDraftTeams,
			'preserved_notified_teams' => $preservedNotifiedTeams,
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $signups
	 * @param array<int, array<string, mixed>> $practiceExps
	 * @param array<int, array<string, mixed>> $qualifierExps
	 * @param array<int, array<string, mixed>> $edges
	 * @param array<string, int>               $weights
	 * @param array<string, mixed>             $lockedInfo
	 * @param array<int, int>                  $proposedCountByExp
	 * @return array{teams: array<int, array<string, mixed>>, warnings: array<int, string>, compromises: array{date_conflict_pending: int, split_date: int, unfulfilled_mutual_pairs: int, solo_gender_teams: array<int, array{team_name: string, stage: string, solo_gender: string, explorer_name: string}>}, stats: array{total_explorers: int, total_teams: int, mutual_pairs_fulfilled: int, mutual_pairs_total: int, first_aid_teams_covered: int}}
	 */
	private function solveBucket(
		string $level,
		string $mode,
		array $signups,
		array $practiceExps,
		array $qualifierExps,
		array $edges,
		array $weights,
		array $lockedInfo,
		array &$proposedCountByExp
	): array {
		$n        = count( $signups );
		$warnings = array();

		$practiceCap  = $this->totalRemainingCapacity( $practiceExps, $lockedInfo['locked_count_by_exp'], $proposedCountByExp );
		$qualifierCap = $this->totalRemainingCapacity( $qualifierExps, $lockedInfo['locked_count_by_exp'], $proposedCountByExp );

		$minK = (int) ceil( $n / 7 );
		$maxK = (int) floor( $n / 4 );
		$capK = min( $practiceCap, $qualifierCap );

		if ( $capK < $minK ) {
			$warnings[] = sprintf(
				'Bucket %s %s needs at least %d team slot(s) for %d explorers, but only %d expedition team slot(s) remain under max_teams.',
				ucfirst( $level ),
				ucfirst( $mode ),
				$minK,
				$n,
				$capK
			);
			return array(
				'teams'       => array(),
				'warnings'    => $warnings,
				'compromises' => array(
					'date_conflict_pending'    => 0,
					'split_date'               => 0,
					'unfulfilled_mutual_pairs' => 0,
					'solo_gender_teams'        => array(),
				),
				'stats'       => array(
					'total_explorers'         => 0,
					'total_teams'             => 0,
					'mutual_pairs_fulfilled'  => 0,
					'mutual_pairs_total'      => 0,
					'first_aid_teams_covered' => 0,
				),
			);
		}

		$k = (int) round( $n / 5.5 );
		$k = max( $minK, min( $maxK, $capK, $k ) );

		// Index signups and edges for fast scoring.
		$signupByIdx = array_values( $signups );
		$idxBySid    = array();
		foreach ( $signupByIdx as $idx => $s ) {
			$idxBySid[ (int) $s['id'] ] = $idx;
		}

		$preferAdj       = array();
		$avoidAdj        = array();
		$unitPreferPrefs = array();
		foreach ( $edges as $edge ) {
			$u = $idxBySid[ (int) $edge['source_signup_id'] ] ?? null;
			if ( $u === null ) {
				continue;
			}
			if ( ( $edge['match_status'] ?? '' ) === 'unit_preference' ) {
				$unitHint = trim( (string) ( $edge['unit_hint'] ?? '' ) );
				if ( $unitHint !== '' && ( $edge['preference_type'] ?? 'prefer' ) !== 'avoid' ) {
					$unitPreferPrefs[ $u ][] = $unitHint;
				}
				continue;
			}
			$v = $idxBySid[ (int) $edge['target_signup_id'] ] ?? null;
			if ( $v === null || $u === $v ) {
				continue;
			}
			if ( $edge['preference_type'] === 'avoid' ) {
				$avoidAdj[ $u ][ $v ] = true;
				$avoidAdj[ $v ][ $u ] = true;
			} else {
				$preferAdj[ $u ][ $v ] = true;
			}
		}

		$mutualPairs = array();
		foreach ( $preferAdj as $u => $targets ) {
			foreach ( $targets as $v => $_ ) {
				if ( isset( $preferAdj[ $v ][ $u ] ) && $u < $v ) {
					$mutualPairs[] = array( $u, $v );
				}
			}
		}

		// Greedy initial clustering into K teams of size 4..7.
		$teamAssign = $this->greedyInitialPartition( $n, $k, $preferAdj, $avoidAdj, $signupByIdx );

		// Assign each team a Practice and Qualifier expedition respecting max_teams.
		$pSlots = $this->buildExpeditionSlots( $practiceExps, $lockedInfo['locked_count_by_exp'], $proposedCountByExp );
		$qSlots = $this->buildExpeditionSlots( $qualifierExps, $lockedInfo['locked_count_by_exp'], $proposedCountByExp );

		$teamPracticeExp  = array();
		$teamQualifierExp = array();
		for ( $t = 0; $t < $k; $t++ ) {
			$teamPracticeExp[ $t ]  = $this->pickBestExpeditionSlot( $t, $teamAssign, $signupByIdx, $pSlots, 'practice_codes' );
			$teamQualifierExp[ $t ] = $this->pickBestExpeditionSlot( $t, $teamAssign, $signupByIdx, $qSlots, 'qualifier_codes' );
		}

		// Simulated Annealing over team assignments & expedition slot assignments.
		$bestState = array(
			'assign' => $teamAssign,
			'pExp'   => $teamPracticeExp,
			'qExp'   => $teamQualifierExp,
		);
		$bestScore = $this->scoreSolution(
			$bestState['assign'],
			$bestState['pExp'],
			$bestState['qExp'],
			$signupByIdx,
			$preferAdj,
			$avoidAdj,
			$mutualPairs,
			$weights,
			$unitPreferPrefs
		);

		$curState = $bestState;
		$curScore = $bestScore;

		$iterations = 1500;
		$rng        = 1234567;
		for ( $iter = 0; $iter < $iterations; $iter++ ) {
			$rng  = ( $rng * 1103515245 + 12345 ) & 0x7fffffff;
			$temp = 60.0 * pow( 0.01, $iter / max( 1, $iterations - 1 ) );
			$next = $curState;

			$moveType = $iter % 3;
			if ( $moveType === 0 && $k >= 2 ) {
				// Swap two explorers between different teams.
				$u    = $rng % $n;
				$rng  = ( $rng * 1103515245 + 12345 ) & 0x7fffffff;
				$v    = $rng % $n;
				if ( $next['assign'][ $u ] === $next['assign'][ $v ] ) {
					for ( $step = 1; $step < $n; $step++ ) {
						$candV = ( $v + $step ) % $n;
						if ( $next['assign'][ $candV ] !== $next['assign'][ $u ] ) {
							$v = $candV;
							break;
						}
					}
				}
				if ( $next['assign'][ $u ] !== $next['assign'][ $v ] ) {
					$tmp                  = $next['assign'][ $u ];
					$next['assign'][ $u ] = $next['assign'][ $v ];
					$next['assign'][ $v ] = $tmp;
				}
			} elseif ( $moveType === 1 && $k >= 2 ) {
				// Move an explorer from a team of size > 4 to a team of size < 7.
				$sizes = array_fill( 0, $k, 0 );
				foreach ( $next['assign'] as $t ) {
					++$sizes[ $t ];
				}
				$u      = ( $iter * 13 + 5 ) % $n;
				$fromT  = $next['assign'][ $u ];
				$toT    = ( $fromT + 1 + ( $iter % ( $k - 1 ) ) ) % $k;
				if ( $sizes[ $fromT ] > 4 && $sizes[ $toT ] < 7 ) {
					$next['assign'][ $u ] = $toT;
				}
			} else {
				// Reassign a team's practice or qualifier expedition to another available slot.
				$t = $iter % $k;
				if ( ( $iter % 2 ) === 0 && count( $practiceExps ) > 1 ) {
					$cand = $this->findAlternativeExpedition( $next['pExp'][ $t ], $next['pExp'], $practiceExps, $lockedInfo['locked_count_by_exp'], $proposedCountByExp, $iter );
					if ( $cand !== null ) {
						$next['pExp'][ $t ] = $cand;
					}
				} elseif ( count( $qualifierExps ) > 1 ) {
					$cand = $this->findAlternativeExpedition( $next['qExp'][ $t ], $next['qExp'], $qualifierExps, $lockedInfo['locked_count_by_exp'], $proposedCountByExp, $iter );
					if ( $cand !== null ) {
						$next['qExp'][ $t ] = $cand;
					}
				}
			}

			$nextScore = $this->scoreSolution(
				$next['assign'],
				$next['pExp'],
				$next['qExp'],
				$signupByIdx,
				$preferAdj,
				$avoidAdj,
				$mutualPairs,
				$weights,
				$unitPreferPrefs
			);

			$delta = $nextScore - $curScore;
			if ( $delta >= 0 || ( exp( $delta / max( 0.01, $temp ) ) > ( ( $iter * 97 % 1000 ) / 1000.0 ) ) ) {
				$curState = $next;
				$curScore = $nextScore;
				if ( $curScore > $bestScore ) {
					$bestState = $curState;
					$bestScore = $curScore;
				}
			}
		}

		// Deterministic 2-opt swap polish so no improving pairwise swap is left behind.
		if ( $k >= 2 ) {
			for ( $pass = 0; $pass < 10; $pass++ ) {
				$improved = false;
				for ( $u = 0; $u < $n; $u++ ) {
					for ( $v = $u + 1; $v < $n; $v++ ) {
						if ( $bestState['assign'][ $u ] === $bestState['assign'][ $v ] ) {
							continue;
						}
						$cand               = $bestState;
						$tmp                = $cand['assign'][ $u ];
						$cand['assign'][ $u ] = $cand['assign'][ $v ];
						$cand['assign'][ $v ] = $tmp;
						$candScore          = $this->scoreSolution(
							$cand['assign'],
							$cand['pExp'],
							$cand['qExp'],
							$signupByIdx,
							$preferAdj,
							$avoidAdj,
							$mutualPairs,
							$weights,
							$unitPreferPrefs
						);
						if ( $candScore > $bestScore ) {
							$bestState = $cand;
							$bestScore = $candScore;
							$improved  = true;
						}
					}
				}
				if ( ! $improved ) {
					break;
				}
			}
		}

		// Format teams and track <practice_code>-<n> sequence numbers.
		$expById = array();
		foreach ( array_merge( $practiceExps, $qualifierExps ) as $exp ) {
			$expById[ (int) $exp['id'] ] = $exp;
		}

		$nextSeqByPracticeExp = $lockedInfo['max_seq_by_exp'];
		$teamsOut             = array();
		$dateConflicts        = 0;
		$firstAidCovered      = 0;
		$soloGenderTeams      = array();

		for ( $t = 0; $t < $k; $t++ ) {
			$pExpId = (int) $bestState['pExp'][ $t ]['id'];
			$qExpId = (int) $bestState['qExp'][ $t ]['id'];
			$pCode  = (string) $bestState['pExp'][ $t ]['event_code'];
			$qCode  = (string) $bestState['qExp'][ $t ]['event_code'];

			$proposedCountByExp[ $pExpId ] = ( $proposedCountByExp[ $pExpId ] ?? 0 ) + 1;
			$proposedCountByExp[ $qExpId ] = ( $proposedCountByExp[ $qExpId ] ?? 0 ) + 1;

			$nextSeq = ( $nextSeqByPracticeExp[ $pExpId ] ?? 0 ) + 1;
			$nextSeqByPracticeExp[ $pExpId ] = $nextSeq;
			$teamName = $pCode !== '' ? ( $pCode . '-' . $nextSeq ) : ( 'TEAM-' . $nextSeq );

			$memberIndices = array();
			foreach ( $bestState['assign'] as $idx => $assignedT ) {
				if ( $assignedT === $t ) {
					$memberIndices[] = $idx;
				}
			}

			$membersDto    = array();
			$teamHasFA     = false;
			$friendEdges   = 0;
			$maleMembers   = array();
			$femaleMembers = array();

			foreach ( $memberIndices as $idx ) {
				$s         = $signupByIdx[ $idx ];
				$gender    = strtolower( trim( (string) ( $s['gender'] ?? '' ) ) );
				if ( $gender === 'male' ) {
					$maleMembers[] = $s;
				} elseif ( $gender === 'female' ) {
					$femaleMembers[] = $s;
				}
				$hasFA     = $this->hasValidFirstAid( $s );
				if ( $hasFA ) {
					$teamHasFA = true;
				}
				$pAvail = empty( $s['practice_codes'] ) || in_array( strtoupper( $pCode ), $s['practice_codes'], true );
				$qAvail = empty( $s['qualifier_codes'] ) || in_array( strtoupper( $qCode ), $s['qualifier_codes'], true );
				if ( ! $pAvail || ! $qAvail ) {
					++$dateConflicts;
				}

				foreach ( $memberIndices as $otherIdx ) {
					if ( $idx !== $otherIdx && isset( $preferAdj[ $idx ][ $otherIdx ] ) ) {
						++$friendEdges;
					}
				}

				$membersDto[] = array(
					'expedition_signup_id' => (int) $s['id'],
					'scout_id'             => (int) $s['scout_id'],
					'name'                 => $s['explorer_name'],
					'unit_name'            => $s['unit_name'],
					'gender'               => $gender,
					'has_first_aid'        => $hasFA,
					'practice_available'   => $pAvail,
					'qualifier_available'  => $qAvail,
					'resolution_status'    => ( $pAvail && $qAvail ) ? 'ok' : 'date_conflict_pending',
				);
			}

			if ( ( count( $maleMembers ) + count( $femaleMembers ) ) >= 2 ) {
				if ( count( $maleMembers ) === 1 ) {
					$soloGenderTeams[] = array(
						'team_name'     => $teamName,
						'stage'         => 'practice+qualifier',
						'solo_gender'   => 'male',
						'explorer_name' => (string) $maleMembers[0]['explorer_name'],
					);
				}
				if ( count( $femaleMembers ) === 1 ) {
					$soloGenderTeams[] = array(
						'team_name'     => $teamName,
						'stage'         => 'practice+qualifier',
						'solo_gender'   => 'female',
						'explorer_name' => (string) $femaleMembers[0]['explorer_name'],
					);
				}
			}

			if ( $teamHasFA ) {
				++$firstAidCovered;
			}

			$teamScore = $this->scoreSingleTeam( $memberIndices, $bestState['pExp'][ $t ], $bestState['qExp'][ $t ], $signupByIdx, $preferAdj, $avoidAdj, $weights, $unitPreferPrefs );

			$teamsOut[] = array(
				'temp_id'                 => sprintf( '%s-%s-%d', $level, $mode, $t + 1 ),
				'team_name'               => $teamName,
				'dofe_level'              => $level,
				'transport_mode'          => $mode,
				'practice_expedition_id'  => $pExpId,
				'practice_code'           => $pCode,
				'qualifier_expedition_id' => $qExpId,
				'qualifier_code'          => $qCode,
				'members'                 => $membersDto,
				'friend_match_count'      => $friendEdges,
				'has_first_aid'           => $teamHasFA,
				'score'                   => $teamScore,
			);
		}

		$mutualFulfilled = 0;
		foreach ( $mutualPairs as list( $u, $v ) ) {
			if ( $bestState['assign'][ $u ] === $bestState['assign'][ $v ] ) {
				++$mutualFulfilled;
			}
		}

		return array(
			'teams'       => $teamsOut,
			'warnings'    => $warnings,
			'compromises' => array(
				'date_conflict_pending'    => $dateConflicts,
				'split_date'               => 0,
				'unfulfilled_mutual_pairs' => count( $mutualPairs ) - $mutualFulfilled,
				'solo_gender_teams'        => $soloGenderTeams,
			),
			'stats'       => array(
				'total_explorers'         => $n,
				'total_teams'             => $k,
				'mutual_pairs_fulfilled'  => $mutualFulfilled,
				'mutual_pairs_total'      => count( $mutualPairs ),
				'first_aid_teams_covered' => $firstAidCovered,
			),
		);
	}

	/**
	 * @param array<int, array<int, bool>>     $preferAdj
	 * @param array<int, array<int, bool>>     $avoidAdj
	 * @param array<int, array<string, mixed>> $signupByIdx
	 * @return array<int, int>
	 */
	private function greedyInitialPartition( int $n, int $k, array $preferAdj, array $avoidAdj, array $signupByIdx = array() ): array {
		$visited  = array();
		$clusters = array();

		for ( $i = 0; $i < $n; $i++ ) {
			if ( isset( $visited[ $i ] ) ) {
				continue;
			}
			$cluster         = array( $i );
			$visited[ $i ]   = true;
			$queue           = array( $i );

			while ( ! empty( $queue ) && count( $cluster ) < 7 ) {
				$curr = array_shift( $queue );
				foreach ( array_keys( $preferAdj[ $curr ] ?? array() ) as $nbr ) {
					if ( isset( $visited[ $nbr ] ) || count( $cluster ) >= 7 ) {
						continue;
					}
					$hasAvoid = false;
					foreach ( $cluster as $member ) {
						if ( isset( $avoidAdj[ $member ][ $nbr ] ) ) {
							$hasAvoid = true;
							break;
						}
					}
					if ( $hasAvoid ) {
						continue;
					}
					$visited[ $nbr ] = true;
					$cluster[]       = $nbr;
					$queue[]         = $nbr;
				}
			}
			$clusters[] = $cluster;
		}

		usort( $clusters, static fn( array $a, array $b ): int => count( $b ) <=> count( $a ) );

		$assign     = array_fill( 0, $n, 0 );
		$sizes      = array_fill( 0, $k, 0 );
		$targetSize = max( 4, (int) ceil( $n / max( 1, $k ) ) );

		foreach ( $clusters as $cluster ) {
			// Find team that can fit cluster (<= 7) with fewest avoid conflicts and same-district/unit affinity.
			$bestT    = 0;
			$bestCost = PHP_INT_MAX;
			for ( $t = 0; $t < $k; $t++ ) {
				if ( $sizes[ $t ] + count( $cluster ) > 7 ) {
					continue;
				}
				$avoidCost    = 0;
				$affinityGain = 0;
				foreach ( $cluster as $idx ) {
					$uDistrict = strtolower( trim( (string) ( $signupByIdx[ $idx ]['district'] ?? '' ) ) );
					$uUnit     = strtolower( trim( (string) ( $signupByIdx[ $idx ]['unit_name'] ?? '' ) ) );
					foreach ( $assign as $other => $otherT ) {
						if ( $otherT !== $t || $sizes[ $t ] === 0 ) {
							continue;
						}
						if ( isset( $avoidAdj[ $idx ][ $other ] ) ) {
							++$avoidCost;
						}
						$vDistrict = strtolower( trim( (string) ( $signupByIdx[ $other ]['district'] ?? '' ) ) );
						$vUnit     = strtolower( trim( (string) ( $signupByIdx[ $other ]['unit_name'] ?? '' ) ) );
						if ( $uUnit !== '' && $uUnit === $vUnit ) {
							$affinityGain += 15;
						} elseif ( $uDistrict !== '' && $uDistrict === $vDistrict ) {
							$affinityGain += 10;
						}
					}
				}
				$overflowPenalty = ( $sizes[ $t ] + count( $cluster ) > $targetSize ) ? 50 : 0;
				$cost            = $avoidCost * 100 + $overflowPenalty + $sizes[ $t ] - $affinityGain;
				if ( $cost < $bestCost ) {
					$bestCost = $cost;
					$bestT    = $t;
				}
			}

			if ( $sizes[ $bestT ] + count( $cluster ) <= 7 ) {
				foreach ( $cluster as $idx ) {
					$assign[ $idx ] = $bestT;
					++$sizes[ $bestT ];
				}
			} else {
				foreach ( $cluster as $idx ) {
					asort( $sizes );
					$t              = (int) array_key_first( $sizes );
					$assign[ $idx ] = $t;
					++$sizes[ $t ];
				}
			}
		}

		// Rebalance so every team has strictly 4..7 members.
		for ( $t = 0; $t < $k; $t++ ) {
			while ( $sizes[ $t ] < 4 ) {
				$donorT = -1;
				for ( $d = 0; $d < $k; $d++ ) {
					if ( $sizes[ $d ] > 4 ) {
						$donorT = $d;
						break;
					}
				}
				if ( $donorT === -1 ) {
					break;
				}
				foreach ( $assign as $idx => $assignedT ) {
					if ( $assignedT === $donorT ) {
						$assign[ $idx ] = $t;
						--$sizes[ $donorT ];
						++$sizes[ $t ];
						break;
					}
				}
			}
		}

		return $assign;
	}

	/**
	 * @param array<int, int>                  $assign
	 * @param array<int, array<string, mixed>> $pExps
	 * @param array<int, array<string, mixed>> $qExps
	 * @param array<int, array<string, mixed>> $signupByIdx
	 * @param array<int, array<int, bool>>     $preferAdj
	 * @param array<int, array<int, bool>>     $avoidAdj
	 * @param array<int, array{0: int, 1: int}> $mutualPairs
	 * @param array<string, int>               $weights
	 * @param array<int, array<int, string>>   $unitPreferPrefs
	 */
	private function scoreSolution(
		array $assign,
		array $pExps,
		array $qExps,
		array $signupByIdx,
		array $preferAdj,
		array $avoidAdj,
		array $mutualPairs,
		array $weights,
		array $unitPreferPrefs = array()
	): int {
		$score = 0;
		$teams = array();
		foreach ( $assign as $idx => $t ) {
			$teams[ $t ][] = $idx;
		}

		foreach ( $teams as $t => $members ) {
			$score += $this->scoreSingleTeam( $members, $pExps[ $t ], $qExps[ $t ], $signupByIdx, $preferAdj, $avoidAdj, $weights, $unitPreferPrefs );
			// Cross-stage continuity bonus for keeping each explorer on the same paired team.
			$score += count( $members ) * $weights['cross_stage_continuity'];
		}

		foreach ( $mutualPairs as list( $u, $v ) ) {
			if ( $assign[ $u ] === $assign[ $v ] ) {
				$score += $weights['mutual_wishlist'];
			}
		}

		return $score;
	}

	/**
	 * @param array<int, int>                  $members
	 * @param array<string, mixed>             $pExp
	 * @param array<string, mixed>             $qExp
	 * @param array<int, array<string, mixed>> $signupByIdx
	 * @param array<int, array<int, bool>>     $preferAdj
	 * @param array<int, array<int, bool>>     $avoidAdj
	 * @param array<string, int>               $weights
	 * @param array<int, array<int, string>>   $unitPreferPrefs
	 */
	private function scoreSingleTeam(
		array $members,
		array $pExp,
		array $qExp,
		array $signupByIdx,
		array $preferAdj,
		array $avoidAdj,
		array $weights,
		array $unitPreferPrefs = array()
	): int {
		$score       = 0;
		$pCode       = strtoupper( (string) $pExp['event_code'] );
		$qCode       = strtoupper( (string) $qExp['event_code'] );
		$hasFA       = false;
		$maleCount   = 0;
		$femaleCount = 0;

		foreach ( $members as $u ) {
			$su = $signupByIdx[ $u ];
			if ( empty( $su['practice_codes'] ) || in_array( $pCode, $su['practice_codes'], true ) ) {
				$score += $weights['code_availability'];
			}
			if ( empty( $su['qualifier_codes'] ) || in_array( $qCode, $su['qualifier_codes'], true ) ) {
				$score += $weights['code_availability'];
			}
			if ( $this->hasValidFirstAid( $su ) ) {
				$hasFA = true;
			}
			$gender = strtolower( trim( (string) ( $su['gender'] ?? '' ) ) );
			if ( $gender === 'male' ) {
				++$maleCount;
			} elseif ( $gender === 'female' ) {
				++$femaleCount;
			}

			$uUnitPrefs = $unitPreferPrefs[ $u ] ?? array();

			foreach ( $members as $v ) {
				if ( $u === $v ) {
					continue;
				}
				if ( isset( $avoidAdj[ $u ][ $v ] ) && $u < $v ) {
					$score += $weights['avoid_exclusion'];
				}
				if ( isset( $preferAdj[ $u ][ $v ] ) ) {
					$score += $weights['one_way_wishlist'];
				}
				if ( ! empty( $uUnitPrefs ) && $signupByIdx[ $v ]['unit_name'] !== '' ) {
					$vUnit = trim( (string) $signupByIdx[ $v ]['unit_name'] );
					foreach ( $uUnitPrefs as $prefUnit ) {
						if ( strcasecmp( $vUnit, trim( $prefUnit ) ) === 0 ) {
							$score += $weights['one_way_wishlist'];
						}
					}
				}
				if ( $u < $v && $su['unit_name'] !== '' && strcasecmp( $su['unit_name'], $signupByIdx[ $v ]['unit_name'] ) === 0 ) {
					$score += $weights['same_unit'];
				}
				$uDistrict = trim( (string) ( $su['district'] ?? '' ) );
				$vDistrict = trim( (string) ( $signupByIdx[ $v ]['district'] ?? '' ) );
				if ( $u < $v && $uDistrict !== '' && $vDistrict !== '' && strcasecmp( $uDistrict, $vDistrict ) === 0 ) {
					$score += $weights['same_district'];
				}
			}
		}

		if ( $hasFA ) {
			$score += $weights['first_aid_presence'];
		}

		if ( ( $maleCount + $femaleCount ) >= 2 ) {
			if ( $maleCount === 1 ) {
				$score += $weights['solo_gender_penalty'];
			}
			if ( $femaleCount === 1 ) {
				$score += $weights['solo_gender_penalty'];
			}
		}

		// Solo unit/district presumption: penalise placing 1 person from a unit/district with others from another district.
		$districtGroups = array();
		foreach ( $members as $u ) {
			$su       = $signupByIdx[ $u ];
			$district = strtolower( trim( (string) ( $su['district'] ?? '' ) ) );
			$unit     = strtolower( trim( (string) ( $su['unit_name'] ?? '' ) ) );
			if ( $district !== '' ) {
				$groupKey = 'd:' . $district;
			} elseif ( $unit !== '' ) {
				$groupKey = 'u:' . $unit;
			} else {
				continue;
			}
			$districtGroups[ $groupKey ] = ( $districtGroups[ $groupKey ] ?? 0 ) + 1;
		}
		if ( count( $districtGroups ) >= 2 ) {
			foreach ( $districtGroups as $cnt ) {
				if ( $cnt === 1 ) {
					$score += $weights['solo_unit_district_penalty'];
				}
			}
		}

		return $score;
	}

	/**
	 * @param array<int, array<string, mixed>> $exps
	 * @param array<int, int>                  $lockedByExp
	 * @param array<int, int>                  $proposedByExp
	 */
	private function totalRemainingCapacity( array $exps, array $lockedByExp, array $proposedByExp ): int {
		$total = 0;
		foreach ( $exps as $exp ) {
			$max = $exp['max_teams'];
			if ( $max === null || $max <= 0 ) {
				return 9999;
			}
			$eid  = (int) $exp['id'];
			$used = ( $lockedByExp[ $eid ] ?? 0 ) + ( $proposedByExp[ $eid ] ?? 0 );
			$total += max( 0, $max - $used );
		}
		return $total;
	}

	/**
	 * @param array<int, array<string, mixed>> $exps
	 * @param array<int, int>                  $lockedByExp
	 * @param array<int, int>                  $proposedByExp
	 * @return array<int, array<string, mixed>>
	 */
	private function buildExpeditionSlots( array $exps, array $lockedByExp, array $proposedByExp ): array {
		$slots = array();
		foreach ( $exps as $exp ) {
			$eid       = (int) $exp['id'];
			$max       = $exp['max_teams'];
			$used      = ( $lockedByExp[ $eid ] ?? 0 ) + ( $proposedByExp[ $eid ] ?? 0 );
			$remaining = ( $max === null || $max <= 0 ) ? 99 : max( 0, $max - $used );
			if ( $remaining > 0 ) {
				$slots[ $eid ] = array(
					'exp'       => $exp,
					'remaining' => $remaining,
				);
			}
		}
		return $slots;
	}

	/**
	 * @param array<int, int>                  $assign
	 * @param array<int, array<string, mixed>> $signupByIdx
	 * @param array<int, array<string, mixed>> $slots
	 * @return array<string, mixed>
	 */
	private function pickBestExpeditionSlot( int $teamIdx, array $assign, array $signupByIdx, array &$slots, string $codeField ): array {
		$bestEid   = (int) array_key_first( $slots );
		$bestScore = -1;

		foreach ( $slots as $eid => $slot ) {
			if ( $slot['remaining'] <= 0 ) {
				continue;
			}
			$code  = strtoupper( (string) $slot['exp']['event_code'] );
			$score = 0;
			foreach ( $assign as $idx => $t ) {
				if ( $t !== $teamIdx ) {
					continue;
				}
				$codes = $signupByIdx[ $idx ][ $codeField ] ?? array();
				if ( empty( $codes ) || in_array( $code, $codes, true ) ) {
					++$score;
				}
			}
			if ( $score > $bestScore ) {
				$bestScore = $score;
				$bestEid   = $eid;
			}
		}

		--$slots[ $bestEid ]['remaining'];
		return $slots[ $bestEid ]['exp'];
	}

	/**
	 * @param array<string, mixed>             $currentExp
	 * @param array<int, array<string, mixed>> $teamExpMap
	 * @param array<int, array<string, mixed>> $exps
	 * @param array<int, int>                  $lockedByExp
	 * @param array<int, int>                  $proposedByExp
	 * @return array<string, mixed>|null
	 */
	private function findAlternativeExpedition(
		array $currentExp,
		array $teamExpMap,
		array $exps,
		array $lockedByExp,
		array $proposedByExp,
		int $seed
	): ?array {
		$usedInBucket = array();
		foreach ( $teamExpMap as $exp ) {
			$eid                  = (int) $exp['id'];
			$usedInBucket[ $eid ] = ( $usedInBucket[ $eid ] ?? 0 ) + 1;
		}

		$count = count( $exps );
		for ( $i = 0; $i < $count; $i++ ) {
			$cand = $exps[ ( $seed + $i ) % $count ];
			$eid  = (int) $cand['id'];
			if ( $eid === (int) $currentExp['id'] ) {
				continue;
			}
			$max = $cand['max_teams'];
			if ( $max !== null && $max > 0 ) {
				$totalUsed = ( $lockedByExp[ $eid ] ?? 0 ) + ( $proposedByExp[ $eid ] ?? 0 ) + ( $usedInBucket[ $eid ] ?? 0 );
				if ( $totalUsed >= $max ) {
					continue;
				}
			}
			return $cand;
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $signup
	 */
	private function hasValidFirstAid( array $signup ): bool {
		$osm    = strtolower( trim( (string) ( $signup['osm_first_aid_level'] ?? '' ) ) );
		$status = strtolower( trim( (string) ( $signup['first_aid_status'] ?? '' ) ) );
		$val    = ( $osm !== '' && $osm !== 'none' ) ? $osm : $status;

		return in_array(
			$val,
			array( 'first-aid-yes', 'first_response', 'first-response', 'full_first_aid', 'first_aider', 'first_aid_plus' ),
			true
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $exps
	 * @return array<int, array<string, mixed>>
	 */
	private function filterExpeditionsForBucket( array $exps, string $level, string $mode, string $stage ): array {
		$filtered = array();
		foreach ( $exps as $exp ) {
			if ( $exp['stage'] !== $stage ) {
				continue;
			}
			if ( $exp['transport_mode'] !== $mode ) {
				continue;
			}
			if ( $exp['dofe_level'] !== $level && $exp['dofe_level'] !== 'multiple' ) {
				continue;
			}
			$filtered[] = $exp;
		}
		return $filtered;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function loadActiveExpeditions(): array {
		$posts = get_posts(
			array(
				'post_type'      => 'expedition',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		if ( ! is_array( $posts ) ) {
			return array();
		}

		$exps         = array();
		$claimedCodes = array();
		foreach ( $posts as $post ) {
			$eid    = (int) $post->ID;
			$status = strtolower( trim( (string) get_post_meta( $eid, 'ems_status', true ) ) );
			if ( $status === 'archived' ) {
				continue;
			}
			$rawType = strtolower( trim( (string) get_post_meta( $eid, 'ems_type', true ) ) );
			$stage   = $rawType === 'practice' ? 'practice' : ( in_array( $rawType, array( 'qualifying', 'qualifier' ), true ) ? 'qualifier' : '' );
			if ( $stage === '' ) {
				continue;
			}

			$code      = trim( (string) ( get_post_meta( $eid, 'ems_event_code', true ) ?: get_post_meta( $eid, 'ems_expedition_code', true ) ?: '' ) );
			$postTitle = trim( (string) $post->post_title );
			$upperCode = strtoupper( $code );
			if ( ( $code === '' || isset( $claimedCodes[ $upperCode ] ) ) && preg_match( '/^[A-Z]+-[A-Z0-9]+$/i', $postTitle ) === 1 ) {
				$code      = $postTitle;
				$upperCode = strtoupper( $code );
			}
			if ( $upperCode !== '' ) {
				$claimedCodes[ $upperCode ] = true;
			}
			$max = $this->resolveMaxTeams( $eid );

			$exps[] = array(
				'id'             => $eid,
				'title'          => (string) $post->post_title,
				'event_code'     => $code,
				'dofe_level'     => strtolower( trim( (string) get_post_meta( $eid, 'ems_level', true ) ) ),
				'transport_mode' => $this->matcher->normalizeTransportMode( (string) ( get_post_meta( $eid, 'ems_transport', true ) ?: 'foot' ) ),
				'stage'          => $stage,
				'max_teams'      => $max,
			);
		}

		return $exps;
	}

	private function resolveMaxTeams( int $expeditionId ): ?int {
		foreach ( array( '_ems_max_teams', '_ems_capacity_teams', 'ems_max_teams', 'ems_capacity_teams' ) as $key ) {
			$val = get_post_meta( $expeditionId, $key, true );
			if ( $val !== '' && $val !== null && (int) $val > 0 ) {
				return (int) $val;
			}
		}
		return null;
	}

	/**
	 * @return array{locked_count_by_exp: array<int, int>, max_seq_by_exp: array<int, int>, locked_signups: array<int, bool>}
	 */
	private function loadLockedTeamsInfo(): array {
		$lockedCountByExp = array();
		$maxSeqByExp      = array();
		$lockedSignups    = array();

		$teamPosts = get_posts(
			array(
				'post_type'      => 'team',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);
		if ( ! is_array( $teamPosts ) || empty( $teamPosts ) ) {
			return array(
				'locked_count_by_exp' => $lockedCountByExp,
				'max_seq_by_exp'      => $maxSeqByExp,
				'locked_signups'      => $lockedSignups,
			);
		}

		$teamIds = array();
		foreach ( $teamPosts as $tp ) {
			$teamIds[] = (int) $tp->ID;
		}

		$wpdb         = $this->get_wpdb();
		$tmTable      = $wpdb->prefix . 'ems_team_members';
		$placeholders = implode( ',', array_fill( 0, count( $teamIds ), '%d' ) );
		$memberRows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT team_post_id, expedition_signup_id, notification_status FROM {$tmTable} WHERE team_post_id IN ({$placeholders})",
				...$teamIds
			),
			ARRAY_A
		) ?: array();

		$membersByTeam = array();
		foreach ( $memberRows as $mr ) {
			$membersByTeam[ (int) $mr['team_post_id'] ][] = $mr;
		}

		foreach ( $teamPosts as $tp ) {
			$tid      = (int) $tp->ID;
			$teamCode = trim( (string) get_post_meta( $tid, 'ems_team_code', true ) );
			if ( strtoupper( $teamCode ) === 'UNALLOCATED' || strtoupper( trim( (string) $tp->post_title ) ) === 'UNALLOCATED' ) {
				continue;
			}
			$expId = (int) $tp->post_parent;
			if ( $expId <= 0 ) {
				continue;
			}

			$members     = $membersByTeam[ $tid ] ?? array();
			$isLocked    = (bool) get_post_meta( $tid, '_ems_team_locked', true );
			foreach ( $members as $mr ) {
				if ( (string) ( $mr['notification_status'] ?? 'draft' ) !== 'draft' ) {
					$isLocked = true;
					break;
				}
			}

			if ( ! $isLocked ) {
				continue;
			}

			$lockedCountByExp[ $expId ] = ( $lockedCountByExp[ $expId ] ?? 0 ) + 1;

			if ( preg_match( '/-(\d+)$/', $teamCode, $m ) ) {
				$seq = (int) $m[1];
				$maxSeqByExp[ $expId ] = max( $maxSeqByExp[ $expId ] ?? 0, $seq );
			} else {
				$num = (int) get_post_meta( $tid, 'ems_team_number', true );
				if ( $num > 0 ) {
					$maxSeqByExp[ $expId ] = max( $maxSeqByExp[ $expId ] ?? 0, $num );
				}
			}

			foreach ( $members as $mr ) {
				$sid = (int) ( $mr['expedition_signup_id'] ?? 0 );
				if ( $sid > 0 ) {
					$lockedSignups[ $sid ] = true;
				}
			}
		}

		return array(
			'locked_count_by_exp' => $lockedCountByExp,
			'max_seq_by_exp'      => $maxSeqByExp,
			'locked_signups'      => $lockedSignups,
		);
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, int>
	 */
	private function resolveWeights( array $overrides ): array {
		$weights = self::DEFAULT_WEIGHTS;
		foreach ( $weights as $key => $default ) {
			if ( isset( $overrides[ $key ] ) && is_numeric( $overrides[ $key ] ) ) {
				$weights[ $key ] = (int) $overrides[ $key ];
			}
		}
		return $weights;
	}

	/**
	 * @param array<int, mixed> $buckets
	 * @param array<int, mixed> $proposedTeams
	 * @return array<string, bool>
	 */
	private function normalizeBucketFilterSet( array $buckets, array $proposedTeams ): array {
		$set = array();
		foreach ( $buckets as $b ) {
			if ( is_string( $b ) && str_contains( $b, '|' ) ) {
				$set[ strtolower( trim( $b ) ) ] = true;
			} elseif ( is_array( $b ) && ! empty( $b['dofe_level'] ) && ! empty( $b['transport_mode'] ) ) {
				$lvl = strtolower( trim( (string) $b['dofe_level'] ) );
				$mod = $this->matcher->normalizeTransportMode( (string) $b['transport_mode'] );
				$set[ $lvl . '|' . $mod ] = true;
			}
		}
		if ( empty( $set ) ) {
			foreach ( $proposedTeams as $pt ) {
				if ( is_array( $pt ) && ! empty( $pt['dofe_level'] ) && ! empty( $pt['transport_mode'] ) ) {
					$lvl = strtolower( trim( (string) $pt['dofe_level'] ) );
					$mod = $this->matcher->normalizeTransportMode( (string) $pt['transport_mode'] );
					$set[ $lvl . '|' . $mod ] = true;
				}
			}
		}
		return $set;
	}

	private function createDraftTeamPost( int $expeditionId, string $eventCode, ?string $preferredCode ): int {
		try {
			return $this->teams->create( $expeditionId, $eventCode, $preferredCode !== '' ? $preferredCode : null );
		} catch ( \InvalidArgumentException $e ) {
			// Fallback to auto-generated unique code if preferredCode already exists on another team.
			return $this->teams->create( $expeditionId, $eventCode, null );
		}
	}

	/**
	 * @param array<int, mixed> $members
	 */
	private function insertDraftMembers( int $teamPostId, array $members, int $userId, string $now ): int {
		$wpdb    = $this->get_wpdb();
		$tmTable = $wpdb->prefix . 'ems_team_members';
		$added   = 0;

		foreach ( $members as $m ) {
			if ( ! is_array( $m ) ) {
				continue;
			}
			$signupId = (int) ( $m['expedition_signup_id'] ?? 0 );
			$scoutId  = (int) ( $m['scout_id'] ?? 0 );
			if ( $signupId <= 0 ) {
				continue;
			}
			$ok = $wpdb->insert(
				$tmTable,
				array(
					'team_post_id'         => $teamPostId,
					'scout_id'             => $scoutId,
					'user_id'              => 0,
					'expedition_signup_id' => $signupId,
					'notification_status'  => 'draft',
					'added_by'             => $userId,
					'added_at'             => $now,
				),
				array( '%d', '%d', '%d', '%d', '%s', '%d', '%s' )
			);
			if ( $ok !== false ) {
				++$added;
			}
		}

		return $added;
	}
}
