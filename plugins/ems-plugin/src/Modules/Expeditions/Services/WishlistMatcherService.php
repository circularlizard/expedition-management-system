<?php
declare(strict_types=1);

namespace EMS\Modules\Expeditions\Services;

/**
 * Bucket-aware wishlist parser and matcher for ems_expedition_signups.expedition_preferences.
 */
class WishlistMatcherService {

	private ?\wpdb $wpdb;
	private WishlistPreferenceRepository $prefs;

	public function __construct( ?\wpdb $wpdb = null, ?WishlistPreferenceRepository $prefs = null ) {
		$this->wpdb  = $wpdb;
		$this->prefs = $prefs ?? new WishlistPreferenceRepository( $wpdb );
	}

	private function get_wpdb(): \wpdb {
		if ( $this->wpdb === null ) {
			global $wpdb;
			$this->wpdb = $wpdb;
		}
		return $this->wpdb;
	}

	/**
	 * Parse all active expedition sign-ups (optionally scoped by dofe_level / transport_mode).
	 *
	 * @param array<string, mixed> $filters
	 * @return array{parsed_count: int, skipped_count: int, needs_review_count: int}
	 */
	public function parseAll( array $filters = array() ): array {
		$signups        = $this->loadActiveSignups();
		$units          = $this->loadUnits( $signups );
		$existingBySrc  = $this->groupExistingBySource( array_column( $signups, 'id' ) );
		$filterLevel    = strtolower( trim( (string) ( $filters['dofe_level'] ?? '' ) ) );
		$filterMode     = isset( $filters['transport_mode'] ) && $filters['transport_mode'] !== ''
			? $this->normalizeTransportMode( (string) $filters['transport_mode'] )
			: '';

		$parsedCount = 0;
		$skippedCount = 0;
		$needsReviewCount = 0;

		foreach ( $signups as $signup ) {
			$sourceId = (int) $signup['id'];
			if ( $filterLevel !== '' && $signup['dofe_level'] !== $filterLevel ) {
				continue;
			}
			if ( $filterMode !== '' && $signup['transport_mode'] !== $filterMode ) {
				continue;
			}

			$wishlistRaw  = trim( (string) $signup['wishlist_raw'] );
			$wishlistHash = md5( $wishlistRaw );
			$existingRows = $existingBySrc[ $sourceId ] ?? array();

			if ( $wishlistRaw === '' ) {
				if ( ! empty( $existingRows ) ) {
					$this->prefs->purgeForSource( $sourceId );
				}
				continue;
			}

			$hasHashDrift = false;
			$hasReviewed  = false;
			foreach ( $existingRows as $row ) {
				if ( (string) $row['wishlist_hash'] !== $wishlistHash ) {
					$hasHashDrift = true;
					break;
				}
				if ( in_array( (string) $row['match_status'], array( 'confirmed', 'ignored' ), true ) ) {
					$hasReviewed = true;
				}
			}

			if ( $hasHashDrift ) {
				$this->prefs->purgeForSource( $sourceId );
			} elseif ( $hasReviewed ) {
				++$skippedCount;
				foreach ( $existingRows as $row ) {
					if ( $this->isNeedsReviewRow( $row ) ) {
						++$needsReviewCount;
					}
				}
				continue;
			} elseif ( ! empty( $existingRows ) ) {
				$this->prefs->purgeUnreviewedForSource( $sourceId );
			}

			$sameBucket  = array();
			$otherBucket = array();
			foreach ( $signups as $cand ) {
				if ( (int) $cand['id'] === $sourceId ) {
					continue;
				}
				if ( $cand['dofe_level'] === $signup['dofe_level'] && $cand['transport_mode'] === $signup['transport_mode'] ) {
					$sameBucket[] = $cand;
				} else {
					$otherBucket[] = $cand;
				}
			}

			$tokens = $this->tokeniseWishlist( $wishlistRaw, $sameBucket );
			++$parsedCount;

			foreach ( $tokens as $token ) {
				$resolved = $this->resolveToken( $signup, $token, $sameBucket, $otherBucket, $units );
				$this->prefs->insertPreference(
					array(
						'source_signup_id'   => $sourceId,
						'target_signup_id'   => $resolved['target_signup_id'],
						'wishlist_hash'      => $wishlistHash,
						'raw_token'          => $token['raw_token'],
						'unit_hint'          => $resolved['unit_hint'] ?? $token['unit_hint'],
						'preference_type'    => $resolved['preference_type'],
						'match_status'       => $resolved['match_status'],
						'confidence_score'   => $resolved['confidence_score'],
						'candidate_ids_json' => ! empty( $resolved['candidates'] )
							? (string) wp_json_encode( $resolved['candidates'] )
							: null,
						'flag_negation'      => $token['flag_negation'],
					)
				);

				if ( $this->isNeedsReviewStatus( $resolved['match_status'], $token['flag_negation'] ) ) {
					++$needsReviewCount;
				}
			}
		}

		return array(
			'parsed_count'       => $parsedCount,
			'skipped_count'      => $skippedCount,
			'needs_review_count' => $needsReviewCount,
		);
	}

