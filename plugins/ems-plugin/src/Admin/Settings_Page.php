<?php
namespace EMS\Admin;

class Settings_Page {

	private const VALID_MODES = array( 'mock', 'live', 'live-auth-only', 'live-limited' );

	private ?\EMS\Integrations\Fluent_Forms_Sync $fluent_sync;

	public function __construct( ?\EMS\Integrations\Fluent_Forms_Sync $fluent_sync = null ) {
		$this->fluent_sync = $fluent_sync;
	}

	public function register(): void {
		add_action( 'admin_init', array( $this, 'maybe_handle_export' ) );

		$settings_hook = add_submenu_page(
			'ems',
			__( 'Settings', 'ems-plugin' ),
			__( 'Settings', 'ems-plugin' ),
			'manage_options',
			'ems-settings',
			array( $this, 'render' )
		);

		if ( $settings_hook ) {
			add_action( "load-{$settings_hook}", array( $this, 'add_help_screen_tabs' ) );
		}

		add_action(
			'admin_enqueue_scripts',
			function ( $hook ) use ( $settings_hook ) {
				if ( $hook === $settings_hook ) {
					$css_path = plugin_dir_path( EMS_PLUGIN_FILE ) . 'assets/js/ems-admin.css';
					$css_url  = plugin_dir_url( EMS_PLUGIN_FILE ) . 'assets/js/ems-admin.css';
					$css_version = EMS_VERSION . ( file_exists( $css_path ) ? '.' . filemtime( $css_path ) : '' );
					wp_enqueue_style( 'ems-admin', $css_url, array(), $css_version );
				}
			}
		);
	}

	public function add_help_screen_tabs( mixed $screen = null ): void {
		if ( ! is_object( $screen ) && function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
		}
		if ( ! is_object( $screen ) || ! is_callable( array( $screen, 'add_help_tab' ) ) ) {
			return;
		}

		$screen->add_help_tab(
			array(
				'id'      => 'ems-help-overview',
				'title'   => __( 'EMS Overview', 'ems-plugin' ),
				'content' => '<p>' . esc_html__( 'The Expedition Management System (EMS) coordinates DofE expeditions, participant portals, volunteer signups, and route planning.', 'ems-plugin' ) . '</p>',
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'ems-help-shortcodes',
				'title'   => __( 'Shortcodes', 'ems-plugin' ),
				'content' => '<p>' . esc_html__( 'EMS provides 5 frontend shortcodes: [ems-portal], [ems-leader-dashboard], [ems-volunteer-signup], [ems_signup_banner], and [ems_route_card_studio]. View the "Help & Shortcodes" tab for full parameter tables and examples.', 'ems-plugin' ) . '</p>',
			)
		);
	}

	public function save_general( array $post_data ): void {
		$mode = in_array( $post_data['ems_api_mode'] ?? '', self::VALID_MODES, true )
			? $post_data['ems_api_mode']
			: 'mock';
		update_option( 'ems_api_mode', $mode );

		$limit = max( 1, (int) ( $post_data['ems_sync_limit'] ?? 5 ) );
		update_option( 'ems_sync_limit', $limit );

		$log_guard = isset( $post_data['ems_debug_log_guard'] ) ? 1 : 0;
		update_option( 'ems_debug_log_guard', $log_guard );

		$query_email_raw = trim( (string) ( $post_data['ems_offer_query_email'] ?? '' ) );
		$query_email     = $query_email_raw !== '' ? sanitize_email( $query_email_raw ) : '';
		update_option( 'ems_offer_query_email', $query_email );
	}

	public function save_connection( array $post_data ): void {
		$raw_url = $post_data['ems_osm_api_base_url'] ?? '';
		$url     = esc_url_raw( rtrim( $raw_url, '/' ) );
		$url     = preg_replace( '#/api\.php$#', '', $url );
		if ( $url !== '' && filter_var( $url, FILTER_VALIDATE_URL ) && str_starts_with( $url, 'https://' ) ) {
			update_option( 'ems_osm_api_base_url', $url );
		}

		foreach ( array( 'ems_osm_auth_url', 'ems_osm_token_url', 'ems_osm_resource_url' ) as $key ) {
			$val = esc_url_raw( $post_data[ $key ] ?? '' );
			if ( $val ) {
				update_option( $key, $val );
			}
		}

		$client_id = sanitize_text_field( $post_data['ems_osm_client_id'] ?? '' );
		if ( $client_id ) {
			update_option( 'ems_osm_client_id', $client_id );
		}

		$client_secret = $post_data['ems_osm_client_secret'] ?? '';
		if ( $client_secret ) {
			$encrypted = \EMS\Core\Encryption::encrypt( $client_secret );
			if ( $encrypted ) {
				update_option( 'ems_osm_client_secret', $encrypted );
			}
		}

		$scope = sanitize_text_field( $post_data['ems_osm_scope'] ?? '' );
		if ( $scope !== '' ) {
			update_option( 'ems_osm_scope', $scope );
		}
	}

	/**
	 * Legacy entry-point used by existing callers (Plugin.php etc.).
	 * Routes to the appropriate per-tab save based on which submit button was pressed.
	 */
	public function save_settings( array $post_data ): void {
		if ( isset( $post_data['ems_save_general'] ) ) {
			$this->save_general( $post_data );
		} elseif ( isset( $post_data['ems_save_connection'] ) ) {
			$this->save_connection( $post_data );
		} elseif ( isset( $post_data['ems_save_form_mappings'] ) ) {
			$this->save_form_mappings( $post_data );
		} elseif ( isset( $post_data['ems_resync_fluent_forms'] ) ) {
			$this->handle_resync_fluent_forms();
		} elseif ( isset( $post_data['ems_save_access_control'] ) ) {
			$this->save_access_control( $post_data );
		} elseif ( isset( $post_data['ems_save_deadlines'] ) ) {
			$this->save_deadlines( $post_data );
		} else {
			$this->save_general( $post_data );
		}
	}

	/**
	 * Checks if a settings backup export request has been made and handles it before headers are sent.
	 *
	 * @return void
	 */
	public function maybe_handle_export(): void {
		if ( isset( $_POST['ems_export_backup'] ) && check_admin_referer( 'ems_settings_backups' ) ) {
			$this->handle_export();
		}
	}

