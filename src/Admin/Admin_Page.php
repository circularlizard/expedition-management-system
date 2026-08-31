<?php
namespace EMS\Admin;

class Admin_Page {
	private Diagnostic_Panel $diagnostic;

	public function __construct( Diagnostic_Panel $diagnostic ) {
		$this->diagnostic = $diagnostic;
	}

	public function register(): void {
		add_action( 'admin_footer', array( $this, 'render_build_timestamp' ) );
		add_action( 'admin_init', array( $this, 'maybe_handle_export' ) );

		add_menu_page(
			'EMS',
			'EMS',
			'manage_options',
			'ems',
			array( $this, 'render_signups_page' ),
			'dashicons-location-alt',
			5
		);

		$signups_hook = add_submenu_page(
			'ems',
			__( 'Signups', 'ems-plugin' ),
			__( 'Signups', 'ems-plugin' ),
			'manage_options',
			'ems',
			array( $this, 'render_signups_page' )
		);

		$dashboard_hook = add_submenu_page(
			'ems',
			__( 'Expeditions', 'ems-plugin' ),
			__( 'Expeditions', 'ems-plugin' ),
			'manage_options',
			'ems-expeditions',
			array( $this, 'render_dashboard' )
		);

		add_action(
			'admin_enqueue_scripts',
			function ( $hook ) use ( $dashboard_hook, $signups_hook ) {
				if ( $hook === $dashboard_hook ) {
					$this->enqueue_dashboard_assets();
				}
				if ( $hook === $signups_hook ) {
					$this->enqueue_signups_assets();
				}
			}
		);
	}

	/**
	 * Registers the Explorer List submenu.
	 */
	public function register_explorers_menu(): void {
		$explorers_hook = add_submenu_page(
			'ems',
			__( 'Explorers', 'ems-plugin' ),
			__( 'Explorers', 'ems-plugin' ),
			'manage_options',
			'ems-explorers',
			array( $this, 'render_explorers_page' )
		);

		add_action(
			'admin_enqueue_scripts',
			function ( $hook ) use ( $explorers_hook ) {
				if ( $hook === $explorers_hook ) {
					$this->enqueue_dashboard_assets();
				}
			}
		);
	}

