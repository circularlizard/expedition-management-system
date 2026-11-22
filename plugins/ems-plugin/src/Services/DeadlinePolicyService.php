<?php
declare(strict_types=1);

namespace EMS\Services;

/**
 * Manages configurable two-tier deadline policies (communicated soft deadline vs.
 * hard system cutoff, with rolling grace fallback for late allocations).
 */
class DeadlinePolicyService {

	public const OPTION_NAME = 'ems_deadline_policies';

	/**
	 * Default policy configuration.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function defaults(): array {
		return array(
			'place_offer' => array(
				'label'             => 'Place Offer Response',
				'communicated_date' => '',
				'hard_cutoff_date'  => '',
				'grace_days'        => 7,
			),
			'route_card'  => array(
				'label'             => 'Route Card Submission',
				'communicated_date' => '',
				'hard_cutoff_date'  => '',
				'grace_days'        => 7,
			),
		);
	}

	/**
	 * Returns normalized deadline policies from WordPress options.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_policies(): array {
		$defaults = self::defaults();
		$stored   = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$result = array();
		foreach ( $defaults as $key => $def ) {
			$row            = isset( $stored[ $key ] ) && is_array( $stored[ $key ] ) ? $stored[ $key ] : array();
			$result[ $key ] = array(
				'label'             => $def['label'],
				'communicated_date' => self::sanitize_date( (string) ( $row['communicated_date'] ?? '' ) ),
				'hard_cutoff_date'  => self::sanitize_date( (string) ( $row['hard_cutoff_date'] ?? '' ) ),
				'grace_days'        => max( 0, (int) ( $row['grace_days'] ?? $def['grace_days'] ) ),
			);
		}

		return $result;
	}

	/**
	 * Saves sanitized deadline policies into WordPress options.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, array<string, mixed>>
	 */
	public function save_policies( array $input ): array {
		$defaults  = self::defaults();
		$sanitized = array();

		foreach ( $defaults as $key => $def ) {
			$row               = isset( $input[ $key ] ) && is_array( $input[ $key ] ) ? $input[ $key ] : array();
			$sanitized[ $key ] = array(
				'communicated_date' => self::sanitize_date( (string) ( $row['communicated_date'] ?? '' ) ),
				'hard_cutoff_date'  => self::sanitize_date( (string) ( $row['hard_cutoff_date'] ?? '' ) ),
				'grace_days'        => max( 0, (int) ( $row['grace_days'] ?? $def['grace_days'] ) ),
			);
		}

		update_option( self::OPTION_NAME, $sanitized );
		return $this->get_policies();
	}

	/**
	 * Evaluates whether an action is permitted under the two-tier deadline policy.
	 *
	 * @param string      $policy_key   'place_offer' or 'route_card'
	 * @param string|null $allocated_at MySQL datetime when member was notified/allocated (for rolling grace).
	 * @param int|null    $now_ts       Optional Unix timestamp override for deterministic tests.
	 * @return array<string, mixed>
	 */
	public function evaluate_cutoff( string $policy_key, ?string $allocated_at = null, ?int $now_ts = null ): array {
		$policies = $this->get_policies();
		$policy   = $policies[ $policy_key ] ?? array(
			'communicated_date' => '',
			'hard_cutoff_date'  => '',
			'grace_days'        => 0,
		);

		$now            = $now_ts ?? time();
		$comm_date      = (string) ( $policy['communicated_date'] ?? '' );
		$hard_date      = (string) ( $policy['hard_cutoff_date'] ?? '' );
		$grace_days     = max( 0, (int) ( $policy['grace_days'] ?? 0 ) );
		$grace_applied  = false;
		$effective_date = $hard_date !== '' ? $hard_date : null;

		if ( $hard_date === '' ) {
			return array(
				'allowed'               => true,
				'communicated_date'     => $comm_date !== '' ? $comm_date : null,
				'hard_cutoff_date'      => null,
				'effective_hard_cutoff' => null,
				'grace_applied'         => false,
				'reason'                => '',
			);
		}

		$hard_ts      = strtotime( $hard_date . ' 23:59:59' );
		$effective_ts = $hard_ts !== false ? $hard_ts : 0;

		if ( $allocated_at && $grace_days > 0 ) {
			$alloc_ts = strtotime( $allocated_at );
			if ( $alloc_ts !== false ) {
				$grace_ts = strtotime( gmdate( 'Y-m-d', $alloc_ts ) . ' 23:59:59 +' . $grace_days . ' days' );
				if ( $grace_ts !== false && $grace_ts > $effective_ts ) {
					$effective_ts   = $grace_ts;
					$effective_date = gmdate( 'Y-m-d', $effective_ts );
					$grace_applied  = true;
				}
			}
		}

		$allowed = $effective_ts <= 0 || $now <= $effective_ts;

		return array(
			'allowed'               => $allowed,
			'communicated_date'     => $comm_date !== '' ? $comm_date : null,
			'hard_cutoff_date'      => $hard_date,
			'effective_hard_cutoff' => $effective_date,
			'grace_applied'         => $grace_applied,
			'reason'                => $allowed
				? ''
				: sprintf( 'The hard cutoff (%s) for this action has passed.', (string) $effective_date ),
		);
	}

	private static function sanitize_date( string $raw ): string {
		$trimmed = trim( $raw );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $trimmed ) === 1 ) {
			return $trimmed;
		}
		return '';
	}
}
