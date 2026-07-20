<?php
namespace EMS\Admin;

use EMS\Integrations\Pushback_Sync_Manager;
use EMS\Integrations\OSM_API_Client;

class Sync_Preview_Controller {

	private OSM_API_Client $api_client;
	private Pushback_Sync_Manager $sync_manager;

	public function __construct( OSM_API_Client $api_client, ?Pushback_Sync_Manager $sync_manager = null ) {
		$this->api_client   = $api_client;
		$this->sync_manager = $sync_manager ?? new Pushback_Sync_Manager( $api_client );
	}

	public function register_routes(): void {
		register_rest_route(
			'ems/v1/admin',
			'/sync-preview',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_sync_preview' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'ems/v1/admin',
			'/sync-push',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'execute_sync_push' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'ems/v1/admin',
			'/sync-status',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_sync_status' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'clear_sync_status' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
	}

	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	public function get_sync_preview( \WP_REST_Request $request ) {
		if ( ! $this->check_permission() ) {
			return new \WP_Error(
				'ems_forbidden',
				'You do not have permission to view sync preview.',
				array( 'status' => 403 )
			);
		}

		$section_id = (int) $request->get_param( 'section_id' );
		if ( ! $section_id ) {
			return new \WP_Error(
				'ems_missing_parameter',
				'Missing required parameter: section_id',
				array( 'status' => 400 )
			);
		}

		$token = $request->get_param( 'access_token' );
		if ( $token ) {
			$this->api_client->set_access_token( $token );
		}

		$preview = $this->sync_manager->get_preview( $section_id );

		return new \WP_REST_Response( $preview, 200 );
	}

	public function execute_sync_push( \WP_REST_Request $request ) {
		if ( ! $this->check_permission() ) {
			return new \WP_Error(
				'ems_forbidden',
				'You do not have permission to execute sync push.',
				array( 'status' => 403 )
			);
		}

		$section_id = (int) $request->get_param( 'section_id' );
		if ( ! $section_id ) {
			return new \WP_Error(
				'ems_missing_parameter',
				'Missing required parameter: section_id',
				array( 'status' => 400 )
			);
		}

		$token = $request->get_param( 'access_token' );
		if ( $token ) {
			$this->api_client->set_access_token( $token );
		}

		if ( get_transient( 'ems_pushback_sync_lock' ) ) {
			return new \WP_Error(
				'ems_sync_locked',
				'A push-back sync is already in progress. Please wait.',
				array( 'status' => 409 )
			);
		}
		set_transient( 'ems_pushback_sync_lock', true, 300 );

		try {
			$result = $this->sync_manager->execute_sync( $section_id );
			delete_option( 'ems_failed_pushback_queue' );

			return new \WP_REST_Response(
				array(
					'success' => true,
					'message' => sprintf(
						'Push-back sync completed. Updated %d flexi-record fields and invited %d members.',
						$result['flexi_updates_count'],
						$result['event_invites_count']
					),
				),
				200
			);
		} catch ( \Exception $e ) {
			$unsynced = 0;
			try {
				$preview = $this->sync_manager->get_preview( $section_id );
				$flexi_count = isset( $preview['flexi_record']['updates'] ) ? count( $preview['flexi_record']['updates'] ) : 0;
				$event_count = 0;
				if ( isset( $preview['events'] ) ) {
					foreach ( $preview['events'] as $ev ) {
						foreach ( $ev['proposed_invites'] as $inv ) {
							if ( $inv['action'] === 'Invite' ) {
								$event_count++;
							}
						}
					}
				}
				$unsynced = $flexi_count + $event_count;
			} catch ( \Exception $prev_e ) {
				$unsynced = 0;
			}

			update_option( 'ems_failed_pushback_queue', array(
				'last_failed_at' => current_time( 'mysql' ),
				'error_message'  => $e->getMessage(),
				'unsynced_items' => $unsynced,
			) );

			return new \WP_Error(
				'ems_sync_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		} finally {
			delete_transient( 'ems_pushback_sync_lock' );
		}
	}

	public function get_sync_status( \WP_REST_Request $request ) {
		if ( ! $this->check_permission() ) {
			return new \WP_Error(
				'ems_forbidden',
				'You do not have permission to view sync status.',
				array( 'status' => 403 )
			);
		}

		$queue = get_option( 'ems_failed_pushback_queue', null );
		$locked = (bool) get_transient( 'ems_pushback_sync_lock' );

		return new \WP_REST_Response(
			array(
				'locked' => $locked,
				'failed_queue' => $queue,
			),
			200
		);
	}

	public function clear_sync_status( \WP_REST_Request $request ) {
		if ( ! $this->check_permission() ) {
			return new \WP_Error(
				'ems_forbidden',
				'You do not have permission to clear sync status.',
				array( 'status' => 403 )
			);
		}

		delete_option( 'ems_failed_pushback_queue' );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'message' => 'Sync error log cleared successfully.',
			),
			200
		);
	}
}
