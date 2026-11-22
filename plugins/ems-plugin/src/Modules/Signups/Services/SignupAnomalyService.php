<?php
declare(strict_types=1);

namespace EMS\Modules\Signups\Services;

/**
 * Server-side anomaly detector for Form 6 (participant) and Form 7 (expedition) sign-ups.
 *
 * Computes the 8 canonical anomalies:
 * - place_without_expedition
 * - expedition_without_place
 * - level_mismatch
 * - duplicate_signup
 * - unmatched_osm (scout_id = 0)
 * - pending_osm_sync (scout_id > 0 && !is_synced_osm)
 * - missing_edofe
 * - unvalidated_asn
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
			if ( ( $row['signup_status'] ?? 'submitted' ) === 'archived' ) {
				continue;
			}
			$row['_type'] = 'participant';
			$all[]        = $row;
		}
		foreach ( $expeditionRows as $row ) {
			if ( ( $row['signup_status'] ?? 'submitted' ) === 'archived' ) {
				continue;
			}
			$row['_type'] = 'expedition';
			$all[]        = $row;
		}

		// Group active sign-ups by explorer identity (scout_id > 0, else normalized name + parent_email).
		$buckets = array();
		$dupMap  = array();
		foreach ( $all as $item ) {
			$identity = $this->buildIdentityKey( $item );
			$buckets[ $identity ][] = $item;

			$type   = (string) $item['_type'];
			$level  = strtolower( trim( (string) ( $item['dofe_level'] ?? '' ) ) );
			$dupKey = $identity . '|' . $type . '|' . $level;
			$dupMap[ $dupKey ][] = $type . '_' . (int) ( $item['id'] ?? 0 );
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
				$type = (string) $item['_type'];
				$id   = (int) ( $item['id'] ?? 0 );
				$key  = $type . '_' . $id;
				$lvl  = strtolower( trim( (string) ( $item['dofe_level'] ?? '' ) ) );

				$flags = array();

				if ( $type === 'participant' && ! isset( $eLevels[ $lvl ] ) ) {
					$flags[] = 'place_without_expedition';
				}
				if ( $type === 'expedition' && ! isset( $pLevels[ $lvl ] ) ) {
					$flags[] = 'expedition_without_place';
				}
				if ( $levelMismatch ) {
					$flags[] = 'level_mismatch';
				}

				$dupKey = $this->buildIdentityKey( $item ) . '|' . $type . '|' . $lvl;
				if ( isset( $dupMap[ $dupKey ] ) && count( $dupMap[ $dupKey ] ) > 1 ) {
					$flags[] = 'duplicate_signup';
				}

				$scoutId = (int) ( $item['scout_id'] ?? 0 );
				if ( $scoutId === 0 ) {
					$flags[] = 'unmatched_osm';
				} elseif ( empty( $item['is_synced_osm'] ) ) {
					$flags[] = 'pending_osm_sync';
				}

				if ( trim( (string) ( $item['dofe_number'] ?? '' ) ) === '' ) {
					$flags[] = 'missing_edofe';
				}

				if ( $type === 'expedition' ) {
					$asn          = trim( (string) ( $item['additional_support_needs'] ?? '' ) );
					$asnValidated = $item['asn_validated'] ?? null;
					if ( $asn !== '' && ( $asnValidated === null || (int) $asnValidated === 0 ) ) {
						$flags[] = 'unvalidated_asn';
					}
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
