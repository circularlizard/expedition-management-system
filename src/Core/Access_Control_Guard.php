<?php
namespace EMS\Core;

class Access_Control_Guard {

	public function __construct() {
		add_action( 'template_redirect', array( $this, 'guard_request' ) );
	}

	public function guard_request(): void {
		$protected_page_ids = get_option( 'ems_protected_pages', array() );
		$allowed_roles      = get_option( 'ems_allowed_roles', array() );
		$protect_tutor      = get_option( 'ems_protect_tutor_lms', true );

		$is_tutor_page = false;
		if ( $protect_tutor && function_exists( 'tutor' ) ) {
			$is_tutor_dash   = function_exists( 'is_tutor_dashboard' ) && is_tutor_dashboard();
			$is_tutor_course = function_exists( 'is_single_course' ) && is_single_course();
			$is_tutor_page   = $is_tutor_dash || $is_tutor_course;
		}
		$is_protected  = ( ! empty( $protected_page_ids ) && is_page( $protected_page_ids ) ) || $is_tutor_page;

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
		
		// Administrators are implicitly allowed to access all protected pages
		if ( in_array( 'administrator', (array) $current_user->roles, true ) ) {
			return;
		}

		$has_role = array_intersect( $allowed_roles, (array) $current_user->roles );

		if ( empty( $has_role ) ) {
			if ( class_exists( '\EMS\Tests\EMSTestCase' ) ) {
				throw new \Exception( 'Access Denied: Your account role does not have permission to view this resource.' );
			}
			status_header( 403 );
			$home_url   = esc_url( home_url( '/' ) );
			$logout_url = esc_url( wp_logout_url( home_url( '/' ) ) );
			?>
			<!DOCTYPE html>
			<html <?php language_attributes(); ?>>
			<head>
				<meta charset="<?php bloginfo( 'charset' ); ?>">
				<meta name="viewport" content="width=device-width, initial-scale=1">
				<title><?php esc_html_e( 'Access Denied', 'ems-plugin' ); ?></title>
				<style>
					body {
						background: #f7f9fa;
						color: #333;
						font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
						display: flex;
						align-items: center;
						justify-content: center;
						min-height: 100vh;
						margin: 0;
					}
					.container {
						background: #fff;
						padding: 40px;
						border-radius: 8px;
						box-shadow: 0 4px 15px rgba(0,0,0,0.05);
						max-width: 500px;
						width: 100%;
						text-align: center;
						box-sizing: border-box;
					}
					.icon {
						font-size: 48px;
						margin-bottom: 20px;
					}
					h1 {
						font-size: 24px;
						margin: 0 0 16px 0;
						color: #23282d;
					}
					p {
						font-size: 15px;
						line-height: 1.6;
						color: #646970;
						margin: 0 0 24px 0;
					}
					.actions {
						display: flex;
						flex-direction: column;
						gap: 12px;
					}
					.button {
						display: inline-block;
						text-decoration: none;
						padding: 12px 20px;
						border-radius: 4px;
						font-weight: 500;
						font-size: 14px;
						transition: background 0.15s ease-in-out;
					}
					.button-primary {
						background: #007cba;
						color: #fff;
					}
					.button-primary:hover {
						background: #006ba1;
					}
					.button-secondary {
						background: #f6f7f7;
						color: #007cba;
						border: 1px solid #007cba;
					}
					.button-secondary:hover {
						background: #f0f6fa;
					}
				</style>
			</head>
			<body>
				<div class="container">
					<div class="icon">🔒</div>
					<h1><?php esc_html_e( 'Access Restricted', 'ems-plugin' ); ?></h1>
					<p>
						<?php esc_html_e( 'Your account role does not have permission to view this page. If you believe this is an error, please contact your organization administrator.', 'ems-plugin' ); ?>
					</p>
					<div class="actions">
						<a href="<?php echo $home_url; ?>" class="button button-primary">
							<?php esc_html_e( 'Go to Homepage', 'ems-plugin' ); ?>
						</a>
						<a href="<?php echo $logout_url; ?>" class="button button-secondary">
							<?php esc_html_e( 'Log Out & Switch Accounts', 'ems-plugin' ); ?>
						</a>
					</div>
				</div>
			</body>
			</html>
			<?php
			exit;
		}
	}
}
