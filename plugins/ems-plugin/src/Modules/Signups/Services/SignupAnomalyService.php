<?php
declare(strict_types=1);

namespace EMS\Modules\Signups\Services;

/**
 * Server-side anomaly detector for Participant Place and Expedition sign-ups.
 *
 * Rules:
 * - Archived/confirmed Participant Places still count as an active Participant Place
 *   when checking Expedition pairing (so archiving after eDofE setup does not trigger
 *   expedition_without_place).
 * - `participant_place_allocated = 1` on an Expedition sign-up also satisfies place pairing
 *   (auto-linked via participant_signup_id or manually set for prior-year places).
 * - OSM sync anomalies (unmatched_osm, pending_osm_sync) apply to Expedition sign-ups only.
 */
class SignupAnomalyService {

	/**
	 * @param array<int, array<string, mixed>> $participantRows
	 * @param array<int, array<string, mixed>> $expeditionRows
	 * @return array{by_key: array<string, string[]>, counts: array<string, int>}
	 */
	public function computeAnomalies( array $participantRows, array $expeditionRows ): array {
		$byKey  = array();
		$counts = array(
			'place_without_expedition' => 0,
			'expedition_without_place' => 0,
			'level_mismatch'           => 0,
			'duplicate_signup'         => 0,
			'unmatched_osm'            => 0,
			'pending_osm_sync'         => 0,
			'missing_edofe'            => 0,
			'unvalidated_asn'          => 0,
		);

		$all = array();
		foreach ( $participantRows as $row ) {
			if ( ( $row['kanban_stage'] ?? '' ) === 'cancelled' ) {
				continue;
			}
			$row['_type'] = 'participant';
			$all[]        = $row;
		}
		foreach ( $expeditionRows as $row ) {
			if ( ( $row['signup_status'] ?? 'submitted' ) === 'archived' ) {
				continue;
			}
			if ( in_array( (string) ( $row['kanban_stage'] ?? '' ), array( 'cancelled', 'withdrawn' ), true ) ) {
				continue;
			}
			$row['_type'] = 'expedition';
			$all[]        = $row;
		}

		$buckets = array();
		$dupMap  = array();
		foreach ( $all as $item ) {
			$identity               = $this->buildIdentityKey( $item );
			$buckets[ $identity ][] = $item;

			// Duplicate check only applies to active (non-archived) signups.
			if ( ( $item['signup_status'] ?? 'submitted' ) !== 'archived' ) {
				$type                = (string) $item['_type'];
				$level               = strtolower( trim( (string) ( $item['dofe_level'] ?? '' ) ) );
				$dupKey              = $identity . '|' . $type . '|' . $level;
				$dupMap[ $dupKey ][] = $type . '_' . (int) ( $item['id'] ?? 0 );
			}
		}

		foreach ( $buckets as $items ) {
			$pLevels = array();
			$eLevels = array();

			foreach ( $items as $item ) {
				$lvl = strtolower( trim( (string) ( $item['dofe_level'] ?? '' ) ) );
				if ( $item['_type'] === 'participant' ) {
					$pLevels[ $lvl ] = true;
				} else {
					$eLevels[ $lvl ] = true;
				}
			}

			$hasP          = ! empty( $pLevels );
			$hasE          = ! empty( $eLevels );
			$levelMismatch = $hasP && $hasE && array_keys( $pLevels ) !== array_keys( $eLevels );

			foreach ( $items as $item ) {
				if ( ( $item['signup_status'] ?? 'submitted' ) === 'archived' ) {
					continue;
				}

				$type = (string) $item['_type'];
				$id   = (int) ( $item['id'] ?? 0 );
				$key  = $type . '_' . $id;
				$lvl  = strtolower( trim( (string) ( $item['dofe_level'] ?? '' ) ) );

				$flags = array();

				if ( $type === 'participant' && ! isset( $eLevels[ $lvl ] ) ) {
					$flags[] = 'place_without_expedition';
				}
				if ( $type === 'expedition' && ! isset( $pLevels[ $lvl ] ) && empty( $item['participant_place_allocated'] ) ) {
					$flags[] = 'expedition_without_place';
				}
				if ( $levelMismatch ) {
					$flags[] = 'level_mismatch';
				}

				$dupKey = $this->buildIdentityKey( $item ) . '|' . $type . '|' . $lvl;
				if ( isset( $dupMap[ $dupKey ] ) && count( $dupMap[ $dupKey ] ) > 1 ) {
					$flags[] = 'duplicate_signup';
				}

				// OSM sync only matters for Expedition sign-ups.
				if ( $type === 'expedition' ) {
					$scoutId = (int) ( $item['scout_id'] ?? 0 );
					if ( $scoutId === 0 ) {
						$flags[] = 'unmatched_osm';
					} elseif ( empty( $item['is_synced_osm'] ) ) {
						$flags[] = 'pending_osm_sync';
					}

					$asn          = trim( (string) ( $item['additional_support_needs'] ?? '' ) );
					$asnValidated = $item['asn_validated'] ?? null;
					if ( $asn !== '' && ( $asnValidated === null || (int) $asnValidated === 0 ) ) {
						$flags[] = 'unvalidated_asn';
					}
				}

				if ( trim( (string) ( $item['dofe_number'] ?? '' ) ) === '' ) {
					$flags[] = 'missing_edofe';
				}

				$byKey[ $key ] = $flags;
				foreach ( $flags as $flag ) {
					if ( isset( $counts[ $flag ] ) ) {
						$counts[ $flag ]++;
					}
				}
			}
		}

		return array(
			'by_key' => $byKey,
			'counts' => $counts,
		);
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function buildIdentityKey( array $item ): string {
		$scoutId = (int) ( $item['scout_id'] ?? 0 );
		if ( $scoutId > 0 ) {
			return 'scout:' . $scoutId;
		}
		$first  = strtolower( trim( (string) ( $item['explorer_first_name'] ?? '' ) ) );
		$last   = strtolower( trim( (string) ( $item['explorer_last_name'] ?? '' ) ) );
		$parent = strtolower( trim( (string) ( $item['parent_email'] ?? '' ) ) );
		return 'name:' . $first . '|' . $last . '|' . $parent;
	}
}
