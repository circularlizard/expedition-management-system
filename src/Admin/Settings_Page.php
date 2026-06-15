<?php
namespace EMS\Admin;

class Settings_Page {

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

    public function save_settings( array $post_data ): void {
        $mode = in_array( $post_data['ems_api_mode'] ?? '', [ 'mock', 'live' ], true )
            ? $post_data['ems_api_mode']
            : 'mock';
        update_option( 'ems_api_mode', $mode );

        $raw_url = $post_data['ems_osm_api_base_url'] ?? '';
        $url     = esc_url_raw( $raw_url );
        if ( $url !== '' && filter_var( $url, FILTER_VALIDATE_URL ) && str_starts_with( $url, 'https://' ) ) {
            update_option( 'ems_osm_api_base_url', $url );
        }

        // OAuth Endpoints
        $auth_url = esc_url_raw( $post_data['ems_osm_auth_url'] ?? '' );
        if ( $auth_url ) {
            update_option( 'ems_osm_auth_url', $auth_url );
        }

        $token_url = esc_url_raw( $post_data['ems_osm_token_url'] ?? '' );
        if ( $token_url ) {
            update_option( 'ems_osm_token_url', $token_url );
        }

        $resource_url = esc_url_raw( $post_data['ems_osm_resource_url'] ?? '' );
        if ( $resource_url ) {
            update_option( 'ems_osm_resource_url', $resource_url );
        }

        // Client ID
        $client_id = sanitize_text_field( $post_data['ems_osm_client_id'] ?? '' );
        if ( $client_id ) {
            update_option( 'ems_osm_client_id', $client_id );
        }

        // Client Secret (Encrypted)
        $client_secret = $post_data['ems_osm_client_secret'] ?? '';
        if ( $client_secret ) {
            $encrypted = \EMS\Core\Encryption::encrypt( $client_secret );
            if ( $encrypted ) {
                update_option( 'ems_osm_client_secret', $encrypted );
            }
        }

        // Managed Sections
        $sections_raw = $post_data['ems_sections'] ?? [];
        $sections     = [];
        if ( is_array( $sections_raw ) ) {
            foreach ( $sections_raw as $id => $data ) {
                $id = (int) $id;
                if ( $id > 0 && ! empty( $data['name'] ) ) {
                    $sections[ $id ] = [
                        'name'    => sanitize_text_field( $data['name'] ),
                        'extraid' => sanitize_text_field( $data['extraid'] ?? '' ),
                    ];
                }
            }
        }
        update_option( 'ems_managed_sections', $sections );
    }

