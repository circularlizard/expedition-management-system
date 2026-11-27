<?php
namespace EMS\Data;

class Expedition_Repository {
	public function create( array $data ): int {
		$code = $data['ems_event_code'] ?? '';
		if ( empty( $code ) ) {
			throw new \InvalidArgumentException( 'ems_event_code is required.' );
		}

		$season_id = (int) ( $data['season_id'] ?? 0 );
		if ( $season_id > 0 && $this->code_exists_in_season( $code, $season_id ) ) {
			throw new \InvalidArgumentException( "Duplicate event code in season: {$code}." );
		}

		if ( ! isset( $data['ems_status'] ) || $data['ems_status'] === '' ) {
			$data['ems_status'] = 'active';
		}

		$post_data = array(
			'post_type'   => 'expedition',
			'post_title'  => $data['post_title'] ?? '',
			'post_status' => 'publish',
			'post_parent' => $season_id > 0 ? $season_id : 0,
		);

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			throw new \RuntimeException( $post_id->get_error_message() );
		}

		$meta_fields = array(
			'ems_event_code',
			'ems_expedition_code',
			'ems_type',
			'ems_transport',
			'ems_level',
			'ems_req_assessors',
			'ems_req_volunteers',
			'ems_max_teams',
			'ems_lic_name',
			'ems_lic_email',
			'ems_lic_phone',
			'ems_lic_private_email',
			'ems_lic_private_phone',
			'ems_lic_id',
			'ems_start_location',
			'ems_end_location',
			'ems_start_date',
			'ems_start_time',
			'ems_end_date',
			'ems_end_time',
			'ems_route_info',
			'ems_joining_instructions',
			'ems_route_deadline',
			'ems_osm_event_id',
			'ems_status',
			'ems_first_aid_level',
			'ems_expedition_whatsapp_explorers',
			'ems_expedition_whatsapp_parents',
			'ems_first_aid_requirements',
			'ems_route_status',
		);

		if ( isset( $data['ems_route_days'] ) && is_array( $data['ems_route_days'] ) ) {
			$normalized_days = $this->normalize_route_days( $data['ems_route_days'] );
			$data            = $this->derive_locations_from_days( $data, $normalized_days );
			if ( ! empty( $normalized_days ) ) {
				update_post_meta( $post_id, 'ems_route_days', wp_json_encode( $normalized_days ) );
			}
		}

		foreach ( $meta_fields as $key ) {
			if ( isset( $data[ $key ] ) && $data[ $key ] !== '' ) {
				update_post_meta( $post_id, $key, $data[ $key ] );
			}
		}