	public function render(): void {
		// Redirect legacy email template tabs to the centralized Emails & Notifications hub
		$requested_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		if ( in_array( $requested_tab, array( 'leader_invite', 'chaser_emails' ), true ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=ems-emails' ) );
			return;
		}

		if ( isset( $_POST['ems_save_general'] ) && check_admin_referer( 'ems_settings_general' ) ) {
			$this->save_general( $_POST );
		} elseif ( isset( $_POST['ems_save_connection'] ) && check_admin_referer( 'ems_settings_connection' ) ) {
			$this->save_connection( $_POST );
		} elseif ( isset( $_POST['ems_save_form_mappings'] ) && check_admin_referer( 'ems_settings_form_mappings' ) ) {
			$this->save_form_mappings( $_POST );
		} elseif ( isset( $_POST['ems_resync_fluent_forms'] ) && check_admin_referer( 'ems_settings_form_mappings' ) ) {
			$this->handle_resync_fluent_forms();
		} elseif ( isset( $_POST['ems_save_access_control'] ) && check_admin_referer( 'ems_settings_access_control' ) ) {
			$this->save_access_control( $_POST );
		} elseif ( isset( $_POST['ems_import_backup'] ) && check_admin_referer( 'ems_settings_backups' ) ) {
			$this->handle_import();
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		$page_url   = admin_url( 'admin.php?page=ems-settings' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'EMS Settings', 'ems-plugin' ); ?></h1>
			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( $page_url . '&tab=general' ); ?>"
					class="nav-tab<?php echo $active_tab === 'general' ? ' nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'General', 'ems-plugin' ); ?>
				</a>
				<a href="<?php echo esc_url( $page_url . '&tab=connection' ); ?>"
					class="nav-tab<?php echo $active_tab === 'connection' ? ' nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'OSM Connection', 'ems-plugin' ); ?>
				</a>
				<a href="<?php echo esc_url( $page_url . '&tab=form_mappings' ); ?>"
					class="nav-tab<?php echo $active_tab === 'form_mappings' ? ' nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Form Mappings', 'ems-plugin' ); ?>
				</a>
				<a href="<?php echo esc_url( $page_url . '&tab=backups' ); ?>"
					class="nav-tab<?php echo $active_tab === 'backups' ? ' nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Backups', 'ems-plugin' ); ?>
				</a>
				<a href="<?php echo esc_url( $page_url . '&tab=audit_logs' ); ?>"
					class="nav-tab<?php echo $active_tab === 'audit_logs' ? ' nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Audit Logs', 'ems-plugin' ); ?>
				</a>
				<a href="<?php echo esc_url( $page_url . '&tab=access_control' ); ?>"
					class="nav-tab<?php echo $active_tab === 'access_control' ? ' nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Access Control', 'ems-plugin' ); ?>
				</a>
				<a href="<?php echo esc_url( $page_url . '&tab=deadlines' ); ?>"
					class="nav-tab<?php echo $active_tab === 'deadlines' ? ' nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Deadlines & Cutoffs', 'ems-plugin' ); ?>
				</a>
				<a href="<?php echo esc_url( $page_url . '&tab=help' ); ?>"
					class="nav-tab<?php echo $active_tab === 'help' ? ' nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Help & Shortcodes', 'ems-plugin' ); ?>
				</a>
			</nav>
			<?php
			if ( $active_tab === 'connection' ) {
				$this->render_connection_tab();
			} elseif ( $active_tab === 'form_mappings' ) {
				$this->render_form_mappings_tab();
			} elseif ( $active_tab === 'backups' ) {
				$this->render_backups_tab();
			} elseif ( $active_tab === 'audit_logs' ) {
				$this->render_audit_logs_tab();
			} elseif ( $active_tab === 'access_control' ) {
				$this->render_access_control_tab();
			} elseif ( $active_tab === 'deadlines' ) {
				$this->render_deadlines_tab();
			} elseif ( $active_tab === 'help' ) {
				$this->render_help_tab();
			} else {
				$this->render_general_tab();
			}
			?>
		</div>
		<?php
	}

	private function render_general_tab(): void {
		$mode  = get_option( 'ems_api_mode', 'mock' );
		$limit = (int) get_option( 'ems_sync_limit', 5 );
		if ( isset( $_GET['purged'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'All reference data and submissions purged successfully.', 'ems-plugin' ) . '</p></div>';
		}
		if ( isset( $_GET['seeded'] ) ) {
			$p_count = isset( $_GET['p_count'] ) ? (int) $_GET['p_count'] : 0;
			$e_count = isset( $_GET['e_count'] ) ? (int) $_GET['e_count'] : 0;
			echo '<div class="notice notice-success is-dismissible"><p>' . sprintf( esc_html__( 'Successfully seeded %1$d participant place submissions and %2$d expedition preference submissions.', 'ems-plugin' ), $p_count, $e_count ) . '</p></div>';
		}
		if ( isset( $_GET['seed_error'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['seed_error'] ) ) ) . '</p></div>';
		}
		?>
		<form method="post">
			<?php wp_nonce_field( 'ems_settings_general' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'API Mode', 'ems-plugin' ); ?></th>
					<td>
						<select name="ems_api_mode" id="ems_api_mode">
							<option value="mock"           <?php selected( $mode, 'mock' ); ?>><?php esc_html_e( 'Mock', 'ems-plugin' ); ?></option>
							<option value="live"           <?php selected( $mode, 'live' ); ?>><?php esc_html_e( 'Live', 'ems-plugin' ); ?></option>
							<option value="live-auth-only" <?php selected( $mode, 'live-auth-only' ); ?>><?php esc_html_e( 'Live — Auth + payload only', 'ems-plugin' ); ?></option>
							<option value="live-limited"   <?php selected( $mode, 'live-limited' ); ?>><?php esc_html_e( 'Live — Limited sync (testing)', 'ems-plugin' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Use Mock to test locally. Live-auth-only and Live-limited are for incremental live testing.', 'ems-plugin' ); ?></p>
					</td>
				</tr>
				<tr id="ems-sync-limit-row" <?php echo $mode !== 'live-limited' ? 'style="display:none"' : ''; ?>>
					<th scope="row"><?php esc_html_e( 'Sync Limit', 'ems-plugin' ); ?></th>
					<td>
						<input type="number" name="ems_sync_limit" value="<?php echo esc_attr( $limit ); ?>" min="1" max="100" class="small-text" />
						<p class="description"><?php esc_html_e( 'Maximum members to sync per section in live-limited mode.', 'ems-plugin' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Sensitive Log Guarding', 'ems-plugin' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="ems_debug_log_guard" value="1" <?php checked( get_option( 'ems_debug_log_guard', 0 ), 1 ); ?> />
							<?php esc_html_e( 'Guard metadata enrichment logs (redact child emails & DOBs in system logs).', 'ems-plugin' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="ems_offer_query_email"><?php esc_html_e( 'Place Offer Query Email', 'ems-plugin' ); ?></label>
					</th>
					<td>
						<input
							type="email"
							id="ems_offer_query_email"
							name="ems_offer_query_email"
							value="<?php echo esc_attr( (string) get_option( 'ems_offer_query_email', '' ) ); ?>"
							placeholder="<?php echo esc_attr( (string) get_option( 'admin_email', '' ) ); ?>"
							class="regular-text"
						/>
						<p class="description">
							<?php esc_html_e( 'Destination email for Place Offer queries submitted from the Participant Portal. Defaults to the WordPress Admin Email if left blank.', 'ems-plugin' ); ?>
						</p>
					</td>
				</tr>
			</table>
			<p class="submit">
				<input type="submit" name="ems_save_general" class="button-primary" value="<?php esc_attr_e( 'Save General Settings', 'ems-plugin' ); ?>" />
			</p>
		</form>
		<script>
		jQuery(document).ready(function($) {
			$('#ems_api_mode').on('change', function() {
				$('#ems-sync-limit-row').toggle($(this).val() === 'live-limited');
			});
		});
		</script>

		<hr class="ems-divider ems-divider--settings" />
		<h3 class="ems-text-danger"><?php esc_html_e( 'Danger Zone / Test Seeding', 'ems-plugin' ); ?></h3>
		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'Purge Database', 'ems-plugin' ); ?></th>
				<td>
					<p class="description" style="margin-bottom: 10px;"><?php esc_html_e( 'Permanently deletes all synced OSM reference data, form submissions, teams, and expeditions from the database. This cannot be undone.', 'ems-plugin' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="
						var phrase = prompt('WARNING: This will permanently delete all form submissions, teams, expeditions, and synced OSM reference data. To confirm, please type \'PURGE SYSTEM\' (case sensitive):');
						if (phrase !== 'PURGE SYSTEM') {
							alert('Deletion cancelled. The confirmation phrase did not match.');
							return false;
						}
						document.getElementById('ems_purge_phrase').value = phrase;
						return true;
					">
						<?php wp_nonce_field( 'ems_purge_osm_data' ); ?>
						<input type="hidden" name="action" value="ems_purge_osm_data" />
						<input type="hidden" name="ems_purge_phrase" id="ems_purge_phrase" value="" />
						<input type="submit" class="button button-link-delete" value="<?php esc_attr_e( 'Purge All Reference Data & Submissions', 'ems-plugin' ); ?>" />
					</form>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Seed Test Data', 'ems-plugin' ); ?></th>
				<td>
					<p class="description" style="margin-bottom: 10px;"><?php esc_html_e( 'Clears old test signups, expeditions, and teams, then creates mock expeditions and generates Fluent Forms submissions for each synced explorer.', 'ems-plugin' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'ems_seed_test_data' ); ?>
						<input type="hidden" name="action" value="ems_seed_test_data" />
						<input type="submit" class="button button-secondary" value="<?php esc_attr_e( 'Seed Test Data', 'ems-plugin' ); ?>" />
					</form>
				</td>
			</tr>
		</table>
		<?php
	}

	private function render_connection_tab(): void {
		$api_url      = get_option( 'ems_osm_api_base_url', 'https://www.onlinescoutmanager.co.uk' );
		$auth_url     = get_option( 'ems_osm_auth_url', 'https://www.onlinescoutmanager.co.uk/oauth/authorize' );
		$token_url    = get_option( 'ems_osm_token_url', 'https://www.onlinescoutmanager.co.uk/oauth/token' );
		$resource_url = get_option( 'ems_osm_resource_url', 'https://www.onlinescoutmanager.co.uk/oauth/resource' );
		$client_id    = get_option( 'ems_osm_client_id', '' );
		$scope        = get_option( 'ems_osm_scope', 'section:member:read section:event:write section:flexirecord:write' );
		$has_secret   = ! empty( get_option( 'ems_osm_client_secret' ) );
		$redirect_uri = admin_url( 'admin-post.php?action=ems_osm_callback' );
		?>
		<form method="post">
			<?php wp_nonce_field( 'ems_settings_connection' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Redirect URI (Callback)', 'ems-plugin' ); ?></th>
					<td>
						<code><?php echo esc_html( $redirect_uri ); ?></code>
						<p class="description"><?php esc_html_e( 'Copy this into the Redirect URL field in your OSM OAuth application.', 'ems-plugin' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'OSM Client ID', 'ems-plugin' ); ?></th>
					<td><input type="text" name="ems_osm_client_id" value="<?php echo esc_attr( $client_id ); ?>" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'OSM Client Secret', 'ems-plugin' ); ?></th>
					<td>
						<input type="password" name="ems_osm_client_secret" value="" class="regular-text" placeholder="<?php echo $has_secret ? '••••••••' : ''; ?>" />
						<p class="description">
							<?php
							echo $has_secret
								? esc_html__( 'Secret is set. Leave blank to keep current value.', 'ems-plugin' )
								: esc_html__( 'Enter your OSM OAuth client secret. Stored encrypted.', 'ems-plugin' );
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'OSM API Base URL', 'ems-plugin' ); ?></th>
					<td>
						<input type="url" name="ems_osm_api_base_url" value="<?php echo esc_attr( $api_url ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'OSM base URL (origin only, no trailing slash). Endpoint paths are appended automatically.', 'ems-plugin' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Authorization URL', 'ems-plugin' ); ?></th>
					<td><input type="url" name="ems_osm_auth_url" value="<?php echo esc_attr( $auth_url ); ?>" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Token URL', 'ems-plugin' ); ?></th>
					<td><input type="url" name="ems_osm_token_url" value="<?php echo esc_attr( $token_url ); ?>" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Resource Owner URL', 'ems-plugin' ); ?></th>
					<td><input type="url" name="ems_osm_resource_url" value="<?php echo esc_attr( $resource_url ); ?>" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'OAuth Scope', 'ems-plugin' ); ?></th>
					<td>
						<input type="text" name="ems_osm_scope" value="<?php echo esc_attr( $scope ); ?>" class="large-text" />
						<p class="description"><?php esc_html_e( 'Space-separated OAuth scopes requested during authorization.', 'ems-plugin' ); ?></p>
					</td>
				</tr>
			</table>
			<p class="submit">
				<input type="submit" name="ems_save_connection" class="button-primary" value="<?php esc_attr_e( 'Save Connection Settings', 'ems-plugin' ); ?>" />
			</p>
		</form>
		<?php
	}

	private function save_form_mappings( array $post ): void {
		$p_id = (int) ( $post['ems_fluent_participant_form_id'] ?? 6 );
		update_option( 'ems_fluent_participant_form_id', $p_id );

		$e_id = (int) ( $post['ems_fluent_expedition_form_id'] ?? 7 );
		update_option( 'ems_fluent_expedition_form_id', $e_id );

		$part_mappings = isset( $post['ems_participant_form_mappings'] ) && is_array( $post['ems_participant_form_mappings'] ) ? $post['ems_participant_form_mappings'] : array();
		$exp_mappings  = isset( $post['ems_expedition_form_mappings'] ) && is_array( $post['ems_expedition_form_mappings'] ) ? $post['ems_expedition_form_mappings'] : array();

		$part_mappings = array_map( 'sanitize_text_field', $part_mappings );
		$exp_mappings  = array_map( 'sanitize_text_field', $exp_mappings );

		update_option( 'ems_participant_form_mappings', $part_mappings );
		update_option( 'ems_expedition_form_mappings', $exp_mappings );

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Form configurations saved.', 'ems-plugin' ) . '</p></div>';
	}

	public function handle_resync_fluent_forms(): void {
		$sync   = $this->fluent_sync ?: new \EMS\Integrations\Fluent_Forms_Sync();
		$result = $sync->resync_existing_signups();

		$updated  = $result['updated'];
		$archived = $result['archived'];

		$message = sprintf(
			/* translators: 1: number of updated signups, 2: number of archived signups */
			__( 'Fluent Forms re-sync complete: %1$d signup(s) updated, %2$d signup(s) archived (missing or deleted form/entry).', 'ems-plugin' ),
			$updated,
			$archived
		);

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	private function save_access_control( array $post ): void {
		$raw_page_roles = $post['ems_page_roles'] ?? array();
		$page_roles     = array();

		foreach ( $raw_page_roles as $page_id => $roles ) {
			$page_id = (int) $page_id;
			$roles   = array_map( 'sanitize_text_field', (array) $roles );
			if ( ! empty( $roles ) && $page_id > 0 ) {
				$page_roles[ $page_id ] = $roles;
			}
		}

		update_option( 'ems_page_roles', $page_roles );

		$protect_tutor = ! empty( $post['ems_protect_tutor_lms'] );
		update_option( 'ems_protect_tutor_lms', $protect_tutor );

		if ( isset( $post['ems_leader_dashboard_page_id'] ) ) {
			update_option( 'ems_leader_dashboard_page_id', max( 0, (int) $post['ems_leader_dashboard_page_id'] ) );
		}

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Access control settings saved.', 'ems-plugin' ) . '</p></div>';
	}

	private function render_form_mappings_tab(): void {
		$p_id = (int) get_option( 'ems_fluent_participant_form_id', 6 );
		$e_id = (int) get_option( 'ems_fluent_expedition_form_id', 7 );

		$part_mappings = array_merge(
			array(
				'scout_id_field'          => 'signup_child',
				'first_name_field'        => 'signup_child_name',
				'last_name_field'         => 'signup_child_name',
				'dofe_level_field'        => 'signup_level',
				'dob_field'               => 'signup_dob',
				'dofe_registered_field'   => 'signup_dofe_registered',
				'dofe_number_field'       => 'signup_dofe_number',
				'dofe_org_field'          => 'signup_dofe_org',
				'bronze_completion_field' => 'signup_bronze_completion',
				'silver_completion_field' => 'signup_silver_completion',
				'esu_patrol_field'        => 'signup_unit',
				'explorer_email_field'    => 'signup_explorer_email',
				'parent_email_field'      => 'signup_parent_email',
				'leader_email_field'      => 'signup_leader_email',
			),
			get_option( 'ems_participant_form_mappings', array() )
		);

		$exp_mappings = array_merge(
			array(
				'scout_id_field'               => 'signup_child',
				'first_name_field'             => 'signup_child_name',
				'last_name_field'              => 'signup_child_name',
				'dob_field'                    => 'signup_dob',
				'dofe_level_field'             => 'signup_level',
				'dofe_registered_field'        => 'signup_edofe_registered',
				'dofe_number_field'            => 'signup_dofe_number',
				'esu_patrol_field'             => 'signup_unit',
				'explorer_email_field'         => 'signup_email',
				'parent_email_field'           => 'signup_parent_email',
				'leader_email_field'           => 'signup_leader_email',
				'exped_type_field'             => 'exped_type',
				'exped_type_bronze_field'      => 'exped_type_bronze',
				'bronze_dates_field'           => 'exped_bronze_dates',
				'practice_dates_field'         => 'exped_practice_dates',
				'qualifier_dates_field'        => 'exped_qualifier_dates',
				'silver_practice_dates_field'  => 'exped_silver_practice_dates',
				'gold_practice_dates_field'    => 'exped_gold_practice_dates',
				'silver_qualifier_dates_field' => 'exped_silver_qualifier_dates',
				'gold_qualifier_dates_field'   => 'exped_gold_qualifier_dates',
				'team_names_field'             => 'exped_team_names',
				'asn_field'                    => 'description',
				'first_aid_field'              => 'input_radio',
				'first_aid_expiry_field'       => 'datetime',
			),
			get_option( 'ems_expedition_form_mappings', array() )
		);
		?>
		<h2><?php esc_html_e( 'Fluent Form configurations', 'ems-plugin' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( 'ems_settings_form_mappings' ); ?>
			
			<h3 style="margin-top: 20px; border-bottom: 1px solid #ccc; padding-bottom: 5px;">
				<?php esc_html_e( 'Form IDs', 'ems-plugin' ); ?>
			</h3>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="ems_fluent_participant_form_id"><?php esc_html_e( 'Participant Place Form ID', 'ems-plugin' ); ?></label></th>
						<td>
							<input name="ems_fluent_participant_form_id" type="number" id="ems_fluent_participant_form_id" value="<?php echo esc_attr( $p_id ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'The Fluent Form ID for Participant Place registration requests.', 'ems-plugin' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ems_fluent_expedition_form_id"><?php esc_html_e( 'Expedition Signup Form ID', 'ems-plugin' ); ?></label></th>
						<td>
							<input name="ems_fluent_expedition_form_id" type="number" id="ems_fluent_expedition_form_id" value="<?php echo esc_attr( $e_id ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'The Fluent Form ID for Expedition Preferences/Details requests.', 'ems-plugin' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>

			<h3 style="margin-top: 30px; border-bottom: 1px solid #ccc; padding-bottom: 5px;">
				<?php esc_html_e( 'Participant Place Form Field Mappings', 'ems-plugin' ); ?>
			</h3>
			<table class="form-table" role="presentation">
				<tbody>
					<?php
					$part_fields = array(
						'scout_id_field'          => __( 'Scout ID Selection Field', 'ems-plugin' ),
						'first_name_field'        => __( 'First Name Field', 'ems-plugin' ),
						'last_name_field'         => __( 'Last Name Field', 'ems-plugin' ),
						'dofe_level_field'        => __( 'DofE Level Field', 'ems-plugin' ),
						'dob_field'               => __( 'Date of Birth Field', 'ems-plugin' ),
						'dofe_registered_field'   => __( 'DofE Registered Field', 'ems-plugin' ),
						'dofe_number_field'       => __( 'DofE Number Field', 'ems-plugin' ),
						'dofe_org_field'          => __( 'DofE Organisation Field', 'ems-plugin' ),
						'bronze_completion_field' => __( 'Bronze Completion Field', 'ems-plugin' ),
						'silver_completion_field' => __( 'Silver Completion Field', 'ems-plugin' ),
						'esu_patrol_field'        => __( 'ESU / Patrol Field', 'ems-plugin' ),
						'explorer_email_field'    => __( 'Explorer Email Field', 'ems-plugin' ),
						'parent_email_field'      => __( 'Parent Email Field', 'ems-plugin' ),
						'leader_email_field'      => __( 'Leader Email Field', 'ems-plugin' ),
					);
					foreach ( $part_fields as $key => $label ) :
						?>
						<tr>
							<th scope="row"><label for="part_map_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
							<td>
								<input name="ems_participant_form_mappings[<?php echo esc_attr( $key ); ?>]" type="text" id="part_map_<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $part_mappings[ $key ] ?? '' ); ?>" class="regular-text" />
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h3 style="margin-top: 30px; border-bottom: 1px solid #ccc; padding-bottom: 5px;">
				<?php esc_html_e( 'Expedition Signup Form Field Mappings', 'ems-plugin' ); ?>
			</h3>
			<table class="form-table" role="presentation">
				<tbody>
					<?php
					$exp_fields = array(
						'scout_id_field'               => __( 'Scout ID Selection Field', 'ems-plugin' ),
						'first_name_field'             => __( 'First Name Field', 'ems-plugin' ),
						'last_name_field'              => __( 'Last Name Field', 'ems-plugin' ),
						'dob_field'                    => __( 'Date of Birth Field', 'ems-plugin' ),
						'dofe_level_field'             => __( 'DofE Level Field', 'ems-plugin' ),
						'dofe_registered_field'        => __( 'DofE Registered Field', 'ems-plugin' ),
						'dofe_number_field'            => __( 'DofE Number Field', 'ems-plugin' ),
						'esu_patrol_field'             => __( 'ESU / Patrol Field', 'ems-plugin' ),
						'explorer_email_field'         => __( 'Explorer Email Field', 'ems-plugin' ),
						'parent_email_field'           => __( 'Parent Email Field', 'ems-plugin' ),
						'leader_email_field'           => __( 'Leader Email Field', 'ems-plugin' ),
						'exped_type_field'             => __( 'Expedition Type (Silver/Gold) Field', 'ems-plugin' ),
						'exped_type_bronze_field'      => __( 'Expedition Type (Bronze) Field', 'ems-plugin' ),
						'bronze_dates_field'           => __( 'Bronze Dates Field', 'ems-plugin' ),
						'practice_dates_field'         => __( 'Practice Dates (Legacy/Fallback) Field', 'ems-plugin' ),
						'qualifier_dates_field'        => __( 'Qualifier Dates (Legacy/Fallback) Field', 'ems-plugin' ),
						'silver_practice_dates_field'  => __( 'Silver Practice Dates Field', 'ems-plugin' ),
						'gold_practice_dates_field'    => __( 'Gold Practice Dates Field', 'ems-plugin' ),
						'silver_qualifier_dates_field' => __( 'Silver Qualifier Dates Field', 'ems-plugin' ),
						'gold_qualifier_dates_field'   => __( 'Gold Qualifier Dates Field', 'ems-plugin' ),
						'team_names_field'             => __( 'Team/Preferences Field', 'ems-plugin' ),
						'asn_field'                    => __( 'Additional Support Needs Field', 'ems-plugin' ),
						'first_aid_field'              => __( 'First Aid Status Field', 'ems-plugin' ),
						'first_aid_expiry_field'       => __( 'First Aid Expiry Field', 'ems-plugin' ),
					);
					foreach ( $exp_fields as $key => $label ) :
						?>
						<tr>
							<th scope="row"><label for="exp_map_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
							<td>
								<input name="ems_expedition_form_mappings[<?php echo esc_attr( $key ); ?>]" type="text" id="exp_map_<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $exp_mappings[ $key ] ?? '' ); ?>" class="regular-text" />
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p class="submit">
				<input type="submit" name="ems_save_form_mappings" id="submit" class="button button-primary" value="<?php esc_attr_e( 'Save Form Configuration', 'ems-plugin' ); ?>" />
				<input type="submit" name="ems_resync_fluent_forms" id="ems_resync_fluent_forms" class="button button-secondary" style="margin-left: 8px;" value="<?php esc_attr_e( 'Re-sync Signups from Fluent Forms', 'ems-plugin' ); ?>" />
			</p>
			<p class="description">
				<?php esc_html_e( 'Use "Re-sync Signups from Fluent Forms" to re-parse existing signup entries with the current field mappings and archive any entries whose Fluent Forms submission or form has been deleted.', 'ems-plugin' ); ?>
			</p>
		</form>
		<?php
	}

	private function render_audit_logs_tab(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'ems_audit_logs';

		$filter_action = isset( $_GET['ems_filter_action'] ) ? sanitize_text_field( wp_unslash( $_GET['ems_filter_action'] ) ) : '';
		$filter_user   = isset( $_GET['ems_filter_user'] ) ? (int) $_GET['ems_filter_user'] : 0;
		$filter_scout  = isset( $_GET['ems_filter_scout'] ) ? (int) $_GET['ems_filter_scout'] : 0;
		$filter_start  = isset( $_GET['ems_filter_start'] ) ? sanitize_text_field( wp_unslash( $_GET['ems_filter_start'] ) ) : '';
		$filter_end    = isset( $_GET['ems_filter_end'] ) ? sanitize_text_field( wp_unslash( $_GET['ems_filter_end'] ) ) : '';

		$paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$per_page = 20;
		$offset   = ( $paged - 1 ) * $per_page;

		$where = array( '1=1' );
		$args  = array();

		if ( $filter_action !== '' ) {
			$where[] = 'action = %s';
			$args[]  = $filter_action;
		}
		if ( $filter_user > 0 ) {
			$where[] = 'user_id = %d';
			$args[]  = $filter_user;
		}
		if ( $filter_scout > 0 ) {
			$where[] = 'target_scout_id = %d';
			$args[]  = $filter_scout;
		}
		if ( $filter_start !== '' ) {
			$where[] = 'timestamp >= %s';
			$args[]  = $filter_start . ' 00:00:00';
		}
		if ( $filter_end !== '' ) {
			$where[] = 'timestamp <= %s';
			$args[]  = $filter_end . ' 23:59:59';
		}

		$where_clause = implode( ' AND ', $where );

		// Count
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}";
		if ( ! empty( $args ) ) {
			$count_sql = $wpdb->prepare( $count_sql, ...$args );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared safely above
		$total_items = (int) $wpdb->get_var( $count_sql );

		// Query results
		$results_sql = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY id DESC";
		if ( ! empty( $args ) ) {
			$results_sql = $wpdb->prepare( $results_sql, ...$args );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared safely above
		$results_sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $per_page, $offset );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared safely above
		$logs = $wpdb->get_results( $results_sql, ARRAY_A ) ?: array();

		$total_pages = (int) ceil( $total_items / $per_page );
		$page_url    = admin_url( 'admin.php?page=ems-settings&tab=audit_logs' );

		// Build filter parameters for pagination links
		$link_params = '';
		if ( $filter_action !== '' ) {
			$link_params .= '&ems_filter_action=' . urlencode( $filter_action );
		}
		if ( $filter_user > 0 ) {
			$link_params .= '&ems_filter_user=' . $filter_user;
		}
		if ( $filter_scout > 0 ) {
			$link_params .= '&ems_filter_scout=' . $filter_scout;
		}
		if ( $filter_start !== '' ) {
			$link_params .= '&ems_filter_start=' . urlencode( $filter_start );
		}
		if ( $filter_end !== '' ) {
			$link_params .= '&ems_filter_end=' . urlencode( $filter_end );
		}
		?>
		<style>
			.ems-audit-filters {
				background: #fff;
				border: 1px solid #ccd0d4;
				padding: 15px;
				margin-bottom: 20px;
				margin-top: 15px;
				display: flex;
				flex-wrap: wrap;
				gap: 15px;
				align-items: flex-end;
			}
			.ems-audit-filters div {
				display: flex;
				flex-direction: column;
				gap: 5px;
			}
			.ems-audit-filters label {
				font-weight: 600;
			}
			.ems-audit-pagination {
				margin-top: 15px;
				display: flex;
				justify-content: space-between;
				align-items: center;
			}
			.ems-audit-table td {
				word-break: break-all;
				white-space: normal;
				vertical-align: middle;
			}
			.ems-audit-table th {
				vertical-align: middle;
			}
		</style>

		<div class="notice notice-info">
			<p><?php esc_html_e( 'Audit retention policy: Logs are kept for 365 days and capped at a maximum of 50,000 entries. Older records are automatically cleaned up daily.', 'ems-plugin' ); ?></p>
		</div>

		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="ems-settings" />
			<input type="hidden" name="tab" value="audit_logs" />

			<div class="ems-audit-filters">
				<div>
					<label for="ems_filter_action"><?php esc_html_e( 'Action', 'ems-plugin' ); ?></label>
					<select name="ems_filter_action" id="ems_filter_action">
						<option value=""><?php esc_html_e( '— All Actions —', 'ems-plugin' ); ?></option>
						<?php
						$actions = array(
							'team_create'        => 'team_create',
							'team_delete'        => 'team_delete',
							'team_member_add'    => 'team_member_add',
							'team_member_remove' => 'team_member_remove',
							'team_member_move'   => 'team_member_move',
							'setting_update'     => 'setting_update',
							'event_update'       => 'event_update',
							'explorer_update'    => 'explorer_update',
							'sync_start'         => 'sync_start',
							'sync_success'       => 'sync_success',
							'sync_failure'       => 'sync_failure',
							'view_gpx'           => 'view_gpx',
							'export_roster'      => 'export_roster',
							'view_asn'           => 'view_asn',
							'login_success'      => 'login_success',
							'login_failure'      => 'login_failure',
							'role_updated'       => 'role_updated',
							'logout'             => 'logout',
						);
						foreach ( $actions as $val => $lbl ) {
							printf( '<option value="%s" %s>%s</option>', esc_attr( $val ), selected( $filter_action, $val, false ), esc_html( $lbl ) );
						}
						?>
					</select>
				</div>
				<div>
					<label for="ems_filter_user"><?php esc_html_e( 'User ID', 'ems-plugin' ); ?></label>
					<input type="number" name="ems_filter_user" id="ems_filter_user" value="<?php echo $filter_user > 0 ? esc_attr( $filter_user ) : ''; ?>" min="1" style="width:100px;" />
				</div>
				<div>
					<label for="ems_filter_scout"><?php esc_html_e( 'Scout ID', 'ems-plugin' ); ?></label>
					<input type="number" name="ems_filter_scout" id="ems_filter_scout" value="<?php echo $filter_scout > 0 ? esc_attr( $filter_scout ) : ''; ?>" min="1" style="width:120px;" />
				</div>
				<div>
					<label for="ems_filter_start"><?php esc_html_e( 'Start Date', 'ems-plugin' ); ?></label>
					<input type="date" name="ems_filter_start" id="ems_filter_start" value="<?php echo esc_attr( $filter_start ); ?>" />
				</div>
				<div>
					<label for="ems_filter_end"><?php esc_html_e( 'End Date', 'ems-plugin' ); ?></label>
					<input type="date" name="ems_filter_end" id="ems_filter_end" value="<?php echo esc_attr( $filter_end ); ?>" />
				</div>
				<div>
					<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Filter Logs', 'ems-plugin' ); ?>" />
					<a href="<?php echo esc_url( $page_url ); ?>" class="button button-secondary"><?php esc_html_e( 'Clear Filters', 'ems-plugin' ); ?></a>
				</div>
			</div>
		</form>

		<table class="ems-table ems-audit-table">
			<thead>
				<tr>
					<th style="width: 60px;"><?php esc_html_e( 'ID', 'ems-plugin' ); ?></th>
					<th style="width: 130px;"><?php esc_html_e( 'User', 'ems-plugin' ); ?></th>
					<th style="width: 150px;"><?php esc_html_e( 'Action', 'ems-plugin' ); ?></th>
					<th style="width: 120px;"><?php esc_html_e( 'Target Scout ID', 'ems-plugin' ); ?></th>
					<th style="width: 120px;"><?php esc_html_e( 'IP Address', 'ems-plugin' ); ?></th>
					<th><?php esc_html_e( 'User Agent', 'ems-plugin' ); ?></th>
					<th style="width: 150px;"><?php esc_html_e( 'Timestamp', 'ems-plugin' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $logs ) ) : ?>
					<tr>
						<td colspan="7"><?php esc_html_e( 'No audit log entries found matching the filter criteria.', 'ems-plugin' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $logs as $log ) : ?>
						<tr>
							<td><?php echo esc_html( $log['id'] ); ?></td>
							<td>
								<?php
								$log_user_id = (int) $log['user_id'];
								if ( $log_user_id === 0 ) {
									esc_html_e( 'System / Unauth', 'ems-plugin' );
								} else {
									$u = get_userdata( $log_user_id );
									echo esc_html( $u ? $u->user_login . ' (ID: ' . $log_user_id . ')' : 'Deleted (ID: ' . $log_user_id . ')' );
								}
								?>
							</td>
							<td><code><?php echo esc_html( $log['action'] ); ?></code></td>
							<td><?php echo $log['target_scout_id'] ? esc_html( $log['target_scout_id'] ) : '—'; ?></td>
							<td><?php echo esc_html( $log['ip_address'] ); ?></td>
							<td>
								<?php echo esc_html( $log['user_agent'] ); ?>
							</td>
							<td><?php echo esc_html( $log['timestamp'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<div class="ems-audit-pagination">
			<div>
				<?php
				printf(
					/* translators: 1: Total matches */
					esc_html__( 'Total Records: %d', 'ems-plugin' ),
					(int) $total_items
				);
				?>
			</div>
			<div>
				<?php if ( $paged > 1 ) : ?>
					<a href="<?php echo esc_url( $page_url . '&paged=' . ( $paged - 1 ) . $link_params ); ?>" class="button"><?php esc_html_e( '« Previous', 'ems-plugin' ); ?></a>
				<?php endif; ?>
				<span><?php printf( esc_html__( 'Page %1$d of %2$d', 'ems-plugin' ), (int) $paged, (int) $total_pages ); ?></span>
				<?php if ( $paged < $total_pages ) : ?>
					<a href="<?php echo esc_url( $page_url . '&paged=' . ( $paged + 1 ) . $link_params ); ?>" class="button"><?php esc_html_e( 'Next »', 'ems-plugin' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private function handle_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'ems-plugin' ) );
		}

		$engine = new \EMS\Core\Portability_Engine();
		$json   = $engine->export_data();
		$filename = 'ems-backup-' . current_time( 'Y-md-His' ) . '.json';

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $json;
		exit;
	}

	private function handle_import(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'ems-plugin' ) );
		}

		if ( empty( $_FILES['ems_backup_file']['tmp_name'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Please upload a backup file.', 'ems-plugin' ) . '</p></div>';
			return;
		}

		try {
			$file_path = sanitize_text_field( wp_unslash( $_FILES['ems_backup_file']['tmp_name'] ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$content = file_get_contents( $file_path );
			if ( ! $content ) {
				throw new \Exception( 'Failed to read uploaded file.' );
			}

			$engine = new \EMS\Core\Portability_Engine();
			$engine->import_data( $content );

			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'EMS backup restored and replicated successfully.', 'ems-plugin' ) . '</p></div>';
		} catch ( \Exception $e ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sprintf( __( 'Restore failed: %s', 'ems-plugin' ), $e->getMessage() ) ) . '</p></div>';
		}
	}

	private function render_backups_tab(): void {
		?>
		<div class="card" style="max-width: 600px; padding: 20px; margin-top: 20px;">
			<h2><?php esc_html_e( 'Export EMS Configuration & Data', 'ems-plugin' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Download a single unified JSON backup file containing all custom database tables (units, signups, events, explorers) and plugin options.', 'ems-plugin' ); ?>
			</p>
			<form method="post">
				<?php wp_nonce_field( 'ems_settings_backups' ); ?>
				<input type="submit" name="ems_export_backup" class="button button-primary" value="<?php esc_attr_e( 'Download Backup (.json)', 'ems-plugin' ); ?>" />
			</form>
		</div>

		<div class="card" style="max-width: 600px; padding: 20px; margin-top: 20px;">
			<h2><?php esc_html_e( 'Import & Replicate Environment', 'ems-plugin' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Upload an EMS JSON backup file. Warning: This will truncate current tables and restore the settings and data from the backup file.', 'ems-plugin' ); ?>
			</p>
			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( 'ems_settings_backups' ); ?>
				<p>
					<input type="file" name="ems_backup_file" accept=".json" required />
				</p>
				<input type="submit" name="ems_import_backup" class="button button-secondary" value="<?php esc_attr_e( 'Upload & Restore', 'ems-plugin' ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure? This will overwrite all current EMS data.', 'ems-plugin' ); ?>');" />
			</form>
		</div>
		<?php
	}

	private function render_access_control_tab(): void {
		$page_roles       = get_option( 'ems_page_roles', array() );
		$protect_tutor    = get_option( 'ems_protect_tutor_lms', true );
		$leader_page_id   = (int) get_option( 'ems_leader_dashboard_page_id', 0 );

		$pages     = get_pages( array( 'post_status' => 'publish' ) );
		$all_roles = wp_roles()->get_names();
		?>
		<form method="post">
			<?php wp_nonce_field( 'ems_settings_access_control' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Page Restrictions', 'ems-plugin' ); ?></th>
					<td>
						<p class="description" style="margin-bottom: 15px;">
							<?php esc_html_e( 'Configure granular access restrictions for specific pages. Public pages (with no restrictions set) are not listed here.', 'ems-plugin' ); ?>
						</p>

						<table class="wp-list-table widefat fixed striped ems-restrictions-table" style="max-width: 850px; border: 1px solid #ccd0d4; box-shadow: none; <?php echo empty( $page_roles ) ? 'display: none;' : ''; ?>">
							<thead>
								<tr>
									<th style="padding: 10px; font-weight: 600; width: 35%;"><?php esc_html_e( 'Page Title', 'ems-plugin' ); ?></th>
									<th style="padding: 10px; font-weight: 600;"><?php esc_html_e( 'Permitted Roles', 'ems-plugin' ); ?></th>
									<th style="padding: 10px; font-weight: 600; width: 15%; text-align: center;"><?php esc_html_e( 'Actions', 'ems-plugin' ); ?></th>
								</tr>
							</thead>
							<tbody id="ems-restrictions-tbody">
								<?php foreach ( $pages as $page ) : 
									if ( ! isset( $page_roles[ $page->ID ] ) ) {
										continue;
									}
									$selected_roles = $page_roles[ $page->ID ];
									?>
									<tr data-page-id="<?php echo esc_attr( $page->ID ); ?>">
										<td style="padding: 10px; vertical-align: middle;">
											<strong><?php echo esc_html( $page->post_title ); ?></strong>
											<span class="description" style="display: block; font-size: 11px; margin-top: 2px;">(ID: <?php echo esc_html( $page->ID ); ?>)</span>
										</td>
										<td style="padding: 10px; vertical-align: middle;">
											<?php foreach ( $all_roles as $role_slug => $role_name ) : 
												if ( $role_slug === 'administrator' ) {
													continue;
												}
												?>
												<label style="margin-right: 15px; display: inline-block; font-size: 13px;">
													<input type="checkbox" name="ems_page_roles[<?php echo esc_attr( $page->ID ); ?>][]" value="<?php echo esc_attr( $role_slug ); ?>" <?php checked( in_array( $role_slug, $selected_roles, true ) ); ?> />
													<?php echo esc_html( translate_user_role( $role_name ) ); ?>
												</label>
											<?php endforeach; ?>
										</td>
										<td style="padding: 10px; vertical-align: middle; text-align: center;">
											<button type="button" class="delete-restriction-btn" style="color: #dc3232; background: none; border: none; padding: 0; margin: 0; cursor: pointer; font-size: 13px; text-decoration: underline; line-height: 1.3; box-shadow: none;" data-page-id="<?php echo esc_attr( $page->ID ); ?>" data-page-title="<?php echo esc_attr( $page->post_title ); ?>">
												<?php esc_html_e( 'Remove', 'ems-plugin' ); ?>
											</button>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>

						<p id="ems-no-restrictions-msg" class="description" style="margin-bottom: 15px; font-style: italic; <?php echo ! empty( $page_roles ) ? 'display: none;' : ''; ?>">
							<?php esc_html_e( 'No page restrictions configured. All pages are currently public.', 'ems-plugin' ); ?>
						</p>

						<?php if ( ! empty( $pages ) ) : ?>
							<div style="margin-top: 15px; background: #f6f7f7; padding: 15px; border-radius: 4px; border: 1px solid #ccd0d4; max-width: 820px;">
								<span style="font-weight: 600; font-size: 13px; margin-right: 10px; vertical-align: middle;"><?php esc_html_e( 'Restrict access to another page:', 'ems-plugin' ); ?></span>
								<select id="ems-add-restriction-select" style="max-width: 350px; vertical-align: middle; font-size: 13px; height: 30px;">
									<option value=""><?php esc_html_e( '-- Select page to restrict --', 'ems-plugin' ); ?></option>
									<?php foreach ( $pages as $page ) : 
										$is_restricted = isset( $page_roles[ $page->ID ] );
										?>
										<option value="<?php echo esc_attr( $page->ID ); ?>" data-title="<?php echo esc_attr( $page->post_title ); ?>" <?php echo $is_restricted ? 'style="display:none;"' : ''; ?>>
											<?php echo esc_html( $page->post_title ); ?> (ID: <?php echo esc_html( $page->ID ); ?>)
										</option>
									<?php endforeach; ?>
								</select>
								<button type="button" id="ems-add-restriction-btn" class="button button-secondary" style="vertical-align: middle; margin-left: 5px; height: 30px;">
									<?php esc_html_e( 'Add Restriction', 'ems-plugin' ); ?>
								</button>
							</div>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Tutor LMS Route Protection', 'ems-plugin' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="ems_protect_tutor_lms" value="1" <?php checked( $protect_tutor ); ?> />
							<?php esc_html_e( 'Enable Tutor LMS Route Protection', 'ems-plugin' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Automatically intercept and restrict access to all Tutor LMS dashboard and course pages (requires the "ems_explorer" role).', 'ems-plugin' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ems_leader_dashboard_page_id"><?php esc_html_e( 'Unit Leader Dashboard Page', 'ems-plugin' ); ?></label></th>
					<td>
						<select id="ems_leader_dashboard_page_id" name="ems_leader_dashboard_page_id" style="max-width: 400px;">
							<option value="0"><?php esc_html_e( '— Auto-detect page with [ems-leader-dashboard] —', 'ems-plugin' ); ?></option>
							<?php foreach ( $pages as $page ) : ?>
								<option value="<?php echo esc_attr( (string) $page->ID ); ?>" <?php selected( $leader_page_id, (int) $page->ID ); ?>>
									<?php echo esc_html( $page->post_title ); ?> (ID: <?php echo esc_html( (string) $page->ID ); ?>)
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Unit leaders (ems_leader role) are automatically signed in and redirected to this page after setting their password or logging in.', 'ems-plugin' ); ?>
						</p>
					</td>
				</tr>
			</table>
			<p class="submit">
				<input type="submit" name="ems_save_access_control" class="button button-primary" value="<?php esc_attr_e( 'Save Access Control Settings', 'ems-plugin' ); ?>" />
			</p>
		</form>

		<template id="ems-row-template">
			<tr data-page-id="{PAGE_ID}">
				<td style="padding: 10px; vertical-align: middle;">
					<strong>{PAGE_TITLE}</strong>
					<span class="description" style="display: block; font-size: 11px; margin-top: 2px;">(ID: {PAGE_ID})</span>
				</td>
				<td style="padding: 10px; vertical-align: middle;">
					<?php foreach ( $all_roles as $role_slug => $role_name ) : 
						if ( $role_slug === 'administrator' ) {
							continue;
						}
						?>
						<label style="margin-right: 15px; display: inline-block; font-size: 13px;">
							<input type="checkbox" name="ems_page_roles[{PAGE_ID}][]" value="<?php echo esc_attr( $role_slug ); ?>" />
							<?php echo esc_html( translate_user_role( $role_name ) ); ?>
						</label>
					<?php endforeach; ?>
				</td>
				<td style="padding: 10px; vertical-align: middle; text-align: center;">
					<button type="button" class="delete-restriction-btn" style="color: #dc3232; background: none; border: none; padding: 0; margin: 0; cursor: pointer; font-size: 13px; text-decoration: underline; line-height: 1.3; box-shadow: none;" data-page-id="{PAGE_ID}" data-page-title="{PAGE_TITLE}">
						<?php esc_html_e( 'Remove', 'ems-plugin' ); ?>
					</button>
				</td>
			</tr>
		</template>

		<script>
		document.addEventListener('DOMContentLoaded', function() {
			var select = document.getElementById('ems-add-restriction-select');
			var addBtn = document.getElementById('ems-add-restriction-btn');
			var table = document.querySelector('.ems-restrictions-table');
			var tbody = document.getElementById('ems-restrictions-tbody');
			var noMsg = document.getElementById('ems-no-restrictions-msg');
			var template = document.getElementById('ems-row-template').innerHTML;

			if (!select || !addBtn || !tbody) {
				return;
			}

			// Add restriction
			addBtn.addEventListener('click', function() {
				var pageId = select.value;
				if (!pageId) {
					return;
				}

				var selectedOption = select.options[select.selectedIndex];
				var pageTitle = selectedOption.getAttribute('data-title');

				// Generate row from template
				var html = template
					.replace(/{PAGE_ID}/g, pageId)
					.replace(/{PAGE_TITLE}/g, pageTitle);

				// Append to table
				tbody.insertAdjacentHTML('beforeend', html);

				// Hide page option from select dropdown
				selectedOption.style.display = 'none';
				select.value = '';

				// Toggle table and empty state visibility
				table.style.display = 'table';
				noMsg.style.display = 'none';
			});

			// Remove restriction (using event delegation)
			tbody.addEventListener('click', function(e) {
				if (e.target && e.target.classList.contains('delete-restriction-btn')) {
					var pageId = e.target.getAttribute('data-page-id');

					// Find and remove row
					var row = tbody.querySelector('tr[data-page-id="' + pageId + '"]');
					if (row) {
						row.remove();
					}

					// Restore option in select dropdown
					for (var i = 0; i < select.options.length; i++) {
						if (select.options[i].value === pageId) {
							select.options[i].style.display = 'block';
							break;
						}
					}

					// Toggle empty state if no rows left
					if (tbody.children.length === 0) {
						table.style.display = 'none';
						noMsg.style.display = 'block';
					}
				}
			});
		});
		</script>
		<?php
	}

	/**
	 * Renders the Help & Shortcodes documentation tab.
	 */
	private function render_help_tab(): void {
		?>
		<div style="max-width: 1000px; margin-top: 20px;">
			<div class="card" style="max-width: 100%; padding: 18px 24px; margin-bottom: 24px; border-left: 4px solid #5c2d8b;">
				<h2 style="margin-top: 0; font-size: 1.4em; color: #1d2327;">
					<?php esc_html_e( 'EMS Shortcode & Integration Reference', 'ems-plugin' ); ?>
				</h2>
				<p style="font-size: 14px; line-height: 1.6; color: #50575e;">
					<?php esc_html_e( 'The Expedition Management System provides five frontend shortcodes that allow you to embed interactive portals, leader dashboards, volunteer forms, fast-track registration banners, and route card tools into any WordPress page, Elementor layout, or block template.', 'ems-plugin' ); ?>
				</p>
				<p style="margin-bottom: 0;">
					<strong><?php esc_html_e( 'Quick Jump:', 'ems-plugin' ); ?></strong>
					<a href="#sc-portal" style="margin-left: 8px;"><code>[ems-portal]</code></a> &bull;
					<a href="#sc-leader" style="margin-left: 8px;"><code>[ems-leader-dashboard]</code></a> &bull;
					<a href="#sc-volunteer" style="margin-left: 8px;"><code>[ems-volunteer-signup]</code></a> &bull;
					<a href="#sc-banner" style="margin-left: 8px;"><code>[ems_signup_banner]</code></a> &bull;
					<a href="#sc-route" style="margin-left: 8px;"><code>[ems_route_card_studio]</code></a>
				</p>
			</div>

			<!-- 1. [ems-portal] -->
			<div id="sc-portal" class="card" style="max-width: 100%; padding: 20px 24px; margin-bottom: 24px;">
				<h3 style="margin-top: 0; font-size: 1.25em;">
					<code style="font-size: 1.1em; background: #f0f0f1; padding: 3px 8px; border-radius: 4px; color: #5c2d8b;">[ems-portal]</code>
					<span style="font-weight: normal; margin-left: 8px; color: #646970;">— <?php esc_html_e( 'Explorer / Participant Expedition Portal', 'ems-plugin' ); ?></span>
				</h3>
				<p style="font-size: 14px; line-height: 1.5;">
					<?php esc_html_e( 'Mounts the primary single-page application for participants and parents. Allows young people to view expedition dates, team allocations, kit checklists, consent requirements, route details, and supervisor notes.', 'ems-plugin' ); ?>
				</p>
				<table class="wp-list-table widefat striped fixed" style="margin: 15px 0;">
					<thead>
						<tr>
							<th style="width: 25%;"><?php esc_html_e( 'Parameter', 'ems-plugin' ); ?></th>
							<th style="width: 15%;"><?php esc_html_e( 'Type', 'ems-plugin' ); ?></th>
							<th style="width: 15%;"><?php esc_html_e( 'Default', 'ems-plugin' ); ?></th>
							<th><?php esc_html_e( 'Description', 'ems-plugin' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td colspan="4" style="color: #646970; font-style: italic;">
								<?php esc_html_e( 'This shortcode accepts no parameters. It automatically detects the logged-in WordPress user and scopes data to their linked Scout/Explorer record.', 'ems-plugin' ); ?>
							</td>
						</tr>
					</tbody>
				</table>
				<h4 style="margin: 15px 0 6px;"><?php esc_html_e( 'Access & Authentication:', 'ems-plugin' ); ?></h4>
				<ul style="list-style-type: disc; margin-left: 20px; color: #50575e; font-size: 13px;">
					<li><?php esc_html_e( 'Public / Logged-Out: Displays a clean login prompt that redirects back to the portal page upon successful login.', 'ems-plugin' ); ?></li>
					<li><?php esc_html_e( 'Feature Flag: Controlled by the "portal" flag. Returns a friendly maintenance card when disabled.', 'ems-plugin' ); ?></li>
				</ul>
				<h4 style="margin: 15px 0 6px;"><?php esc_html_e( 'Example Usage:', 'ems-plugin' ); ?></h4>
				<pre style="background: #f6f7f7; padding: 10px 14px; border: 1px solid #dcdcde; border-radius: 4px; overflow-x: auto;"><code>[ems-portal]</code></pre>
			</div>

			<!-- 2. [ems-leader-dashboard] -->
			<div id="sc-leader" class="card" style="max-width: 100%; padding: 20px 24px; margin-bottom: 24px;">
				<h3 style="margin-top: 0; font-size: 1.25em;">
					<code style="font-size: 1.1em; background: #f0f0f1; padding: 3px 8px; border-radius: 4px; color: #5c2d8b;">[ems-leader-dashboard]</code>
					<span style="font-weight: normal; margin-left: 8px; color: #646970;">— <?php esc_html_e( 'Unit Leader Expedition & Validation Dashboard', 'ems-plugin' ); ?></span>
				</h3>
				<p style="font-size: 14px; line-height: 1.5;">
					<?php esc_html_e( 'Mounts the Unit Leader expedition dashboard. Leaders can manage explorer cohorts, review and approve Additional Support Needs (ASN), monitor course training progress, and verify OSM attendance synchronisation.', 'ems-plugin' ); ?>
				</p>
				<table class="wp-list-table widefat striped fixed" style="margin: 15px 0;">
					<thead>
						<tr>
							<th style="width: 25%;"><?php esc_html_e( 'Parameter', 'ems-plugin' ); ?></th>
							<th style="width: 15%;"><?php esc_html_e( 'Type', 'ems-plugin' ); ?></th>
							<th style="width: 15%;"><?php esc_html_e( 'Default', 'ems-plugin' ); ?></th>
							<th><?php esc_html_e( 'Description', 'ems-plugin' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td colspan="4" style="color: #646970; font-style: italic;">
								<?php esc_html_e( 'This shortcode accepts no parameters. Data is filtered according to the logged-in leader\'s unit permissions.', 'ems-plugin' ); ?>
							</td>
						</tr>
					</tbody>
				</table>
				<h4 style="margin: 15px 0 6px;"><?php esc_html_e( 'Access & Authentication:', 'ems-plugin' ); ?></h4>
				<ul style="list-style-type: disc; margin-left: 20px; color: #50575e; font-size: 13px;">
					<li><?php esc_html_e( 'Requires the user to be logged in and possess either the "access_ems_leader_portal" capability (granted to "ems_leader" role) or "manage_options" (administrators).', 'ems-plugin' ); ?></li>
					<li><?php esc_html_e( 'Feature Flag: Controlled by the "leader_dashboard" flag.', 'ems-plugin' ); ?></li>
				</ul>
				<h4 style="margin: 15px 0 6px;"><?php esc_html_e( 'Example Usage:', 'ems-plugin' ); ?></h4>
				<pre style="background: #f6f7f7; padding: 10px 14px; border: 1px solid #dcdcde; border-radius: 4px; overflow-x: auto;"><code>[ems-leader-dashboard]</code></pre>
			</div>

			<!-- 3. [ems-volunteer-signup] -->
			<div id="sc-volunteer" class="card" style="max-width: 100%; padding: 20px 24px; margin-bottom: 24px;">
				<h3 style="margin-top: 0; font-size: 1.25em;">
					<code style="font-size: 1.1em; background: #f0f0f1; padding: 3px 8px; border-radius: 4px; color: #5c2d8b;">[ems-volunteer-signup]</code>
					<span style="font-weight: normal; margin-left: 8px; color: #646970;">— <?php esc_html_e( 'Adult Volunteer Availability & Registration Form', 'ems-plugin' ); ?></span>
				</h3>
				<p style="font-size: 14px; line-height: 1.5;">
					<?php esc_html_e( 'Mounts the self-service adult volunteer sign-up board. Volunteers can register for upcoming expedition weekends, specify available roles (Checkpointer, Supervisor, Assessor, Basecamp), and submit dietary or medical requirements.', 'ems-plugin' ); ?>
				</p>
				<table class="wp-list-table widefat striped fixed" style="margin: 15px 0;">
					<thead>
						<tr>
							<th style="width: 25%;"><?php esc_html_e( 'Parameter', 'ems-plugin' ); ?></th>
							<th style="width: 15%;"><?php esc_html_e( 'Type', 'ems-plugin' ); ?></th>
							<th style="width: 15%;"><?php esc_html_e( 'Default', 'ems-plugin' ); ?></th>
							<th><?php esc_html_e( 'Description', 'ems-plugin' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td colspan="4" style="color: #646970; font-style: italic;">
								<?php esc_html_e( 'This shortcode accepts no parameters. Works for both authenticated volunteers (pre-filling details) and guest signups.', 'ems-plugin' ); ?>
							</td>
						</tr>
					</tbody>
				</table>
				<h4 style="margin: 15px 0 6px;"><?php esc_html_e( 'Access & Authentication:', 'ems-plugin' ); ?></h4>
				<ul style="list-style-type: disc; margin-left: 20px; color: #50575e; font-size: 13px;">
					<li><?php esc_html_e( 'Public: Open to all volunteers. Automatically pre-populates details if the volunteer is logged in with an OSM-linked account.', 'ems-plugin' ); ?></li>
					<li><?php esc_html_e( 'Feature Flag: Controlled by the "volunteers" flag.', 'ems-plugin' ); ?></li>
				</ul>
				<h4 style="margin: 15px 0 6px;"><?php esc_html_e( 'Example Usage:', 'ems-plugin' ); ?></h4>
				<pre style="background: #f6f7f7; padding: 10px 14px; border: 1px solid #dcdcde; border-radius: 4px; overflow-x: auto;"><code>[ems-volunteer-signup]</code></pre>
			</div>

			<!-- 4. [ems_signup_banner] -->
			<div id="sc-banner" class="card" style="max-width: 100%; padding: 20px 24px; margin-bottom: 24px;">
				<h3 style="margin-top: 0; font-size: 1.25em;">
					<code style="font-size: 1.1em; background: #f0f0f1; padding: 3px 8px; border-radius: 4px; color: #5c2d8b;">[ems_signup_banner]</code>
					<span style="font-weight: normal; margin-left: 8px; color: #646970;">— <?php esc_html_e( 'Fluent Forms OSM Fast-Track & OTP Verification Banner', 'ems-plugin' ); ?></span>
				</h3>
				<p style="font-size: 14px; line-height: 1.5;">
					<?php esc_html_e( 'Renders a fast-track callout above a Fluent Forms registration form. Prompts parents to authenticate with Online Scout Manager, auto-populating their explorer\'s profile and providing instant email OTP verification.', 'ems-plugin' ); ?>
				</p>
				<table class="wp-list-table widefat striped fixed" style="margin: 15px 0;">
					<thead>
						<tr>
							<th style="width: 22%;"><?php esc_html_e( 'Parameter', 'ems-plugin' ); ?></th>
							<th style="width: 12%;"><?php esc_html_e( 'Type', 'ems-plugin' ); ?></th>
							<th style="width: 20%;"><?php esc_html_e( 'Default', 'ems-plugin' ); ?></th>
							<th><?php esc_html_e( 'Description', 'ems-plugin' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><code>form_id</code></td>
							<td><code>integer</code></td>
							<td><code>6</code></td>
							<td><?php esc_html_e( 'The numeric ID of the target Fluent Form on this page.', 'ems-plugin' ); ?></td>
						</tr>
						<tr>
							<td><code>type</code></td>
							<td><code>string</code></td>
							<td><code>participant</code></td>
							<td><?php esc_html_e( 'Registration workflow type: "participant" (Place Signup) or "expedition" (Expedition Preferences). Uses the field mappings defined in the Form Mappings tab.', 'ems-plugin' ); ?></td>
						</tr>
						<tr>
							<td><code>scout_field</code></td>
							<td><code>string</code></td>
							<td><code>signup_child</code></td>
							<td><?php esc_html_e( 'Fluent Form input field name for the Scout / Child selector dropdown.', 'ems-plugin' ); ?></td>
						</tr>
						<tr>
							<td><code>unit_field</code></td>
							<td><code>string</code></td>
							<td><code>signup_unit</code></td>
							<td><?php esc_html_e( 'Fluent Form input field name for the Explorer Unit.', 'ems-plugin' ); ?></td>
						</tr>
						<tr>
							<td><code>parent_email_field</code></td>
							<td><code>string</code></td>
							<td><code>signup_parent_email</code></td>
							<td><?php esc_html_e( 'Fluent Form input field name for the Parent Email address.', 'ems-plugin' ); ?></td>
						</tr>
						<tr>
							<td><code>parent_otp_field</code></td>
							<td><code>string</code></td>
							<td><code>signup_parent_otp_code</code></td>
							<td><?php esc_html_e( 'Fluent Form input field name for the Parent OTP verification code.', 'ems-plugin' ); ?></td>
						</tr>
						<tr>
							<td><code>explorer_otp_field</code></td>
							<td><code>string</code></td>
							<td><code>signup_explorer_otp_code</code></td>
							<td><?php esc_html_e( 'Fluent Form input field name for the Explorer OTP verification code.', 'ems-plugin' ); ?></td>
						</tr>
						<tr>
							<td><code>headline</code></td>
							<td><code>string</code></td>
							<td><code>Speed up your DofE registration</code></td>
							<td><?php esc_html_e( 'Custom title displayed at the top of the fast-track banner.', 'ems-plugin' ); ?></td>
						</tr>
						<tr>
							<td><code>message</code></td>
							<td><code>string</code></td>
							<td><em>Log in with Online Scout Manager to auto-fill...</em></td>
							<td><?php esc_html_e( 'Custom description message explaining the benefits of logging in.', 'ems-plugin' ); ?></td>
						</tr>
					</tbody>
				</table>
				<h4 style="margin: 15px 0 6px;"><?php esc_html_e( 'Example Usages:', 'ems-plugin' ); ?></h4>
				<pre style="background: #f6f7f7; padding: 10px 14px; border: 1px solid #dcdcde; border-radius: 4px; overflow-x: auto; margin-bottom: 8px;"><code>[ems_signup_banner form_id="6" type="participant"]</code></pre>
				<pre style="background: #f6f7f7; padding: 10px 14px; border: 1px solid #dcdcde; border-radius: 4px; overflow-x: auto;"><code>[ems_signup_banner form_id="8" type="expedition" headline="Confirm Your Expedition Place" message="Log in to view available expedition dates and confirm your squad."]</code></pre>
			</div>

			<!-- 5. [ems_route_card_studio] -->
			<div id="sc-route" class="card" style="max-width: 100%; padding: 20px 24px; margin-bottom: 24px;">
				<h3 style="margin-top: 0; font-size: 1.25em;">
					<code style="font-size: 1.1em; background: #f0f0f1; padding: 3px 8px; border-radius: 4px; color: #5c2d8b;">[ems_route_card_studio]</code>
					<span style="font-weight: normal; margin-left: 8px; color: #646970;">— <?php esc_html_e( 'Interactive GPX Route Card & Elevation Studio', 'ems-plugin' ); ?></span>
				</h3>
				<p style="font-size: 14px; line-height: 1.5;">
					<?php esc_html_e( 'Mounts the interactive DofE Route Card Builder studio on any frontend page. Explorers can plot route waypoints, upload GPX files, query OS Terrain 50 elevation data, calculate Naismith timing rules, and export official printable PDF route cards.', 'ems-plugin' ); ?>
				</p>
				<table class="wp-list-table widefat striped fixed" style="margin: 15px 0;">
					<thead>
						<tr>
							<th style="width: 22%;"><?php esc_html_e( 'Parameter', 'ems-plugin' ); ?></th>
							<th style="width: 12%;"><?php esc_html_e( 'Type', 'ems-plugin' ); ?></th>
							<th style="width: 20%;"><?php esc_html_e( 'Default', 'ems-plugin' ); ?></th>
							<th><?php esc_html_e( 'Description', 'ems-plugin' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><code>expedition_id</code></td>
							<td><code>integer</code></td>
							<td><code>1</code></td>
							<td><?php esc_html_e( 'Numeric WordPress post ID of the expedition to load context, dates, and supervisors from.', 'ems-plugin' ); ?></td>
						</tr>
						<tr>
							<td><code>team_id</code></td>
							<td><code>integer</code></td>
							<td><code>1</code></td>
							<td><?php esc_html_e( 'Assigned team or patrol number within the expedition.', 'ems-plugin' ); ?></td>
						</tr>
						<tr>
							<td><code>day</code></td>
							<td><code>integer</code></td>
							<td><code>1</code></td>
							<td><?php esc_html_e( 'The specific day of the expedition to view and edit (e.g. 1 for Day 1, 2 for Day 2).', 'ems-plugin' ); ?></td>
						</tr>
					</tbody>
				</table>
				<h4 style="margin: 15px 0 6px;"><?php esc_html_e( 'Example Usages:', 'ems-plugin' ); ?></h4>
				<pre style="background: #f6f7f7; padding: 10px 14px; border: 1px solid #dcdcde; border-radius: 4px; overflow-x: auto; margin-bottom: 8px;"><code>[ems_route_card_studio]</code></pre>
				<pre style="background: #f6f7f7; padding: 10px 14px; border: 1px solid #dcdcde; border-radius: 4px; overflow-x: auto;"><code>[ems_route_card_studio expedition_id="42" team_id="3" day="2"]</code></pre>
			</div>
		</div>
		<?php
	}

	/**
	 * Saves two-tier deadline policies from Settings & System -> Deadlines & Cutoffs.
	 */
	public function save_deadlines( array $post_data ): void {
		$service = new \EMS\Services\DeadlinePolicyService();
		$raw     = isset( $post_data['ems_deadline_policies'] ) && is_array( $post_data['ems_deadline_policies'] )
			? $post_data['ems_deadline_policies']
			: array();
		$service->save_policies( $raw );
	}

	private function render_deadlines_tab(): void {
		$service  = new \EMS\Services\DeadlinePolicyService();
		$policies = $service->get_policies();
		?>
		<form method="post">
			<?php wp_nonce_field( 'ems_settings_deadlines' ); ?>
			<div class="card" style="max-width: 900px; padding: 20px; margin-top: 16px;">
				<h2><?php esc_html_e( 'Two-Tier Deadlines & Cutoffs', 'ems-plugin' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Configure a communicated (soft) deadline shown to Explorers & Parents alongside a hard system cutoff. Rolling grace days automatically extend the hard cutoff for late allocations/notifications.', 'ems-plugin' ); ?>
				</p>
				<table class="form-table">
					<?php foreach ( $policies as $key => $policy ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( (string) $policy['label'] ); ?></th>
							<td>
								<label style="display: inline-block; margin-right: 16px;">
									<?php esc_html_e( 'Communicated Date', 'ems-plugin' ); ?><br>
									<input type="date"
										name="ems_deadline_policies[<?php echo esc_attr( $key ); ?>][communicated_date]"
										value="<?php echo esc_attr( (string) $policy['communicated_date'] ); ?>">
								</label>
								<label style="display: inline-block; margin-right: 16px;">
									<?php esc_html_e( 'Hard System Cutoff', 'ems-plugin' ); ?><br>
									<input type="date"
										name="ems_deadline_policies[<?php echo esc_attr( $key ); ?>][hard_cutoff_date]"
										value="<?php echo esc_attr( (string) $policy['hard_cutoff_date'] ); ?>">
								</label>
								<label style="display: inline-block;">
									<?php esc_html_e( 'Rolling Grace (days)', 'ems-plugin' ); ?><br>
									<input type="number" min="0" max="60" style="width: 80px;"
										name="ems_deadline_policies[<?php echo esc_attr( $key ); ?>][grace_days]"
										value="<?php echo esc_attr( (string) $policy['grace_days'] ); ?>">
								</label>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
				<?php submit_button( __( 'Save Deadlines & Cutoffs', 'ems-plugin' ), 'primary', 'ems_save_deadlines' ); ?>
			</div>
		</form>
		<?php
	}
}


