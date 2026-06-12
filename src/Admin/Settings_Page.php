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
    }

    public function render(): void {
        if ( isset( $_POST['ems_settings_submit'] ) && check_admin_referer( 'ems_settings' ) ) {
            $this->save_settings( $_POST );
        }

        $mode    = get_option( 'ems_api_mode', 'mock' );
        $api_url = get_option( 'ems_osm_api_base_url', '' );
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
                            <input type="url" name="ems_osm_api_base_url" value="<?php echo esc_attr( $api_url ); ?>" class="regular-text" placeholder="https://www.onlinescoutmanager.co.uk/api.php" />
                            <p class="description"><?php esc_html_e( 'Must be an HTTPS URL.', 'ems-plugin' ); ?></p>
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
