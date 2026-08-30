<?php
namespace EMS\Admin;

class Audit_Log_Controller {

	public function register_routes(): void {
		register_rest_route(
			'ems/v1',
			'/audit-logs',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_audit_logs' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	public function get_audit_logs( \WP_REST_Request $request ) {
		if ( ! $this->check_permission() ) {
			return new \WP_Error(
				'ems_forbidden',
				'You do not have permission to access audit logs.',
				array( 'status' => 403 )
			);
		}

		global $wpdb;
		$table = $wpdb->prefix . 'ems_audit_logs';

		$action          = $request->get_param( 'action' );
		$user_id         = $request->get_param( 'user_id' );
		$target_scout_id = $request->get_param( 'target_scout_id' );
		$start_date      = $request->get_param( 'start_date' );
		$end_date        = $request->get_param( 'end_date' );

		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ?: 20 ) );
		$offset   = ( $page - 1 ) * $per_page;

		$where = array( '1=1' );
		$args  = array();

		if ( ! empty( $action ) ) {
			$where[] = 'action = %s';
			$args[]  = sanitize_text_field( $action );
		}

		if ( ! empty( $user_id ) ) {
			$where[] = 'user_id = %d';
			$args[]  = (int) $user_id;
		}

		if ( ! empty( $target_scout_id ) ) {
			$where[] = 'target_scout_id = %d';
			$args[]  = (int) $target_scout_id;
		}

		if ( ! empty( $start_date ) ) {
			$where[] = 'timestamp >= %s';
			$args[]  = sanitize_text_field( $start_date ) . ' 00:00:00';
		}

		if ( ! empty( $end_date ) ) {
			$where[] = 'timestamp <= %s';
			$args[]  = sanitize_text_field( $end_date ) . ' 23:59:59';
		}

		$where_clause = implode( ' AND ', $where );

		// 1. Get total matching records
		$count_query = "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}";
		if ( ! empty( $args ) ) {
			$count_query = $wpdb->prepare( $count_query, ...$args );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared safely above
		$total_items = (int) $wpdb->get_var( $count_query );

		// 2. Get paginated items
		$results_query = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY id DESC";
		if ( ! empty( $args ) ) {
			$results_query = $wpdb->prepare( $results_query, ...$args );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared safely above
		$results_query .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $per_page, $offset );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared safely above
		$items = $wpdb->get_results( $results_query, ARRAY_A ) ?: array();

		$response = new \WP_REST_Response( $items, 200 );
		$response->header( 'X-WP-Total', (string) $total_items );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $total_items / $per_page ) );

		return $response;
	}
}
