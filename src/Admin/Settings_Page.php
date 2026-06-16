<?php
namespace EMS\Admin;

class Settings_Page {

    private const VALID_MODES = [ 'mock', 'live', 'live-auth-only', 'live-limited' ];

    public function register(): void {
        add_submenu_page(
            'ems',
            __( 'Settings', 'ems-plugin' ),
            __( 'Settings', 'ems-plugin' ),
            'manage_options',
            'ems-settings',
            [ $this, 'render' ]
        );
    }

    public function save_general( array $post_data ): void {
        $mode = in_array( $post_data['ems_api_mode'] ?? '', self::VALID_MODES, true )
            ? $post_data['ems_api_mode']
            : 'mock';
        update_option( 'ems_api_mode', $mode );

        $limit = max( 1, (int) ( $post_data['ems_sync_limit'] ?? 5 ) );
        update_option( 'ems_sync_limit', $limit );
    }

    public function save_connection( array $post_data ): void {
        $raw_url = $post_data['ems_osm_api_base_url'] ?? '';
        $url     = esc_url_raw( rtrim( $raw_url, '/' ) );
        $url     = preg_replace( '#/api\.php$#', '', $url );
        if ( $url !== '' && filter_var( $url, FILTER_VALIDATE_URL ) && str_starts_with( $url, 'https://' ) ) {
            update_option( 'ems_osm_api_base_url', $url );
        }

        foreach ( [ 'ems_osm_auth_url', 'ems_osm_token_url', 'ems_osm_resource_url' ] as $key ) {
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
        $checked_ids = array_map( 'intval', (array) ( $post_data['ems_managed_section_ids'] ?? [] ) );

        $sections = [];
        foreach ( $checked_ids as $id ) {
            if ( isset( $available[ $id ] ) ) {
                $sections[ $id ] = [
                    'name' => sanitize_text_field( $available[ $id ]['name'] ?? '' ),
                    'type' => sanitize_text_field( $available[ $id ]['type'] ?? '' ),
                ];
            }
        }
        update_option( 'ems_managed_sections', $sections );
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
        }

        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'sections';
        $page_url   = admin_url( 'admin.php?page=ems-settings' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'EMS Settings', 'ems-plugin' ); ?></h1>
            <nav class="nav-tab-wrapper">
                <a href="<?php echo esc_url( $page_url . '&tab=sections' ); ?>"
                   class="nav-tab<?php echo $active_tab === 'sections' ? ' nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Managed Sections', 'ems-plugin' ); ?>
                </a>
                <a href="<?php echo esc_url( $page_url . '&tab=general' ); ?>"
                   class="nav-tab<?php echo $active_tab === 'general' ? ' nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'General', 'ems-plugin' ); ?>
                </a>
                <a href="<?php echo esc_url( $page_url . '&tab=connection' ); ?>"
                   class="nav-tab<?php echo $active_tab === 'connection' ? ' nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'OSM Connection', 'ems-plugin' ); ?>
                </a>
            </nav>
            <?php
            if ( $active_tab === 'general' ) {
                $this->render_general_tab();
            } elseif ( $active_tab === 'connection' ) {
                $this->render_connection_tab();
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
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'OSM reference data purged successfully.', 'ems-plugin' ) . '</p></div>';
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

        <hr style="margin:2em 0" />
        <h3 style="color:#b32d2e"><?php esc_html_e( 'Danger Zone', 'ems-plugin' ); ?></h3>
        <p class="description"><?php esc_html_e( 'Deletes all synced OSM reference data from the database (explorers, events, attendance, patrols). This cannot be undone.', 'ems-plugin' ); ?></p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return document.getElementById('ems_purge_confirm').checked || (alert('Check the confirmation box first.'), false);">
            <?php wp_nonce_field( 'ems_purge_osm_data' ); ?>
            <input type="hidden" name="action" value="ems_purge_osm_data" />
            <label>
                <input type="checkbox" id="ems_purge_confirm" />
                <?php esc_html_e( 'Yes, I want to permanently delete all synced OSM reference data.', 'ems-plugin' ); ?>
            </label>
            <br /><br />
            <input type="submit" class="button button-link-delete" value="<?php esc_attr_e( 'Purge OSM Reference Data', 'ems-plugin' ); ?>" />
        </form>
        <?php
    }