	/**
	 * Build the Step 1 review queue grouped by source_signup_id.
	 *
	 * @param array<string, mixed> $filters
	 * @return array{items: array<int, array<string, mixed>>, summary: array{total: int, needs_review: int, auto_matched: int, confirmed: int, ignored: int}}
	 */
	public function getQueue( array $filters = array() ): array {
		$signups       = $this->loadActiveSignups();
		$signupsById   = array();
		foreach ( $signups as $s ) {
			$signupsById[ (int) $s['id'] ] = $s;
		}

		$filterLevel  = strtolower( trim( (string) ( $filters['dofe_level'] ?? '' ) ) );
		$filterMode   = isset( $filters['transport_mode'] ) && $filters['transport_mode'] !== ''
			? $this->normalizeTransportMode( (string) $filters['transport_mode'] )
			: '';
		$filterScope  = strtolower( trim( (string) ( $filters['filter'] ?? 'needs_review' ) ) );

		$allPrefRows = $this->prefs->findBySourceSignupIds( array_keys( $signupsById ) );
		$grouped     = array();
		foreach ( $allPrefRows as $row ) {
			$src = (int) $row['source_signup_id'];
			if ( ! isset( $grouped[ $src ] ) ) {
				$grouped[ $src ] = array();
			}
			$grouped[ $src ][] = $row;
		}

		$summary = array(
			'total'        => 0,
			'needs_review' => 0,
			'auto_matched' => 0,
			'confirmed'    => 0,
			'ignored'      => 0,
		);

		$items = array();
		foreach ( $grouped as $sourceId => $rows ) {
			$src = $signupsById[ $sourceId ] ?? null;
			if ( $src === null ) {
				continue;
			}
			if ( $filterLevel !== '' && $src['dofe_level'] !== $filterLevel ) {
				continue;
			}
			if ( $filterMode !== '' && $src['transport_mode'] !== $filterMode ) {
				continue;
			}

			$formattedTokens = array();
			$itemHasReview   = false;

			foreach ( $rows as $row ) {
				++$summary['total'];
				$status       = (string) $row['match_status'];
				$flagNegation = (int) $row['flag_negation'];

				if ( $this->isNeedsReviewStatus( $status, $flagNegation ) ) {
					++$summary['needs_review'];
					$itemHasReview = true;
				} elseif ( in_array( $status, array( 'auto_matched', 'unit_preference' ), true ) ) {
					++$summary['auto_matched'];
				} elseif ( $status === 'confirmed' ) {
					++$summary['confirmed'];
				} elseif ( $status === 'ignored' ) {
					++$summary['ignored'];
				}

				$targetId   = isset( $row['target_signup_id'] ) ? (int) $row['target_signup_id'] : 0;
				$targetRow  = $targetId > 0 ? ( $signupsById[ $targetId ] ?? null ) : null;
				$candidates = $this->hydrateCandidates( $src, (array) ( $row['candidates'] ?? array() ), $signupsById );

				$formattedTokens[] = array(
					'id'                   => (int) $row['id'],
					'source_signup_id'     => $sourceId,
					'target_signup_id'     => $targetId > 0 ? $targetId : null,
					'wishlist_hash'        => (string) $row['wishlist_hash'],
					'raw_token'            => (string) $row['raw_token'],
					'unit_hint'            => $row['unit_hint'],
					'preference_type'      => (string) $row['preference_type'],
					'match_status'         => $status,
					'confidence_score'     => (int) $row['confidence_score'],
					'flag_negation'        => $flagNegation,
					'candidates'           => $candidates,
					'resolved_target_name' => $targetRow !== null ? $targetRow['explorer_name'] : null,
					'resolved_target_unit' => $targetRow !== null
						? $targetRow['unit_name']
						: ( $status === 'unit_preference' ? $row['unit_hint'] : null ),
				);
			}

			if ( $filterScope === 'needs_review' && ! $itemHasReview ) {
				continue;
			}

			$items[] = array(
				'source_signup_id' => $sourceId,
				'explorer_name'    => $src['explorer_name'],
				'unit_name'        => $src['unit_name'],
				'gender'           => $src['gender'] ?? '',
				'dofe_level'       => $src['dofe_level'],
				'transport_mode'   => $src['transport_mode'],
				'wishlist_raw'     => $src['wishlist_raw'],
				'wishlist_hash'    => md5( trim( (string) $src['wishlist_raw'] ) ),
				'tokens'           => $formattedTokens,
			);
		}

		return array(
			'items'   => $items,
			'summary' => $summary,
		);
	}

