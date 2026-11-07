<?php
namespace EMS\Admin;

use EMS\Data\Unit_Repository;

class Unit_Leader_Page {

	/**
	 * Registers admin menu and init hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'maybe_handle_export' ) );

		$page_hook = add_submenu_page(
			'ems',
			__( 'Units & Leaders', 'ems-plugin' ),
			__( 'Units & Leaders', 'ems-plugin' ),
			'manage_options',
			'ems-units',
			array( $this, 'render' )
		);

		add_action(
			'admin_enqueue_scripts',
			function ( $hook ) use ( $page_hook ) {
				if ( $hook === $page_hook ) {
					$this->enqueue_assets();
				}
			}
		);
	}

	/**
	 * Enqueues styles and scripts for the Units & Leaders page.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		wp_enqueue_style( 'wp-components' );

		$css_path    = plugin_dir_path( EMS_PLUGIN_FILE ) . 'assets/js/ems-admin.css';
		$css_url     = plugin_dir_url( EMS_PLUGIN_FILE ) . 'assets/js/ems-admin.css';
		$css_version = EMS_VERSION . ( file_exists( $css_path ) ? '.' . filemtime( $css_path ) : '' );
		wp_enqueue_style( 'ems-admin', $css_url, array( 'wp-components' ), $css_version );
	}

	/**
	 * Handles export file download before headers are sent.
	 *
	 * @return void
	 */
	public function maybe_handle_export(): void {
		if ( isset( $_POST['ems_export_units'] ) && check_admin_referer( 'ems_settings_unit_portability' ) ) {
			$this->handle_export_units();
		}
	}

	/**
	 * Gets the Unit_Repository instance.
	 *
	 * @return Unit_Repository
	 */
	protected function get_unit_repository(): Unit_Repository {
		return new Unit_Repository();
	}