	private function enqueue_signups_assets(): void {
		$this->enqueue_admin_script( 'ems-signups-board', 'assets/js/signups-board.js' );
		$this->enqueue_admin_styles();
		wp_localize_script(
			'ems-signups-board',
			'emsSignupsBoard',
			array(
				'root_url' => get_rest_url( null, 'ems/v1' ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	public function render_explorers_page(): void {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Explorer List', 'ems-plugin' ) . '</h1>';
		echo '<div id="ems-explorers-root"></div>';
		echo '</div>';
	}

	public function render_signups_page(): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Signups', 'ems-plugin' ); ?></h1>
			<div id="ems-signups-root"></div>
		</div>
		<?php
	}

	public function register_volunteers_menu(): void {
		$volunteers_hook = add_submenu_page(
			'ems',
			__( 'Volunteers', 'ems-plugin' ),
			__( 'Volunteers', 'ems-plugin' ),
			'manage_options',
			'ems-volunteers',
			array( $this, 'render_volunteers_page' )
		);

		add_action(
			'admin_enqueue_scripts',
			function ( $hook ) use ( $volunteers_hook ) {
				if ( $hook === $volunteers_hook ) {
					$this->enqueue_admin_script( 'ems-volunteers', 'assets/js/volunteers.js' );
					$this->enqueue_admin_styles();
					wp_localize_script(
						'ems-volunteers',
						'emsVolunteers',
						array(
							'root_url' => get_rest_url( null, 'ems/v1' ),
							'nonce'    => wp_create_nonce( 'wp_rest' ),
						)
					);
				}
			}
		);
	}

	/**
	 * Registers the OSM Reference Data submenu.
	 */
	public function register_reference_menu(): void {
		$reference_hook = add_submenu_page(
			'ems',
			__( 'OSM Sync', 'ems-plugin' ),
			__( 'OSM Sync', 'ems-plugin' ),
			'manage_options',
			'ems-reference',
			array( $this, 'render_reference_page' )
		);

		add_action(
			'admin_enqueue_scripts',
			function ( $hook ) use ( $reference_hook ) {
				if ( $hook === $reference_hook ) {
					$this->enqueue_dashboard_assets();
				}
			}
		);
	}

	/**
	 * Registers the Column Mapper submenu (called at a later priority for correct ordering).
	 */
	public function register_mapper_menu(): void {
		$mapper_hook = add_submenu_page(
			null,
			__( 'Column Mapper', 'ems-plugin' ),
			__( 'Column Mapper', 'ems-plugin' ),
			'manage_options',
			'ems-column-mapper',
			array( $this, 'render_column_mapper' )
		);

		add_action(
			'admin_enqueue_scripts',
			function ( $hook ) use ( $mapper_hook ) {
				if ( $hook === $mapper_hook ) {
					$this->enqueue_mapper_assets();
				}
			}
		);
	}


	private function enqueue_dashboard_assets(): void {
		wp_enqueue_editor();
		$this->enqueue_admin_script( 'ems-expedition-board', 'assets/js/expedition-board.js' );
		$this->enqueue_admin_styles();
		wp_localize_script(
			'ems-expedition-board',
			'emsExpeditionBoard',
			array(
				'root_url'   => get_rest_url( null, 'ems/v1' ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'admin_url'  => admin_url( 'post.php' ),
				'sections'   => (array) get_option( 'ems_managed_sections', array() ),
				'plugin_url' => plugin_dir_url( EMS_PLUGIN_FILE ),
			)
		);
	}

	private function enqueue_mapper_assets(): void {
		$this->enqueue_admin_script( 'ems-column-mapper', 'assets/js/column-mapper.js' );
		$this->enqueue_admin_styles();
		wp_localize_script(
			'ems-column-mapper',
			'emsColumnMapper',
			array(
				'root_url' => get_rest_url( null, 'ems/v1' ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'sections' => (array) get_option( 'ems_managed_sections', array() ),
			)
		);
	}

	/**
	 * Enqueue the shared EMS admin stylesheet (compiled by Vite).
	 * Also ensures @wordpress/components stylesheet is loaded.
	 * Safe to call multiple times — wp_enqueue_style is idempotent.
	 */
	private function enqueue_admin_styles(): void {
		wp_enqueue_style( 'wp-components' );

		$css_path    = plugin_dir_path( EMS_PLUGIN_FILE ) . 'assets/js/ems-admin.css';
		$css_url     = plugin_dir_url( EMS_PLUGIN_FILE ) . 'assets/js/ems-admin.css';
		$css_version = EMS_VERSION . ( file_exists( $css_path ) ? '.' . filemtime( $css_path ) : '' );
		wp_enqueue_style( 'ems-admin', $css_url, array( 'wp-components' ), $css_version );
	}

	public function render_dashboard(): void {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Expedition Board', 'ems-plugin' ) . '</h1>';

		if ( isset( $_GET['sync'] ) && $_GET['sync'] === 'success' ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'OSM data synced successfully.', 'ems-plugin' ) . '</p></div>';
		}

		echo '<div id="ems-expedition-board-root"></div>';
		echo '</div>';
	}




	public function render_reference_page(): void {
		global $wpdb;

		// Handle Form Submissions
		if ( isset( $_POST['ems_save_sections'] ) && check_admin_referer( 'ems_settings_sections' ) ) {
			$this->save_sections( $_POST );
		} elseif ( isset( $_POST['ems_save_unit_leaders'] ) && check_admin_referer( 'ems_settings_unit_leaders' ) ) {
			$this->save_unit_leaders( $_POST );
		} elseif ( isset( $_POST['ems_import_units'] ) && check_admin_referer( 'ems_settings_unit_portability' ) ) {
			$this->handle_import_units();
		} elseif ( isset( $_POST['ems_add_custom_unit'] ) && check_admin_referer( 'ems_add_custom_unit' ) ) {
			$this->handle_add_custom_unit();
		} elseif ( isset( $_POST['ems_delete_custom_unit'] ) && check_admin_referer( 'ems_settings_unit_leaders' ) ) {
			$this->handle_delete_custom_unit( (int) $_POST['ems_delete_custom_unit'] );
		} elseif ( isset( $_POST['ems_action'] ) && $_POST['ems_action'] === 'add_district' && check_admin_referer( 'ems_add_district' ) ) {
			$this->handle_add_district();
		} elseif ( isset( $_POST['ems_delete_district'] ) && check_admin_referer( 'ems_settings_unit_leaders' ) ) {
			$this->handle_delete_district( sanitize_text_field( wp_unslash( $_POST['ems_delete_district'] ) ) );
		} elseif ( isset( $_POST['ems_action'] ) && $_POST['ems_action'] === 'link_patrol' && check_admin_referer( 'ems_link_patrol' ) ) {
			$this->handle_link_patrol_to_unit();
		} elseif ( isset( $_POST['ems_action'] ) && $_POST['ems_action'] === 'create_unit_from_patrol' && check_admin_referer( 'ems_create_unit_from_patrol' ) ) {
			$this->handle_create_unit_from_patrol();
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'explorers';
		$valid_tabs = array( 'explorers', 'patrols', 'events', 'sections', 'unit_mapping', 'diagnostics', 'pushback' );
		if ( ! in_array( $active_tab, $valid_tabs, true ) ) {
			$active_tab = 'explorers';
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'OSM Reference Data', 'ems-plugin' ) . '</h1>';

		$this->render_error_notices();

		$sync_status = get_transient( 'ems_sync_status' );
		$sync_state  = $sync_status['state'] ?? '';
		if ( $sync_state === 'queued' && ! empty( $sync_status['queued_at'] ) ) {
			$queued_age = time() - (int) $sync_status['queued_at'];
			if ( $queued_age > 5 * MINUTE_IN_SECONDS ) {
				delete_transient( 'ems_sync_status' );
				delete_transient( 'ems_pending_sync_job' );
				$sync_state = '';
			}
		}
		$sync_running = in_array( $sync_state, array( 'queued', 'running' ), true );

		if ( $sync_running ) {
			$this->render_sync_progress_banner();
		} else {
			$this->render_sync_result_panel();
		}

		$base_url = admin_url( 'admin.php?page=ems-reference' );

		$is_blocked  = (bool) get_option( 'ems_api_blocked', false );
		$last_result = get_transient( 'ems_last_sync_result' );
		$last_sync   = $last_result['started_at'] ?? null;

		echo '<div class="ems-admin-header-row">';
		if ( ! $is_blocked && ! $sync_running ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="ems_sync_osm" />';
			wp_nonce_field( 'ems_sync_osm' );
			echo '<button type="submit" class="button button-primary">' . esc_html__( 'Sync from OSM', 'ems-plugin' ) . '</button>';
			echo '</form>';
		}
		if ( $last_sync ) {
			echo '<span class="ems-text-muted">' . esc_html__( 'Last synced:', 'ems-plugin' ) . ' ' . esc_html( $last_sync ) . '</span>';
		} else {
			echo '<span class="ems-text-muted">' . esc_html__( 'Never synced', 'ems-plugin' ) . '</span>';
		}
		echo '</div>';

		$tab_labels = array(
			'explorers'    => __( 'Explorers', 'ems-plugin' ),
			'patrols'      => __( 'Patrols', 'ems-plugin' ),
			'events'       => __( 'Events', 'ems-plugin' ),
			'sections'     => __( 'Managed Sections', 'ems-plugin' ),
			'unit_mapping' => __( 'Unit Mapping', 'ems-plugin' ),
			'pushback'     => __( 'Pushback Sync', 'ems-plugin' ),
			'diagnostics'  => __( 'Diagnostics', 'ems-plugin' ),
		);

		echo '<nav class="nav-tab-wrapper ems-nav-tab-wrapper--no-margin">';
		foreach ( $tab_labels as $slug => $label ) {
			$class = ( $slug === $active_tab ) ? 'nav-tab nav-tab-active' : 'nav-tab';
			echo '<a href="' . esc_url( $base_url . '&tab=' . $slug ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		echo '<div class="ems-admin-tab-content-panel">';

		if ( $active_tab === 'explorers' ) {
			$this->render_explorers_tab( $wpdb );
		} elseif ( $active_tab === 'patrols' ) {
			$this->render_patrols_tab( $wpdb );
		} elseif ( $active_tab === 'events' ) {
			$this->render_events_tab( $wpdb );
		} elseif ( $active_tab === 'sections' ) {
			$this->render_sections_tab();
		} elseif ( $active_tab === 'unit_mapping' ) {
			$this->render_unit_leaders_tab();
		} elseif ( $active_tab === 'diagnostics' ) {
			$this->render_diagnostics_tab();
		} elseif ( $active_tab === 'pushback' ) {
			$this->render_pushback_tab();
		}

		echo '</div>';
		echo '</div>';
	}

	private function render_pushback_tab(): void {
		$auth_handler = new \EMS\Admin\OSM_Sync_Auth_Handler();
		$cached_token = $auth_handler->get_cached_token();

		if ( empty( $cached_token ) ) {
			echo '<div class="notice notice-info inline ems-notice-container">';
			echo '<p>' . esc_html__( 'To preview or execute push-back synchronization, you must first authenticate with Online Scout Manager.', 'ems-plugin' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="ems_pushback_auth" />';
			wp_nonce_field( 'ems_pushback_auth' );
			echo '<button type="submit" class="button button-primary">' . esc_html__( 'Authenticate with OSM', 'ems-plugin' ) . '</button>';
			echo '</form>';
			echo '</div>';
		} else {
			echo '<div id="ems-pushback-root" data-token="' . esc_attr( $cached_token ) . '"></div>';
		}
	}

	private function render_explorers_tab( $wpdb ): void {
		echo '<div id="ems-explorers-root"></div>';
	}

	private function render_patrols_tab( $wpdb ): void {
		$table = $wpdb->prefix . 'ems_osm_explorers';
		$rows  = $wpdb->get_results(
			"SELECT patrol, COUNT(*) AS member_count FROM {$table} GROUP BY patrol ORDER BY patrol",
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No patrol data. Run an OSM sync first.', 'ems-plugin' ) . '</p>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Patrol', 'ems-plugin' ) . '</th>';
		echo '<th>' . esc_html__( 'Members', 'ems-plugin' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row['patrol'] ?: __( '(none)', 'ems-plugin' ) ) . '</td>';
			echo '<td>' . (int) $row['member_count'] . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private function render_events_tab( $wpdb ): void {
		$events_table     = $wpdb->prefix . 'ems_osm_events';
		$attendance_table = $wpdb->prefix . 'ems_osm_event_attendance';

		$rows = $wpdb->get_results(
			"SELECT e.event_id, e.name, e.start_date, e.end_date, e.location,
                    COUNT(a.id) AS attendance_count
             FROM {$events_table} e
             LEFT JOIN {$attendance_table} a ON a.event_id = e.event_id
             GROUP BY e.event_id
             ORDER BY e.start_date DESC",
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No event data. Run an OSM sync first.', 'ems-plugin' ) . '</p>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Name', 'ems-plugin' ) . '</th>';
		echo '<th>' . esc_html__( 'Start', 'ems-plugin' ) . '</th>';
		echo '<th>' . esc_html__( 'End', 'ems-plugin' ) . '</th>';
		echo '<th>' . esc_html__( 'Location', 'ems-plugin' ) . '</th>';
		echo '<th>' . esc_html__( 'Attendance', 'ems-plugin' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row['name'] ) . '</td>';
			echo '<td>' . esc_html( $row['start_date'] ) . '</td>';
			echo '<td>' . esc_html( $row['end_date'] ) . '</td>';
			echo '<td>' . esc_html( $row['location'] ) . '</td>';
			echo '<td>' . (int) $row['attendance_count'] . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private function render_error_notices(): void {
		$error_map = array(
			'forbidden'          => __( 'You do not have permission to perform that action.', 'ems-plugin' ),
			'invalid_state'      => __( 'Invalid OAuth state. Please try again.', 'ems-plugin' ),
			'missing_code'       => __( 'Authorization code was missing from OSM callback.', 'ems-plugin' ),
			'token_exchange'     => __( 'Failed to exchange authorization code for token.', 'ems-plugin' ),
			'no_access_token'    => __( 'OSM did not return an access token.', 'ems-plugin' ),
			'api_blocked'        => __( 'Sync is disabled: this application has been blocked by OSM. Clear the block flag below before retrying.', 'ems-plugin' ),
			'payload_failed'     => __( 'OSM returned an unexpected response when fetching data.', 'ems-plugin' ),
			'osm_access_denied'  => __( 'OSM authorization was denied. Did you cancel the login or decline the permissions request?', 'ems-plugin' ),
			'osm_invalid_client' => __( 'OSM rejected the client credentials. Check Client ID and Secret in Settings.', 'ems-plugin' ),
		);

		if ( isset( $_GET['error'] ) ) {
			$slug = sanitize_key( $_GET['error'] );
			$msg  = $error_map[ $slug ] ?? sprintf( __( 'OSM authorization error: %s', 'ems-plugin' ), $slug );
			if ( isset( $_GET['error_msg'] ) ) {
				$msg .= ' ' . esc_html( sanitize_text_field( urldecode( $_GET['error_msg'] ) ) );
			}
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}

		if ( isset( $_GET['sync'] ) && $_GET['sync'] === 'success' ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'OSM sync complete.', 'ems-plugin' ) . '</p></div>';
		}

		if ( isset( $_GET['block_cleared'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'API block flag cleared. You may now attempt a sync.', 'ems-plugin' ) . '</p></div>';
		}
	}

	private function render_sync_progress_banner(): void {
		$status_url  = esc_js( rest_url( 'ems/v1/sync-status' ) );
		$nonce       = wp_create_nonce( 'wp_rest' );
		$reload_url  = esc_js( admin_url( 'admin.php?page=ems-reference' ) );
		$handler     = new \EMS\Admin\OSM_Sync_Auth_Handler();
		$has_token   = $handler->get_cached_token() !== '';
		$token_label = $has_token
			? '<span style="color:#00a32a;">&#10003; Authenticated with OSM</span>'
			: '<span style="color:#dba617;">&#9888; No cached OSM session — a new login will be required for the next sync</span>';
		?>
		<div id="ems-sync-progress" class="ems-sync-progress-box">
			<p style="margin:0 0 6px;font-weight:600;display:flex;align-items:center;gap:12px;">
				<span>
					<span id="ems-sync-spinner" class="spinner is-active" style="float:none;margin:0 6px 0 0;vertical-align:middle;"></span>
					<span id="ems-sync-state-label"><?php esc_html_e( 'Sync queued…', 'ems-plugin' ); ?></span>
				</span>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ems_cancel_sync' ), 'ems_cancel_sync' ) ); ?>" style="font-weight:normal;font-size:12px;"><?php esc_html_e( 'Cancel', 'ems-plugin' ); ?></a>
			</p>
			<p style="margin:0 0 8px;font-size:13px;font-weight:normal;"><?php echo $token_label; // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
			<ul style="margin:.3em 0 0 1.5em;" id="ems-sync-counts">
				<li><?php esc_html_e( 'Members synced: ', 'ems-plugin' ); ?><strong id="ems-count-members">—</strong></li>
				<li><?php esc_html_e( 'Events synced: ', 'ems-plugin' ); ?><strong id="ems-count-events">—</strong></li>
			</ul>
		</div>
		<script>
		(function() {
			var statusUrl  = '<?php echo $status_url; ?>',
				nonce      = '<?php echo $nonce; ?>',
				reloadUrl  = '<?php echo $reload_url; ?>',
				interval;

			var queuedSince = null;

			function updateLabel( state ) {
				var labels = {
					queued:  'Sync queued — waiting for background process…',
					running: 'Sync running…',
					done:    'Sync complete — reloading…',
					idle:    'Sync complete — reloading…'
				};
				document.getElementById('ems-sync-state-label').textContent = labels[state] || state;
			}

			function markStuck() {
				clearInterval( interval );
				document.getElementById('ems-sync-spinner').classList.remove('is-active');
				document.getElementById('ems-sync-state-label').textContent = 'Background process did not start.';
				var counts = document.getElementById('ems-sync-counts');
				counts.innerHTML = '<li style="color:#d63638;">WP-Cron may not be running in this environment. '
					+ '<a href="' + reloadUrl + '">Reload</a> to check again, or run '
					+ '<code>wp cron event run ems_run_osm_sync</code> manually, then reload.</li>';
			}

			function poll() {
				fetch( statusUrl, { headers: { 'X-WP-Nonce': nonce } } )
					.then( function(r) { return r.json(); } )
					.then( function(data) {
						if ( data.state === 'queued' ) {
							if ( ! queuedSince ) { queuedSince = Date.now(); }
							if ( Date.now() - queuedSince > 30000 ) {
								markStuck();
								return;
							}
						} else {
							queuedSince = null;
						}
						updateLabel( data.state );
						if ( data.state === 'running' || data.state === 'done' ) {
							document.getElementById('ems-count-members').textContent = data.members_upserted;
							document.getElementById('ems-count-events').textContent  = data.events_upserted;
						}
						if ( data.state === 'done' || data.state === 'idle' || data.state === '' ) {
							clearInterval( interval );
							document.getElementById('ems-sync-spinner').classList.remove('is-active');
							setTimeout( function() { window.location.href = reloadUrl; }, 1500 );
						}
					} )
					.catch( function() {} );
			}

			poll();
			interval = setInterval( poll, 3000 );
		})();
		</script>
		<?php
	}

	private function render_sync_result_panel(): void {
		if ( get_option( 'ems_api_blocked', false ) ) {
			echo '<div class="notice notice-error">';
			echo '<p><strong>' . esc_html__( 'OSM has permanently blocked this application.', 'ems-plugin' ) . '</strong> ';
			echo esc_html__( 'No further sync attempts will be made. Contact OSM support to resolve the block, then clear the flag.', 'ems-plugin' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px;">';
			echo '<input type="hidden" name="action" value="ems_clear_api_block" />';
			wp_nonce_field( 'ems_clear_api_block' );
			echo '<button type="submit" class="button button-secondary">' . esc_html__( 'Clear block flag', 'ems-plugin' ) . '</button>';
			echo '</form>';
			echo '</div>';
			return;
		}

		$result = get_transient( 'ems_last_sync_result' );
		if ( empty( $result ) ) {
			return;
		}

		if ( ! empty( $result['rate_limited'] ) ) {
			$retry = (int) ( $result['retry_after_seconds'] ?? 0 );
			$reset = (int) ( $result['rate_limit_reset_seconds'] ?? 0 );
			echo '<div class="notice notice-warning is-dismissible"><p>';
			echo '<strong>' . esc_html__( 'Sync stopped: OSM rate limit reached.', 'ems-plugin' ) . '</strong> ';
			printf( esc_html__( 'Retry after: %1$ds (resets in %2$ds).', 'ems-plugin' ), $retry, $reset );
			echo '</p></div>';
		}

		if ( ! empty( $result['deprecated_endpoints'] ) ) {
			echo '<div class="notice notice-info is-dismissible"><p>';
			echo esc_html__( 'Deprecated OSM endpoints detected: ', 'ems-plugin' );
			echo esc_html( implode( ', ', (array) $result['deprecated_endpoints'] ) );
			echo '</p></div>';
		}

		$mode      = esc_html( $result['mode'] ?? 'unknown' );
		$started   = esc_html( $result['started_at'] ?? '' );
		$m_ok      = (int) ( $result['members_upserted'] ?? 0 );
		$m_fail    = (int) ( $result['members_failed'] ?? 0 );
		$e_ok      = (int) ( $result['events_upserted'] ?? 0 );
		$e_fail    = (int) ( $result['events_failed'] ?? 0 );
		$err_count = count( (array) ( $result['errors'] ?? array() ) );

		echo '<div style="background:#fff;border:1px solid #ccd0d4;padding:12px 16px;margin-bottom:16px;border-radius:2px;">';
		echo '<strong>' . esc_html__( 'Last Sync Result', 'ems-plugin' ) . '</strong>';
		echo ' <span style="color:#666;font-size:12px;">(' . $mode . ' — ' . $started . ')</span>';
		echo '<ul style="margin:.5em 0 0 1.5em;">';
		echo '<li>' . sprintf( esc_html__( 'Members: %1$d upserted, %2$d failed', 'ems-plugin' ), $m_ok, $m_fail ) . '</li>';
		echo '<li>' . sprintf( esc_html__( 'Events: %1$d upserted, %2$d failed', 'ems-plugin' ), $e_ok, $e_fail ) . '</li>';
		if ( $err_count > 0 ) {
			echo '<li>';
			echo '<details><summary>' . sprintf( esc_html__( '%d error(s)', 'ems-plugin' ), $err_count ) . '</summary>';
			echo '<ul style="margin:.5em 0 0 1.5em;">';
			foreach ( (array) $result['errors'] as $err ) {
				echo '<li>' . esc_html( $err ) . '</li>';
			}
			echo '</ul></details>';
			echo '</li>';
		}
		echo '</ul>';
		echo '</div>';
	}

	private function render_diagnostics_tab(): void {
		$user_id = get_current_user_id();

		echo '<h3>' . esc_html__( 'System', 'ems-plugin' ) . '</h3>';
		echo $this->diagnostic->get_system_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$access_type = get_user_meta( $user_id, 'ems_access_type', true );
		if ( ! empty( $access_type ) && $access_type !== 'local' ) {
			echo '<h3 style="margin-top:20px;">' . esc_html__( 'Your OSM Account', 'ems-plugin' ) . '</h3>';
			echo $this->diagnostic->get_user_html( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		$log = get_transient( 'ems_last_sync_log' );
		if ( ! empty( $log ) ) {
			echo '<h3 style="margin-top:20px;">' . esc_html__( 'Last Sync Log', 'ems-plugin' ) . '</h3>';
			$log_json = wp_json_encode( $log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			echo '<pre class="ems-log-output-box">';
			echo esc_html( $log_json );
			echo '</pre>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px;">';
			echo '<input type="hidden" name="action" value="ems_download_sync_log" />';
			wp_nonce_field( 'ems_download_sync_log' );
			echo '<button type="submit" class="button">' . esc_html__( 'Download log (JSON)', 'ems-plugin' ) . '</button>';
			echo '</form>';
		}

		$dump = get_transient( 'ems_last_payload_dump' );
		if ( ! empty( $dump ) ) {
			echo '<h3 style="margin-top:20px;">' . esc_html__( 'Last Payload Dump (get_data_payload)', 'ems-plugin' ) . '</h3>';
			$roles       = $dump['data']['globals']['roles'] ?? array();
			$role_labels = array_map( fn( $r ) => ( $r['section'] ?? '?' ) . ' @ ' . ( $r['groupname'] ?? '?' ), $roles );
			$summary     = array(
				'userid'      => $dump['data']['globals']['userid'] ?? null,
				'email'       => $dump['data']['globals']['email'] ?? null,
				'roles'       => $role_labels,
				'section_ids' => array_keys( $dump['data']['globals']['member_access'] ?? array() ),
				'term_count'  => count( $dump['data']['globals']['terms'] ?? array() ),
			);
			echo '<pre style="background:#f6f7f7;padding:10px;font-size:11px;">';
			echo esc_html( wp_json_encode( $summary, JSON_PRETTY_PRINT ) );
			echo '</pre>';
		}
	}

	public function render_volunteers_page(): void {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Volunteers', 'ems-plugin' ) . '</h1>';
		echo '<div id="ems-volunteers-root"></div>';
		echo '</div>';
	}

	public function render_column_mapper(): void {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Flexi-Record Column Mapper', 'ems-plugin' ) . '</h1>';
		echo '<div id="ems-column-mapper-root"></div>';
		echo '</div>';
	}

	/**
	 * Renders a build timestamp footer on EMS admin pages.
	 */
	public function render_build_timestamp(): void {
		$screen = get_current_screen();
		if ( ! $screen || strpos( $screen->id, 'ems' ) === false ) {
			return;
		}

		$manifest_path = plugin_dir_path( EMS_PLUGIN_FILE ) . 'assets/build-manifest.json';
		$built_at      = '';

		if ( file_exists( $manifest_path ) ) {
			$data     = json_decode( file_get_contents( $manifest_path ), true );
			$built_at = $data['built_at'] ?? '';
		}

		if ( ! $built_at ) {
			return;
		}

		$dt      = new \DateTime( $built_at );
		$display = $dt->format( 'j M Y H:i' ) . ' UTC';

		echo '<div style="position:fixed;bottom:0;right:0;padding:4px 10px;font-size:11px;color:#999;background:rgba(255,255,255,.85);border-top-left-radius:4px;z-index:9999;">';
		echo 'Build: ' . esc_html( $display );
		echo '</div>';
	}

	/**
	 * Enqueues an admin script as an ES module.
	 */
	private function enqueue_admin_script( string $handle, string $rel_path ): void {
		$script_url = plugin_dir_url( EMS_PLUGIN_FILE ) . $rel_path;

		wp_enqueue_script(
			$handle,
			$script_url,
			array( 'wp-components', 'wp-element', 'wp-i18n' ),
			EMS_VERSION,
			true
		);
	}

	/**
	 * Gets the Unit_Repository instance.
	 *
	 * @return \EMS\Data\Unit_Repository
	 */
	protected function get_unit_repository(): \EMS\Data\Unit_Repository {
		return new \EMS\Data\Unit_Repository();
	}

	/**
	 * Saves managed sections settings.
	 *
	 * @param array $post_data The POST data.
	 * @return void
	 */
	public function save_sections( array $post_data ): void {
		$ids       = $post_data['ems_managed_section_ids'] ?? array();
		$available = get_transient( 'ems_available_sections' );
		if ( ! is_array( $available ) ) {
			$available = array();
		}

		$managed = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( isset( $available[ $id ] ) ) {
				$managed[ $id ] = array(
					'name' => $available[ $id ]['name'] ?? '',
					'type' => $available[ $id ]['type'] ?? '',
				);
			}
		}

		update_option( 'ems_managed_sections', $managed );

		$writeback = (int) ( $post_data['ems_writeback_section_id'] ?? 0 );
		if ( isset( $managed[ $writeback ] ) ) {
			update_option( 'ems_writeback_section_id', $writeback );
		} else {
			delete_option( 'ems_writeback_section_id' );
		}

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Managed sections updated.', 'ems-plugin' ) . '</p></div>';
	}

	/**
	 * Saves unit lookup settings.
	 *
	 * @param array $post_data The POST data.
	 * @return void
	 */
	public function save_unit_leaders( array $post_data ): void {
		$leaders_data = $post_data['unit_leaders'] ?? array();
		$unit_repo    = $this->get_unit_repository();

		$districts = get_option( 'ems_districts', array() );
		if ( ! is_array( $districts ) ) {
			$districts = array();
		}

		foreach ( $leaders_data as $id => $fields ) {
			$email      = sanitize_text_field( $fields['email'] ?? '' );
			$district   = sanitize_text_field( $fields['district'] ?? '' );
			$unit_id    = empty( $fields['unit_id'] ) ? null : (int) $fields['unit_id'];
			$short_code = sanitize_text_field( $fields['short_code'] ?? '' );

			if ( $district !== '' && ! in_array( $district, $districts, true ) ) {
				$districts[] = $district;
			}

			$data = array(
				'unit_id'      => $unit_id,
				'district'     => $district,
				'short_code'   => $short_code,
				'leader_email' => $email,
			);
			if ( isset( $fields['name'] ) ) {
				$data['name'] = sanitize_text_field( $fields['name'] );
			}

			try {
				$unit_repo->update_custom_mappings( (int) $id, $data );
			} catch ( \Exception $e ) {
				error_log( '[EMS] Admin save_unit_leaders failed: ' . $e->getMessage() );
			}
		}

		sort( $districts, SORT_NATURAL | SORT_FLAG_CASE );
		update_option( 'ems_districts', $districts );

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Unit lookup configurations saved.', 'ems-plugin' ) . '</p></div>';
	}

	/**
	 * Handles creating a standalone district.
	 *
	 * @return void
	 */
	private function handle_add_district(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'ems-plugin' ) );
		}

		$district = sanitize_text_field( wp_unslash( $_POST['ems_new_district_name'] ?? '' ) );
		if ( empty( $district ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'District name is required.', 'ems-plugin' ) . '</p></div>';
			return;
		}

		$districts = get_option( 'ems_districts', array() );
		if ( ! is_array( $districts ) ) {
			$districts = array();
		}

		if ( ! in_array( $district, $districts, true ) ) {
			$districts[] = $district;
			sort( $districts, SORT_NATURAL | SORT_FLAG_CASE );
			update_option( 'ems_districts', $districts );
			echo '<div class="notice notice-success is-dismissible"><p>' . sprintf( esc_html__( 'District "%s" created successfully.', 'ems-plugin' ), esc_html( $district ) ) . '</p></div>';
		} else {
			echo '<div class="notice notice-warning is-dismissible"><p>' . sprintf( esc_html__( 'District "%s" already exists.', 'ems-plugin' ), esc_html( $district ) ) . '</p></div>';
		}
	}

	/**
	 * Handles deleting a district from the districts option.
	 *
	 * @param string $district The district name to delete.
	 * @return void
	 */
	private function handle_delete_district( string $district ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'ems-plugin' ) );
		}

		$districts = get_option( 'ems_districts', array() );
		if ( is_array( $districts ) ) {
			$districts = array_values( array_diff( $districts, array( $district ) ) );
			update_option( 'ems_districts', $districts );
		}

		echo '<div class="notice notice-success is-dismissible"><p>' . sprintf( esc_html__( 'District "%s" removed.', 'ems-plugin' ), esc_html( $district ) ) . '</p></div>';
	}

	/**
	 * Checks if a unit export request has been made and handles it before headers are sent.
	 *
	 * @return void
	 */
	public function maybe_handle_export(): void {
		if ( isset( $_POST['ems_export_units'] ) && check_admin_referer( 'ems_settings_unit_portability' ) ) {
			$this->handle_export_units();
		}
	}

	/**
	 * Exports the Unit lookup configurations as a JSON string file download.
	 *
	 * @return void
	 */
	private function handle_export_units(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'ems-plugin' ) );
		}

		$engine   = new \EMS\Core\Portability_Engine();
		$json     = $engine->export_units();
		$filename = 'ems-units-backup-' . current_time( 'Y-md-His' ) . '.json';

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $json;
		exit;
	}

	/**
	 * Handles adding a custom/manual unit.
	 *
	 * @return void
	 */
	private function handle_add_custom_unit(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'ems-plugin' ) );
		}

		$name       = sanitize_text_field( $_POST['custom_unit_name'] ?? '' );
		$district   = sanitize_text_field( $_POST['custom_unit_district'] ?? '' );
		$short_code = sanitize_text_field( $_POST['custom_unit_short_code'] ?? '' );
		$unit_id    = empty( $_POST['custom_unit_id'] ) ? null : (int) $_POST['custom_unit_id'];
		$email      = sanitize_text_field( $_POST['custom_unit_email'] ?? '' );

		if ( empty( $name ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Unit name is required.', 'ems-plugin' ) . '</p></div>';
			return;
		}
		if ( empty( $unit_id ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Unit ID is required.', 'ems-plugin' ) . '</p></div>';
			return;
		}

		$unit_repo = $this->get_unit_repository();
		try {
			$unit_repo->add_custom_unit( array(
				'name'         => $name,
				'district'     => $district,
				'short_code'   => $short_code,
				'unit_id'      => $unit_id,
				'leader_email' => $email,
			) );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Custom unit created successfully.', 'ems-plugin' ) . '</p></div>';
		} catch ( \Exception $e ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $e->getMessage() ) . '</p></div>';
		}
	}

	/**
	 * Handles deleting a custom/manual unit.
	 *
	 * @param int $id The unit ID.
	 * @return void
	 */
	private function handle_delete_custom_unit( int $id ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'ems-plugin' ) );
		}

		$unit_repo = $this->get_unit_repository();
		if ( $unit_repo->delete_custom_unit( $id ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Custom unit deleted.', 'ems-plugin' ) . '</p></div>';
		} else {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Failed to delete unit or unit is synced from OSM.', 'ems-plugin' ) . '</p></div>';
		}
	}

	/**
	 * Handles linking a synced patrol to an existing master unit.
	 *
	 * @return void
	 */
	private function handle_link_patrol_to_unit(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'ems-plugin' ) );
		}

