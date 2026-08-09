<?php
namespace EMS\Admin;

class Settings_Page {

	private const VALID_MODES = array( 'mock', 'live', 'live-auth-only', 'live-limited' );

	private \EMS\Data\Unit_Repository $unit_leaders;

	public function __construct( ?\EMS\Data\Unit_Repository $unit_leaders = null ) {
		$this->unit_leaders = $unit_leaders ?: new \EMS\Data\Unit_Repository();
	}

	public function register(): void {
		$settings_hook = add_submenu_page(
			'ems',
			__( 'Settings', 'ems-plugin' ),
			__( 'Settings', 'ems-plugin' ),
			'manage_options',
			'ems-settings',
			array( $this, 'render' )
		);

		add_action(
			'admin_enqueue_scripts',
			function ( $hook ) use ( $settings_hook ) {
				if ( $hook === $settings_hook ) {
					wp_enqueue_style( 'wp-components' );
					$css_path = plugin_dir_path( EMS_PLUGIN_FILE ) . 'assets/js/ems-admin.css';
					$css_url  = plugin_dir_url( EMS_PLUGIN_FILE ) . 'assets/js/ems-admin.css';
					$css_version = EMS_VERSION . ( file_exists( $css_path ) ? '.' . filemtime( $css_path ) : '' );
					wp_enqueue_style( 'ems-admin', $css_url, array( 'wp-components' ), $css_version );
				}
			}
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

	public function save_sections( array $post_data ): void {
		$available   = (array) get_transient( 'ems_available_sections' );
		$checked_ids = array_map( 'intval', (array) ( $post_data['ems_managed_section_ids'] ?? array() ) );

		$sections = array();
		foreach ( $checked_ids as $id ) {
			if ( isset( $available[ $id ] ) ) {
				$sections[ $id ] = array(
					'name' => sanitize_text_field( $available[ $id ]['name'] ?? '' ),
					'type' => sanitize_text_field( $available[ $id ]['type'] ?? '' ),
				);
			}
		}
		update_option( 'ems_managed_sections', $sections );
		$writeback_id = isset( $post_data['ems_writeback_section_id'] ) ? (int) $post_data['ems_writeback_section_id'] : 0;
		update_option( 'ems_writeback_section_id', $writeback_id );
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
		} elseif ( isset( $post_data['ems_save_sections'] ) ) {
			$this->save_sections( $post_data );
		} elseif ( isset( $post_data['ems_save_unit_leaders'] ) ) {
			$this->save_unit_leaders( $post_data );
		} elseif ( isset( $post_data['ems_save_form_mappings'] ) ) {
			$this->save_form_mappings( $post_data );
		} elseif ( isset( $post_data['ems_save_access_control'] ) ) {
			$this->save_access_control( $post_data );
		} else {
			$this->save_general( $post_data );
		}
	}

	public function render(): void {
		if ( isset( $_POST['ems_save_general'] ) && check_admin_referer( 'ems_settings_general' ) ) {
			$this->save_general( $_POST );
		} elseif ( isset( $_POST['ems_save_connection'] ) && check_admin_referer( 'ems_settings_connection' ) ) {
			$this->save_connection( $_POST );
		} elseif ( isset( $_POST['ems_save_sections'] ) && check_admin_referer( 'ems_settings_sections' ) ) {
			$this->save_sections( $_POST );
		} elseif ( isset( $_POST['ems_save_unit_leaders'] ) && check_admin_referer( 'ems_settings_unit_leaders' ) ) {
			$this->save_unit_leaders( $_POST );
		} elseif ( isset( $_POST['ems_save_form_mappings'] ) && check_admin_referer( 'ems_settings_form_mappings' ) ) {
			$this->save_form_mappings( $_POST );
		} elseif ( isset( $_POST['ems_save_access_control'] ) && check_admin_referer( 'ems_settings_access_control' ) ) {
			$this->save_access_control( $_POST );
		} elseif ( isset( $_POST['ems_import_backup'] ) && check_admin_referer( 'ems_settings_backups' ) ) {
			$this->handle_import();
		} elseif ( isset( $_POST['ems_export_backup'] ) && check_admin_referer( 'ems_settings_backups' ) ) {
			$this->handle_export();
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'sections';
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
				<a href="<?php echo esc_url( $page_url . '&tab=sections' ); ?>"
					class="nav-tab<?php echo $active_tab === 'sections' ? ' nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Managed Sections', 'ems-plugin' ); ?>
				</a>
				<a href="<?php echo esc_url( $page_url . '&tab=unit_leaders' ); ?>"
					class="nav-tab<?php echo $active_tab === 'unit_leaders' ? ' nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Unit Lookup', 'ems-plugin' ); ?>
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
			</nav>
			<?php
			if ( $active_tab === 'general' ) {
				$this->render_general_tab();
			} elseif ( $active_tab === 'connection' ) {
				$this->render_connection_tab();
			} elseif ( $active_tab === 'unit_leaders' ) {
				$this->render_unit_leaders_tab();
			} elseif ( $active_tab === 'form_mappings' ) {
				$this->render_form_mappings_tab();
			} elseif ( $active_tab === 'backups' ) {
				$this->render_backups_tab();
			} elseif ( $active_tab === 'audit_logs' ) {
				$this->render_audit_logs_tab();
			} elseif ( $active_tab === 'access_control' ) {
				$this->render_access_control_tab();
			} else {
				$this->render_sections_tab();
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
		<?php endif; ?>
		<?php
	}

	public function save_unit_leaders( array $post_data ): void {
		$leaders_data = $post_data['unit_leaders'] ?? array();

		foreach ( $leaders_data as $id => $fields ) {
			$email      = sanitize_text_field( $fields['email'] ?? '' );
			$first      = sanitize_text_field( $fields['first_name'] ?? '' );
			$last       = sanitize_text_field( $fields['last_name'] ?? '' );
			$unit_id    = empty( $fields['unit_id'] ) ? null : (int) $fields['unit_id'];
			$short_code = sanitize_text_field( $fields['short_code'] ?? '' );

			$data = array(
				'unit_id'           => $unit_id,
				'short_code'        => $short_code,
				'leader_first_name' => $first,
				'leader_last_name'  => $last,
				'leader_email'      => $email,
			);

			try {
				$this->unit_leaders->update_custom_mappings( (int) $id, $data );
			} catch ( \InvalidArgumentException $e ) {
				error_log( '[EMS] Settings save_unit_leaders failed: ' . $e->getMessage() );
			}
		}
	}

	private function render_unit_leaders_tab(): void {
		$units            = $this->unit_leaders->list_active_units();
		$managed_sections = get_option( 'ems_managed_sections', array() );
		?>
		<style>
			.ems-unit-leaders-table-container {
				max-height: calc(100vh - 220px);
				overflow-y: auto;
				border: 1px solid #ccd0d4;
				margin-top: 15px;
			}
			.ems-unit-leaders-table-container table {
				margin-top: 0 !important;
				border: none !important;
			}
			.ems-unit-leaders-table-container thead th {
				position: sticky;
				top: 0;
				background: #f6f7f7;
				box-shadow: inset 0 -1px 0 #ccd0d4;
				z-index: 2;
			}
			.ems-unit-leaders-table-container input[type="text"],
			.ems-unit-leaders-table-container input[type="email"],
			.ems-unit-leaders-table-container input[type="number"] {
				width: 100%;
				max-width: 100%;
				box-sizing: border-box;
			}
		</style>
		<form method="post">
			<?php wp_nonce_field( 'ems_settings_unit_leaders' ); ?>
			<div class="ems-unit-leaders-table-container">
				<table class="ems-table">
					<thead>
						<tr>
							<th style="width: 15%;"><?php esc_html_e( 'OSM Section', 'ems-plugin' ); ?></th>
							<th style="width: 15%;"><?php esc_html_e( 'ESU / Unit Name', 'ems-plugin' ); ?></th>
							<th style="width: 10%;"><?php esc_html_e( 'Unit ID', 'ems-plugin' ); ?></th>
							<th style="width: 15%;"><?php esc_html_e( 'Short Code', 'ems-plugin' ); ?></th>
							<th><?php esc_html_e( 'Leader First Name', 'ems-plugin' ); ?></th>
							<th><?php esc_html_e( 'Leader Last Name', 'ems-plugin' ); ?></th>
							<th><?php esc_html_e( 'Leader Email', 'ems-plugin' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $units ) ) : ?>
							<tr>
								<td colspan="7"><?php esc_html_e( 'No ESU/patrol data found. Sync OSM data first.', 'ems-plugin' ); ?></td>
							</tr>
						<?php else : ?>
							<?php
							foreach ( $units as $u ) :
								$sec_id   = $u['section_id'];
								$sec_name = $managed_sections[ $sec_id ]['name'] ?? "Section #{$sec_id}";
								$row_id   = (int) $u['id'];
								?>
								<tr>
									<td><?php echo esc_html( $sec_name ); ?></td>
									<td><strong><?php echo esc_html( $u['name'] ); ?></strong></td>
									<td>
										<input type="number" name="unit_leaders[<?php echo $row_id; ?>][unit_id]" 
												value="<?php echo esc_attr( $u['unit_id'] ?? '' ); ?>" />
									</td>
									<td>
										<input type="text" name="unit_leaders[<?php echo $row_id; ?>][short_code]" 
												value="<?php echo esc_attr( $u['short_code'] ?: $u['name'] ); ?>" />
									</td>
									<td>
										<input type="text" name="unit_leaders[<?php echo $row_id; ?>][first_name]" 
												value="<?php echo esc_attr( $u['leader_first_name'] ?? '' ); ?>" />
									</td>
									<td>
										<input type="text" name="unit_leaders[<?php echo $row_id; ?>][last_name]" 
												value="<?php echo esc_attr( $u['leader_last_name'] ?? '' ); ?>" />
									</td>
									<td>
										<input type="email" name="unit_leaders[<?php echo $row_id; ?>][email]" 
												value="<?php echo esc_attr( $u['leader_email'] ?? '' ); ?>" />
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
			<?php if ( ! empty( $units ) ) : ?>
				<p class="submit">
					<input type="submit" name="ems_save_unit_leaders" id="submit" class="button button-primary" value="<?php esc_attr_e( 'Save Unit Leaders', 'ems-plugin' ); ?>" />
				</p>
			<?php endif; ?>
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

	private function save_access_control( array $post ): void {
		$pages         = array_map( 'intval', (array) ( $post['ems_protected_pages'] ?? array() ) );
		$roles         = array_map( 'sanitize_text_field', (array) ( $post['ems_allowed_roles'] ?? array() ) );
		$protect_tutor = ! empty( $post['ems_protect_tutor_lms'] );

		update_option( 'ems_protected_pages', $pages );
		update_option( 'ems_allowed_roles', $roles );
		update_option( 'ems_protect_tutor_lms', $protect_tutor );

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
				'dofe_level_field'             => 'signup_level',
				'dofe_number_field'            => 'signup_dofe_number',
				'esu_patrol_field'             => 'signup_unit',
				'explorer_email_field'         => 'signup_explorer_email',
				'parent_email_field'           => 'signup_parent_email',
				'leader_email_field'           => 'signup_leader_email',
				'exped_type_field'             => 'exped_type',
				'practice_dates_field'         => 'exped_practice_dates',
				'qualifier_dates_field'        => 'exped_qualifier_dates',
				'silver_practice_dates_field'  => 'exped-silver-practice-dates',
				'gold_practice_dates_field'    => 'exped-gold-practice-dates',
				'silver_qualifier_dates_field' => 'exped-silver-qualifier-dates',
				'gold_qualifier_dates_field'   => 'exped-gold-qualifier-dates',
				'team_names_field'             => 'exped_team_names',
				'asn_field'                    => 'exped_asn',
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
						'dofe_level_field'             => __( 'DofE Level Field', 'ems-plugin' ),
						'dofe_number_field'            => __( 'DofE Number Field', 'ems-plugin' ),
						'esu_patrol_field'             => __( 'ESU / Patrol Field', 'ems-plugin' ),
						'explorer_email_field'         => __( 'Explorer Email Field', 'ems-plugin' ),
						'parent_email_field'           => __( 'Parent Email Field', 'ems-plugin' ),
						'leader_email_field'           => __( 'Leader Email Field', 'ems-plugin' ),
						'exped_type_field'             => __( 'Expedition Type Field', 'ems-plugin' ),
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
		$protected_page_ids = get_option( 'ems_protected_pages', array() );
		$allowed_roles      = get_option( 'ems_allowed_roles', array( 'ems_explorer', 'administrator' ) );
		$protect_tutor      = get_option( 'ems_protect_tutor_lms', true );

		$pages     = get_pages( array( 'post_status' => 'publish' ) );
		$all_roles = wp_roles()->get_names();
		?>
		<form method="post">
			<?php wp_nonce_field( 'ems_settings_access_control' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Protected Pages', 'ems-plugin' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><span><?php esc_html_e( 'Protected Pages', 'ems-plugin' ); ?></span></legend>
							<?php if ( empty( $pages ) ) : ?>
								<p class="description"><?php esc_html_e( 'No published pages found.', 'ems-plugin' ); ?></p>
							<?php else : ?>
								<?php foreach ( $pages as $page ) : ?>
									<label style="display:block; margin-bottom: 5px;">
										<input type="checkbox" name="ems_protected_pages[]" value="<?php echo esc_attr( $page->ID ); ?>" <?php checked( in_array( $page->ID, $protected_page_ids, true ) ); ?> />
										<?php echo esc_html( $page->post_title ); ?> (ID: <?php echo esc_html( $page->ID ); ?>)
									</label>
								<?php endforeach; ?>
								<p class="description"><?php esc_html_e( 'Select which WordPress pages are protected and require a user to login via OIDC and have a permitted role.', 'ems-plugin' ); ?></p>
							<?php endif; ?>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Allowed Roles', 'ems-plugin' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><span><?php esc_html_e( 'Allowed Roles', 'ems-plugin' ); ?></span></legend>
							<?php foreach ( $all_roles as $role_slug => $role_name ) : ?>
								<label style="display:block; margin-bottom: 5px;">
									<input type="checkbox" name="ems_allowed_roles[]" value="<?php echo esc_attr( $role_slug ); ?>" <?php checked( in_array( $role_slug, $allowed_roles, true ) ); ?> />
									<?php echo esc_html( translate_user_role( $role_name ) ); ?> (<code><?php echo esc_html( $role_slug ); ?></code>)
								</label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Select which roles are permitted to access protected pages (Administrators are implicitly allowed).', 'ems-plugin' ); ?></p>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Tutor LMS Route Protection', 'ems-plugin' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="ems_protect_tutor_lms" value="1" <?php checked( $protect_tutor ); ?> />
							<?php esc_html_e( 'Enable Tutor LMS Route Protection', 'ems-plugin' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Automatically intercept and restrict access to all Tutor LMS dashboard and course pages.', 'ems-plugin' ); ?></p>
					</td>
				</tr>
			</table>
			<p class="submit">
				<input type="submit" name="ems_save_access_control" class="button button-primary" value="<?php esc_attr_e( 'Save Access Control Settings', 'ems-plugin' ); ?>" />
			</p>
		</form>
		<?php
	}
}