	/**
	 * Renders the page and dispatches tab requests.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'ems-plugin' ) );
		}

		global $wpdb;

		// Handle Form Submissions
		if ( isset( $_POST['ems_save_unit_leaders'] ) && check_admin_referer( 'ems_settings_unit_leaders' ) ) {
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
			$this->handle_delete_district( sanitize_text_field( wp_unslash( (string) $_POST['ems_delete_district'] ) ) );
		} elseif ( isset( $_POST['ems_consolidate_units'] ) && check_admin_referer( 'ems_settings_unit_leaders' ) ) {
			$this->handle_consolidate_units();
		} elseif ( isset( $_POST['ems_bulk_invite_leaders'] ) && check_admin_referer( 'ems_settings_unit_leaders' ) ) {
			$this->handle_bulk_invite_leaders( $_POST );
		} elseif ( isset( $_POST['ems_invite_leader_email'] ) && check_admin_referer( 'ems_settings_unit_leaders' ) ) {
			$this->handle_invite_leader_by_email( sanitize_email( wp_unslash( (string) $_POST['ems_invite_leader_email'] ) ) );
		} elseif ( isset( $_POST['ems_invite_single_unit'] ) && check_admin_referer( 'ems_settings_unit_leaders' ) ) {
			$this->handle_invite_single_unit( (int) $_POST['ems_invite_single_unit'], $_POST );
		} elseif ( isset( $_POST['ems_action'] ) && $_POST['ems_action'] === 'link_patrol' && check_admin_referer( 'ems_link_patrol' ) ) {
			$this->handle_link_patrol_to_unit();
		} elseif ( isset( $_POST['ems_action'] ) && $_POST['ems_action'] === 'create_unit_from_patrol' && check_admin_referer( 'ems_create_unit_from_patrol' ) ) {
			$this->handle_create_unit_from_patrol();
		} elseif ( isset( $_POST['ems_save_leader_invite'] ) && check_admin_referer( 'ems_settings_leader_invite' ) ) {
			$this->save_leader_invite( $_POST );
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'units';
		$valid_tabs = array( 'units', 'leaders', 'patrols', 'email' );
		if ( ! in_array( $active_tab, $valid_tabs, true ) ) {
			$active_tab = 'units';
		}

		$page_url = admin_url( 'admin.php?page=ems-units' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Units & Leaders Management', 'ems-plugin' ); ?></h1>
			<nav class="nav-tab-wrapper" style="margin-bottom: 20px;">
				<a href="<?php echo esc_url( $page_url . '&tab=units' ); ?>"
					class="nav-tab<?php echo $active_tab === 'units' ? ' nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Units & Districts', 'ems-plugin' ); ?>
				</a>
				<a href="<?php echo esc_url( $page_url . '&tab=leaders' ); ?>"
					class="nav-tab<?php echo $active_tab === 'leaders' ? ' nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Leaders & Access', 'ems-plugin' ); ?>
				</a>
				<a href="<?php echo esc_url( $page_url . '&tab=patrols' ); ?>"
					class="nav-tab<?php echo $active_tab === 'patrols' ? ' nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'OSM Patrol Mapping', 'ems-plugin' ); ?>
				</a>
				<a href="<?php echo esc_url( $page_url . '&tab=email' ); ?>"
					class="nav-tab<?php echo $active_tab === 'email' ? ' nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Invitation Email', 'ems-plugin' ); ?>
				</a>
			</nav>

			<?php
			if ( $active_tab === 'leaders' ) {
				$this->render_leaders_tab();
			} elseif ( $active_tab === 'patrols' ) {
				$this->render_patrols_tab();
			} elseif ( $active_tab === 'email' ) {
				$this->render_email_tab();
			} else {
				$this->render_units_tab();
			}
			?>
		</div>
		<?php
	}

	// -------------------------------------------------------------
	// TAB 1: Units & Districts
	// -------------------------------------------------------------

	/**
	 * Renders the Units & Districts management tab.
	 *
	 * @return void
	 */
	public function render_units_tab(): void {
		$unit_repo        = $this->get_unit_repository();
		$units            = $unit_repo->list_active_units();
		$managed_sections = (array) get_option( 'ems_managed_sections', array() );

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
		foreach ( $all_districts as $d ) {
			$grouped_units[ $d ] = array();
		}

		foreach ( $units as $u ) {
			$dist      = trim( $u['district'] ?? '' );
			$group_key = ( $dist !== '' ) ? $dist : '__unassigned__';
			if ( ! isset( $grouped_units[ $group_key ] ) ) {
				$grouped_units[ $group_key ] = array();
			}
			$grouped_units[ $group_key ][] = $u;
		}

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
		?>
		<style>
			.ems-unit-leaders-table-container { margin-top: 15px; margin-bottom: 20px; }
			.ems-district-card { margin-top: 15px; padding: 14px 20px; max-width: 100%; border-left: 4px solid #2271b1; box-shadow: 0 1px 3px rgba(0,0,0,0.05); transition: all 0.15s ease-in-out; }
			.ems-district-card.ems-unassigned-card { border-left-color: #dcdcde; }
			.ems-district-header { display: flex; justify-content: space-between; align-items: center; padding: 2px 0; cursor: pointer; user-select: none; flex-wrap: wrap; gap: 10px; }
			.ems-district-header input, .ems-district-header button, .ems-district-header select { cursor: auto; }
			.ems-district-toggle-icon { transition: transform 0.2s ease-in-out; color: #50575e; font-size: 20px; width: 20px; height: 20px; display: inline-block; vertical-align: middle; }
			.ems-district-card.is-collapsed { padding-bottom: 14px; }
			.ems-district-card.is-collapsed .ems-district-toggle-icon { transform: rotate(-90deg); }
			.ems-district-card.is-collapsed .ems-district-units-table { display: none !important; }
			.ems-unit-leaders-table-container input[type="text"], .ems-unit-leaders-table-container input[type="email"], .ems-unit-leaders-table-container input[type="number"] { width: 100%; box-sizing: border-box; padding: 5px 8px; margin: 0; height: auto; min-height: 0; }
			.ems-unit-leaders-table-container td { padding: 8px 10px !important; vertical-align: middle !important; }
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
							<?php esc_html_e( 'Units are grouped by District. Click any district header to collapse or expand it.', 'ems-plugin' ); ?>
						</p>
						<div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
							<button type="button" class="button" id="ems_toggle_all_districts" style="height: 30px; line-height: 28px;">
								<span class="dashicons dashicons-editor-expand" style="vertical-align: text-top; font-size: 15px; margin-right: 2px;"></span>
								<?php esc_html_e( 'Toggle All', 'ems-plugin' ); ?>
							</button>
							<button type="submit" name="ems_consolidate_units" value="1" class="button button-secondary" onclick="return confirm('<?php echo esc_attr( __( 'Consolidate duplicate units? This will merge duplicate master units with the same name, combine all their matched patrols into one unit, and remove duplicate rows.', 'ems-plugin' ) ); ?>');">
								<span class="dashicons dashicons-randomize" style="vertical-align: text-top; font-size: 15px; margin-right: 2px;"></span>
								<?php esc_html_e( 'Consolidate Duplicate Units', 'ems-plugin' ); ?>
							</button>
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
							<div class="ems-district-header" title="<?php esc_attr_e( 'Click to collapse or expand', 'ems-plugin' ); ?>">
								<div style="display: flex; align-items: center; gap: 8px;">
									<span class="dashicons dashicons-arrow-down-alt2 ems-district-toggle-icon"></span>
									<span class="dashicons dashicons-location" style="color: <?php echo $is_unassigned ? '#646970' : '#2271b1'; ?>; font-size: 20px; width: 20px; height: 20px;"></span>
									<?php if ( $is_unassigned ) : ?>
										<strong style="font-size: 15px; color: #646970;"><?php esc_html_e( 'Unassigned Units (No District)', 'ems-plugin' ); ?></strong>
									<?php else : ?>
										<label style="font-size: 15px; font-weight: 600; color: #1d2327;" onclick="event.stopPropagation();">
											<?php esc_html_e( 'District:', 'ems-plugin' ); ?>
											<input type="text" class="ems-district-header-input" 
													data-original-district="<?php echo esc_attr( $group_key ); ?>"
													value="<?php echo esc_attr( $group_key ); ?>" 
													style="font-size: 15px; font-weight: 600; width: 240px; margin-left: 4px; padding: 3px 8px;" />
										</label>
									<?php endif; ?>
									<span class="ems-unit-count-badge" style="background: #f0f0f1; border: 1px solid #c3c4c7; padding: 2px 8px; border-radius: 10px; font-size: 12px; font-weight: 600; color: #50575e;">
										<?php echo sprintf( _n( '%d Unit', '%d Units', count( $group_units ), 'ems-plugin' ), count( $group_units ) ); ?>
									</span>
								</div>
								<div style="display: flex; align-items: center; gap: 6px;" onclick="event.stopPropagation();">
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

							<table class="wp-list-table widefat striped ems-district-units-table" style="margin-top: 10px;">
								<thead>
									<tr>
										<th style="width: 18%;"><?php esc_html_e( 'Unit Name', 'ems-plugin' ); ?></th>
										<th style="width: 8%;"><?php esc_html_e( 'Unit ID', 'ems-plugin' ); ?></th>
										<th style="width: 12%;"><?php esc_html_e( 'Short Code', 'ems-plugin' ); ?></th>
										<th style="width: 22%;"><?php esc_html_e( 'Leader Email', 'ems-plugin' ); ?></th>
										<th style="width: 22%;"><?php esc_html_e( 'Matched OSM Patrols', 'ems-plugin' ); ?></th>
										<th style="width: 12%;"><?php esc_html_e( 'Move District', 'ems-plugin' ); ?></th>
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
											$row_id        = (int) $u['id'];
											$cur_dist      = $u['district'] ?? '';
											$leader_email  = $u['leader_email'] ?? '';
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
															value="<?php echo esc_attr( $leader_email ); ?>" />
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
			// 1. Click header to collapse / expand
			document.querySelectorAll('.ems-district-header').forEach(function(header) {
				header.addEventListener('click', function(e) {
					if (e.target.closest('input, button, a, select')) {
						return;
					}
					var card = this.closest('.ems-district-card');
					if (card) {
						card.classList.toggle('is-collapsed');
					}
				});
			});

			// 2. Toggle All button
			var toggleAllBtn = document.getElementById('ems_toggle_all_districts');
			if (toggleAllBtn) {
				var allCollapsed = false;
				toggleAllBtn.addEventListener('click', function() {
					allCollapsed = !allCollapsed;
					document.querySelectorAll('.ems-district-card').forEach(function(card) {
						if (allCollapsed) {
							card.classList.add('is-collapsed');
						} else {
							card.classList.remove('is-collapsed');
						}
					});
				});
			}

			// 3. Sync district header edits to all units in the card
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

			// 4. Handle Move District select
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
						row.style.backgroundColor = '#fcf8e3';
					}
				});
			});

			// 5. Quick Add button pre-fills District and scrolls to Add Master Unit
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

	// -------------------------------------------------------------
	// TAB 2: Leaders & Access
	// -------------------------------------------------------------

	/**
	 * Renders the Leaders & Portal Access tab.
	 *
	 * @return void
	 */
	public function render_leaders_tab(): void {
		$unit_repo         = $this->get_unit_repository();
		$leaders           = $unit_repo->list_leaders_with_units();
		$total_leaders     = count( $leaders );
		$invited_count     = 0;
		$logged_in_count   = 0;
		$not_invited_count = 0;

		foreach ( $leaders as $l ) {
			if ( ! empty( $l['access_status']['invited'] ) ) {
				++$invited_count;
			} else {
				++$not_invited_count;
			}
			if ( ! empty( $l['access_status']['logged_in'] ) ) {
				++$logged_in_count;
			}
		}
		?>
		<div class="card" style="padding: 16px 20px; margin-top: 15px; margin-bottom: 20px; max-width: 100%; background: #fcfcfc; border-top: 3px solid #2271b1;">
			<div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
				<div>
					<h3 style="margin: 0 0 6px 0;"><?php esc_html_e( 'Unit Leader Invitations & Portal Access', 'ems-plugin' ); ?></h3>
					<div style="display: flex; gap: 16px; align-items: center; font-size: 13px; color: #50575e; flex-wrap: wrap;">
						<span><?php echo sprintf( esc_html__( 'Total Leaders: %d', 'ems-plugin' ), $total_leaders ); ?></span>
						<span>&bull;</span>
						<span style="color: #007017; font-weight: 600;"><?php echo sprintf( esc_html__( 'Invited: %d', 'ems-plugin' ), $invited_count ); ?></span>
						<span>&bull;</span>
						<span style="color: #135e96; font-weight: 600;"><?php echo sprintf( esc_html__( 'Logged In: %d', 'ems-plugin' ), $logged_in_count ); ?></span>
						<span>&bull;</span>
						<span style="color: #b32d2e; font-weight: 600;"><?php echo sprintf( esc_html__( 'Not Invited: %d', 'ems-plugin' ), $not_invited_count ); ?></span>
					</div>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=ems-units&tab=leaders' ) ); ?>" style="margin: 0;">
					<?php wp_nonce_field( 'ems_settings_unit_leaders' ); ?>
					<button type="submit" name="ems_bulk_invite_leaders" value="1" class="button button-primary" onclick="return confirm('<?php echo esc_attr( __( 'Send invitation emails to all unit leaders who have not yet been invited?', 'ems-plugin' ) ); ?>');">
						<span class="dashicons dashicons-email-alt" style="vertical-align: text-top; font-size: 15px; margin-right: 2px;"></span>
						<?php esc_html_e( 'Invite All Uninvited Leaders', 'ems-plugin' ); ?>
					</button>
				</form>
			</div>
		</div>

		<?php if ( empty( $leaders ) ) : ?>
			<div class="card" style="padding: 24px; text-align: center; max-width: 100%;">
				<span class="dashicons dashicons-admin-users" style="font-size: 36px; width: 36px; height: 36px; color: #2271b1; margin-bottom: 8px;"></span>
				<h3 style="margin-top: 0;"><?php esc_html_e( 'No Leaders Found', 'ems-plugin' ); ?></h3>
				<p class="description" style="max-width: 600px; margin: 0 auto 15px;">
					<?php esc_html_e( 'No unit leader email addresses are configured. Switch to "Units & Districts" to assign leader emails to your units.', 'ems-plugin' ); ?>
				</p>
			</div>
		<?php else : ?>
			<table class="wp-list-table widefat striped ems-leaders-table" style="margin-top: 10px;">
				<thead>
					<tr>
						<th style="width: 20%;"><?php esc_html_e( 'Leader Email', 'ems-plugin' ); ?></th>
						<th style="width: 20%;"><?php esc_html_e( 'WordPress Account', 'ems-plugin' ); ?></th>
						<th style="width: 25%;"><?php esc_html_e( 'Assigned Units', 'ems-plugin' ); ?></th>
						<th style="width: 13%;"><?php esc_html_e( 'Districts', 'ems-plugin' ); ?></th>
						<th style="width: 12%;"><?php esc_html_e( 'Portal Access', 'ems-plugin' ); ?></th>
						<th style="width: 10%; text-align: center;"><?php esc_html_e( 'Actions', 'ems-plugin' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $leaders as $leader_email => $l ) :
						$status  = $l['access_status'];
						$wp_user = $l['user'];
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( $leader_email ); ?></strong>
							</td>
							<td>
								<?php if ( $wp_user instanceof \WP_User ) : ?>
									<div>
										<strong><?php echo esc_html( $wp_user->display_name ?: $wp_user->user_login ); ?></strong>
										<span style="color: #646970; font-size: 11px;">(ID: <?php echo (int) $wp_user->ID; ?>)</span>
									</div>
									<?php if ( function_exists( 'get_edit_user_link' ) ) : ?>
										<div style="font-size: 11px; margin-top: 2px;">
											<a href="<?php echo esc_url( (string) get_edit_user_link( (int) $wp_user->ID ) ); ?>" target="_blank" rel="noopener noreferrer">
												<?php esc_html_e( 'Edit User', 'ems-plugin' ); ?> &rarr;
											</a>
										</div>
									<?php endif; ?>
								<?php else : ?>
									<span class="description" style="color: #8c8f94; font-style: italic;">
										<?php esc_html_e( 'No WP account yet', 'ems-plugin' ); ?>
									</span>
								<?php endif; ?>
							</td>
							<td>
								<div style="display: flex; flex-wrap: wrap; gap: 4px;">
									<?php foreach ( $l['units'] as $u ) : ?>
										<span style="display: inline-block; background: #f0f0f1; border: 1px solid #c3c4c7; border-radius: 4px; padding: 2px 6px; font-size: 12px;" title="<?php echo esc_attr( sprintf( __( 'Unit ID: %s', 'ems-plugin' ), $u['unit_id'] ?? '' ) ); ?>">
											<strong><?php echo esc_html( $u['name'] ); ?></strong>
											<?php if ( ! empty( $u['short_code'] ) && $u['short_code'] !== $u['name'] ) : ?>
												<span style="color: #646970; font-size: 11px;">(<?php echo esc_html( $u['short_code'] ); ?>)</span>
											<?php endif; ?>
										</span>
									<?php endforeach; ?>
								</div>
							</td>
							<td>
								<?php echo esc_html( ! empty( $l['districts'] ) ? implode( ', ', $l['districts'] ) : '—' ); ?>
							</td>
							<td>
								<div style="display: flex; flex-wrap: wrap; gap: 4px;">
									<?php if ( $status['invited'] ) : ?>
										<span class="ems-status-badge ems-status-badge--planning" title="<?php echo esc_attr( $status['invited_at'] ? sprintf( __( 'Invited: %s', 'ems-plugin' ), $status['invited_at'] ) : __( 'Invited', 'ems-plugin' ) ); ?>">
											<?php esc_html_e( 'Invited', 'ems-plugin' ); ?>
										</span>
									<?php else : ?>
										<span class="ems-status-badge ems-status-badge--archived">
											<?php esc_html_e( 'Not Invited', 'ems-plugin' ); ?>
										</span>
									<?php endif; ?>

									<?php if ( $status['logged_in'] ) : ?>
										<span class="ems-status-badge ems-status-badge--active" title="<?php echo esc_attr( sprintf( __( 'Last login: %s', 'ems-plugin' ), $status['last_login_at'] ?? '' ) ); ?>">
											✓ <?php esc_html_e( 'Logged In', 'ems-plugin' ); ?>
										</span>
									<?php elseif ( $status['invited'] ) : ?>
										<span class="ems-status-badge ems-status-badge--pending">
											<?php esc_html_e( 'Never Logged In', 'ems-plugin' ); ?>
										</span>
									<?php endif; ?>
								</div>
							</td>
							<td style="text-align: center;">
								<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=ems-units&tab=leaders' ) ); ?>" style="margin: 0;">
									<?php wp_nonce_field( 'ems_settings_unit_leaders' ); ?>
									<button type="submit" name="ems_invite_leader_email" value="<?php echo esc_attr( $leader_email ); ?>" class="button button-small" style="font-size: 11px; min-height: 24px; line-height: 22px; padding: 0 8px;">
										<?php echo $status['invited'] ? esc_html__( 'Resend Invite', 'ems-plugin' ) : esc_html__( 'Send Invite', 'ems-plugin' ); ?>
									</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif;
	}

	// -------------------------------------------------------------
	// TAB 3: OSM Patrol Mapping
	// -------------------------------------------------------------

	/**
	 * Renders the OSM Patrol Mapping tab.
	 *
	 * @return void
	 */
	public function render_patrols_tab(): void {
		global $wpdb;
		$unit_repo        = $this->get_unit_repository();
		$units            = $unit_repo->list_active_units();
		$managed_sections = (array) get_option( 'ems_managed_sections', array() );

		// Query unmatched patrols
		$unmatched_patrols = $wpdb->get_results(
			"SELECT p.* FROM {$wpdb->prefix}ems_unit_patrols p
			 LEFT JOIN {$wpdb->prefix}ems_units u ON p.unit_id = u.unit_id
			 WHERE (p.unit_id IS NULL OR p.unit_id = 0 OR u.unit_id IS NULL) AND p.active = 1
			 ORDER BY p.name",
			ARRAY_A
		) ?: array();
		?>
		<div class="card" style="margin-top: 15px; padding: 20px; max-width: 100%;">
			<h3 style="margin-top: 0; color: #b58100;"><?php esc_html_e( 'Unmatched Synced Patrols from OSM', 'ems-plugin' ); ?></h3>
			<p>
				<?php esc_html_e( 'These patrols were synced from OSM but are not linked to any master unit. You can link them to an existing unit or create a new master unit from them.', 'ems-plugin' ); ?>
			</p>

			<?php if ( empty( $unmatched_patrols ) ) : ?>
				<div class="notice notice-success inline" style="margin-top: 12px;">
					<p><?php esc_html_e( 'All synced patrols are currently linked to master units. No unmatched patrols found.', 'ems-plugin' ); ?></p>
				</div>
			<?php else : ?>
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
							$sec_id    = (int) $patrol['section_id'];
							$sec_name  = $managed_sections[ $sec_id ]['name'] ?? "Section #{$sec_id}";
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
			<?php endif; ?>
		</div>
		<?php
	}

	// -------------------------------------------------------------
	// TAB 4: Invitation Email
	// -------------------------------------------------------------

	/**
	 * Renders the Invitation Email settings tab.
	 *
	 * @return void
	 */
	public function render_email_tab(): void {
		$invite_subject  = (string) get_option( 'ems_leader_invite_email_subject', Unit_Repository::DEFAULT_INVITE_SUBJECT );
		$invite_reply_to = (string) get_option( 'ems_leader_invite_email_reply_to', '' );
		$invite_body     = (string) get_option( 'ems_leader_invite_email_body', Unit_Repository::DEFAULT_INVITE_BODY );
		?>
		<form method="post">
			<?php wp_nonce_field( 'ems_settings_leader_invite' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="ems_leader_invite_email_subject"><?php esc_html_e( 'Leader Invitation Email Subject', 'ems-plugin' ); ?></label></th>
					<td>
						<input
							type="text"
							id="ems_leader_invite_email_subject"
							name="ems_leader_invite_email_subject"
							value="<?php echo esc_attr( $invite_subject ); ?>"
							class="large-text"
							style="max-width: 650px;"
						/>
						<p class="description">
							<?php esc_html_e( 'Subject line for the invitation email sent to unit leaders. Supports placeholders below.', 'ems-plugin' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ems_leader_invite_email_reply_to"><?php esc_html_e( 'Leader Invitation Reply-To Address', 'ems-plugin' ); ?></label></th>
					<td>
						<input
							type="text"
							id="ems_leader_invite_email_reply_to"
							name="ems_leader_invite_email_reply_to"
							value="<?php echo esc_attr( $invite_reply_to ); ?>"
							class="large-text"
							style="max-width: 650px;"
							placeholder="e.g. leader-support@example.com or Support Team &lt;leader-support@example.com&gt;"
						/>
						<p class="description">
							<?php esc_html_e( 'Optional reply-to address for leader invitation emails (e.g. support@example.com or Expedition Team <support@example.com>).', 'ems-plugin' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ems_leader_invite_email_body"><?php esc_html_e( 'Leader Invitation Email Body', 'ems-plugin' ); ?></label></th>
					<td>
						<textarea
							id="ems_leader_invite_email_body"
							name="ems_leader_invite_email_body"
							rows="10"
							class="large-text code"
							style="max-width: 650px;"
						><?php echo esc_textarea( $invite_body ); ?></textarea>
						<p class="description" style="margin-top: 6px;">
							<?php esc_html_e( 'Body of the invitation email. Available placeholder tokens:', 'ems-plugin' ); ?><br />
							<code>{leader_name}</code> — <?php esc_html_e( "Recipient's name or username", 'ems-plugin' ); ?><br />
							<code>{unit_name}</code> — <?php esc_html_e( 'Name of the first assigned unit', 'ems-plugin' ); ?><br />
							<code>{unit_names}</code> — <?php esc_html_e( 'Comma-separated list of all assigned units', 'ems-plugin' ); ?><br />
							<code>{unit_code}</code> — <?php esc_html_e( 'Short code of the primary unit', 'ems-plugin' ); ?><br />
							<code>{invite_url}</code> — <?php esc_html_e( 'Single-use password setup / login URL', 'ems-plugin' ); ?><br />
							<code>{site_name}</code> — <?php esc_html_e( 'Site title from WordPress settings', 'ems-plugin' ); ?>
						</p>
					</td>
				</tr>
			</table>
			<p class="submit">
				<input type="submit" name="ems_save_leader_invite" class="button button-primary" value="<?php esc_attr_e( 'Save Leader Invitation Email Settings', 'ems-plugin' ); ?>" />
			</p>
		</form>
		<?php
	}

	// -------------------------------------------------------------
	// ACTION HANDLERS
	// -------------------------------------------------------------

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
				error_log( '[EMS] Unit_Leader_Page save_unit_leaders failed: ' . $e->getMessage() );
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

		if ( in_array( $district, $districts, true ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . sprintf( esc_html__( 'District "%s" already exists.', 'ems-plugin' ), esc_html( $district ) ) . '</p></div>';
			return;
		}

		$districts[] = $district;
		sort( $districts, SORT_NATURAL | SORT_FLAG_CASE );
		update_option( 'ems_districts', $districts );

		echo '<div class="notice notice-success is-dismissible"><p>' . sprintf( esc_html__( 'District "%s" created successfully.', 'ems-plugin' ), esc_html( $district ) ) . '</p></div>';
	}

	/**
	 * Handles removing an empty district.
	 *
	 * @param string $district The district name.
	 * @return void
	 */
	private function handle_delete_district( string $district ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'ems-plugin' ) );
		}

		$districts = get_option( 'ems_districts', array() );
		if ( ! is_array( $districts ) ) {
			$districts = array();
		}

		$key = array_search( $district, $districts, true );
		if ( $key !== false ) {
			unset( $districts[ $key ] );
			update_option( 'ems_districts', array_values( $districts ) );
			echo '<div class="notice notice-success is-dismissible"><p>' . sprintf( esc_html__( 'District "%s" removed.', 'ems-plugin' ), esc_html( $district ) ) . '</p></div>';
		}
	}

	/**
	 * Handles adding a custom master unit.
	 *
	 * @return void
	 */
	private function handle_add_custom_unit(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'ems-plugin' ) );
		}

		$name       = sanitize_text_field( wp_unslash( $_POST['custom_unit_name'] ?? '' ) );
		$district   = sanitize_text_field( wp_unslash( $_POST['custom_unit_district'] ?? '' ) );
		$short_code = sanitize_text_field( wp_unslash( $_POST['custom_unit_short_code'] ?? '' ) );
		$unit_id    = (int) ( $_POST['custom_unit_id'] ?? 0 );
		$email      = sanitize_email( wp_unslash( (string) ( $_POST['custom_unit_email'] ?? '' ) ) );

		if ( empty( $name ) || empty( $unit_id ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Unit Name and Unit ID are required.', 'ems-plugin' ) . '</p></div>';
			return;
		}

		$unit_repo = $this->get_unit_repository();
		try {
			$unit_repo->add_custom_unit(
				array(
					'name'         => $name,
					'district'     => $district,
					'short_code'   => $short_code ?: $name,
					'unit_id'      => $unit_id,
					'leader_email' => $email,
				)
			);
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Custom unit created successfully.', 'ems-plugin' ) . '</p></div>';
		} catch ( \Exception $e ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $e->getMessage() ) . '</p></div>';
		}
	}

	/**
	 * Handles deleting a custom unit.
	 *
	 * @param int $id The row ID.
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
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Failed to delete custom unit.', 'ems-plugin' ) . '</p></div>';
		}
	}

	/**
	 * Handles consolidating duplicate master units.
	 *
	 * @return void
	 */
	private function handle_consolidate_units(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'ems-plugin' ) );
		}

		$unit_repo = $this->get_unit_repository();
		$result    = $unit_repo->consolidate_duplicate_units();

		if ( $result['merged_count'] > 0 ) {
			$msg = sprintf(
				esc_html__( 'Consolidation complete: Merged %d duplicate unit record(s).', 'ems-plugin' ),
				$result['merged_count']
			);
			if ( ! empty( $result['details'] ) ) {
				$msg .= '<br><small>' . implode( '<br>', array_map( 'esc_html', $result['details'] ) ) . '</small>';
			}
			echo '<div class="notice notice-success is-dismissible"><p>' . $msg . '</p></div>';
		} else {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'No duplicate units found. Everything is already consolidated.', 'ems-plugin' ) . '</p></div>';
		}
	}

	/**
	 * Handles linking an unmatched patrol to a unit.
	 *
	 * @return void
	 */
	private function handle_link_patrol_to_unit(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'ems-plugin' ) );
		}

		global $wpdb;
		$patrol_id  = (int) ( $_POST['patrol_id'] ?? 0 );
		$section_id = (int) ( $_POST['section_id'] ?? 0 );
		$unit_id    = (int) ( $_POST['link_unit_id'] ?? 0 );

		if ( ! $patrol_id || ! $section_id || ! $unit_id ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Missing patrol, section, or target unit ID.', 'ems-plugin' ) . '</p></div>';
			return;
		}

		$table_name = $wpdb->prefix . 'ems_unit_patrols';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$table_name,
			array( 'unit_id' => $unit_id ),
			array(
				'patrol_id'  => $patrol_id,
				'section_id' => $section_id,
			),
			array( '%d' ),
			array( '%d', '%d' )
		);

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Patrol linked to unit successfully.', 'ems-plugin' ) . '</p></div>';
	}

	/**
	 * Handles creating a master unit from an unmatched patrol.
	 *
	 * @return void
	 */
	private function handle_create_unit_from_patrol(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'ems-plugin' ) );
		}

		global $wpdb;
		$patrol_id   = (int) ( $_POST['patrol_id'] ?? 0 );
		$section_id  = (int) ( $_POST['section_id'] ?? 0 );
		$patrol_name = sanitize_text_field( wp_unslash( $_POST['patrol_name'] ?? '' ) );

		if ( ! $patrol_id || ! $section_id || empty( $patrol_name ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Missing patrol details.', 'ems-plugin' ) . '</p></div>';
			return;
		}

		$units_table = $wpdb->prefix . 'ems_units';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$max_custom_id = $wpdb->get_var( "SELECT MAX(unit_id) FROM {$units_table} WHERE unit_id >= 900000" );
		$new_unit_id   = $max_custom_id ? ( (int) $max_custom_id + 1 ) : 900001;

		$district = '';
		if ( str_contains( $patrol_name, '-' ) ) {
			$parts    = explode( '-', $patrol_name, 2 );
			$district = trim( $parts[0] );
		}

		$unit_repo = $this->get_unit_repository();
		try {
			$unit_repo->add_custom_unit(
				array(
					'unit_id'      => $new_unit_id,
					'district'     => $district,
					'name'         => $patrol_name,
					'short_code'   => $patrol_name,
					'leader_email' => '',
				)
			);

			$patrols_table = $wpdb->prefix . 'ems_unit_patrols';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->update(
				$patrols_table,
				array( 'unit_id' => $new_unit_id ),
				array(
					'patrol_id'  => $patrol_id,
					'section_id' => $section_id,
				),
				array( '%d' ),
				array( '%d', '%d' )
			);

			echo '<div class="notice notice-success is-dismissible"><p>' . sprintf( esc_html__( 'Master unit "%s" created and linked successfully.', 'ems-plugin' ), esc_html( $patrol_name ) ) . '</p></div>';
		} catch ( \Exception $e ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $e->getMessage() ) . '</p></div>';
		}
	}

	/**
	 * Handles sending an invitation email to a single leader.
	 *
	 * @param string $email The leader email.
	 * @return void
	 */
	private function handle_invite_leader_by_email( string $email ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'ems-plugin' ) );
		}

		$unit_repo = $this->get_unit_repository();
		$success   = $unit_repo->invite_leader_by_email( $email, true );

		if ( $success ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . sprintf( esc_html__( 'Leader invitation email sent to %s.', 'ems-plugin' ), esc_html( $email ) ) . '</p></div>';
		} else {
			echo '<div class="notice notice-error is-dismissible"><p>' . sprintf( esc_html__( 'Could not send invitation email to %s.', 'ems-plugin' ), esc_html( $email ) ) . '</p></div>';
		}
	}

	/**
	 * Handles sending an invitation for a single unit.
	 *
	 * @param int   $unit_id   The unit row ID.
	 * @param array $post_data The POST array.
	 * @return void
	 */
	private function handle_invite_single_unit( int $unit_id, array $post_data ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'ems-plugin' ) );
		}

		if ( ! empty( $post_data['unit_leaders'] ) ) {
			$this->save_unit_leaders( $post_data );
		}

		$unit_repo = $this->get_unit_repository();
		$summary   = $unit_repo->invite_unit_leaders( $unit_id, true );

		if ( $summary['invited'] > 0 ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Leader invitation email sent.', 'ems-plugin' ) . '</p></div>';
		} elseif ( ! empty( $summary['errors'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( implode( '; ', $summary['errors'] ) ) . '</p></div>';
		} else {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Please enter a valid Leader Email for this unit before sending an invite.', 'ems-plugin' ) . '</p></div>';
		}
	}

	/**
	 * Handles bulk inviting all uninvited leaders.
	 *
	 * @param array $post_data The POST array.
	 * @return void
	 */
	private function handle_bulk_invite_leaders( array $post_data ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'ems-plugin' ) );
		}

		if ( ! empty( $post_data['unit_leaders'] ) ) {
			$this->save_unit_leaders( $post_data );
		}

		$unit_repo = $this->get_unit_repository();
		$summary   = $unit_repo->invite_unit_leaders( null, false );

		echo '<div class="notice notice-success is-dismissible"><p>' .
			sprintf(
				/* translators: 1: number of invited leaders, 2: already invited count, 3: skipped without email count */
				esc_html__( 'Leader invitations complete: %1$d new leader(s) invited, %2$d already invited/active, %3$d unit(s) skipped (no email).', 'ems-plugin' ),
				$summary['invited'],
				$summary['already_invited'],
				$summary['skipped_no_email']
			) .
			'</p></div>';

		if ( ! empty( $summary['errors'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( implode( '; ', $summary['errors'] ) ) . '</p></div>';
		}
	}

	/**
	 * Gets the Portability_Engine instance.
	 *
	 * @return \EMS\Core\Portability_Engine
	 */
	protected function get_portability_engine(): \EMS\Core\Portability_Engine {
		return new \EMS\Core\Portability_Engine();
	}

	/**
	 * Handles JSON unit lookup table export.
	 *
	 * @return void
	 */
	public function handle_export_units(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'ems-plugin' ) );
		}

		$engine   = $this->get_portability_engine();
		$json     = $engine->export_units();
		$filename = 'ems-units-backup-' . current_time( 'Y-md-His' ) . '.json';

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		echo $json;
		exit;
	}

	/**
	 * Handles importing a JSON unit lookup table.
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

			$engine = $this->get_portability_engine();
			$engine->import_units( $content );

			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Unit lookup data imported successfully.', 'ems-plugin' ) . '</p></div>';
		} catch ( \Exception $e ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sprintf( __( 'Import failed: %s', 'ems-plugin' ), $e->getMessage() ) ) . '</p></div>';
		}
	}

	/**
	 * Saves leader invite email template settings.
	 *
	 * @param array $post The POST data.
	 * @return void
	 */
	public function save_leader_invite( array $post ): void {
		if ( isset( $post['ems_leader_invite_email_subject'] ) ) {
			$subject = sanitize_text_field( wp_unslash( (string) $post['ems_leader_invite_email_subject'] ) );
			update_option( 'ems_leader_invite_email_subject', $subject );
		}

		if ( isset( $post['ems_leader_invite_email_reply_to'] ) ) {
			$raw_reply_to = trim( (string) wp_unslash( $post['ems_leader_invite_email_reply_to'] ) );
			$reply_to     = preg_replace( '/[\r\n\t]+/', '', $raw_reply_to );
			$reply_to     = sanitize_text_field( trim( (string) $reply_to ) );
			update_option( 'ems_leader_invite_email_reply_to', $reply_to );
		}

		if ( isset( $post['ems_leader_invite_email_body'] ) ) {
			$raw_body = (string) wp_unslash( $post['ems_leader_invite_email_body'] );
			$body     = sanitize_textarea_field( $raw_body );
			update_option( 'ems_leader_invite_email_body', $body );
		}

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Leader invitation email settings saved.', 'ems-plugin' ) . '</p></div>';
	}
}