	/**
	 * Strictly bucket-scoped candidate search for Step 1 override / "+ Add teammate".
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function searchCandidates( int $sourceSignupId, string $query ): array {
		$signups     = $this->loadActiveSignups();
		$signupsById = array();
		foreach ( $signups as $s ) {
			$signupsById[ (int) $s['id'] ] = $s;
		}

		$source = $signupsById[ $sourceSignupId ] ?? null;
		if ( $source === null ) {
			return array();
		}

		$q       = strtolower( trim( $query ) );
		$results = array();

		foreach ( $signups as $cand ) {
			if ( (int) $cand['id'] === $sourceSignupId ) {
				continue;
			}
			// Strict bucket isolation: exact same (dofe_level, transport_mode).
			if ( $cand['dofe_level'] !== $source['dofe_level'] || $cand['transport_mode'] !== $source['transport_mode'] ) {
				continue;
			}

			$score = 100;
			if ( $q !== '' ) {
				$haystack = strtolower( $cand['explorer_name'] . ' ' . $cand['unit_name'] . ' ' . $cand['unit_short_code'] );
				if ( str_contains( $haystack, $q ) ) {
					$score = str_starts_with( strtolower( $cand['explorer_name'] ), $q ) ? 100 : 90;
				} else {
					$nameScore = $this->scoreNameMatch( $q, $cand );
					if ( $nameScore < 65 ) {
						continue;
					}
					$score = $nameScore;
				}
			}

			$results[] = array(
				'signup_id'         => (int) $cand['id'],
				'explorer_name'     => $cand['explorer_name'],
				'unit_name'         => $cand['unit_name'],
				'gender'            => $cand['gender'] ?? '',
				'dofe_level'        => $cand['dofe_level'],
				'transport_mode'    => $cand['transport_mode'],
				'shared_code_count' => $this->countSharedCodes( $source, $cand ),
				'score'             => $score,
				'reason'            => 'Same level & mode',
			);
		}

		usort(
			$results,
			static function ( array $a, array $b ): int {
				if ( $b['score'] !== $a['score'] ) {
					return $b['score'] <=> $a['score'];
				}
				return strcmp( (string) $a['explorer_name'], (string) $b['explorer_name'] );
			}
		);

		return array_slice( $results, 0, 25 );
	}

	/**
	 * Save admin review decisions (including manual additions) for one explorer.
	 *
	 * @param array<int, array<string, mixed>> $decisions
	 * @return array{updated: int, items: array<int, array<string, mixed>>}
	 */
	public function saveDecisions( int $sourceSignupId, array $decisions, ?string $gender = null ): array {
		if ( $gender !== null && $sourceSignupId > 0 ) {
			$this->prefs->setGenders( array( $sourceSignupId => $gender ) );
		}

		$signups     = $this->loadActiveSignups();
		$signupsById = array();
		foreach ( $signups as $s ) {
			$signupsById[ (int) $s['id'] ] = $s;
		}
		$source = $signupsById[ $sourceSignupId ] ?? null;
		$hash   = $source !== null ? md5( trim( (string) $source['wishlist_raw'] ) ) : md5( '' );

		$updated = 0;
		$now     = current_time( 'mysql', true );
		$userId  = (int) get_current_user_id();

		foreach ( $decisions as $decision ) {
			if ( ! is_array( $decision ) ) {
				continue;
			}
			$prefId   = isset( $decision['id'] ) ? (int) $decision['id'] : 0;
			$targetId = isset( $decision['target_signup_id'] ) && (int) $decision['target_signup_id'] > 0
				? (int) $decision['target_signup_id']
				: null;
			$prefType = in_array( (string) ( $decision['preference_type'] ?? 'prefer' ), array( 'prefer', 'avoid' ), true )
				? (string) $decision['preference_type']
				: 'prefer';
			$status   = sanitize_text_field( (string) ( $decision['match_status'] ?? ( $targetId !== null ? 'confirmed' : 'ignored' ) ) );
			if ( ! in_array( $status, array( 'confirmed', 'ignored', 'auto_matched', 'unit_preference', 'ambiguous', 'cross_bucket', 'not_found' ), true ) ) {
				$status = $targetId !== null ? 'confirmed' : 'ignored';
			}

			// Enforce bucket isolation on confirmed links.
			if ( $targetId !== null && $source !== null && isset( $signupsById[ $targetId ] ) ) {
				$target = $signupsById[ $targetId ];
				if ( $target['dofe_level'] !== $source['dofe_level'] || $target['transport_mode'] !== $source['transport_mode'] ) {
					$status   = 'cross_bucket';
					$targetId = null;
				}
			}

			if ( $prefId > 0 ) {
				$ok = $this->prefs->updateDecision(
					$prefId,
					array(
						'target_signup_id' => $targetId,
						'preference_type'  => $prefType,
						'match_status'     => $status,
						'reviewed_by'      => $userId,
						'reviewed_at'      => $now,
					)
				);
				if ( $ok ) {
					++$updated;
				}
			} else {
				$rawToken = trim( (string) ( $decision['raw_token'] ?? '' ) );
				if ( $rawToken === '' && $targetId !== null && isset( $signupsById[ $targetId ] ) ) {
					$rawToken = (string) $signupsById[ $targetId ]['explorer_name'];
				}
				if ( $rawToken === '' ) {
					$rawToken = 'Manual teammate link';
				}
				$newId = $this->prefs->insertPreference(
					array(
						'source_signup_id' => $sourceSignupId,
						'target_signup_id' => $targetId,
						'wishlist_hash'    => $hash,
						'raw_token'        => $rawToken,
						'preference_type'  => $prefType,
						'match_status'     => $status,
						'confidence_score' => $targetId !== null ? 100 : 0,
						'reviewed_by'      => $userId,
						'reviewed_at'      => $now,
					)
				);
				if ( $newId > 0 ) {
					++$updated;
				}
			}
		}

		$items = $this->prefs->findBySourceSignupId( $sourceSignupId );

		return array(
			'updated' => $updated,
			'items'   => $items,
		);
	}