		return (int) $post_id;
	}

	public function update( int $id, array $data ): bool {
		$post = get_post( $id );
		if ( ! $post || $post->post_type !== 'expedition' ) {
			return false;
		}

		$update = array();
		if ( isset( $data['post_title'] ) ) {
			$update['post_title'] = $data['post_title'];
		}
		if ( isset( $data['season_id'] ) ) {
			$update['post_parent'] = (int) $data['season_id'];
		}
		if ( ! empty( $update ) ) {
			$update['ID'] = $id;
			wp_update_post( $update );
		}

		$meta_fields = array(
			'ems_event_code',
			'ems_expedition_code',
			'ems_type',
			'ems_transport',
			'ems_level',
			'ems_req_assessors',
			'ems_req_volunteers',
			'ems_max_teams',
			'ems_lic_name',
			'ems_lic_email',
			'ems_lic_phone',
			'ems_lic_private_email',
			'ems_lic_private_phone',
			'ems_lic_id',
			'ems_start_location',
			'ems_end_location',
			'ems_start_date',
			'ems_start_time',
			'ems_end_date',
			'ems_end_time',
			'ems_route_info',
			'ems_joining_instructions',
			'ems_route_deadline',
			'ems_osm_event_id',
			'ems_status',
			'ems_first_aid_level',
			'ems_expedition_whatsapp_explorers',
			'ems_expedition_whatsapp_parents',
			'ems_first_aid_requirements',
			'ems_route_status',
		);

		if ( array_key_exists( 'ems_route_days', $data ) ) {
			if ( is_array( $data['ems_route_days'] ) && ! empty( $data['ems_route_days'] ) ) {
				$normalized_days = $this->normalize_route_days( $data['ems_route_days'] );
				$data            = $this->derive_locations_from_days( $data, $normalized_days );
				update_post_meta( $id, 'ems_route_days', wp_json_encode( $normalized_days ) );
			} else {
				delete_post_meta( $id, 'ems_route_days' );
			}
		}

		foreach ( $meta_fields as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				if ( $data[ $key ] === '' ) {
					delete_post_meta( $id, $key );
				} else {
					update_post_meta( $id, $key, $data[ $key ] );
				}
			}
		}

		return true;
	}

	public function delete( int $id ): bool {
		$post = get_post( $id );
		if ( ! $post || $post->post_type !== 'expedition' ) {
			return false;
		}
		return wp_delete_post( $id, true ) !== false;
	}

	public function get_by_id( int $id ): ?array {
		$post = get_post( $id );

		if ( ! $post || $post->post_type !== 'expedition' ) {
			return null;
		}

		return $this->to_array( $post );
	}

	public function list_all(): array {
		$posts = get_posts(
			array(
				'post_type'   => 'expedition',
				'post_status' => 'publish',
				'numberposts' => -1,
			)
		);

		return array_map( array( $this, 'to_array' ), $posts );
	}

	public function list_all_chronological(): array {
		$posts = get_posts(
			array(
				'post_type'   => 'expedition',
				'post_status' => 'publish',
				'numberposts' => -1,
				'meta_key'    => 'ems_start_date',
				'orderby'     => 'meta_value',
				'order'       => 'ASC',
			)
		);

		return array_map( array( $this, 'to_array' ), $posts );
	}

	public function list_upcoming(): array {
		$today = current_time( 'Y-m-d' );
		$posts = get_posts(
			array(
				'post_type'   => 'expedition',
				'post_status' => 'publish',
				'numberposts' => -1,
				'meta_key'    => 'ems_start_date',
				'orderby'     => 'meta_value',
				'order'       => 'ASC',
				'meta_query'  => array(
					'relation' => 'AND',
					array(
						'key'     => 'ems_start_date',
						'value'   => $today,
						'compare' => '>=',
						'type'    => 'DATE',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => 'ems_status',
							'value'   => 'archived',
							'compare' => '!=',
						),
						array(
							'key'     => 'ems_status',
							'compare' => 'NOT EXISTS',
						),
					),
				),
			)
		);

		return array_map( array( $this, 'to_array' ), $posts );
	}

	public function list_past(): array {
		$today = current_time( 'Y-m-d' );
		$posts = get_posts(
			array(
				'post_type'   => 'expedition',
				'post_status' => 'publish',
				'numberposts' => -1,
				'meta_key'    => 'ems_end_date',
				'orderby'     => 'meta_value',
				'order'       => 'DESC',
				'meta_query'  => array(
					'relation' => 'AND',
					array(
						'key'     => 'ems_end_date',
						'value'   => $today,
						'compare' => '<',
						'type'    => 'DATE',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => 'ems_status',
							'value'   => 'archived',
							'compare' => '!=',
						),
						array(
							'key'     => 'ems_status',
							'compare' => 'NOT EXISTS',
						),
					),
				),
			)
		);

		return array_map( array( $this, 'to_array' ), $posts );
	}

	public function has_teams( int $id ): bool {
		$teams = get_posts(
			array(
				'post_type'   => 'team',
				'post_status' => 'publish',
				'numberposts' => 1,
				'post_parent' => $id,
			)
		);
		return ! empty( $teams );
	}

	private function code_exists_in_season( string $code, int $season_id ): bool {
		$existing = get_posts(
			array(
				'post_type'   => 'expedition',
				'post_status' => 'publish',
				'numberposts' => 1,
				'post_parent' => $season_id,
				'meta_query'  => array(
					array(
						'key'   => 'ems_event_code',
						'value' => $code,
					),
				),
			)
		);

		return ! empty( $existing );
	}

	private function to_array( object $post ): array {
		$result = array(
			'ID'          => $post->ID,
			'post_title'  => $post->post_title,
			'post_status' => $post->post_status,
			'season_id'   => (int) $post->post_parent,
		);

		$meta_fields = array(
			'ems_event_code',
			'ems_expedition_code',
			'ems_type',
			'ems_transport',
			'ems_level',
			'ems_req_assessors',
			'ems_req_volunteers',
			'ems_max_teams',
			'ems_lic_name',
			'ems_lic_email',
			'ems_lic_phone',
			'ems_lic_private_email',
			'ems_lic_private_phone',
			'ems_lic_id',
			'ems_start_location',
			'ems_end_location',
			'ems_start_date',
			'ems_start_time',
			'ems_end_date',
			'ems_end_time',
			'ems_route_info',
			'ems_joining_instructions',
			'ems_route_deadline',
			'ems_osm_event_id',
			'ems_status',
			'ems_first_aid_level',
			'ems_expedition_whatsapp_explorers',
			'ems_expedition_whatsapp_parents',
			'ems_first_aid_requirements',
			'ems_route_status',
		);

		foreach ( $meta_fields as $key ) {
			$result[ $key ] = get_post_meta( $post->ID, $key, true );
		}

		$raw_days = get_post_meta( $post->ID, 'ems_route_days', true );
		if ( is_string( $raw_days ) && $raw_days !== '' ) {
			$decoded                  = json_decode( $raw_days, true );
			$result['ems_route_days'] = is_array( $decoded ) ? $decoded : array();
		} elseif ( is_array( $raw_days ) ) {
			$result['ems_route_days'] = $raw_days;
		} else {
			$result['ems_route_days'] = array();
		}

		if ( $result['ems_max_teams'] === '' ) {
			foreach ( array( '_ems_max_teams', '_ems_capacity_teams', 'ems_capacity_teams' ) as $legacy_key ) {
				$legacy_val = get_post_meta( $post->ID, $legacy_key, true );
				if ( $legacy_val !== '' ) {
					$result['ems_max_teams'] = $legacy_val;
					break;
				}
			}
		}

		return $result;
	}

	/**
	 * Normalize multi-day route waypoints payload.
	 *
	 * @param array<int, mixed> $days Raw route days.
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize_route_days( array $days ): array {
		$normalized = array();
		$index      = 1;
		foreach ( $days as $day ) {
			if ( ! is_array( $day ) ) {
				continue;
			}
			$day_number  = isset( $day['day_number'] ) && (int) $day['day_number'] > 0 ? (int) $day['day_number'] : $index;
			$checkpoints = array();
			if ( ! empty( $day['checkpoints'] ) && is_array( $day['checkpoints'] ) ) {
				foreach ( $day['checkpoints'] as $cp_idx => $cp ) {
					if ( is_array( $cp ) ) {
						$checkpoints[] = $this->normalize_waypoint( $cp, "cp-{$day_number}-" . ( $cp_idx + 1 ), 'Checkpoint ' . ( $cp_idx + 1 ) );
					}
				}
			}
			$normalized[] = array(
				'day_number'  => $day_number,
				'date'        => isset( $day['date'] ) ? sanitize_text_field( (string) $day['date'] ) : '',
				'start'       => $this->normalize_waypoint( is_array( $day['start'] ?? null ) ? $day['start'] : array(), "day-{$day_number}-start", 'Start' ),
				'checkpoints' => $checkpoints,
				'end'         => $this->normalize_waypoint( is_array( $day['end'] ?? null ) ? $day['end'] : array(), "day-{$day_number}-end", 'End' ),
			);
			$index++;
		}
		return $normalized;
	}

	/**
	 * Normalize a single route waypoint.
	 *
	 * @param array<string, mixed> $wp Raw waypoint.
	 * @param string               $default_id Fallback ID.
	 * @param string               $default_label Fallback label.
	 * @return array<string, mixed>
	 */
	private function normalize_waypoint( array $wp, string $default_id, string $default_label ): array {
		$lat = isset( $wp['lat'] ) && $wp['lat'] !== '' && is_numeric( $wp['lat'] ) ? (float) $wp['lat'] : null;
		$lng = isset( $wp['lng'] ) && $wp['lng'] !== '' && is_numeric( $wp['lng'] ) ? (float) $wp['lng'] : null;

		return array(
			'id'    => ! empty( $wp['id'] ) ? sanitize_text_field( (string) $wp['id'] ) : $default_id,
			'label' => isset( $wp['label'] ) && $wp['label'] !== '' ? sanitize_text_field( (string) $wp['label'] ) : $default_label,
			'lat'   => $lat,
			'lng'   => $lng,
			'notes' => isset( $wp['notes'] ) ? sanitize_textarea_field( (string) $wp['notes'] ) : '',
		);
	}

	/**
	 * Derive backward-compatible ems_start_location and ems_end_location from Day 1 Start and Last Day End.
	 *
	 * @param array<string, mixed>             $data Expedition payload.
	 * @param array<int, array<string, mixed>> $days Normalized route days.
	 * @return array<string, mixed>
	 */
	private function derive_locations_from_days( array $data, array $days ): array {
		if ( empty( $days ) ) {
			return $data;
		}
		$first_start = $days[0]['start'] ?? null;
		if ( is_array( $first_start ) && $first_start['lat'] !== null && $first_start['lng'] !== null ) {
			$data['ems_start_location'] = sprintf( '%.6f, %.6f', (float) $first_start['lat'], (float) $first_start['lng'] );
		}
		$last_day = $days[ count( $days ) - 1 ];
		$last_end = $last_day['end'] ?? null;
		if ( is_array( $last_end ) && $last_end['lat'] !== null && $last_end['lng'] !== null ) {
			$data['ems_end_location'] = sprintf( '%.6f, %.6f', (float) $last_end['lat'], (float) $last_end['lng'] );
		}
		return $data;
	}
}
