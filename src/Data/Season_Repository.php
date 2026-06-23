<?php
namespace EMS\Data;

class Season_Repository {

	public function create( array $data ): int {
		$year = sanitize_text_field( $data['year'] ?? '' );

		if ( empty( $year ) ) {
			throw new \InvalidArgumentException( 'Season year is required.' );
		}

		if ( $this->year_exists( $year ) ) {
			throw new \InvalidArgumentException( "Season year already exists: {$year}." );
		}

		$post_id = wp_insert_post( [
			'post_type'   => 'season',
			'post_title'  => $data['post_title'] ?? $year . ' Season',
			'post_status' => 'publish',
		], true );

		if ( is_wp_error( $post_id ) ) {
			throw new \RuntimeException( $post_id->get_error_message() );
		}

		update_post_meta( $post_id, 'ems_season_year', $year );
		update_post_meta( $post_id, 'ems_season_status', $data['status'] ?? 'active' );

		return (int) $post_id;
	}

	public function get_by_id( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post || $post->post_type !== 'season' ) {
			return null;
		}
		return $this->to_array( $post );
	}

	public function list_all(): array {
		$posts = get_posts( [
			'post_type'   => 'season',
			'post_status' => 'publish',
			'numberposts' => -1,
			'orderby'     => 'date',
			'order'       => 'DESC',
		] );

		return array_map( [ $this, 'to_array' ], $posts );
	}

	public function archive( int $id ): bool {
		if ( ! $this->get_by_id( $id ) ) {
			return false;
		}
		update_post_meta( $id, 'ems_season_status', 'archived' );
		return true;
	}

	private function year_exists( string $year ): bool {
		$existing = get_posts( [
			'post_type'   => 'season',
			'post_status' => 'publish',
			'numberposts' => 1,
			'meta_query'  => [
				[
					'key'   => 'ems_season_year',
					'value' => $year,
				],
			],
		] );

		return ! empty( $existing );
	}

	private function to_array( object $post ): array {
		return [
			'ID'                  => $post->ID,
			'post_title'          => $post->post_title,
			'post_status'         => $post->post_status,
			'ems_season_year'     => get_post_meta( $post->ID, 'ems_season_year', true ),
			'ems_season_status'   => get_post_meta( $post->ID, 'ems_season_status', true ) ?: 'active',
		];
	}
}