	/**
	 * Normalize expedition_preferences.exped_type to canonical transport_mode.
	 */
	public function normalizeTransportMode( string $raw ): string {
		$norm = strtolower( trim( $raw ) );
		if ( in_array( $norm, array( 'hillwalking', 'walking', 'foot' ), true ) ) {
			return 'foot';
		}
		if ( in_array( $norm, array( 'biking', 'cycling', 'bike', 'cycle' ), true ) ) {
			return 'cycle';
		}
		if ( in_array( $norm, array( 'paddling', 'canoeing', 'paddle', 'canoe' ), true ) ) {
			return 'paddle';
		}
		return $norm !== '' ? $norm : 'foot';
	}

	/**
	 * Parse expedition_preferences JSON safely.
	 *
	 * @return array{exped_type: string, transport_mode: string, practice_codes: array<int, string>, qualifier_codes: array<int, string>, wishlist_raw: string}
	 */
	public function parsePreferencesJson( ?string $json ): array {
		$decoded = array();
		if ( $json !== null && trim( $json ) !== '' ) {
			$parsed = json_decode( $json, true );
			if ( is_array( $parsed ) ) {
				$decoded = $parsed;
			}
		}

		$expedTypeRaw = $decoded['exped_type'] ?? '';
		if ( is_array( $expedTypeRaw ) ) {
			$expedTypeRaw = (string) ( $expedTypeRaw[0] ?? '' );
		}
		$expedType = trim( (string) $expedTypeRaw );

		$practiceRaw  = is_array( $decoded['exped_practice_dates'] ?? null ) ? $decoded['exped_practice_dates'] : array();
		$qualifierRaw = is_array( $decoded['exped_qualifier_dates'] ?? null ) ? $decoded['exped_qualifier_dates'] : array();

		$practiceCodes = array_values(
			array_unique(
				array_filter(
					array_map( static fn( $c ): string => strtoupper( trim( (string) $c ) ), $practiceRaw ),
					static fn( string $c ): bool => $c !== ''
				)
			)
		);
		$qualifierCodes = array_values(
			array_unique(
				array_filter(
					array_map( static fn( $c ): string => strtoupper( trim( (string) $c ) ), $qualifierRaw ),
					static fn( string $c ): bool => $c !== ''
				)
			)
		);

		return array(
			'exped_type'      => $expedType,
			'transport_mode'  => $this->normalizeTransportMode( $expedType ),
			'practice_codes'  => $practiceCodes,
			'qualifier_codes' => $qualifierCodes,
			'wishlist_raw'    => (string) ( $decoded['exped_team_names'] ?? '' ),
		);
	}

	/**
	 * Tokenise a raw wishlist string into name tokens with unit hints and negation flags.
	 *
	 * @param array<int, array<string, mixed>> $sameBucket
	 * @return array<int, array{raw_token: string, clean_name: string, unit_hint: string, flag_negation: int}>
	 */
	public function tokeniseWishlist( string $wishlistRaw, array $sameBucket = array() ): array {
		$stripped = $this->stripProsePrefixes( $wishlistRaw );
		$chunks   = preg_split( '/\s*(?:,|;|\R)+\s*/u', $stripped ) ?: array();

		$tokens = array();
		foreach ( $chunks as $chunk ) {
			$chunk = trim( $chunk );
			if ( $chunk === '' ) {
				continue;
			}

			// Split on " and " or " & " unless the chunk is empty.
			$subChunks = preg_split( '/\s+(?:and|&)\s+/iu', $chunk ) ?: array( $chunk );
			foreach ( $subChunks as $sub ) {
				$sub = trim( $sub );
				if ( $sub === '' ) {
					continue;
				}

				$flagNegation = $this->hasNegation( $sub ) ? 1 : 0;
				$unitHint     = '';
				if ( preg_match( '/\(([^)]+)\)/', $sub, $m ) ) {
					$unitHint = trim( $m[1] );
				}

				$cleanName = $this->cleanTokenForNameMatch( $sub );
				if ( $cleanName === '' ) {
					continue;
				}

				// Sliding two-token window for space-separated names (e.g. "Lucy Hann Ben Cole")
				// when the full chunk doesn't directly match a single explorer.
				$words = preg_split( '/\s+/', $cleanName ) ?: array();
				if ( count( $words ) >= 4 && ! empty( $sameBucket ) && $this->bestMatchScore( $cleanName, $sameBucket ) < 80 ) {
					$windowMatches = $this->extractSlidingWindowTokens( $words, $sameBucket, $unitHint, $flagNegation );
					if ( ! empty( $windowMatches ) ) {
						foreach ( $windowMatches as $wm ) {
							$tokens[] = $wm;
						}
						continue;
					}
				}

				$tokens[] = array(
					'raw_token'     => $sub,
					'clean_name'    => $cleanName,
					'unit_hint'     => $unitHint,
					'flag_negation' => $flagNegation,
				);
			}
		}

		return $tokens;
	}

	/**
	 * @param array<int, string>               $words
	 * @param array<int, array<string, mixed>> $sameBucket
	 * @return array<int, array{raw_token: string, clean_name: string, unit_hint: string, flag_negation: int}>
	 */
	private function extractSlidingWindowTokens( array $words, array $sameBucket, string $unitHint, int $flagNegation ): array {
		$found = array();
		$count = count( $words );
		$i     = 0;

		while ( $i < $count - 1 ) {
			$pair = $words[ $i ] . ' ' . $words[ $i + 1 ];
			if ( $this->bestMatchScore( $pair, $sameBucket ) >= 80 ) {
				$found[] = array(
					'raw_token'     => $pair,
					'clean_name'    => $pair,
					'unit_hint'     => $unitHint,
					'flag_negation' => $flagNegation,
				);
				$i += 2;
				continue;
			}
			++$i;
		}

		return $found;
	}

