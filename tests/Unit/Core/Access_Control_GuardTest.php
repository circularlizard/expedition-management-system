<?php
namespace {
    // Define global helper flags for testing
    global $ems_test_tutor_dashboard;
    global $ems_test_single_course;
    $ems_test_tutor_dashboard = false;
    $ems_test_single_course = false;

    if ( ! function_exists( 'tutor' ) ) {
        function tutor() {}
    }
    if ( ! function_exists( 'is_tutor_dashboard' ) ) {
        function is_tutor_dashboard() {
            global $ems_test_tutor_dashboard;
            return (bool) $ems_test_tutor_dashboard;
        }
    }
    if ( ! function_exists( 'is_single_course' ) ) {
        function is_single_course() {
            global $ems_test_single_course;
            return (bool) $ems_test_single_course;
        }
    }
}

namespace EMS\Tests\Unit\Core {

    use EMS\Core\Access_Control_Guard;
    use EMS\Tests\EMSTestCase;
    use Brain\Monkey\Functions;
    use Mockery;

    class Access_Control_GuardTest extends EMSTestCase {

        protected function setUp(): void {
            parent::setUp();
            global $ems_test_tutor_dashboard;
            global $ems_test_single_course;
            $ems_test_tutor_dashboard = false;
            $ems_test_single_course = false;

            // Stub standard options
            Functions\when( 'get_option' )->alias( function( $option, $default = false ) {
                if ( $option === 'ems_page_roles' ) {
                    return [
                        42 => [ 'ems_explorer' ],
                        43 => [ 'ems_explorer' ],
                    ];
                }
                if ( $option === 'ems_protect_tutor_lms' ) {
                    return true;
                }
                return $default;
            } );

            Functions\when( 'is_page' )->justReturn( false );
            Functions\when( 'is_user_logged_in' )->justReturn( false );
            Functions\when( 'wp_login_url' )->alias( function( $redirect ) {
                return 'https://example.com/wp-login.php?redirect_to=' . urlencode( $redirect );
            } );
        }

        public function test_construct_adds_template_redirect_hook(): void {
            Functions\expect( 'add_action' )
                ->once()
                ->with( 'template_redirect', Mockery::any() );

            new Access_Control_Guard();
            $this->addToAssertionCount( 1 );
        }

        public function test_guard_returns_early_if_page_is_not_protected(): void {
            Functions\when( 'is_page' )->justReturn( false );
            Functions\when( 'is_user_logged_in' )->justReturn( false );

            // If it returns early, wp_redirect should never be called.
            Functions\expect( 'wp_redirect' )->never();

            $guard = new Access_Control_Guard();
            $guard->guard_request();
            $this->addToAssertionCount( 1 );
        }

        public function test_guard_redirects_unauthenticated_user_to_login(): void {
            // Page 42 is protected, so is_page( [42, 43] ) will return true
            Functions\when( 'is_page' )->alias( function( $pages ) {
                return in_array( 42, (array) $pages, true );
            } );
            Functions\when( 'is_user_logged_in' )->justReturn( false );

            $_SERVER['REQUEST_URI'] = '/protected-page/';

            Functions\expect( 'wp_safe_redirect' )
                ->once()
                ->with( 'https://example.com/wp-login.php?redirect_to=' . urlencode( '/protected-page/' ) );

            $guard = new Access_Control_Guard();
            
            // Assert script execution termination
            try {
                $guard->guard_request();
                $this->fail( 'Expected guard_request to terminate script after redirect' );
            } catch ( \Exception $e ) {
                $this->assertSame( 'Redirect terminated', $e->getMessage() );
            }
        }

        public function test_guard_denies_access_to_user_with_insufficient_role(): void {
            Functions\when( 'is_page' )->alias( function( $pages ) {
                return in_array( 42, (array) $pages, true );
            } );
            Functions\when( 'is_user_logged_in' )->justReturn( true );

            $user = Mockery::mock( \WP_User::class );
            $user->roles = [ 'ems_parent' ];
            Functions\when( 'wp_get_current_user' )->justReturn( $user );

            $guard = new Access_Control_Guard();

            // wp_die is stubbed in EMSTestCase to throw an Exception
            $this->expectException( \Exception::class );
            $this->expectExceptionMessage( 'Access Denied: Your account role does not have permission to view this resource.' );

            $guard->guard_request();
        }

        public function test_guard_allows_access_to_user_with_permitted_role(): void {
            Functions\when( 'is_page' )->alias( function( $pages ) {
                return in_array( 42, (array) $pages, true );
            } );
            Functions\when( 'is_user_logged_in' )->justReturn( true );

            $user = Mockery::mock( \WP_User::class );
            $user->roles = [ 'ems_explorer' ];
            Functions\when( 'wp_get_current_user' )->justReturn( $user );

            // No exception should be thrown and no redirect should occur
            Functions\expect( 'wp_safe_redirect' )->never();

            $guard = new Access_Control_Guard();
            $guard->guard_request();
            $this->addToAssertionCount( 1 );
        }

        public function test_guard_allows_administrators_implicitly(): void {
            Functions\when( 'is_page' )->alias( function( $pages ) {
                return in_array( 42, (array) $pages, true );
            } );
            Functions\when( 'is_user_logged_in' )->justReturn( true );

            $user = Mockery::mock( \WP_User::class );
            // administrator role is not in the allowed list (only ems_explorer is in get_option stub)
            $user->roles = [ 'administrator' ];
            Functions\when( 'wp_get_current_user' )->justReturn( $user );

            $guard = new Access_Control_Guard();
            $guard->guard_request();
            $this->addToAssertionCount( 1 );
        }

        public function test_guard_protects_tutor_lms_pages_if_enabled(): void {
            global $ems_test_tutor_dashboard;
            Functions\when( 'is_page' )->justReturn( false );
            Functions\when( 'is_user_logged_in' )->justReturn( false );
            $ems_test_tutor_dashboard = true;

            $_SERVER['REQUEST_URI'] = '/courses/dashboard/';

            Functions\expect( 'wp_safe_redirect' )
                ->once()
                ->with( 'https://example.com/wp-login.php?redirect_to=' . urlencode( '/courses/dashboard/' ) );

            $guard = new Access_Control_Guard();
            try {
                $guard->guard_request();
                $this->fail( 'Expected redirect' );
            } catch ( \Exception $e ) {
                $this->assertSame( 'Redirect terminated', $e->getMessage() );
            }
        }

        public function test_guard_ignores_tutor_lms_pages_if_disabled(): void {
            global $ems_test_tutor_dashboard;
            Functions\when( 'get_option' )->alias( function( $option, $default = false ) {
                if ( $option === 'ems_protect_tutor_lms' ) {
                    return false;
                }
                return $default;
            } );

            Functions\when( 'is_page' )->justReturn( false );
            Functions\when( 'is_user_logged_in' )->justReturn( false );
            $ems_test_tutor_dashboard = true;

            Functions\expect( 'wp_redirect' )->never();

            $guard = new Access_Control_Guard();
            $guard->guard_request();
            $this->addToAssertionCount( 1 );
        }
    }
}