		$patrol_id  = (int) ( $_POST['patrol_id'] ?? 0 );
		$section_id = (int) ( $_POST['section_id'] ?? 0 );
		$unit_id    = (int) ( $_POST['link_unit_id'] ?? 0 );

		if ( ! $patrol_id || ! $section_id || ! $unit_id ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Invalid request parameters.', 'ems-plugin' ) . '</p></div>';
			return;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$updated = $wpdb->update(
			$wpdb->prefix . 'ems_unit_patrols',
			array( 'unit_id' => $unit_id ),
			array( 'patrol_id' => $patrol_id, 'section_id' => $section_id ),
			array( '%d' ),
			array( '%d', '%d' )
		);

		if ( $updated !== false ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Patrol linked to unit successfully.', 'ems-plugin' ) . '</p></div>';
		} else {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Failed to link patrol.', 'ems-plugin' ) . '</p></div>';
		}
	}

	/**
	 * Handles creating a master unit from a synced patrol and automatically linking it.
	 *
	 * @return void
	 */
	private function handle_create_unit_from_patrol(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'ems-plugin' ) );
		}

		$patrol_id   = (int) ( $_POST['patrol_id'] ?? 0 );
		$section_id  = (int) ( $_POST['section_id'] ?? 0 );
		$patrol_name = sanitize_text_field( $_POST['patrol_name'] ?? '' );

		if ( ! $patrol_id || ! $section_id || empty( $patrol_name ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Invalid request parameters.', 'ems-plugin' ) . '</p></div>';
			return;
		}

		$unit_repo = $this->get_unit_repository();

		try {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$max_id = (int) $wpdb->get_var( "SELECT MAX(unit_id) FROM {$wpdb->prefix}ems_units WHERE unit_id >= 900000" );
			$new_unit_id = max( 900000, $max_id ) + 1;

			$district = '';
			$name     = $patrol_name;
			if ( strpos( $patrol_name, '-' ) !== false ) {
				$parts    = explode( '-', $patrol_name, 2 );
				$district = trim( $parts[0] );
				$name     = trim( $parts[1] );
			}

			$unit_repo->add_custom_unit(
				array(
					'unit_id'      => $new_unit_id,
					'district'     => $district,
					'name'         => $patrol_name,
					'short_code'   => $patrol_name,
					'leader_email' => '',
				)
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->update(
				$wpdb->prefix . 'ems_unit_patrols',
				array( 'unit_id' => $new_unit_id ),
				array( 'patrol_id' => $patrol_id, 'section_id' => $section_id ),
				array( '%d' ),
				array( '%d', '%d' )
			);

			echo '<div class="notice notice-success is-dismissible"><p>' . sprintf( esc_html__( 'Master unit "%s" created and linked successfully.', 'ems-plugin' ), esc_html( $patrol_name ) ) . '</p></div>';
		} catch ( \Exception $e ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $e->getMessage() ) . '</p></div>';
		}
	}

	/**
	 * Imports Unit lookup configurations from an uploaded JSON file.
	 *
	 * @return void
	 */
	private function handle_import_units(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'ems-plugin' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $_FILES['ems_units_backup_file']['tmp_name'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Please upload a unit lookup backup file.', 'ems-plugin' ) . '</p></div>';
			return;
		}

		try {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$file_path = sanitize_text_field( wp_unslash( $_FILES['ems_units_backup_file']['tmp_name'] ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$content = file_get_contents( $file_path );
			if ( ! $content ) {
				throw new \Exception( 'Failed to read uploaded file.' );
			}

			$engine = new \EMS\Core\Portability_Engine();
			$engine->import_units( $content );

			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Unit lookup data imported successfully.', 'ems-plugin' ) . '</p></div>';
		} catch ( \Exception $e ) {
			// translators: %s: The error message returned when import fails.
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sprintf( __( 'Import failed: %s', 'ems-plugin' ), $e->getMessage() ) ) . '</p></div>';
		}
	}

	/**
	 * Renders the Managed Sections tab contents.
	 *
	 * @return void
	 */
	private function render_sections_tab(): void {
		$available = get_transient( 'ems_available_sections' );
		if ( ! is_array( $available ) ) {
			$available = array();
		}
		$managed = get_option( 'ems_managed_sections', array() );
		if ( ! is_array( $managed ) ) {
			$managed = array();
		}
		if ( isset( $_GET['fetched'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Section list refreshed from OSM.', 'ems-plugin' ) . '</p></div>';
		}
		?>
		<div class="ems-panel ems-mb-24">
			<h3 class="ems-panel-title"><?php esc_html_e( 'Fetch OSM Sections', 'ems-plugin' ); ?></h3>
			<div class="ems-panel-content">
				<p><?php esc_html_e( 'Before you can manage sections, you need to fetch the available sections list from Online Scout Manager (OSM).', 'ems-plugin' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 12px; display: flex; align-items: center; gap: 12px;">
					<?php wp_nonce_field( 'ems_fetch_sections' ); ?>
					<input type="hidden" name="action" value="ems_fetch_sections" />
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Fetch sections from OSM', 'ems-plugin' ); ?></button>
					<span class="description"><?php esc_html_e( 'Retrieves the section list from OSM (or mock data) and caches it for 1 hour.', 'ems-plugin' ); ?></span>
				</form>
			</div>
		</div>

		<?php if ( empty( $available ) ) : ?>
			<div class="notice notice-info inline"><p>
				<?php esc_html_e( 'No section list cached yet. Click "Fetch sections from OSM" above to populate this list.', 'ems-plugin' ); ?>
			</p></div>
		<?php else :
			$writeback_id = (int) get_option( 'ems_writeback_section_id', 0 );
			?>
			<div class="ems-panel ems-mb-24">
				<h3 class="ems-panel-title"><?php esc_html_e( 'Configure Managed Sections', 'ems-plugin' ); ?></h3>
				<div class="ems-panel-content">
					<form method="post">
						<?php wp_nonce_field( 'ems_settings_sections' ); ?>
						<table class="ems-table">
							<thead>
								<tr>
									<th class="ems-col-width-70"><?php esc_html_e( 'Managed', 'ems-plugin' ); ?></th>
									<th class="ems-col-width-120"><?php esc_html_e( 'Write-Back Target', 'ems-plugin' ); ?></th>
									<th><?php esc_html_e( 'Section Name', 'ems-plugin' ); ?></th>
									<th><?php esc_html_e( 'Type', 'ems-plugin' ); ?></th>
									<th><?php esc_html_e( 'Section ID', 'ems-plugin' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php
								foreach ( $available as $id => $data ) :
									$id            = (int) $id;
									$checked       = isset( $managed[ $id ] );
									$radio_checked = ( $writeback_id === $id );
									$name          = esc_html( $data['name'] ?? '' );
									$type          = esc_html( $data['type'] ?? '' );
									?>
								<tr>
									<td><input type="checkbox" name="ems_managed_section_ids[]" value="<?php echo $id; ?>" <?php checked( $checked ); ?> /></td>
									<td><input type="radio" name="ems_writeback_section_id" value="<?php echo $id; ?>" <?php checked( $radio_checked ); ?> /></td>
									<td><strong><?php echo $name; ?></strong></td>
									<td><span class="ems-pill"><?php echo $type; ?></span></td>
									<td><code><?php echo $id; ?></code></td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<p class="submit" style="margin-top: 16px; margin-bottom: 0;">
							<input type="submit" name="ems_save_sections" class="button-primary" value="<?php esc_attr_e( 'Save Managed Sections', 'ems-plugin' ); ?>" />
						</p>
					</form>
				</div>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $managed ) ) :
			$writeback_id = (int) get_option( 'ems_writeback_section_id', 0 );
			?>
			<div class="ems-panel">
				<h3 class="ems-panel-title"><?php esc_html_e( 'Currently Managed Sections Summary', 'ems-plugin' ); ?></h3>
				<div class="ems-panel-content">
					<table class="ems-table">
						<thead><tr>
							<th><?php esc_html_e( 'Section ID', 'ems-plugin' ); ?></th>
							<th><?php esc_html_e( 'Name', 'ems-plugin' ); ?></th>
							<th><?php esc_html_e( 'Type', 'ems-plugin' ); ?></th>
							<th><?php esc_html_e( 'Write-Back Target', 'ems-plugin' ); ?></th>
						</tr></thead>
						<tbody>
							<?php foreach ( $managed as $id => $data ) :
								$is_target = ( (int) $id === $writeback_id );
								?>
							<tr>
								<td><code><?php echo (int) $id; ?></code></td>
								<td><strong><?php echo esc_html( $data['name'] ?? '' ); ?></strong></td>
								<td><span class="ems-pill"><?php echo esc_html( $data['type'] ?? '' ); ?></span></td>
								<td><?php echo $is_target ? '<span class="ems-status-badge ems-status-badge--active">' . esc_html__( 'Active Target', 'ems-plugin' ) . '</span>' : esc_html__( 'No', 'ems-plugin' ); ?></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		<?php endif;
	}

	/**
	 * Renders the Unit Mapping tab contents.
	 *
	 * @return void
	 */
	private function render_unit_leaders_tab(): void {
		global $wpdb;
		$unit_repo        = $this->get_unit_repository();
		$units            = $unit_repo->list_active_units();
		$managed_sections = get_option( 'ems_managed_sections', array() );

		// Group units by District
		$districts_option = get_option( 'ems_districts', array() );
		if ( ! is_array( $districts_option ) ) {
			$districts_option = array();
		}

		$all_districts = $districts_option;
		foreach ( $units as $u ) {
			$dist = trim( $u['district'] ?? '' );
			if ( $dist !== '' && ! in_array( $dist, $all_districts, true ) ) {
				$all_districts[] = $dist;
			}
		}
		natcasesort( $all_districts );

		$grouped_units = array();
		// Initialize all known districts (even if currently empty)
		foreach ( $all_districts as $d ) {
			$grouped_units[ $d ] = array();
		}

		// Populate units into their district groups
		foreach ( $units as $u ) {
			$dist      = trim( $u['district'] ?? '' );
			$group_key = ( $dist !== '' ) ? $dist : '__unassigned__';
			if ( ! isset( $grouped_units[ $group_key ] ) ) {
				$grouped_units[ $group_key ] = array();
			}
			$grouped_units[ $group_key ][] = $u;
		}

		// Sort grouped keys: alphabetical districts first, __unassigned__ last
		uksort(
			$grouped_units,
			function( $a, $b ) {
				if ( $a === '__unassigned__' ) {
					return 1;
				}
				if ( $b === '__unassigned__' ) {
					return -1;
				}
				return strcasecmp( $a, $b );
			}
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$unmatched_patrols = $wpdb->get_results(
			"SELECT p.* FROM {$wpdb->prefix}ems_unit_patrols p
			 LEFT JOIN {$wpdb->prefix}ems_units u ON p.unit_id = u.unit_id
			 WHERE (p.unit_id IS NULL OR p.unit_id = 0 OR u.unit_id IS NULL) AND p.active = 1
			 ORDER BY p.name",
			ARRAY_A
		) ?: array();
		?>
		<style>
			.ems-unit-leaders-table-container {
				margin-top: 15px;
				margin-bottom: 20px;
			}
			.ems-district-card {
				margin-top: 20px;
				padding: 16px 20px;
				max-width: 100%;
				border-left: 4px solid #2271b1;
				box-shadow: 0 1px 3px rgba(0,0,0,0.05);
			}
			.ems-district-card.ems-unassigned-card {
				border-left-color: #dcdcde;
			}
			.ems-district-header {
				display: flex;
				justify-content: space-between;
				align-items: center;
				margin-bottom: 12px;
				flex-wrap: wrap;
				gap: 10px;
			}
			.ems-unit-leaders-table-container input[type="text"],
			.ems-unit-leaders-table-container input[type="email"],
			.ems-unit-leaders-table-container input[type="number"] {
				width: 100%;
				box-sizing: border-box;
				padding: 5px 8px;
				margin: 0;
				height: auto;
				min-height: 0;
			}
			.ems-unit-leaders-table-container td {
				padding: 8px 10px !important;
				vertical-align: middle !important;
			}
		</style>

		<div class="card" style="padding: 16px 20px; margin-top: 15px; margin-bottom: 20px; max-width: 100%; background: #fcfcfc; border-top: 3px solid #2271b1;">
			<div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
				<div>
					<h3 style="margin: 0 0 4px 0;"><?php esc_html_e( 'Create New District', 'ems-plugin' ); ?></h3>
					<p class="description" style="margin: 0;">
						<?php esc_html_e( 'Create a district group to organize your master units (no unit details required).', 'ems-plugin' ); ?>
					</p>
				</div>
				<form method="post" style="display: inline-flex; align-items: center; gap: 8px; margin: 0;">
					<?php wp_nonce_field( 'ems_add_district' ); ?>
					<input type="text" name="ems_new_district_name" placeholder="<?php esc_attr_e( 'District Name (e.g. Borders, Braid, Pentland)', 'ems-plugin' ); ?>" class="regular-text" style="width: 280px; height: 32px;" required />
					<button type="submit" name="ems_action" value="add_district" class="button button-primary">
						<span class="dashicons dashicons-plus-alt2" style="vertical-align: text-top; font-size: 15px; margin-right: 2px;"></span>
						<?php esc_html_e( 'Create District', 'ems-plugin' ); ?>
					</button>
				</form>
			</div>
		</div>

		<form method="post">
			<?php wp_nonce_field( 'ems_settings_unit_leaders' ); ?>
			<div class="ems-unit-leaders-table-container">
				<?php if ( empty( $grouped_units ) ) : ?>
					<div class="card" style="padding: 24px; text-align: center; max-width: 100%;">
						<span class="dashicons dashicons-location-alt" style="font-size: 36px; width: 36px; height: 36px; color: #2271b1; margin-bottom: 8px;"></span>
						<h3 style="margin-top: 0;"><?php esc_html_e( 'No Districts Created Yet', 'ems-plugin' ); ?></h3>
						<p class="description" style="max-width: 600px; margin: 0 auto 15px;">
							<?php esc_html_e( 'Use the "Create New District" box above to create your first district, or add a master unit with a district name below.', 'ems-plugin' ); ?>
						</p>
					</div>
				<?php else : ?>
					<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 10px;">
						<p class="description" style="margin: 0;">
							<?php esc_html_e( 'Units are grouped by District. Edit the district name in any card header to rename it for all units in that card, or use "Move District" to reassign individual units.', 'ems-plugin' ); ?>
						</p>
						<div style="display: flex; gap: 8px; align-items: center;">
							<button type="button" class="button button-secondary" onclick="var el = document.getElementById('custom_unit_district'); if (el) { el.value = ''; el.focus(); el.scrollIntoView({behavior: 'smooth', block: 'center'}); }">
								<span class="dashicons dashicons-plus-alt2" style="vertical-align: text-top; font-size: 15px; margin-right: 2px;"></span>
								<?php esc_html_e( 'Add Master Unit', 'ems-plugin' ); ?>
							</button>
							<input type="submit" name="ems_save_unit_leaders" class="button button-primary" value="<?php esc_attr_e( 'Save All Units', 'ems-plugin' ); ?>" />
						</div>
					</div>

					<?php foreach ( $grouped_units as $group_key => $group_units ) : 
						$is_unassigned = ( $group_key === '__unassigned__' );
						$card_class    = $is_unassigned ? 'card ems-district-card ems-unassigned-card' : 'card ems-district-card';
						?>
						<div class="<?php echo esc_attr( $card_class ); ?>" data-district-key="<?php echo esc_attr( $group_key ); ?>">
							<div class="ems-district-header">
								<div style="display: flex; align-items: center; gap: 10px;">
									<span class="dashicons dashicons-location" style="color: <?php echo $is_unassigned ? '#646970' : '#2271b1'; ?>; font-size: 22px; width: 22px; height: 22px;"></span>
									<?php if ( $is_unassigned ) : ?>
										<strong style="font-size: 16px; color: #646970;"><?php esc_html_e( 'Unassigned Units (No District)', 'ems-plugin' ); ?></strong>
									<?php else : ?>
										<label style="font-size: 15px; font-weight: 600; color: #1d2327;">
											<?php esc_html_e( 'District:', 'ems-plugin' ); ?>
											<input type="text" class="ems-district-header-input" 
													data-original-district="<?php echo esc_attr( $group_key ); ?>"
													value="<?php echo esc_attr( $group_key ); ?>" 
													style="font-size: 15px; font-weight: 600; width: 240px; margin-left: 4px; padding: 4px 8px;" />
										</label>
									<?php endif; ?>
									<span class="ems-unit-count-badge" style="background: #f0f0f1; border: 1px solid #c3c4c7; padding: 2px 8px; border-radius: 10px; font-size: 12px; font-weight: 600; color: #50575e;">
										<?php echo sprintf( _n( '%d Unit', '%d Units', count( $group_units ), 'ems-plugin' ), count( $group_units ) ); ?>
									</span>
								</div>
								<div style="display: flex; align-items: center; gap: 6px;">
									<?php if ( ! $is_unassigned ) : ?>
										<button type="button" class="button button-secondary ems-quick-add-btn" data-district="<?php echo esc_attr( $group_key ); ?>" style="font-size: 12px; height: 28px; line-height: 26px;">
											<span class="dashicons dashicons-plus-alt2" style="vertical-align: text-top; font-size: 15px; margin-right: 2px;"></span>
											<?php echo sprintf( esc_html__( 'Add Unit to %s', 'ems-plugin' ), esc_html( $group_key ) ); ?>
										</button>
										<?php if ( empty( $group_units ) ) : ?>
											<button type="submit" name="ems_delete_district" value="<?php echo esc_attr( $group_key ); ?>" 
													class="button button-link-delete" style="color: #b32d2e; font-size: 12px; margin-left: 6px;"
													onclick="return confirm('<?php echo esc_attr( sprintf( __( 'Are you sure you want to remove the empty district "%s"?', 'ems-plugin' ), $group_key ) ); ?>');">
												<?php esc_html_e( 'Delete District', 'ems-plugin' ); ?>
											</button>
										<?php endif; ?>
									<?php endif; ?>
								</div>
							</div>

							<table class="wp-list-table widefat striped ems-district-units-table">
								<thead>
									<tr>
										<th style="width: 20%;"><?php esc_html_e( 'Unit Name', 'ems-plugin' ); ?></th>
										<th style="width: 8%;"><?php esc_html_e( 'Unit ID', 'ems-plugin' ); ?></th>
										<th style="width: 12%;"><?php esc_html_e( 'Short Code', 'ems-plugin' ); ?></th>
										<th style="width: 20%;"><?php esc_html_e( 'Leader Email', 'ems-plugin' ); ?></th>
										<th style="width: 24%;"><?php esc_html_e( 'Matched OSM Patrols', 'ems-plugin' ); ?></th>
										<th style="width: 10%;"><?php esc_html_e( 'Move District', 'ems-plugin' ); ?></th>
										<th style="width: 6%; text-align: center;"><?php esc_html_e( 'Actions', 'ems-plugin' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php if ( empty( $group_units ) ) : ?>
										<tr>
											<td colspan="7" style="padding: 16px; text-align: center; color: #646970; font-style: italic;">
												<?php echo sprintf( esc_html__( 'No units in %s yet. Click "Add Unit to %s" above or use "Move District" on existing units to assign them here.', 'ems-plugin' ), esc_html( $group_key ), esc_html( $group_key ) ); ?>
											</td>
										</tr>
									<?php else : ?>
										<?php foreach ( $group_units as $u ) :
											$row_id   = (int) $u['id'];
											$cur_dist = $u['district'] ?? '';
											?>
											<tr class="ems-unit-row" data-unit-row-id="<?php echo $row_id; ?>">
												<input type="hidden" class="ems-unit-district-hidden" 
														name="unit_leaders[<?php echo $row_id; ?>][district]" 
														value="<?php echo esc_attr( $cur_dist ); ?>" />
												<td>
													<input type="text" name="unit_leaders[<?php echo $row_id; ?>][name]" 
															value="<?php echo esc_attr( $u['name'] ); ?>" required />
												</td>
												<td>
													<input type="number" name="unit_leaders[<?php echo $row_id; ?>][unit_id]" 
															value="<?php echo esc_attr( $u['unit_id'] ?? '' ); ?>" required />
												</td>
												<td>
													<input type="text" name="unit_leaders[<?php echo $row_id; ?>][short_code]" 
															value="<?php echo esc_attr( $u['short_code'] ?: $u['name'] ); ?>" />
												</td>
												<td>
													<input type="email" name="unit_leaders[<?php echo $row_id; ?>][email]" 
															value="<?php echo esc_attr( $u['leader_email'] ?? '' ); ?>" />
												</td>
												<td>
													<?php if ( ! empty( $u['matched_patrols'] ) ) : ?>
														<div style="display: flex; flex-direction: column; gap: 6px;">
															<?php foreach ( $u['matched_patrols'] as $patrol ) : 
																$sec_id   = (int) $patrol['section_id'];
																$sec_name = $managed_sections[ $sec_id ]['name'] ?? "Section #{$sec_id}";
																?>
																<div style="background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px; padding: 4px 8px; font-size: 12px; line-height: 1.35;">
																	<div style="font-weight: 600; color: #1d2327;"><?php echo esc_html( $patrol['name'] ); ?></div>
																	<div style="color: #646970; font-size: 11px; margin-top: 2px;">
																		<span><?php echo esc_html( $sec_name ); ?></span> &bull; <code>ID: <?php echo (int) $patrol['patrol_id']; ?></code>
																	</div>
																</div>
															<?php endforeach; ?>
														</div>
													<?php else : ?>
														<span class="description" style="color: #8c8f94; font-style: italic;"><?php esc_html_e( 'None matched', 'ems-plugin' ); ?></span>
													<?php endif; ?>
												</td>
												<td>
													<select class="ems-move-district-select" style="width: 100%; height: 30px; font-size: 12px; line-height: 1; padding: 2px 4px;">
														<option value=""><?php esc_html_e( '— Keep —', 'ems-plugin' ); ?></option>
														<option value="__unassigned__"><?php esc_html_e( '(Unassigned)', 'ems-plugin' ); ?></option>
														<?php foreach ( $all_districts as $d ) : ?>
															<option value="<?php echo esc_attr( $d ); ?>" <?php selected( $cur_dist, $d ); ?>>
																<?php echo esc_html( $d ); ?>
															</option>
														<?php endforeach; ?>
													</select>
												</td>
												<td style="text-align: center;">
													<button type="submit" name="ems_delete_custom_unit" value="<?php echo $row_id; ?>" 
															class="button button-link-delete" style="color: #b32d2e;"
															onclick="return confirm('<?php echo esc_attr( __( 'Are you sure you want to delete this unit?', 'ems-plugin' ) ); ?>');">
														<?php esc_html_e( 'Delete', 'ems-plugin' ); ?>
													</button>
												</td>
											</tr>
										<?php endforeach; ?>
									<?php endif; ?>
								</tbody>
							</table>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
			<?php if ( ! empty( $units ) ) : ?>
				<p class="submit">
					<input type="submit" name="ems_save_unit_leaders" id="submit" class="button button-primary" value="<?php esc_attr_e( 'Save All Units', 'ems-plugin' ); ?>" />
				</p>
			<?php endif; ?>
		</form>

		<script type="text/javascript">
		document.addEventListener('DOMContentLoaded', function() {
			// 1. Sync district header edits to all units in the card
			document.querySelectorAll('.ems-district-header-input').forEach(function(input) {
				input.addEventListener('input', function() {
					var newDistrict = this.value.trim();
					var card = this.closest('.ems-district-card');
					if (card) {
						card.querySelectorAll('.ems-unit-district-hidden').forEach(function(hidden) {
							hidden.value = newDistrict;
						});
					}
				});
			});

			// 2. Handle Move District select
			document.querySelectorAll('.ems-move-district-select').forEach(function(select) {
				select.addEventListener('change', function() {
					var targetDistrict = this.value;
					if (targetDistrict === '') return;
					if (targetDistrict === '__unassigned__') targetDistrict = '';
					var row = this.closest('.ems-unit-row');
					if (row) {
						var hidden = row.querySelector('.ems-unit-district-hidden');
						if (hidden) {
							hidden.value = targetDistrict;
						}
						row.style.backgroundColor = '#fcf8e3'; // Visual cue for pending move
					}
				});
			});

			// 3. Quick Add button pre-fills District and scrolls to Add Master Unit
			document.querySelectorAll('.ems-quick-add-btn').forEach(function(btn) {
				btn.addEventListener('click', function() {
					var dist = this.getAttribute('data-district');
					var distInput = document.getElementById('custom_unit_district');
					var nameInput = document.getElementById('custom_unit_name');
					if (distInput) {
						distInput.value = dist;
					}
					if (nameInput) {
						nameInput.focus();
						nameInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
					}
				});
			});
		});
		</script>

		<?php if ( ! empty( $unmatched_patrols ) ) : ?>
			<div class="card" style="margin-top: 30px; padding: 20px; max-width: 100%;">
				<h3 style="margin-top: 0; color: #b58100;"><?php esc_html_e( 'Unmatched Synced Patrols from OSM', 'ems-plugin' ); ?></h3>
				<p>
					<?php esc_html_e( 'These patrols were synced from OSM but are not linked to any master unit. You can link them to an existing unit or create a new master unit from them.', 'ems-plugin' ); ?>
				</p>
				<table class="wp-list-table widefat striped" style="margin-top: 15px;">
					<thead>
						<tr>
							<th style="width: 25%;"><?php esc_html_e( 'Patrol Name', 'ems-plugin' ); ?></th>
							<th style="width: 20%;"><?php esc_html_e( 'Section', 'ems-plugin' ); ?></th>
							<th style="width: 15%;"><?php esc_html_e( 'Patrol ID', 'ems-plugin' ); ?></th>
							<th style="width: 25%;"><?php esc_html_e( 'Link to Existing Unit', 'ems-plugin' ); ?></th>
							<th style="width: 15%;"><?php esc_html_e( 'Actions', 'ems-plugin' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $unmatched_patrols as $patrol ) : 
							$patrol_id = (int) $patrol['patrol_id'];
							$sec_id = (int) $patrol['section_id'];
							$sec_name = $managed_sections[ $sec_id ]['name'] ?? "Section #{$sec_id}";
							?>
							<tr>
								<td><strong><?php echo esc_html( $patrol['name'] ); ?></strong></td>
								<td><?php echo esc_html( $sec_name ); ?></td>
								<td><code><?php echo $patrol_id; ?></code></td>
								<td>
									<form method="post" style="display: inline-flex; align-items: center; gap: 6px; width: 100%; margin: 0;">
										<?php wp_nonce_field( 'ems_link_patrol' ); ?>
										<input type="hidden" name="patrol_id" value="<?php echo $patrol_id; ?>" />
										<input type="hidden" name="section_id" value="<?php echo $sec_id; ?>" />
										<select name="link_unit_id" required style="max-width: 200px; width: 100%; height: 30px; line-height: 1; padding: 2px 6px;">
											<option value=""><?php esc_html_e( '— Select Unit —', 'ems-plugin' ); ?></option>
											<?php foreach ( $units as $u ) : ?>
												<option value="<?php echo esc_attr( $u['unit_id'] ); ?>">
													<?php echo esc_html( $u['name'] . ' (' . $u['short_code'] . ')' ); ?>
												</option>
											<?php endforeach; ?>
										</select>
										<button type="submit" name="ems_action" value="link_patrol" class="button button-small" style="height: 30px; line-height: 28px;">
											<?php esc_html_e( 'Link', 'ems-plugin' ); ?>
										</button>
									</form>
								</td>
								<td>
									<form method="post" style="display: inline; margin: 0;">
										<?php wp_nonce_field( 'ems_create_unit_from_patrol' ); ?>
										<input type="hidden" name="patrol_id" value="<?php echo $patrol_id; ?>" />
										<input type="hidden" name="section_id" value="<?php echo $sec_id; ?>" />
										<input type="hidden" name="patrol_name" value="<?php echo esc_attr( $patrol['name'] ); ?>" />
										<button type="submit" name="ems_action" value="create_unit_from_patrol" class="button button-secondary button-small" style="height: 30px; line-height: 28px;">
											<?php esc_html_e( 'Create Master Unit', 'ems-plugin' ); ?>
										</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>

		<div class="card" style="margin-top: 30px; padding: 20px; max-width: 800px;">
			<h3><?php esc_html_e( 'Add Master Unit', 'ems-plugin' ); ?></h3>
			<form method="post">
				<?php wp_nonce_field( 'ems_add_custom_unit' ); ?>
				<table class="form-table" role="presentation" style="margin-top: 0;">
					<tr>
						<th scope="row"><label for="custom_unit_district"><?php esc_html_e( 'District', 'ems-plugin' ); ?></label></th>
						<td>
							<input type="text" name="custom_unit_district" id="custom_unit_district" list="ems_existing_districts" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Borders, Braid, Pentland', 'ems-plugin' ); ?>" />
							<datalist id="ems_existing_districts">
								<?php foreach ( $all_districts as $d ) : ?>
									<option value="<?php echo esc_attr( $d ); ?>"></option>
								<?php endforeach; ?>
							</datalist>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="custom_unit_name"><?php esc_html_e( 'Unit Name', 'ems-plugin' ); ?></label></th>
						<td><input type="text" name="custom_unit_name" id="custom_unit_name" class="regular-text" required /></td>
					</tr>
					<tr>
						<th scope="row"><label for="custom_unit_id"><?php esc_html_e( 'Unit ID (numeric)', 'ems-plugin' ); ?></label></th>
						<td><input type="number" name="custom_unit_id" id="custom_unit_id" class="regular-text" required /></td>
					</tr>
					<tr>
						<th scope="row"><label for="custom_unit_short_code"><?php esc_html_e( 'Short Code', 'ems-plugin' ); ?></label></th>
						<td><input type="text" name="custom_unit_short_code" id="custom_unit_short_code" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="custom_unit_email"><?php esc_html_e( 'Leader Email', 'ems-plugin' ); ?></label></th>
						<td><input type="email" name="custom_unit_email" id="custom_unit_email" class="regular-text" /></td>
					</tr>
				</table>
				<p class="submit" style="margin-bottom: 0;">
					<input type="submit" name="ems_add_custom_unit" class="button button-primary" value="<?php esc_attr_e( 'Add Unit', 'ems-plugin' ); ?>" />
				</p>
			</form>
		</div>

		<div style="display: flex; gap: 20px; margin-top: 30px;">
			<div class="card" style="flex: 1; min-width: 280px; padding: 20px; margin: 0;">
				<h3><?php esc_html_e( 'Export Unit Lookup Table', 'ems-plugin' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Download a JSON file containing only the Unit lookup configurations.', 'ems-plugin' ); ?>
				</p>
				<form method="post">
					<?php wp_nonce_field( 'ems_settings_unit_portability' ); ?>
					<input type="submit" name="ems_export_units" class="button button-secondary" value="<?php esc_attr_e( 'Export Units (.json)', 'ems-plugin' ); ?>" />
				</form>
			</div>

			<div class="card" style="flex: 1; min-width: 280px; padding: 20px; margin: 0;">
				<h3><?php esc_html_e( 'Import Unit Lookup Table', 'ems-plugin' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Upload a units-only JSON backup file. Warning: This will truncate current units data and restore it from the file.', 'ems-plugin' ); ?>
				</p>
				<form method="post" enctype="multipart/form-data">
					<?php wp_nonce_field( 'ems_settings_unit_portability' ); ?>
					<p>
						<input type="file" name="ems_units_backup_file" accept=".json" required />
					</p>
					<input type="submit" name="ems_import_units" class="button button-secondary" value="<?php esc_attr_e( 'Import Units', 'ems-plugin' ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure? This will overwrite the current Unit lookup data.', 'ems-plugin' ); ?>');" />
				</form>
			</div>
		</div>
		<?php
	}
}