	/**
	 * Resolve a single token against same-bucket and cross-bucket rosters.
	 *
	 * @param array<string, mixed>             $source
	 * @param array{raw_token: string, clean_name: string, unit_hint: string, flag_negation: int} $token
	 * @param array<int, array<string, mixed>> $sameBucket
	 * @param array<int, array<string, mixed>> $otherBucket
	 * @param array<int, array{name: string, short_code: string}> $units
	 * @return array{target_signup_id: ?int, unit_hint?: string, preference_type: string, match_status: string, confidence_score: int, candidates: array<int, array<string, mixed>>}
	 */
	private function resolveToken( array $source, array $token, array $sameBucket, array $otherBucket, array $units = array() ): array {
		$cleanName    = $token['clean_name'];
		$unitHint     = $token['unit_hint'];
		$flagNegation = $token['flag_negation'];

		$sameMatches = $this->findMatchesInPool( $source, $cleanName, $sameBucket, 'Same level & mode' );

		if ( count( $sameMatches ) === 1 ) {
			$only = $sameMatches[0];
			if ( $flagNegation === 1 ) {
				return array(
					'target_signup_id' => null,
					'preference_type'  => 'avoid',
					'match_status'     => 'ambiguous',
					'confidence_score' => (int) $only['score'],
					'candidates'       => $sameMatches,
				);
			}
			return array(
				'target_signup_id' => (int) $only['signup_id'],
				'preference_type'  => 'prefer',
				'match_status'     => 'auto_matched',
				'confidence_score' => (int) $only['score'],
				'candidates'       => array(),
			);
		}

		if ( count( $sameMatches ) > 1 ) {
			if ( $unitHint !== '' ) {
				$unitFiltered = $this->filterByUnitHint( $sameMatches, $sameBucket, $unitHint );
				if ( count( $unitFiltered ) === 1 ) {
					$winner = $unitFiltered[0];
					if ( $flagNegation === 1 ) {
						return array(
							'target_signup_id' => null,
							'preference_type'  => 'avoid',
							'match_status'     => 'ambiguous',
							'confidence_score' => min( 100, (int) $winner['score'] + 5 ),
							'candidates'       => array( $winner ),
						);
					}
					return array(
						'target_signup_id' => (int) $winner['signup_id'],
						'preference_type'  => 'prefer',
						'match_status'     => 'auto_matched',
						'confidence_score' => min( 100, (int) $winner['score'] + 5 ),
						'candidates'       => array(),
					);
				}
			}

			return array(
				'target_signup_id' => null,
				'preference_type'  => $flagNegation === 1 ? 'avoid' : 'prefer',
				'match_status'     => 'ambiguous',
				'confidence_score' => (int) $sameMatches[0]['score'],
				'candidates'       => $sameMatches,
			);
		}

		// No match in same bucket -> check unit-level preference ("Tormain Explorers", "anyone from Lauderdale ESU").
		$matchedUnit = $this->matchUnitPreference( $cleanName, $units );
		if ( $matchedUnit !== null ) {
			return array(
				'target_signup_id' => null,
				'unit_hint'        => $matchedUnit,
				'preference_type'  => $flagNegation === 1 ? 'avoid' : 'prefer',
				'match_status'     => 'unit_preference',
				'confidence_score' => 90,
				'candidates'       => array(),
			);
		}

		// No match in same bucket -> check other (dofe_level, transport_mode) buckets.
		$crossMatches = $this->findMatchesInPool( $source, $cleanName, $otherBucket, 'Cross-bucket match' );
		if ( ! empty( $crossMatches ) ) {
			foreach ( $crossMatches as &$cm ) {
				$cm['reason'] = sprintf(
					'Signed up for %s %s — ineligible',
					ucfirst( (string) $cm['dofe_level'] ),
					ucfirst( (string) $cm['transport_mode'] )
				);
			}
			unset( $cm );

			return array(
				'target_signup_id' => null,
				'preference_type'  => $flagNegation === 1 ? 'avoid' : 'prefer',
				'match_status'     => 'cross_bucket',
				'confidence_score' => (int) $crossMatches[0]['score'],
				'candidates'       => $crossMatches,
			);
		}

		return array(
			'target_signup_id' => null,
			'preference_type'  => $flagNegation === 1 ? 'avoid' : 'prefer',
			'match_status'     => 'not_found',
			'confidence_score' => 0,
			'candidates'       => array(),
		);
	}

	/**
	 * @param array<string, mixed>             $source
	 * @param array<int, array<string, mixed>> $pool
	 * @return array<int, array<string, mixed>>
	 */
	private function findMatchesInPool( array $source, string $cleanName, array $pool, string $reason ): array {
		$matches = array();
		foreach ( $pool as $cand ) {
			$score = $this->scoreNameMatch( $cleanName, $cand );
			if ( $score >= 75 ) {
				$matches[] = array(
					'signup_id'         => (int) $cand['id'],
					'explorer_name'     => $cand['explorer_name'],
					'unit_name'         => $cand['unit_name'],
					'dofe_level'        => $cand['dofe_level'],
					'transport_mode'    => $cand['transport_mode'],
					'shared_code_count' => $this->countSharedCodes( $source, $cand ),
					'score'             => $score,
					'reason'            => $reason,
				);
			}
		}

		usort(
			$matches,
			static fn( array $a, array $b ): int => $b['score'] <=> $a['score']
		);

		return $matches;
	}