    public function render(): void {
        if ( isset( $_POST['ems_settings_submit'] ) && check_admin_referer( 'ems_settings' ) ) {
            $this->save_settings( $_POST );
        }

        $mode          = get_option( 'ems_api_mode', 'mock' );
        $api_url       = get_option( 'ems_osm_api_base_url', 'https://www.onlinescoutmanager.co.uk/api.php' );
        $auth_url      = get_option( 'ems_osm_auth_url', 'https://www.onlinescoutmanager.co.uk/oauth/authorize' );
        $token_url     = get_option( 'ems_osm_token_url', 'https://www.onlinescoutmanager.co.uk/oauth/token' );
        $resource_url  = get_option( 'ems_osm_resource_url', 'https://www.onlinescoutmanager.co.uk/oauth/resource' );
        $client_id     = get_option( 'ems_osm_client_id', '' );
        $has_secret    = ! empty( get_option( 'ems_osm_client_secret' ) );
        $redirect_uri  = admin_url( 'admin-post.php?action=ems_osm_callback' );
        $sections      = (array) get_option( 'ems_managed_sections', [] );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'EMS Settings', 'ems-plugin' ); ?></h1>
            <form method="post">
                <?php wp_nonce_field( 'ems_settings' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'API Mode', 'ems-plugin' ); ?></th>
                        <td>
                            <select name="ems_api_mode">
                                <option value="mock" <?php selected( $mode, 'mock' ); ?>><?php esc_html_e( 'Mock', 'ems-plugin' ); ?></option>
                                <option value="live" <?php selected( $mode, 'live' ); ?>><?php esc_html_e( 'Live', 'ems-plugin' ); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e( 'Use Mock to test locally without a live OSM connection.', 'ems-plugin' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'OSM API Base URL', 'ems-plugin' ); ?></th>
                        <td>
                            <input type="url" name="ems_osm_api_base_url" value="<?php echo esc_attr( $api_url ); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e( 'Main OSM API endpoint (api.php).', 'ems-plugin' ); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row" colspan="2"><h3><?php esc_html_e( 'Managed Sections', 'ems-plugin' ); ?></h3></th>
                    </tr>
                    <tr>
                        <td colspan="2">
                            <table class="widefat striped" id="ems-sections-table">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e( 'Section ID', 'ems-plugin' ); ?></th>
                                        <th><?php esc_html_e( 'Name', 'ems-plugin' ); ?></th>
                                        <th><?php esc_html_e( 'Flexi-Record ID (extraid)', 'ems-plugin' ); ?></th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( $sections as $id => $data ) : ?>
                                        <tr>
                                            <td><input type="number" name="ems_sections[<?php echo (int) $id; ?>][id]" value="<?php echo (int) $id; ?>" readonly /></td>
                                            <td><input type="text" name="ems_sections[<?php echo (int) $id; ?>][name]" value="<?php echo esc_attr( $data['name'] ); ?>" /></td>
                                            <td><input type="text" name="ems_sections[<?php echo (int) $id; ?>][extraid]" value="<?php echo esc_attr( $data['extraid'] ); ?>" /></td>
                                            <td><button type="button" class="button ems-remove-section"><?php esc_html_e( 'Remove', 'ems-plugin' ); ?></button></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr class="ems-new-section-row">
                                        <td><input type="number" id="ems-new-section-id" placeholder="e.g. 43105" /></td>
                                        <td><input type="text" id="ems-new-section-name" placeholder="e.g. Silver ESU" /></td>
                                        <td><input type="text" id="ems-new-section-extraid" placeholder="e.g. 73848" /></td>
                                        <td><button type="button" class="button" id="ems-add-section"><?php esc_html_e( 'Add Section', 'ems-plugin' ); ?></button></td>
                                    </tr>
                                </tbody>
                            </table>
                            <script>
                                jQuery(document).ready(function($) {
                                    $('#ems-add-section').on('click', function() {
                                        var id = $('#ems-new-section-id').val();
                                        var name = $('#ems-new-section-name').val();
                                        var extraid = $('#ems-new-section-extraid').val();
                                        if (!id || !name) return;
                                        
                                        var row = '<tr>' +
                                            '<td><input type="number" name="ems_sections['+id+'][id]" value="'+id+'" readonly /></td>' +
                                            '<td><input type="text" name="ems_sections['+id+'][name]" value="'+name+'" /></td>' +
                                            '<td><input type="text" name="ems_sections['+id+'][extraid]" value="'+extraid+'" /></td>' +
                                            '<td><button type="button" class="button ems-remove-section">Remove</button></td>' +
                                            '</tr>';
                                        $('.ems-new-section-row').before(row);
                                        $('#ems-new-section-id, #ems-new-section-name, #ems-new-section-extraid').val('');
                                    });
                                    $(document).on('click', '.ems-remove-section', function() {
                                        $(this).closest('tr').remove();
                                    });
                                });
                            </script>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row" colspan="2"><h3><?php esc_html_e( 'OAuth Configuration', 'ems-plugin' ); ?></h3></th>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e( 'Redirect URI (Callback)', 'ems-plugin' ); ?></th>
                        <td>
                            <code><?php echo esc_html( $redirect_uri ); ?></code>
                            <p class="description"><?php esc_html_e( 'Copy this URL and paste it into the "Redirect URL" field in your OSM OAuth application settings.', 'ems-plugin' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'OSM Authorization URL', 'ems-plugin' ); ?></th>
                        <td>
                            <input type="url" name="ems_osm_auth_url" value="<?php echo esc_attr( $auth_url ); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'OSM Token URL', 'ems-plugin' ); ?></th>
                        <td>
                            <input type="url" name="ems_osm_token_url" value="<?php echo esc_attr( $token_url ); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'OSM Resource Owner URL', 'ems-plugin' ); ?></th>
                        <td>
                            <input type="url" name="ems_osm_resource_url" value="<?php echo esc_attr( $resource_url ); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'OSM Client ID', 'ems-plugin' ); ?></th>
                        <td>
                            <input type="text" name="ems_osm_client_id" value="<?php echo esc_attr( $client_id ); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'OSM Client Secret', 'ems-plugin' ); ?></th>
                        <td>
                            <input type="password" name="ems_osm_client_secret" value="" class="regular-text" placeholder="<?php echo $has_secret ? '********' : ''; ?>" />
                            <p class="description">
                                <?php 
                                if ( $has_secret ) {
                                    esc_html_e( 'Secret is set. Leave blank to keep current secret.', 'ems-plugin' );
                                } else {
                                    esc_html_e( 'Enter your OSM OAuth client secret. It will be stored encrypted.', 'ems-plugin' );
                                }
                                ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="ems_settings_submit" class="button-primary" value="<?php esc_attr_e( 'Save Settings', 'ems-plugin' ); ?>" />
                </p>
            </form>
        </div>
        <?php
    }
}
