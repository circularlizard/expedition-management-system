<?php
namespace EMS;

use EMS\Admin\Admin_Page;
use EMS\Admin\Diagnostic_Panel;
use EMS\Admin\Settings_Page;
use EMS\Admin\Training_Report_Page;
use EMS\Core\CPT_Registry;
use EMS\Core\Table_Installer;
use EMS\Integrations\Drivers\Live_Driver;
use EMS\Integrations\Drivers\Mock_Driver;
use EMS\Integrations\OSM_API_Client;
use EMS\Integrations\OSM_Parser;
use EMS\Integrations\Rate_Limiter;
use EMS\Integrations\TutorLMS_Client;

class Plugin {
	private CPT_Registry $cpt_registry;

	public function __construct() {
		$this->cpt_registry = new CPT_Registry();
		$this->init_hooks();
		add_shortcode( 'ems-volunteer-signup', array( $this, 'render_volunteer_signup_shortcode' ) );
		add_shortcode( 'ems-portal', array( $this, 'render_portal_shortcode' ) );
		add_shortcode( 'ems_signup_banner', array( $this, 'render_signup_banner_shortcode' ) );
	}

	private function init_hooks(): void {
		add_action( 'init', array( $this->cpt_registry, 'register' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_and_enqueue_frontend_assets' ) );
		add_action( 'init', array( new \EMS\Core\Role_Manager(), 'register_roles' ) );
		add_action( 'ems_daily_log_cleanup', array( '\\EMS\\Core\\Log_Rotator', 'purge_old_logs' ) );

		add_action( 'wp_login', function( $user_login, $user ) {
			$u_id = ( $user instanceof \WP_User ) ? $user->ID : null;
			\EMS\Core\Audit_Logger::log( 'login_success', null, $u_id );
		}, 10, 2 );

		add_action( 'wp_login_failed', function( $username ) {
			\EMS\Core\Audit_Logger::log( 'login_failure' );
		} );

		static $logout_user_id = 0;

		add_action( 'clear_auth_cookie', function() use ( &$logout_user_id ) {
			$logout_user_id = get_current_user_id();
		} );

		add_action( 'wp_logout', function() use ( &$logout_user_id ) {
			\EMS\Core\Audit_Logger::log( 'logout', null, $logout_user_id ?: null );
			$logout_user_id = 0;
		} );

		$admin_page = new Admin_Page(
			new Diagnostic_Panel()
		);
		add_action( 'admin_menu', array( $admin_page, 'register' ), 10 );
		add_action( 'admin_menu', array( $admin_page, 'register_explorers_menu' ), 11 );
		add_action( 'admin_menu', array( $admin_page, 'register_volunteers_menu' ), 11 );

		add_action( 'admin_menu', array( $admin_page, 'register_reference_menu' ), 12 );

		$report_page = new Training_Report_Page( new TutorLMS_Client() );
		add_action( 'admin_menu', array( $report_page, 'register' ), 14 );

		add_action( 'admin_menu', array( $admin_page, 'register_mapper_menu' ), 16 );

		$api_mode   = get_option( 'ems_api_mode', 'mock' );
		$driver     = ( $api_mode === 'live' ) ? new Live_Driver() : new Mock_Driver();
		$osm_client = new OSM_API_Client( $driver, new OSM_Parser(), new Rate_Limiter( 10, 1.0 ) );

		new \EMS\Integrations\OIDC_Login_Handler(
			$osm_client,
			new OSM_Parser(),
			new \EMS\Data\OSM_Explorer_Repository()
		);

		$fluent_sync = new \EMS\Integrations\Fluent_Forms_Sync();
		$fluent_sync->init_hooks();

		new \EMS\Core\Access_Control_Guard();

		$settings_page = new Settings_Page();
		add_action( 'admin_menu', array( $settings_page, 'register' ), 18 );

		// REST API
		add_action(
			'rest_api_init',
			function () use ( $osm_client ) {
				$flexi_controller = new \EMS\Admin\Flexi_Mapper_Controller(
					$osm_client,
					new \EMS\Integrations\Flexi_Structure_Parser(),
					new \EMS\Integrations\Flexi_Column_Map(),
					new \EMS\Integrations\Flexi_Record_Importer(
						new \EMS\Integrations\Flexi_Column_Map(),
						new \EMS\Data\Expedition_Repository(),
						new \EMS\Data\Team_Repository(),
						new \EMS\Data\Team_Member_Repository()
					)
				);
				$flexi_controller->register_routes();

				$sync_preview_controller = new \EMS\Admin\Sync_Preview_Controller( $osm_client );
				$sync_preview_controller->register_routes();

				$view_controller = new \EMS\Admin\Admin_View_Controller(
					new \EMS\Data\Expedition_Repository(),
					new \EMS\Data\Team_Repository(),
					new \EMS\Data\Team_Member_Repository(),
					new \EMS\Integrations\TutorLMS_Client()
				);
				$view_controller->register_routes();

				$expedition_controller = new \EMS\Admin\Expedition_Admin_Controller(
					new \EMS\Data\Season_Repository(),
					new \EMS\Data\Expedition_Repository(),
					new \EMS\Data\Team_Repository(),
					new \EMS\Data\Team_Member_Repository(),
					null,
					null,
					null,
					new \EMS\Data\Signup_Repository()
				);
				$expedition_controller->register_routes();

				$unit_leader_controller = new \EMS\Admin\Unit_Leader_Controller();
				$unit_leader_controller->register_routes();

				$volunteer_controller = new \EMS\Admin\Volunteer_Controller();
				$volunteer_controller->register_routes();

				$portal_controller = new \EMS\Admin\Portal_Controller();
				$portal_controller->register_routes();

				$audit_controller = new \EMS\Admin\Audit_Log_Controller();
				$audit_controller->register_routes();

				register_rest_route(
					'ems/v1',
					'/sync-status',
					array(
						'methods'             => 'GET',
						'callback'            => function () {
							$status = get_transient( 'ems_sync_status' ) ?: array( 'state' => 'idle' );
							$result = get_transient( 'ems_last_sync_result' ) ?: array();
							$state  = $status['state'] ?? 'idle';
							return rest_ensure_response(
								array(
									'state'            => $state,
									'queued_at'        => $status['queued_at'] ?? null,
									'started_at'       => $status['started_at'] ?? null,
									'completed_at'     => $status['completed_at'] ?? null,
									'members_upserted' => $state === 'running'
										? (int) ( $status['members_upserted'] ?? 0 )
										: (int) ( $result['members_upserted'] ?? 0 ),
									'members_failed'   => (int) ( $result['members_failed'] ?? 0 ),
									'events_upserted'  => $state === 'running'
										? (int) ( $status['events_upserted'] ?? 0 )
										: (int) ( $result['events_upserted'] ?? 0 ),
									'events_failed'    => (int) ( $result['events_failed'] ?? 0 ),
									'error_count'      => count( $result['errors'] ?? array() ),
								)
							);
						},
						'permission_callback' => function () {
							return current_user_can( 'manage_options' );
						},
					)
				);
			}
		);

		// Settings page "Fetch sections from OSM" handler — mock mode only for now (live deferred to 1.10 OAuth callback)
		add_action(
			'admin_post_ems_fetch_sections',
			function () {
				if ( ! current_user_can( 'manage_options' ) ) {
					wp_safe_redirect( admin_url( 'admin.php?page=ems-settings&tab=sections&error=forbidden' ) );
					exit;
				}
				check_admin_referer( 'ems_fetch_sections' );

				$api_mode = get_option( 'ems_api_mode', 'mock' );
				$parser   = new OSM_Parser();

				if ( in_array( $api_mode, array( 'live', 'live-auth-only', 'live-limited' ), true ) ) {
					$handler = new \EMS\Admin\OSM_Sync_Auth_Handler();
					$handler->initiate(
						function ( string $token ) use ( $parser ) {
							$driver     = new Live_Driver();
							$osm_client = new OSM_API_Client( $driver, $parser, new Rate_Limiter( 500, 0.1 ) );
							$osm_client->set_access_token( $token );
							$payload = $osm_client->get_data_payload();
							set_transient( 'ems_available_sections', $parser->parse_section_names( $payload ), HOUR_IN_SECONDS );
							wp_safe_redirect( admin_url( 'admin.php?page=ems-settings&tab=sections&fetched=1' ) );
						},
						'fetch_sections'
					);
				} else {
					$driver     = new Mock_Driver();
					$osm_client = new OSM_API_Client( $driver, $parser, new Rate_Limiter( 10, 1.0 ) );
					$payload    = $osm_client->get_data_payload();
					set_transient( 'ems_available_sections', $parser->parse_section_names( $payload ), HOUR_IN_SECONDS );
					wp_safe_redirect( admin_url( 'admin.php?page=ems-settings&tab=sections&fetched=1' ) );
				}
				exit;
			}
		);

		// OSM Pushback authentication handler
		add_action(
			'admin_post_ems_pushback_auth',
			function () {
				if ( ! current_user_can( 'manage_options' ) ) {
					wp_safe_redirect( admin_url( 'admin.php?page=ems-reference&tab=pushback&error=forbidden' ) );
					exit;
				}
				check_admin_referer( 'ems_pushback_auth' );
				( new \EMS\Admin\OSM_Sync_Auth_Handler() )->initiate( null, 'pushback' );
				exit;
			}
		);

		// OSM Reference page "Sync from OSM" form handler
		add_action(
			'admin_post_ems_sync_osm',
			function () {
				if ( ! current_user_can( 'manage_options' ) ) {
					wp_safe_redirect( admin_url( 'admin.php?page=ems-reference&error=forbidden' ) );
					exit;
				}
				check_admin_referer( 'ems_sync_osm' );

				$api_mode = get_option( 'ems_api_mode', 'mock' );

				if ( in_array( $api_mode, array( 'live', 'live-auth-only', 'live-limited' ), true ) ) {
					( new \EMS\Admin\OSM_Sync_Auth_Handler() )->initiate(
						function ( string $token ) use ( $api_mode ) {
							$parser     = new OSM_Parser();
							$driver     = new Live_Driver();
							$osm_client = new OSM_API_Client( $driver, $parser, new Rate_Limiter( 500, 0.1 ) );
							$osm_client->set_access_token( $token );
							$payload          = $osm_client->get_data_payload();
							$section_ids      = $parser->parse_section_ids( $payload );
							$managed_sections = (array) get_option( 'ems_managed_sections', array() );
							$managed_ids      = array_map( 'intval', array_keys( $managed_sections ) );
							$all_ids          = array_unique( array_merge( $section_ids, $managed_ids ) );
							$member_limit     = ( $api_mode === 'live-limited' ) ? max( 1, (int) get_option( 'ems_sync_limit', 5 ) ) : 0;
							$sync_ids         = ( $api_mode === 'live-limited' ) ? array_slice( $managed_ids ?: $all_ids, 0, 1 ) : ( $managed_ids ?: $all_ids );
							set_transient(
								'ems_pending_sync_job',
								array(
									'token'        => $token,
									'payload'      => $payload,
									'sync_ids'     => $sync_ids,
									'api_mode'     => $api_mode,
									'member_limit' => $member_limit,
								),
								5 * MINUTE_IN_SECONDS
							);
							set_transient(
								'ems_sync_status',
								array(
									'state'     => 'queued',
									'queued_at' => time(),
								),
								HOUR_IN_SECONDS
							);
							wp_schedule_single_event( time(), 'ems_run_osm_sync' );
							spawn_cron();
							wp_safe_redirect( admin_url( 'admin.php?page=ems-reference&sync=running' ) );
						}
					);
				} else {
					$parser     = new OSM_Parser();
					$driver     = new Mock_Driver();
					$logger     = new \EMS\Integrations\OSM_Sync_Logger();
					$osm_client = new OSM_API_Client( $driver, $parser, new Rate_Limiter( 10, 1.0 ), $logger );

					$payload     = $osm_client->get_data_payload();
					$section_ids = $parser->parse_section_ids( $payload );

					set_transient( 'ems_last_payload_dump', $payload, HOUR_IN_SECONDS );
					set_transient( 'ems_available_sections', $parser->parse_section_names( $payload ), HOUR_IN_SECONDS );

					$managed_sections = (array) get_option( 'ems_managed_sections', array() );
					$managed_ids      = array_map( 'intval', array_keys( $managed_sections ) );
					$all_ids          = array_unique( array_merge( $section_ids, $managed_ids ) );

					delete_transient( 'ems_sync_status' );

					( new \EMS\Integrations\OSM_Reference_Sync( $osm_client, $parser ) )
					->sync( $all_ids, $payload, $api_mode, 0, $logger );

					wp_safe_redirect( admin_url( 'admin.php?page=ems-reference&sync=success' ) );
				}
				exit;
			}
		);

		// OSM OAuth Callback — stores job, redirects immediately, cron runs sync
		add_action(
			'admin_post_ems_osm_callback',
			function () {
				$handler = new \EMS\Admin\OSM_Sync_Auth_Handler();
				$handler->handle_callback(
					function ( string $token, string $mode = 'sync' ) {
						if ( $mode === 'pushback' ) {
							return;
						}
						if ( $mode === 'fetch_sections' ) {
							$parser     = new OSM_Parser();
							$driver     = new Live_Driver();
							$osm_client = new OSM_API_Client( $driver, $parser, new Rate_Limiter( 500, 0.1 ) );
							$osm_client->set_access_token( $token );
							try {
								$payload = $osm_client->get_data_payload();
								set_transient( 'ems_available_sections', $parser->parse_section_names( $payload ), HOUR_IN_SECONDS );
							} catch ( \Exception $e ) {
								error_log( '[EMS] Fetch sections callback failed: ' . $e->getMessage() );
							}
							return;
						}
						$api_mode   = get_option( 'ems_api_mode', 'mock' );
						$parser     = new OSM_Parser();
						$driver     = new Live_Driver();
						$osm_client = new OSM_API_Client( $driver, $parser, new Rate_Limiter( 500, 0.1 ) );
						$osm_client->set_access_token( $token );

						try {
								$payload = $osm_client->get_data_payload();
						} catch ( \EMS\Integrations\Exceptions\Api_Response_Exception $e ) {
							error_log( '[EMS] getDataPayload failed: ' . $e->getMessage() );
							wp_safe_redirect( admin_url( 'admin.php?page=ems-reference&error=payload_failed&error_msg=' . rawurlencode( substr( $e->getMessage(), 0, 100 ) ) ) );
							return;
						} catch ( \EMS\Integrations\Exceptions\Api_Blocked_Exception $e ) {
							update_option( 'ems_api_blocked', true );
							wp_safe_redirect( admin_url( 'admin.php?page=ems-reference&error=api_blocked' ) );
							return;
						}

						set_transient( 'ems_last_payload_dump', $payload, HOUR_IN_SECONDS );
						set_transient( 'ems_available_sections', $parser->parse_section_names( $payload ), HOUR_IN_SECONDS );

						if ( $api_mode === 'live-auth-only' ) {
							( new \EMS\Integrations\OSM_Sync_Logger() )->persist();
							wp_safe_redirect( admin_url( 'admin.php?page=ems-reference&sync=auth_only' ) );
							return;
						}

						$section_ids      = $parser->parse_section_ids( $payload );
						$managed_sections = (array) get_option( 'ems_managed_sections', array() );
						$managed_ids      = array_map( 'intval', array_keys( $managed_sections ) );
						$all_ids          = array_unique( array_merge( $section_ids, $managed_ids ) );

						$member_limit = ( $api_mode === 'live-limited' )
						? max( 1, (int) get_option( 'ems_sync_limit', 5 ) )
						: 0;

						$sync_ids = ( $api_mode === 'live-limited' )
						? array_slice( $managed_ids ?: $all_ids, 0, 1 )
						: ( $managed_ids ?: $all_ids );

						set_transient(
							'ems_pending_sync_job',
							array(
								'token'        => $token,
								'payload'      => $payload,
								'sync_ids'     => $sync_ids,
								'api_mode'     => $api_mode,
								'member_limit' => $member_limit,
							),
							5 * MINUTE_IN_SECONDS
						);

						set_transient(
							'ems_sync_status',
							array(
								'state'     => 'queued',
								'queued_at' => time(),
							),
							HOUR_IN_SECONDS
						);

						wp_schedule_single_event( time(), 'ems_run_osm_sync' );
						spawn_cron();

						wp_safe_redirect( admin_url( 'admin.php?page=ems-reference&sync=running' ) );
					}
				);
			}
		);

		// Background cron: run the actual sync job
		add_action(
			'ems_run_osm_sync',
			function () {
				$job = get_transient( 'ems_pending_sync_job' );
				if ( empty( $job ) ) {
					return;
				}
				delete_transient( 'ems_pending_sync_job' );

				set_transient(
					'ems_sync_status',
					array(
						'state'      => 'running',
						'started_at' => gmdate( 'c' ),
					),
					HOUR_IN_SECONDS
				);

				$parser     = new OSM_Parser();
				$driver     = new Live_Driver();
				$logger     = new \EMS\Integrations\OSM_Sync_Logger();
				$osm_client = new OSM_API_Client( $driver, $parser, new Rate_Limiter( 500, 0.1 ), $logger );
				$osm_client->set_access_token( $job['token'] );

				\EMS\Core\Audit_Logger::log( 'sync_start' );

				$result = ( new \EMS\Integrations\OSM_Reference_Sync( $osm_client, $parser ) )
				->sync( $job['sync_ids'], $job['payload'], $job['api_mode'], $job['member_limit'], $logger );

				if ( ! empty( $result->errors ) ) {
					\EMS\Core\Audit_Logger::log( 'sync_failure' );
				} else {
					\EMS\Core\Audit_Logger::log( 'sync_success' );
				}

				delete_transient( 'ems_sync_status' );
			}
		);

		// Cancel a stuck/queued sync
		add_action(
			'admin_post_ems_cancel_sync',
			function () {
				if ( ! current_user_can( 'manage_options' ) ) {
					wp_die( 'Forbidden' );
				}
				check_admin_referer( 'ems_cancel_sync' );
				delete_transient( 'ems_sync_status' );
				delete_transient( 'ems_pending_sync_job' );
				wp_safe_redirect( admin_url( 'admin.php?page=ems-reference' ) );
				exit;
			}
		);

		// Clear API blocked flag
		add_action(
			'admin_post_ems_clear_api_block',
			function () {
				if ( ! current_user_can( 'manage_options' ) ) {
					wp_die( 'Forbidden' );
				}
				check_admin_referer( 'ems_clear_api_block' );
				delete_option( 'ems_api_blocked' );
				wp_safe_redirect( admin_url( 'admin.php?page=ems-reference&block_cleared=1' ) );
				exit;
			}
		);

		// Purge OSM reference data
		add_action(
			'admin_post_ems_purge_osm_data',
			function () {
				if ( ! current_user_can( 'manage_options' ) ) {
					wp_die( 'Forbidden' );
				}
				check_admin_referer( 'ems_purge_osm_data' );

				if ( empty( $_POST['ems_purge_phrase'] ) || sanitize_text_field( wp_unslash( $_POST['ems_purge_phrase'] ) ) !== 'PURGE SYSTEM' ) {
					wp_die( 'Error: Invalid confirmation phrase.' );
				}

				global $wpdb;
				$tables = array(
					'ems_team_members',
					'ems_volunteer_availability',
					'ems_route_submissions',
					'ems_osm_explorers',
					'ems_osm_events',
					'ems_osm_event_attendance',
					'ems_units',
					'ems_volunteers',
					'ems_participant_signups',
					'ems_expedition_signups',
					'ems_audit_logs',
				);
				foreach ( $tables as $table ) {
					$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
				}

				$posts = get_posts(
					array(
						'post_type'   => array( 'team', 'expedition' ),
						'post_status' => 'any',
						'numberposts' => -1,
						'fields'      => 'ids',
					)
				);
				foreach ( $posts as $post_id ) {
					wp_delete_post( $post_id, true );
				}

				delete_transient( 'ems_last_sync_result' );
				delete_transient( 'ems_last_sync_log' );
				delete_transient( 'ems_last_payload_dump' );
				delete_transient( 'ems_available_sections' );
				delete_transient( 'ems_sync_status' );
				delete_transient( 'ems_pending_sync_job' );

				wp_safe_redirect( admin_url( 'admin.php?page=ems-settings&tab=general&purged=1' ) );
				exit;
			}
		);

		// Seed test data
		add_action(
			'admin_post_ems_seed_test_data',
			function () {
				if ( ! current_user_can( 'manage_options' ) ) {
					wp_die( 'Forbidden' );
				}
				check_admin_referer( 'ems_seed_test_data' );

				try {
					$seeder  = new \EMS\Data\Database_Seeder();
					$results = $seeder->seed();

					wp_safe_redirect( admin_url( 'admin.php?page=ems-settings&tab=general&seeded=1&p_count=' . $results['participant_count'] . '&e_count=' . $results['expedition_count'] ) );
					exit;
				} catch ( \Exception $e ) {
					wp_safe_redirect( admin_url( 'admin.php?page=ems-settings&tab=general&seed_error=' . urlencode( $e->getMessage() ) ) );
					exit;
				}
			}
		);

		// Sync log download handler
		add_action(
			'admin_post_ems_download_sync_log',
			function () {
				if ( ! current_user_can( 'manage_options' ) ) {
					wp_die( 'Forbidden' );
				}
				check_admin_referer( 'ems_download_sync_log' );

				$log  = get_transient( 'ems_last_sync_log' );
				$json = wp_json_encode( $log ?? array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
				$name = 'ems-sync-log-' . gmdate( 'Ymd-His' ) . '.json';

				header( 'Content-Type: application/json; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename="' . $name . '"' );
				header( 'Content-Length: ' . strlen( $json ) );
				echo $json; // phpcs:ignore WordPress.Security.EscapeOutput
				exit;
			}
		);

		// Clear cached OSM token on WP logout
		add_action(
			'wp_logout',
			function () {
				( new \EMS\Admin\OSM_Sync_Auth_Handler() )->clear_cached_token();
			}
		);

		// Support for ES Modules in Admin
		add_filter(
			'script_loader_tag',
			function ( $tag, $handle, $src ) {
				if ( str_starts_with( $handle, 'ems-' ) ) {
					return sprintf(
						'<script type="module" src="%s" id="%s-js"></script>',
						esc_url( $src ),
						esc_attr( $handle )
					);
				}
				return $tag;
			},
			10,
			3
		);
	}

	public static function activate(): void {
		( new Table_Installer() )->install();
		( new \EMS\Core\Role_Manager() )->register_roles();
		\EMS\Core\Log_Rotator::register_cron();
		update_option( 'ems_db_version', EMS_VERSION );
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'ems_db_version' ) !== EMS_VERSION ) {
			( new Table_Installer() )->install();
			( new \EMS\Core\Role_Manager() )->register_roles();
			\EMS\Core\Log_Rotator::register_cron();
			update_option( 'ems_db_version', EMS_VERSION );
		}
	}

	public function register_and_enqueue_frontend_assets(): void {
		$css_path     = plugin_dir_url( EMS_PLUGIN_FILE ) . 'assets/js/ems-admin.css';
		$volunteer_js = plugin_dir_url( EMS_PLUGIN_FILE ) . 'assets/js/volunteer-signup.js';
		$portal_js    = plugin_dir_url( EMS_PLUGIN_FILE ) . 'assets/js/ems-portal.js';

		wp_register_style( 'ems-admin', $css_path, array( 'wp-components' ), EMS_VERSION );
		wp_register_script(
			'ems-volunteer-signup',
			$volunteer_js,
			array( 'wp-element', 'wp-i18n' ),
			EMS_VERSION,
			true
		);
		wp_register_script(
			'ems-portal',
			$portal_js,
			array( 'wp-element', 'wp-i18n' ),
			EMS_VERSION,
			true
		);

		global $post;
		if ( is_a( $post, 'WP_Post' ) ) {
			if ( has_shortcode( $post->post_content, 'ems-volunteer-signup' ) ) {
				wp_enqueue_style( 'wp-components' );
				wp_enqueue_style( 'ems-admin' );
				wp_enqueue_script( 'ems-volunteer-signup' );
			}
			if ( has_shortcode( $post->post_content, 'ems-portal' ) ) {
				wp_enqueue_style( 'wp-components' );
				wp_enqueue_style( 'ems-admin' );
				wp_enqueue_script( 'ems-portal' );
			}
		}
	}

	public function render_volunteer_signup_shortcode(): string {
		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( 'ems-admin' );
		wp_enqueue_script( 'ems-volunteer-signup' );

		$current_user = wp_get_current_user();
		$user_data    = array(
			'logged_in'  => $current_user->exists(),
			'first_name' => $current_user->exists() ? ( $current_user->user_firstname ?: $current_user->display_name ) : '',
			'last_name'  => $current_user->exists() ? $current_user->user_lastname : '',
			'email'      => $current_user->exists() ? $current_user->user_email : '',
			'is_osm'     => $current_user->exists() && ! empty( get_user_meta( $current_user->ID, 'ems_access_type', true ) ),
		);

		// Determine current page URL for redirecting back after login
		$current_url = home_url( add_query_arg( array(), $GLOBALS['wp']->request ?? '' ) );

		wp_localize_script(
			'ems-volunteer-signup',
			'emsVolunteerSignup',
			array(
				'root_url'  => get_rest_url( null, 'ems/v1' ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'login_url' => wp_login_url( $current_url ),
				'user_data' => $user_data,
			)
		);

		return '<div id="ems-volunteer-signup-root"></div>';
	}

	public function render_portal_shortcode(): string {
		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( 'ems-admin' );
		wp_enqueue_script( 'ems-portal' );

		$current_user = wp_get_current_user();
		$user_data    = array(
			'logged_in'   => $current_user->exists(),
			'first_name'  => $current_user->exists() ? ( $current_user->user_firstname ?: $current_user->display_name ) : '',
			'last_name'   => $current_user->exists() ? $current_user->user_lastname : '',
			'email'       => $current_user->exists() ? $current_user->user_email : '',
			'access_type' => $current_user->exists() ? get_user_meta( $current_user->ID, 'ems_access_type', true ) : '',
		);

		$current_url = home_url( add_query_arg( array(), $GLOBALS['wp']->request ?? '' ) );

		wp_localize_script(
			'ems-portal',
			'emsPortal',
			array(
				'root_url'  => get_rest_url( null, 'ems/v1' ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'login_url' => wp_login_url( $current_url ),
				'user_data' => $user_data,
			)
		);

		return '<div id="ems-portal-root"></div>';
	}

	public function render_signup_banner_shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'form_id'     => '6',
				'type'        => 'participant',
				'scout_field' => 'signup_child',
				'unit_field'  => 'signup_unit',
			),
			$atts,
			'ems_signup_banner'
		);

		$form_id     = (int) $atts['form_id'];
		$type        = sanitize_text_field( $atts['type'] );
		$scout_field = sanitize_text_field( $atts['scout_field'] );
		$unit_field  = sanitize_text_field( $atts['unit_field'] );
		$first_name_field = 'signup_child_name';

		if ( in_array( $type, array( 'participant', 'expedition' ), true ) ) {
			$existing_id       = (int) get_option( "ems_fluent_{$type}_form_id" );
			$existing_mappings = get_option( "ems_{$type}_form_mappings", array() );
			$first_name_field  = $existing_mappings['first_name_field'] ?? 'signup_child_name';
			$new_mappings      = array_merge(
				$existing_mappings,
				array(
					'scout_id_field'   => $scout_field,
					'esu_patrol_field' => $unit_field,
				)
			);

			if ( $existing_id !== $form_id ) {
				update_option( "ems_fluent_{$type}_form_id", $form_id );
			}
			if ( $existing_mappings !== $new_mappings ) {
				update_option( "ems_{$type}_form_mappings", $new_mappings );
			}
		}

		wp_enqueue_style( 'ems-admin' );

		if ( is_user_logged_in() ) {
			return '<style>.ff-el-group:has([name^="' . esc_attr( $first_name_field ) . '"]) { display: none !important; }</style>';
		}

		$style = '<style>.ff-el-group:has(select[name="' . esc_attr( $scout_field ) . '"]), .ff-el-group:has([name="' . esc_attr( $scout_field ) . '"]) { display: none !important; }</style>';
		$login_url = esc_url( wp_login_url( get_permalink() ) );
		$logo_url  = esc_url( site_url( '/wp-content/uploads/2026/02/osm-logo-wo.webp' ) );

		return $style . '<div class="ems-login-banner ems-card ems-p-20 ems-mb-24" style="border: 2px solid #5c2d8b; background-color: #f7f3fc; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; gap: 20px; flex-wrap: wrap;">' .
			'<div class="ems-login-banner__text" style="flex: 1; min-width: 280px;">' .
			'<h4 class="ems-m-0" style="margin: 0 0 6px 0; color: #411f62; font-size: 18px; font-weight: 700;">' . esc_html__( 'Speed up your DofE registration', 'ems-plugin' ) . '</h4>' .
			'<p class="ems-meta-text ems-m-0 ems-small-text" style="margin: 0; color: #50575e; font-size: 14px; line-height: 1.4;">' . esc_html__( "Log in with Online Scout Manager to auto-fill your child's details and skip email confirmation.", 'ems-plugin' ) . '</p>' .
			'</div>' .
			'<div class="oauth-login-button-container" style="flex-shrink: 0;">' .
			'<a class="oauth-login-button oauth-login-button--osm" href="' . $login_url . '" style="background-color:#411f62;color:#fff;border:solid #5c2d8b;border-width:1px 1px calc(1px + 1px);border-radius:4px;padding:10px 15px;font-size:16px;display:inline-flex;align-items:center;text-decoration:none;gap:10px;">' .
			'<img class="oauth-login-button__icon" src="' . $logo_url . '" alt="OSM" style="height:24px;width:auto;vertical-align:middle;display:inline-block;">' .
			'<span class="oauth-login-button__text" style="font-weight:600;">' . esc_html__( 'Login with OSM', 'ems-plugin' ) . '</span>' .
			'</a>' .
			'</div>' .
			'</div>';
	}
}