	/**
	 * @param array<int, array<string, mixed>> $matches
	 * @param array<int, array<string, mixed>> $pool
	 * @return array<int, array<string, mixed>>
	 */
	private function filterByUnitHint( array $matches, array $pool, string $unitHint ): array {
		$poolById = array();
		foreach ( $pool as $p ) {
			$poolById[ (int) $p['id'] ] = $p;
		}

		$normHint = $this->normalizeUnitString( $unitHint );
		if ( $normHint === '' ) {
			return array();
		}

		$filtered = array();
		foreach ( $matches as $m ) {
			$row       = $poolById[ (int) $m['signup_id'] ] ?? null;
			$unitName  = $this->normalizeUnitString( (string) ( $row['unit_name'] ?? $m['unit_name'] ?? '' ) );
			$shortCode = $this->normalizeUnitString( (string) ( $row['unit_short_code'] ?? '' ) );

			$isMatch = false;
			if ( $unitName !== '' && ( str_contains( $unitName, $normHint ) || str_contains( $normHint, $unitName ) || levenshtein( $unitName, $normHint ) <= 2 ) ) {
				$isMatch = true;
			}
			if ( ! $isMatch && $shortCode !== '' && ( $shortCode === $normHint || str_contains( $normHint, $shortCode ) ) ) {
				$isMatch = true;
			}

			if ( $isMatch ) {
				$filtered[] = $m;
			}
		}

		return $filtered;
	}

	private function normalizeUnitString( string $unit ): string {
		$u = strtolower( trim( $unit ) );
		$u = (string) preg_replace( '/\b(esu|explorers?|scouts?|units?)\b/i', '', $u );
		$u = (string) preg_replace( '/[^a-z0-9]+/', '', $u );
		return trim( $u );
	}

