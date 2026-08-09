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
			wp_die(
				esc_html__( 'Access Denied: Your account role does not have permission to view this resource.', 'ems-plugin' ),
				esc_html__( 'Access Denied', 'ems-plugin' ),
				array( 'response' => 403 )
			);
		}
	}
}
