<?php
declare(strict_types=1);

namespace EMS\Core;

/**
 * Canonical resolver between WordPress user IDs and OSM scout_ids.
 *
 * Standardises on the plural `ems_scout_ids` (array) and `ems_children` (array)
 * user meta populated by OIDC_Login_Handler, with read-only fallback to legacy
 * singular `ems_scout_id` meta.
 */
class ExplorerUserResolver {

	/**
	 * Returns all scout_ids linked directly to a WordPress user (`ems_scout_ids`,
	 * falling back to legacy `ems_scout_id` if present).
	 *
	 * @return int[]
	 */
	public static function getScoutIdsForUser( int $userId ): array {
		if ( $userId <= 0 || ! function_exists( 'get_user_meta' ) ) {
			return array();
		}

		$raw = get_user_meta( $userId, 'ems_scout_ids', true );
		$ids = self::normalizeIdList( $raw );

		if ( empty( $ids ) ) {
			$legacy = get_user_meta( $userId, 'ems_scout_id', true );
			$ids    = self::normalizeIdList( $legacy );
		}

		return $ids;
	}

	/**
	 * Returns child scout_ids managed by a Parent / Networker (`ems_children`).
	 *
	 * @return int[]
	 */
	public static function getManagedChildScoutIds( int $userId ): array {
		if ( $userId <= 0 || ! function_exists( 'get_user_meta' ) ) {
			return array();
		}

		$raw = get_user_meta( $userId, 'ems_children', true );
		return self::normalizeIdList( $raw );
	}

	/**
	 * Resolves a WordPress user ID for a given OSM scout_id.
	 */
	public static function findWpUserIdByScoutId( int $scoutId ): ?int {
		if ( $scoutId <= 0 || ! function_exists( 'get_users' ) ) {
			return null;
		}

		// Primary: search plural `ems_scout_ids` (serialized integer or string in wp_usermeta).
		$candidates = get_users(
			array(
				'fields'     => 'ID',
				'meta_query' => array(
					'relation' => 'OR',
					array(
						'key'     => 'ems_scout_ids',
						'value'   => 'i:' . $scoutId . ';',
						'compare' => 'LIKE',
					),
					array(
						'key'     => 'ems_scout_ids',
						'value'   => '"' . $scoutId . '"',
						'compare' => 'LIKE',
					),
				),
			)
		);

		foreach ( (array) $candidates as $candidate ) {
			$uid = is_numeric( $candidate ) ? (int) $candidate : ( ( $candidate instanceof \WP_User ) ? (int) $candidate->ID : 0 );
			if ( $uid > 0 && in_array( $scoutId, self::getScoutIdsForUser( $uid ), true ) ) {
				return $uid;
			}
		}

		// Legacy fallback: singular `ems_scout_id`.
		$legacy = get_users(
			array(
				'meta_key'   => 'ems_scout_id',
				'meta_value' => (string) $scoutId,
				'number'     => 1,
				'fields'     => 'ID',
			)
		);

		if ( ! empty( $legacy ) ) {
			$first = reset( $legacy );
			$uid   = is_numeric( $first ) ? (int) $first : ( ( $first instanceof \WP_User ) ? (int) $first->ID : 0 );
			return $uid > 0 ? $uid : null;
		}

		return null;
	}

	/**
	 * @param mixed $raw
	 * @return int[]
	 */
	private static function normalizeIdList( mixed $raw ): array {
		if ( is_string( $raw ) && $raw !== '' ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$raw = $decoded;
			} elseif ( is_numeric( $raw ) ) {
				$raw = array( (int) $raw );
			}
		}

		if ( is_numeric( $raw ) ) {
			$raw = array( (int) $raw );
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$ids = array();
		foreach ( $raw as $item ) {
			if ( is_array( $item ) && isset( $item['scout_id'] ) ) {
				$val = (int) $item['scout_id'];
			} else {
				$val = is_numeric( $item ) ? (int) $item : 0;
			}
			if ( $val > 0 ) {
				$ids[ $val ] = $val;
			}
		}

		return array_values( $ids );
	}
}