    private function render_connection_tab(): void {
        $api_url      = get_option( 'ems_osm_api_base_url', 'https://www.onlinescoutmanager.co.uk' );
        $auth_url     = get_option( 'ems_osm_auth_url', 'https://www.onlinescoutmanager.co.uk/oauth/authorize' );
        $token_url    = get_option( 'ems_osm_token_url', 'https://www.onlinescoutmanager.co.uk/oauth/token' );
        $resource_url = get_option( 'ems_osm_resource_url', 'https://www.onlinescoutmanager.co.uk/oauth/resource' );
        $client_id    = get_option( 'ems_osm_client_id', '' );
        $scope        = get_option( 'ems_osm_scope', 'section:member:read section:events:read section:flexirecord:read' );
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
                            <?php echo $has_secret
                                ? esc_html__( 'Secret is set. Leave blank to keep current value.', 'ems-plugin' )
                                : esc_html__( 'Enter your OSM OAuth client secret. Stored encrypted.', 'ems-plugin' ); ?>
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
        $available = (array) get_transient( 'ems_available_sections' );
        $managed   = (array) get_option( 'ems_managed_sections', [] );
        if ( isset( $_GET['fetched'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Section list refreshed from OSM.', 'ems-plugin' ) . '</p></div>';
        }
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:1em 0">
            <?php wp_nonce_field( 'ems_fetch_sections' ); ?>
            <input type="hidden" name="action" value="ems_fetch_sections" />
            <button type="submit" class="button"><?php esc_html_e( 'Fetch sections from OSM', 'ems-plugin' ); ?></button>
            <span class="description" style="margin-left:.5em"><?php esc_html_e( 'Retrieves the section list from OSM (or mock data) and caches it for 1 hour.', 'ems-plugin' ); ?></span>
        </form>
        <?php if ( empty( $available ) ) : ?>
            <div class="notice notice-info inline"><p>
                <?php esc_html_e( 'No section list cached yet. Click "Fetch sections from OSM" above to populate this list.', 'ems-plugin' ); ?>
            </p></div>
        <?php else : ?>
        <form method="post">
            <?php wp_nonce_field( 'ems_settings_sections' ); ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:40px"><?php esc_html_e( 'Managed', 'ems-plugin' ); ?></th>
                        <th><?php esc_html_e( 'Section Name', 'ems-plugin' ); ?></th>
                        <th><?php esc_html_e( 'Type', 'ems-plugin' ); ?></th>
                        <th><?php esc_html_e( 'Section ID', 'ems-plugin' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $available as $id => $data ) :
                        $id      = (int) $id;
                        $checked = isset( $managed[ $id ] );
                        $name    = esc_html( $data['name'] ?? '' );
                        $type    = esc_html( $data['type'] ?? '' );
                    ?>
                    <tr>
                        <td><input type="checkbox" name="ems_managed_section_ids[]" value="<?php echo $id; ?>" <?php checked( $checked ); ?> /></td>
                        <td><?php echo $name; ?></td>
                        <td><?php echo $type; ?></td>
                        <td><code><?php echo $id; ?></code></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="submit">
                <input type="submit" name="ems_save_sections" class="button-primary" value="<?php esc_attr_e( 'Save Managed Sections', 'ems-plugin' ); ?>" />
            </p>
        </form>
        <?php endif; ?>
        <hr />
        <?php if ( ! empty( $managed ) ) : ?>
        <h3><?php esc_html_e( 'Currently Managed', 'ems-plugin' ); ?></h3>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr>
                <th><?php esc_html_e( 'Section ID', 'ems-plugin' ); ?></th>
                <th><?php esc_html_e( 'Name', 'ems-plugin' ); ?></th>
                <th><?php esc_html_e( 'Type', 'ems-plugin' ); ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ( $managed as $id => $data ) : ?>
                <tr>
                    <td><code><?php echo (int) $id; ?></code></td>
                    <td><?php echo esc_html( $data['name'] ?? '' ); ?></td>
                    <td><?php echo esc_html( $data['type'] ?? '' ); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <?php
    }
}
