<?php
namespace EMS\Core;

class Access_Control_Guard {

	public function __construct() {
		add_action( 'template_redirect', array( $this, 'guard_request' ) );
	}

	public function guard_request(): void {
		$page_roles    = get_option( 'ems_page_roles', array() );
		$protect_tutor = get_option( 'ems_protect_tutor_lms', true );

		// 1. Check Tutor LMS pages
		$is_tutor_page = false;
		if ( $protect_tutor && function_exists( 'tutor' ) ) {
			$is_tutor_dash   = function_exists( 'is_tutor_dashboard' ) && is_tutor_dashboard();
			$is_tutor_course = function_exists( 'is_single_course' ) && is_single_course();
			$is_tutor_page   = $is_tutor_dash || $is_tutor_course;
		}

		// 2. Check general protected pages
		$current_page_id = 0;
		if ( ! empty( $page_roles ) ) {
			foreach ( array_keys( $page_roles ) as $protected_id ) {
				if ( is_page( $protected_id ) ) {
					$current_page_id = $protected_id;
					break;
				}
			}
		}

		$is_protected = $is_tutor_page || ( $current_page_id > 0 );

		if ( ! $is_protected ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			$target_url = esc_url_raw( $_SERVER['REQUEST_URI'] ?? '' );
			wp_redirect( wp_login_url( $target_url ) );
			if ( class_exists( '\EMS\Tests\EMSTestCase' ) ) {
				throw new \Exception( 'Redirect terminated' );
			}
			exit;
		}

		$current_user = wp_get_current_user();
		$user_roles   = (array) $current_user->roles;

		// Administrators are implicitly allowed to access all pages
		if ( in_array( 'administrator', $user_roles, true ) ) {
			return;
		}

		if ( $is_tutor_page ) {
			$allowed_roles = array( 'ems_explorer' );
			$this->check_role_and_die( $user_roles, $allowed_roles );
			return;
		}

		if ( $current_page_id > 0 ) {
			$allowed_roles = $page_roles[ $current_page_id ];
			$this->check_role_and_die( $user_roles, $allowed_roles );
		}
	}

	private function check_role_and_die( array $user_roles, array $allowed_roles ): void {
		$has_role = array_intersect( $allowed_roles, $user_roles );

		if ( empty( $has_role ) ) {
			if ( class_exists( '\EMS\Tests\EMSTestCase' ) ) {
				throw new \Exception( 'Access Denied: Your account role does not have permission to view this resource.' );
			}
			status_header( 403 );
			get_header();
			$logout_url = esc_url( wp_logout_url( home_url( '/' ) ) );

			// Format current user roles and permitted roles
			$wp_roles        = wp_roles();
			$user_role_names = array_map( function( $role_slug ) use ( $wp_roles ) {
				$names     = $wp_roles->get_names();
				$role_name = $names[ $role_slug ] ?? $role_slug;
				return translate_user_role( $role_name );
			}, $user_roles );
			$user_roles_str  = ! empty( $user_role_names ) ? implode( ', ', $user_role_names ) : __( 'None', 'ems-plugin' );

			$allowed_role_names = array_map( function( $role_slug ) use ( $wp_roles ) {
				$names     = $wp_roles->get_names();
				$role_name = $names[ $role_slug ] ?? $role_slug;
				return translate_user_role( $role_name );
			}, $allowed_roles );
			$allowed_roles_str  = implode( ', ', $allowed_role_names );
			?>
			<div class="ems-access-denied-wrap" style="padding: 80px 20px; background: #f7f9fa; display: flex; align-items: center; justify-content: center; min-height: 50vh;">
				<div class="ems-access-denied-container" style="background: #fff; padding: 40px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); max-width: 500px; width: 100%; text-align: center; box-sizing: border-box; margin: 0 auto;">
					<div class="ems-access-denied-icon">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="48" height="48" style="fill: #dc3232; margin: 0 auto 20px auto; display: block;">
							<path d="M12 2c-2.76 0-5 2.24-5 5v3H6c-1.1 0-2 .9-2 2v8c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2v-8c0-1.1-.9-2-2-2h-1V7c0-2.76-2.24-5-5-5zm-3 5c0-1.66 1.34-3 3-3s3 1.34 3 3v3H9V7zm3 9c-.83 0-1.5-.67-1.5-1.5S11.17 13 12 13s1.5.67 1.5 1.5S12.83 16 12 16z"/>
						</svg>
					</div>
					<h1 style="font-size: 24px; margin: 0 0 16px 0; color: #23282d; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;"><?php esc_html_e( 'Access Restricted', 'ems-plugin' ); ?></h1>
					<p style="font-size: 15px; line-height: 1.6; color: #646970; margin: 0 0 20px 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
						<?php esc_html_e( 'Your account does not have permission to view this page.', 'ems-plugin' ); ?>
					</p>

					<div style="background: #fcf8e3; border: 1px solid #faebcc; color: #8a6d3b; padding: 15px; border-radius: 4px; margin-bottom: 24px; text-align: left; font-size: 14px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
						<div style="margin-bottom: 8px;"><strong><?php esc_html_e( 'Your Role:', 'ems-plugin' ); ?></strong> <code><?php echo esc_html( $user_roles_str ); ?></code></div>
						<div><strong><?php esc_html_e( 'Required Roles:', 'ems-plugin' ); ?></strong> <code><?php echo esc_html( $allowed_roles_str ); ?></code></div>
					</div>

					<p style="font-size: 14px; line-height: 1.6; color: #646970; margin: 0 0 24px 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
						<?php
						echo sprintf(
							esc_html__( 'If you believe this is an error, please contact your organization administrator or email %s.', 'ems-plugin' ),
							'<a href="mailto:expeditions@sesscouts.org.uk" style="color: #007cba; text-decoration: none; font-weight: 500;">expeditions@sesscouts.org.uk</a>'
						);
						?>
					</p>

					<div class="ems-access-denied-actions" style="display: flex; flex-direction: column; gap: 12px;">
						<a href="<?php echo $logout_url; ?>" class="ems-btn-primary" style="display: inline-block; text-decoration: none; padding: 12px 20px; border-radius: 4px; font-weight: 500; font-size: 14px; background: #007cba; color: #fff; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; text-align: center;">
							<?php esc_html_e( 'Log Out & Switch Accounts', 'ems-plugin' ); ?>
						</a>
					</div>
				</div>
			</div>
			<?php
			get_footer();
			exit;
		}
	}
}