	/**
	 * Load unit names and short codes from ems_units merged with active signups.
	 *
	 * @param array<int, array<string, mixed>> $signups
	 * @return array<int, array{name: string, short_code: string}>
	 */
	private function loadUnits( array $signups = array() ): array {
		$wpdb       = $this->get_wpdb();
		$unitsTable = $wpdb->prefix . 'ems_units';
		$rows       = $wpdb->get_results(
			"SELECT name, short_code FROM {$unitsTable}",
			ARRAY_A
		);

		$byKey = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$name      = trim( (string) ( $row['name'] ?? '' ) );
				$shortCode = trim( (string) ( $row['short_code'] ?? '' ) );
				if ( $name === '' && $shortCode === '' ) {
					continue;
				}
				$key = strtolower( $name !== '' ? $name : $shortCode );
				$byKey[ $key ] = array(
					'name'       => $name !== '' ? $name : $shortCode,
					'short_code' => $shortCode,
				);
			}
		}

		foreach ( $signups as $s ) {
			$name      = trim( (string) ( $s['unit_name'] ?? '' ) );
			$shortCode = trim( (string) ( $s['unit_short_code'] ?? '' ) );
			if ( $name === '' && $shortCode === '' ) {
				continue;
			}
			$key = strtolower( $name !== '' ? $name : $shortCode );
			if ( ! isset( $byKey[ $key ] ) ) {
				$byKey[ $key ] = array(
					'name'       => $name !== '' ? $name : $shortCode,
					'short_code' => $shortCode,
				);
			}
		}

		return array_values( $byKey );
	}

	/**
	 * Match a cleaned wishlist token against known units after stripping unit-preference phrases.
	 *
	 * @param array<int, array{name: string, short_code: string}> $units
	 */
	private function matchUnitPreference( string $cleanName, array $units ): ?string {
		if ( empty( $units ) ) {
			return null;
		}

		$stripped = (string) preg_replace(
			'/\b(?:anyone|someone|anybody|explorers?|people|members)\s+(?:from|of|in)\b/iu',
			' ',
			$cleanName
		);
		$stripped = (string) preg_replace( '/\bunit\b/iu', ' ', $stripped );
		$stripped = trim( (string) preg_replace( '/\s+/', ' ', $stripped ) );
		if ( $stripped === '' ) {
			return null;
		}

		$strippedLower = strtolower( $stripped );
		$normStripped  = $this->normalizeUnitString( $stripped );

		// Pass 1: exact match on name or short_code.
		foreach ( $units as $unit ) {
			$uName  = $unit['name'];
			$uShort = $unit['short_code'];
			if ( strtolower( $uName ) === $strippedLower || ( $uShort !== '' && strtolower( $uShort ) === $strippedLower ) ) {
				return $uName;
			}
		}

		// Pass 2: case-insensitive substring or normalized unit string match.
		foreach ( $units as $unit ) {
			$uName      = $unit['name'];
			$uShort     = $unit['short_code'];
			$uNameLower = strtolower( $uName );
			$normUnit   = $this->normalizeUnitString( $uName );

			if ( strlen( $strippedLower ) >= 3 && ( str_contains( $uNameLower, $strippedLower ) || str_contains( $strippedLower, $uNameLower ) ) ) {
				return $uName;
			}
			if ( $uShort !== '' && strlen( $uShort ) >= 2 && str_contains( $strippedLower, strtolower( $uShort ) ) ) {
				return $uName;
			}
			if ( strlen( $normStripped ) >= 3 && $normUnit !== '' && ( $normUnit === $normStripped || str_contains( $normUnit, $normStripped ) || str_contains( $normStripped, $normUnit ) ) ) {
				return $uName;
			}
		}

		return null;
	}

	/**
	 * @param array<int, array<string, mixed>> $pool
	 */
	private function bestMatchScore( string $name, array $pool ): int {
		$best = 0;
		foreach ( $pool as $cand ) {
			$score = $this->scoreNameMatch( $name, $cand );
			if ( $score > $best ) {
				$best = $score;
			}
		}
		return $best;
	}

	/**
	 * Score similarity (0-100) between a cleaned token and an explorer signup.
	 *
	 * @param array<string, mixed> $cand
	 */
	private function scoreNameMatch( string $query, array $cand ): int {
		$q     = strtolower( trim( (string) preg_replace( '/\s+/', ' ', $query ) ) );
		$first = strtolower( trim( (string) ( $cand['first_name'] ?? '' ) ) );
		$last  = strtolower( trim( (string) ( $cand['last_name'] ?? '' ) ) );
		$full  = trim( $first . ' ' . $last );
		$rev   = trim( $last . ' ' . $first );

		if ( $q === '' || $full === '' ) {
			return 0;
		}
		if ( $q === $full || $q === $rev ) {
			return 100;
		}

		$parts = explode( ' ', $q );
		if ( count( $parts ) === 2 && $first !== '' && $last !== '' ) {
			$qFirst = $parts[0];
			$qLast  = $parts[1];

			$firstExact = ( $qFirst === $first );
			$lastExact  = ( $qLast === $last );
			$firstFuzzy = $firstExact || levenshtein( $qFirst, $first ) <= 1 || metaphone( $qFirst ) === metaphone( $first );
			$lastFuzzy  = $lastExact || levenshtein( $qLast, $last ) <= 2 || metaphone( $qLast ) === metaphone( $last );

			if ( $firstExact && $lastFuzzy ) {
				return 90;
			}
			if ( $firstFuzzy && $lastExact ) {
				return 88;
			}
			if ( $firstFuzzy && $lastFuzzy ) {
				return 78;
			}
		}

		$dist  = levenshtein( $q, $full );
		$len   = max( strlen( $q ), strlen( $full ) );
		$ratio = 1.0 - ( $dist / $len );
		if ( $dist <= 2 || $ratio >= 0.82 ) {
			return (int) round( $ratio * 100 );
		}

		return 0;
	}

	private function stripProsePrefixes( string $text ): string {
		$clean = trim( $text );
		// Strip prose prefixes ending with a colon (e.g. "Same Wildlife group as per Bronze: ...").
		$clean = (string) preg_replace( '/^(?:same\s+[^:]{3,60}:|friends?\s*:|teammates?\s*:|team\s*:|prefer(?:red)?\s*:)\s*/iu', '', $clean );
		// Strip conversational lead-ins.
		$clean = (string) preg_replace( '/^(?:would\s+like\s+to\s+be\s+with|ideally\s+with|ideally|hopefully\s+with|hopefully|please\s+pair\s+with)\s+/iu', '', $clean );
		return trim( $clean );
	}

	private function hasNegation( string $token ): bool {
		return preg_match( '/\b(?:not|except|without|avoid|don\'?t|do\s+not)\b/iu', $token ) === 1;
	}

	private function cleanTokenForNameMatch( string $token ): string {
		// Remove parenthesised unit hints.
		$clean = (string) preg_replace( '/\([^)]*\)/', ' ', $token );
		// Remove negation keywords so the target name itself can still be matched.
		$clean = (string) preg_replace( '/\b(?:not|except|without|avoid|don\'?t|do\s+not|with)\b/iu', ' ', $clean );
		$clean = (string) preg_replace( '/[^\p{L}\p{N}\s\'-]/u', ' ', $clean );
		$clean = (string) preg_replace( '/\s+/', ' ', $clean );
		return trim( $clean );
	}

	/**
	 * @param array<string, mixed> $a
	 * @param array<string, mixed> $b
	 */
	private function countSharedCodes( array $a, array $b ): int {
		$pShared = count( array_intersect( (array) ( $a['practice_codes'] ?? array() ), (array) ( $b['practice_codes'] ?? array() ) ) );
		$qShared = count( array_intersect( (array) ( $a['qualifier_codes'] ?? array() ), (array) ( $b['qualifier_codes'] ?? array() ) ) );
		return $pShared + $qShared;
	}

	/**
	 * @param array<string, mixed>             $source
	 * @param array<int, array<string, mixed>> $candidates
	 * @param array<int, array<string, mixed>> $signupsById
	 * @return array<int, array<string, mixed>>
	 */
	private function hydrateCandidates( array $source, array $candidates, array $signupsById ): array {
		$hydrated = array();
		foreach ( $candidates as $cand ) {
			if ( ! is_array( $cand ) ) {
				continue;
			}
			$cid = (int) ( $cand['signup_id'] ?? 0 );
			if ( $cid <= 0 ) {
				continue;
			}
			$row        = $signupsById[ $cid ] ?? null;
			$hydrated[] = array(
				'signup_id'         => $cid,
				'explorer_name'     => (string) ( $row['explorer_name'] ?? $cand['explorer_name'] ?? '' ),
				'unit_name'         => (string) ( $row['unit_name'] ?? $cand['unit_name'] ?? '' ),
				'dofe_level'        => (string) ( $row['dofe_level'] ?? $cand['dofe_level'] ?? '' ),
				'transport_mode'    => (string) ( $row['transport_mode'] ?? $cand['transport_mode'] ?? '' ),
				'shared_code_count' => $row !== null
					? $this->countSharedCodes( $source, $row )
					: (int) ( $cand['shared_code_count'] ?? 0 ),
				'score'             => (int) ( $cand['score'] ?? 0 ),
				'reason'            => (string) ( $cand['reason'] ?? '' ),
			);
		}
		return $hydrated;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function isNeedsReviewRow( array $row ): bool {
		return $this->isNeedsReviewStatus(
			(string) ( $row['match_status'] ?? 'not_found' ),
			(int) ( $row['flag_negation'] ?? 0 )
		);
	}

	private function isNeedsReviewStatus( string $status, int $flagNegation ): bool {
		if ( in_array( $status, array( 'confirmed', 'ignored' ), true ) ) {
			return false;
		}
		if ( $flagNegation === 1 ) {
			return true;
		}
		return in_array( $status, array( 'ambiguous', 'not_found', 'cross_bucket' ), true );
	}

	/**
	 * @param array<int, int> $sourceIds
	 * @return array<int, array<int, array<string, mixed>>>
	 */
	private function groupExistingBySource( array $sourceIds ): array {
		$rows    = $this->prefs->findBySourceSignupIds( $sourceIds );
		$grouped = array();
		foreach ( $rows as $row ) {
			$src = (int) $row['source_signup_id'];
			if ( ! isset( $grouped[ $src ] ) ) {
				$grouped[ $src ] = array();
			}
			$grouped[ $src ][] = $row;
		}
		return $grouped;
	}

	/**
	 * Load all active expedition sign-ups with unit metadata and parsed preferences.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function loadActiveSignups(): array {
		$wpdb          = $this->get_wpdb();
		$signupsTable  = $wpdb->prefix . 'ems_expedition_signups';
		$explorersTable = $wpdb->prefix . 'ems_osm_explorers';
		$unitsTable    = $wpdb->prefix . 'ems_units';

		$rows = $wpdb->get_results(
			"SELECT s.id, s.scout_id, s.unit_id, s.unit_name,
				s.explorer_first_name, s.explorer_last_name,
				s.dofe_level, s.expedition_preferences,
				s.first_aid_status, s.first_aid_expiry,
				e.first_name AS osm_first_name, e.last_name AS osm_last_name,
				e.first_aid_level AS osm_first_aid_level,
				u.name AS resolved_unit_name, u.short_code AS unit_short_code
			FROM {$signupsTable} s
			LEFT JOIN {$explorersTable} e ON (s.scout_id > 0 AND s.scout_id = e.scout_id)
			LEFT JOIN {$unitsTable} u ON (s.unit_id IS NOT NULL AND s.unit_id > 0 AND s.unit_id = u.unit_id)
			WHERE s.signup_status = 'submitted'
			  AND COALESCE(s.kanban_stage, 'new') != 'cancelled'
			ORDER BY s.id ASC",
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$genderMap = $this->prefs->getGenderMap();
		$result    = array();
		foreach ( $rows as $row ) {
			$first = trim( (string) ( $row['explorer_first_name'] ?: $row['osm_first_name'] ?? '' ) );
			$last  = trim( (string) ( $row['explorer_last_name'] ?: $row['osm_last_name'] ?? '' ) );
			$prefs = $this->parsePreferencesJson( $row['expedition_preferences'] ?? null );

			$unitName = trim( (string) ( $row['resolved_unit_name'] ?? '' ) );
			if ( $unitName === '' ) {
				$unitName = trim( (string) ( $row['unit_name'] ?? '' ) );
			}

			$signupId = (int) $row['id'];
			$result[] = array(
				'id'                  => $signupId,
				'scout_id'            => (int) ( $row['scout_id'] ?? 0 ),
				'unit_id'             => isset( $row['unit_id'] ) && $row['unit_id'] !== null ? (int) $row['unit_id'] : null,
				'unit_name'           => $unitName,
				'unit_short_code'     => trim( (string) ( $row['unit_short_code'] ?? '' ) ),
				'gender'              => $genderMap[ $signupId ] ?? '',
				'first_name'          => $first,
				'last_name'           => $last,
				'explorer_name'       => trim( $first . ' ' . $last ),
				'dofe_level'          => strtolower( trim( (string) ( $row['dofe_level'] ?? '' ) ) ),
				'transport_mode'      => $prefs['transport_mode'],
				'practice_codes'      => $prefs['practice_codes'],
				'qualifier_codes'     => $prefs['qualifier_codes'],
				'wishlist_raw'        => $prefs['wishlist_raw'],
				'first_aid_status'    => (string) ( $row['first_aid_status'] ?? 'none' ),
				'osm_first_aid_level' => (string) ( $row['osm_first_aid_level'] ?? '' ),
			);
		}

		return $result;
	}
}
